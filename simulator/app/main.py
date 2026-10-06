from __future__ import annotations

import asyncio
import json
import os
import random
import uuid
from datetime import datetime, timezone

from gmqtt import Client as MQTTClient

from app.mqtt_tls import build_mqtt_ssl_context
from app.provisioning import load_or_enroll, wait_for_approval


async def main() -> None:
    provisioned = await asyncio.to_thread(wait_for_approval, load_or_enroll())
    project_id = provisioned['device']['project_id']
    device_id = provisioned['device']['id']
    access_token = provisioned['credentials']['access_token']

    client_id = f"sim-{device_id[:8]}"
    client = MQTTClient(client_id)
    client.set_auth_credentials(access_token, access_token)
    mqtt_tls_context = build_mqtt_ssl_context(os.getenv('MQTT_TLS_CA_FILE', '/run/secrets/mqtt_tls_ca_certificate'))
    await client.connect(
        provisioned['mqtt']['host'],
        provisioned['mqtt']['port'],
        ssl=mqtt_tls_context,
    )
    topic = provisioned['mqtt']['topics']['telemetry']
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
