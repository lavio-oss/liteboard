"""Publish one flat and one enveloped telemetry message to a LiteBoard device over MQTT, then exit.

Usage: LB_TOKEN=<access token> python simulator/publish_once.py <project_id> <device_id> [host] [port] [--tls-ca CA_FILE]
Defaults target the plaintext playground (localhost:1883); the device needs allow_plaintext enabled.
"""
from __future__ import annotations

import argparse
import json
import os
import random
import ssl
import uuid
from datetime import datetime, timezone

import paho.mqtt.client as mqtt


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('project_id')
    parser.add_argument('device_id')
    parser.add_argument('host', nargs='?', default='localhost')
    parser.add_argument('port', nargs='?', type=int, default=1883)
    parser.add_argument('--tls-ca')
    parser.add_argument('--payload', help='Publish only this JSON payload instead of the two samples.')
    args = parser.parse_args()

    token = os.environ['LB_TOKEN']
    topic = f'liteboard/{args.project_id}/devices/{args.device_id}/telemetry'
    payloads = {
        'flat': {'temperature': round(random.uniform(20, 45), 2), 'rpm': random.randint(900, 1800)},
        'envelope': {
            'message_id': str(uuid.uuid4()),
            'schema_version': 1,
            'occurred_at': datetime.now(timezone.utc).isoformat(),
            'values': {'temperature': round(random.uniform(20, 45), 2), 'rpm': random.randint(900, 1800)},
        },
    }

    if args.payload:
        payloads = {'custom': json.loads(args.payload)}

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f'fake-{args.device_id[:8]}')
    client.username_pw_set(token, token)
    if args.tls_ca:
        client.tls_set(ca_certs=args.tls_ca, cert_reqs=ssl.CERT_REQUIRED)
    client.connect(args.host, args.port)
    client.loop_start()
    for name, payload in payloads.items():
        info = client.publish(topic, json.dumps(payload), qos=1)
        info.wait_for_publish(timeout=10)
        print(f'{name}: published={info.is_published()} payload={json.dumps(payload)}')
    client.loop_stop()
    client.disconnect()


if __name__ == '__main__':
    main()
