/**
 * Notification server. Launch with:
 *
 *   sudo node aphlict_server.js --user=aphlict
 *
 * You can also specify `port`, `admin`, `host` and `log`.
 */

var JX = require('./lib/javelin').JX;

JX.require('lib/AphlictFlashPolicyServer', __dirname);
JX.require('lib/AphlictListenerList', __dirname);
JX.require('lib/AphlictLog', __dirname);

var debug = new JX.AphlictLog()
  .addConsole(console);

var clients = new JX.AphlictListenerList();

var config = parse_command_line_arguments(process.argv);

if (config.logfile) {
  debug.addLogfile(config.logfile);
}

function parse_command_line_arguments(argv) {
  var config = {
    port : 22280,
    admin : 22281,
    host : '127.0.0.1',
    user : null,
    log: '/var/log/aphlict.log'
  };

  for (var ii = 2; ii < argv.length; ii++) {
    var arg = argv[ii];
    var matches = arg.match(/^--([^=]+)=(.*)$/);
    if (!matches) {
      throw new Error("Unknown argument '"+arg+"'!");
    }
    if (!(matches[1] in config)) {
      throw new Error("Unknown argument '"+matches[1]+"'!");
    }
    config[matches[1]] = matches[2];
  }

  config.port = parseInt(config.port, 10);
  config.admin = parseInt(config.admin, 10);

  return config;
}

if (process.getuid() !== 0) {
  console.log(
    "ERROR: "+
    "This server must be run as root because it needs to bind to privileged "+
    "port 843 to start a Flash policy server. It will downgrade to run as a "+
    "less-privileged user after binding if you pass a user in the command "+
    "line arguments with '--user=alincoln'.");
  process.exit(1);
}

var net = require('net');
var http  = require('http');
var url = require('url');
var querystring = require('querystring');

process.on('uncaughtException', function (err) {
  debug.log("\n<<< UNCAUGHT EXCEPTION! >>>\n\n" + err);
  process.exit(1);
});

var flash_server = new JX.AphlictFlashPolicyServer()
  .setDebugLog(debug)
  .setAccessPort(config.port)
  .start();


var send_server = net.createServer(function(socket) {
  var listener = clients.addListener(socket);

  debug.log('<%s> Connected from %s',
    listener.getDescription(),
    socket.remoteAddress);

  socket.on('close', function() {
    clients.removeListener(listener);
    debug.log('<%s> Disconnected', listener.getDescription());
  });

  socket.on('timeout', function() {
    debug.log('<%s> Timed Out', listener.getDescription());
  });

  socket.on('end', function() {
    debug.log('<%s> Ended Connection', listener.getDescription());
  });

  socket.on('error', function (e) {
    debug.log('<%s> Error: %s', listener.getDescription(), e);
  });

}).listen(config.port);


var messages_out = 0;
var messages_in = 0;
var start_time = new Date().getTime();

var receive_server = http.createServer(function(request, response) {
  response.writeHead(200, {'Content-Type' : 'text/plain'});

  // Publishing a notification.
  if (request.method == 'POST') {
    var body = '';

    request.on('data', function (data) {
      body += data;
    });

    request.on('end', function () {
      ++messages_in;

      var data = querystring.parse(body);
      debug.log('notification: ' + JSON.stringify(data));
      broadcast(data);
      response.end();
    });
  } else if (request.url == '/status/') {
    request.on('data', function(data) {
      // We just ignore the request data, but newer versions of Node don't
      // get to 'end' if we don't process the data. See T2953.
    });

    request.on('end', function() {
      var status = {
        'uptime': (new Date().getTime() - start_time),
        'clients.active': clients.getActiveListenerCount(),
        'clients.total': clients.getTotalListenerCount(),
        'messages.in': messages_in,
        'messages.out': messages_out,
        'log': config.log,
        'version': 3
      };

      response.write(JSON.stringify(status));
      response.end();
    });
  } else {
    response.statusCode = 400;
    response.write('400 Bad Request');
    response.end();
  }

}).listen(config.admin, config.host);

function broadcast(data) {
  var listeners = clients.getListeners();
  for (var id in listeners) {
    var listener = listeners[id];
    try {
      listener.writeMessage(data);

      ++messages_out;
      debug.log('<%s> Wrote Message', listener.getDescription());
    } catch (error) {
      clients.removeListener(listener);
      debug.log('<%s> Write Error: %s', error);
    }
  }
}

// If we're configured to drop permissions, get rid of them now that we've
// bound to the ports we need and opened logfiles.
if (config.user) {
  process.setuid(config.user);
}

debug.log('Started Server (PID %d)', process.pid);
