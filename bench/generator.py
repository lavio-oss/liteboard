"""Runs INSIDE a Linux container (epoll; Windows select() caps near 512 sockets).

usage: generator.py DEVICES_JSON DURATION_S HOST PORT RAMP_S
Prints one line: RESULT{json}. QoS 1. Counts PUBACKs, so "sent" and "broker accepted" are separate numbers.
"""
import asyncio
import json
import ssl
import sys
import time
from datetime import datetime, timezone

from gmqtt import Client
from gmqtt.mqtt.handler import MqttPackageHandler

PUBACKS = 0
_original_puback = MqttPackageHandler._handle_puback_packet


def _counting_puback(self, cmd, packet):
    global PUBACKS
    PUBACKS += 1
    return _original_puback(self, cmd, packet)


MqttPackageHandler._handle_puback_packet = _counting_puback


def payload(device, seq):
    now = datetime.now(timezone.utc).isoformat()
    return json.dumps({'message_id': f'{device["id"][:8]}-{seq}', 'schema_version': 1, 'occurred_at': now,
                       'seq': seq, 'temperature': 20 + seq % 10, 'humidity': 50, 'sent_at': now})


async def run_device(device, host, port, ssl_ctx, duration, stats, go):
    client = Client(device['client_id'])
    client.set_auth_credentials(device['username'], device['password'])
    try:
        await client.connect(host, port, ssl=ssl_ctx, version=4)
    except Exception as error:  # noqa: BLE001
        stats['connect_fail'] += 1
        stats['errors'].append(repr(error))
        return
    stats['connected'] += 1
    await go.wait()  # publishing starts only once every client is connected: connect storm and steady state are separate
    start, seq = time.perf_counter(), 0
    while time.perf_counter() - start < duration:
        client.publish(device['topic'], payload(device, seq), qos=1)
        stats['sent'] += 1
        seq += 1
        await asyncio.sleep(max(0, start + seq - time.perf_counter()))  # 1 msg/s
    await asyncio.sleep(5)  # let in-flight QoS 1 messages be acknowledged
    try:
        await client.disconnect()
    except Exception:  # noqa: BLE001
        pass


async def main():
    devices = json.load(open(sys.argv[1]))
    duration, host, port, ramp = int(sys.argv[2]), sys.argv[3], int(sys.argv[4]), float(sys.argv[5])
    ssl_ctx = ssl.create_default_context(cafile='/ca.crt')
    ssl_ctx.check_hostname = False  # the dev certificate is issued for mqtt.local
    stats = {'sent': 0, 'connected': 0, 'connect_fail': 0, 'errors': []}
    go, started = asyncio.Event(), time.perf_counter()
    tasks = []
    for device in devices:
        tasks.append(asyncio.create_task(run_device(device, host, port, ssl_ctx, duration, stats, go)))
        await asyncio.sleep(ramp)
    while stats['connected'] + stats['connect_fail'] < len(devices):
        await asyncio.sleep(0.2)
    stats['connect_s'] = round(time.perf_counter() - started, 1)
    go.set()
    await asyncio.gather(*tasks)
    stats['pubacked'] = PUBACKS
    stats['errors'] = stats['errors'][:5]
    print('RESULT' + json.dumps(stats))


asyncio.run(main())
