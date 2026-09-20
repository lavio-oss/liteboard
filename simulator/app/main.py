from __future__ import annotations

import asyncio
import json
import os
import random
from datetime import datetime, timezone

from gmqtt import Client as MQTTClient


async def main() -> None:
    client = MQTTClient(os.getenv('DEVICE_UID', 'simulator-01'))
    await client.connect(os.getenv('MQTT_HOST', 'localhost'), int(os.getenv('MQTT_PORT', '1883')))
    topic = f"liteboard/demo/{os.getenv('DEVICE_UID', 'simulator-01')}/telemetry"

    try:
        while True:
            payload = {
                'device_uid': os.getenv('DEVICE_UID', 'simulator-01'),
                'occurred_at': datetime.now(timezone.utc).isoformat(),
                'temperature': round(random.uniform(20, 45), 2),
                'rpm': random.randint(900, 1800),
            }
            client.publish(topic, json.dumps(payload), qos=1)
            await asyncio.sleep(5)
    finally:
        await client.disconnect()


asyncio.run(main())
