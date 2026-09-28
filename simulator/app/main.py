from __future__ import annotations

import asyncio
import json
import os
import random
import uuid
from datetime import datetime, timezone

from gmqtt import Client as MQTTClient


async def main() -> None:
    project_id = os.getenv('PROJECT_ID', '')
    device_id = os.getenv('DEVICE_ID', '')
    access_token = os.getenv('DEVICE_ACCESS_TOKEN', '')
    if not project_id or not device_id or not access_token:
        raise RuntimeError('PROJECT_ID, DEVICE_ID, and DEVICE_ACCESS_TOKEN environment variables are required.')

    client_id = f"sim-{device_id[:8]}"
    client = MQTTClient(client_id)
    client.set_auth_credentials(access_token, access_token)
    await client.connect(os.getenv('MQTT_HOST', 'localhost'), int(os.getenv('MQTT_PORT', '1883')))
    topic = f"liteboard/{project_id}/devices/{device_id}/telemetry"
    interval_seconds = int(os.getenv('SIMULATOR_INTERVAL_SECONDS', '5'))

    try:
        while True:
            payload = {
                'message_id': str(uuid.uuid4()),
                'occurred_at': datetime.now(timezone.utc).isoformat(),
                'values': {
                    'temperature': round(random.uniform(20, 45), 2),
                    'rpm': random.randint(900, 1800),
                },
            }
            client.publish(topic, json.dumps(payload), qos=1)
            await asyncio.sleep(interval_seconds)
    finally:
        await client.disconnect()


asyncio.run(main())
