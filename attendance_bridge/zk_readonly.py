#!/usr/bin/env python3
"""Read-only attendance extraction using optional `pyzk` dependency.
Run only from trusted server. Prints JSON records to stdout.
Requires pyzk package and compatible ZK firmware. No template/user writes.
"""
import argparse
import json
import sys
import time
from datetime import datetime

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--host',required=True)
    p.add_argument('--port',type=int,required=True)
    p.add_argument('--protocol',choices=['tcp','udp','auto'],required=True)
    p.add_argument('--probe-only',action='store_true')
    p.add_argument('--timeout',type=int,default=6)
    p.add_argument('--credentials-stdin',action='store_true')
    args=p.parse_args()
    if not 1 <= args.timeout <= 15:
        print('invalid_configuration', file=sys.stderr)
        return 2
    credentials = {}
    if args.credentials_stdin:
        try:
            credentials = json.load(sys.stdin)
            if not isinstance(credentials, dict):
                raise ValueError()
            password = int(credentials.get('password', 0))
            if not 0 <= password <= 4294967295:
                raise ValueError()
        except (ValueError, TypeError):
            print('invalid_configuration', file=sys.stderr)
            return 2
    try:
        from zk import ZK
    except ImportError:
        print('pyzk_unavailable',file=sys.stderr)
        return 3
    last_error=None
    modes=[False,True] if args.protocol=='auto' else [args.protocol=='udp']
    for force_udp in modes:
        connection=None
        started=time.monotonic()
        try:
            options = dict(port=args.port,timeout=args.timeout,force_udp=force_udp,ommit_ping=True)
            if args.credentials_stdin:
                options['password'] = password
            connection=ZK(args.host,**options).connect()
            metadata = {}
            for key, method in [('firmware', 'get_firmware_version'), ('platform', 'get_platform'), ('device_time', 'get_time')]:
                if hasattr(connection, method):
                    value = getattr(connection, method)()
                    metadata[key] = value.isoformat(sep=' ') if isinstance(value, datetime) else str(value)[:150]
            if args.probe_only:
                print(json.dumps({'connected':True,'protocol':'udp' if force_udp else 'tcp',
                    'latency_ms':max(0,round((time.monotonic()-started)*1000)), **metadata}))
                return 0
            events=connection.get_attendance() or []
            rows=[]
            for e in events:
                at=e.timestamp
                if not isinstance(at,datetime):
                    raise ValueError('invalid_timestamp')
                rows.append({'uid':str(e.user_id),'timestamp':at.isoformat(sep=' '),
                    'device_event_id':getattr(e,'uid',None),
                    'status':getattr(e,'status',None), 'type':getattr(e,'type',None),
                    'punch':getattr(e,'punch',None)})
            print(json.dumps({'protocol':'udp' if force_udp else 'tcp','events':rows, **metadata},ensure_ascii=False))
            return 0
        except Exception as exc:
            last_error=type(exc).__name__
        finally:
            if connection:
                try: connection.disconnect()
                except Exception: pass
    print('probe_timeout' if last_error == 'TimeoutError' else 'reader_failed',file=sys.stderr)
    return 2
if __name__=='__main__': sys.exit(main())
