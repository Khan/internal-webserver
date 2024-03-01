#!/usr/bin/env python3
"""
Flask app to collect oauth refresh tokens which will for upload captions.

In order to use this script Youtube account owners will visit:
http://youtube-uploader.khanacademy.org/ and click the <click here> link
displayed on that page. After clicking on the link, signing into youtube,
and granting permission, the oauth2callback route will be hit.  This will will
save their request token and send off an email to responsible parties. They
will then have to copy the request token in that file to
khantube_oauth_refresh_tokens in secrets.py on webapp and then delete the file.
This will then allow the khantube sync to synchronize the captions.

In order for this app to work a secrets.py file needs to be set up similar to
secrets_example, with values copied over from secrets.py on webapp.
"""

import email.MIMEText
import optparse
import os
import smtplib
import tempfile
import urllib.parse

import flask
import oauth2client.client

import secrets

OAUTH_SCOPES = ['https://gdata.youtube.com',
                'https://www.googleapis.com/auth/youtube.force-ssl',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile']
TO_BE_NOTIFIED = ['jamiewong@khanacademy.org', 'james@khanacademy.org',
                  'i18n-blackhole@khanacademy.org']

app = flask.Flask(__name__)


@app.route("/")
def request_permission():
    oauth_link = ('https://accounts.google.com/o/oauth2/auth?%s' %
        urllib.parse.urlencode({
            'scope': ' '.join(OAUTH_SCOPES),
            'redirect_uri': flask.url_for('oauth2callback', _external=True),
            'response_type': 'code',
            'client_id': secrets.khantube_client_id,
            'access_type': 'offline',
            'approval_prompt': 'force'
        }))

    return ('In order to give Khan Academy permission to upload captions to '
            'your youtube account <a href="%s">click here<a/>' % oauth_link)


@app.route("/oauth2callback", methods=["GET"])
def oauth2callback():
    code = flask.request.args.get('code', None)

    # This constructor makes a call to google for us
    credentials = oauth2client.client.credentials_from_code(
        client_id=secrets.khantube_client_id,
        client_secret=secrets.khantube_client_secret,
        scope=OAUTH_SCOPES,
        code=code,
        redirect_uri=flask.url_for('oauth2callback', _external=True))

    email_address = credentials.id_token['email']
    refresh_token = credentials.refresh_token
    credentials_dir = tempfile.mkdtemp(prefix='oauth.',
                                       suffix='.%s' % email_address)
    credentials_file = os.path.join(credentials_dir, 'credentials')
    with open(credentials_file, 'wb') as f:
        print(refresh_token, file=f)

    # Send off an email altering us that a new oauth refresh token is waiting
    # to be added.
    s = smtplib.SMTP('localhost')
    content = ('%s gave us edit privileges. Copy the data on '
               'internal-webserver in %s to khantube_oauth_refresh_tokens '
               'in secrets.py in webapp, run make secrets_encrypt, and '
               'then delete the file' % (
                   email_address, credentials_file))
    msg = email.MIMEText.MIMEText(content)
    msg['Subject'] = "We got a token!"
    msg['From'] = "no-reply@khanacademy.org"
    msg['To'] = ", ".join(TO_BE_NOTIFIED)

    s.sendmail(msg['From'], TO_BE_NOTIFIED, msg.as_string())
    s.quit()
    if app.debug:
        return ('This is your token, we need it <pre>{refresh_token}</pre> '
                '<a href="{home}">home<a/><pre>{email}</pre>').format(
                    home=flask.url_for('request_permission'),
                    refresh_token=refresh_token,
                    email=email_address)
    else:
        return 'Thanks, we can now caption your videos!'


def main():
    parser = optparse.OptionParser()
    parser.add_option("-d", "--debug", action="store_true", dest="debug",
                      help="Whether to run in debug mode "
                           "(only accessible by localhost and autoreloads)")
    parser.add_option("-p", "--port", type="int", default=-1,
                      help="The port to run on (defaults to 5000 for debug, "
                           "else defaults to 80)")
    options, _ = parser.parse_args()

    app.debug = options.debug
    port = options.port
    if options.debug:
        if port == -1:
            port = 5000
        app.run(port=port)
    else:
        if port == -1:
            port = 80
        app.run(host='0.0.0.0', port=port)


if __name__ == "__main__":
    main()

