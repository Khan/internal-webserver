#!/usr/bin/env python

"""Read a UDP packet on a given port and log it to a logfile."""

import datetime
import socket


def listen_and_log(interface, port, logfile):
    """interface should be "127.0.0.1", or "0.0.0.0"."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind((interface, port))
    while True:
        (data, (ip, port)) = sock.recvfrom(1024)  # buffer size is 1024 bytes
        now = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
        logfile.write("%s %s %s\n" % (now, ip, data))
        logfile.flush()


if __name__ == '__main__':
    import argparse
    import sys

    parser = argparse.ArgumentParser()
    parser.add_argument('logfile',
                        help=('The logfile to write the UDP packets to, '
                              'or "-" for stdout'))
    parser.add_argument('-p', '--port', type=int, default=60000,
                        help='The port to listen on (default: %(default)s)')
    parser.add_argument('-i', '--interface', default='0.0.0.0',
                        help='The interface to listen to; 127.0.0.1 is safest')
    args = parser.parse_args()

    if args.logfile == '-':
        listen_and_log(args.interface, args.port, sys.stdout)
    else:
        with open(args.logfile, 'a') as f:
            listen_and_log(args.interface, args.port, f)
