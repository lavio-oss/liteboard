"""Live end-to-end check of rule evaluation against the running dev stack (real time, ~8 minutes).

Rule under test: temperature > 80 for 30s, clear <= 50 for 30s, max sample gap 15s, critical.

It creates the rule through the REST API, publishes telemetry over MQTT TLS as the configured device
(one sample every 5 seconds, like the ESP32 sketch), and after each scenario reads PostgreSQL to compare
alerts, alert events and outbox events with what the semantics require. Everything is written to a log file.

Not a unit test (the name does not match test_*.py). Configuration comes from the environment:
E2E_DATABASE_URL, E2E_API_URL, E2E_API_TOKEN, E2E_DEVICE_ID, E2E_ACCESS_TOKEN, E2E_CA_FILE,
E2E_MQTT_HOST, E2E_MQTT_PORT, E2E_LOG_FILE.
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import ssl
import sys
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from typing import Any

import asyncpg
from gmqtt import Client as MQTTClient

INTERVAL = 5.0
LOGGER = logging.getLogger('e2e')
RESULTS: list[tuple[str, bool, str]] = []

DATABASE_URL = os.environ['E2E_DATABASE_URL']
API_URL = os.getenv('E2E_API_URL', 'http://localhost:8800').rstrip('/')
API_TOKEN = os.environ['E2E_API_TOKEN']
DEVICE_ID = os.environ['E2E_DEVICE_ID']
ACCESS_TOKEN = os.environ['E2E_ACCESS_TOKEN']
CA_FILE = os.environ['E2E_CA_FILE']
MQTT_HOST = os.getenv('E2E_MQTT_HOST', '127.0.0.1')
MQTT_PORT = int(os.getenv('E2E_MQTT_PORT', '8883'))
LOG_FILE = os.environ['E2E_LOG_FILE']

RULE_DEFINITION = {
    'field': 'temperature',
    'trigger': {'operator': 'gt', 'value': 80, 'duration_seconds': 30},
    'clear': {'operator': 'lte', 'value': 50, 'duration_seconds': 30},
    'max_sample_gap_seconds': 15,
    'severity': 'critical',
}


def check(name: str, condition: bool, detail: str) -> None:
    RESULTS.append((name, condition, detail))
    LOGGER.log(logging.INFO if condition else logging.ERROR, '%s  %s  (%s)', 'PASS' if condition else 'FAIL', name, detail)


class Harness:
    def __init__(self, pool: asyncpg.Pool, client: MQTTClient) -> None:
        self.pool = pool
        self.client = client
        self.sent: dict[str, float] = {}
        self.started_at = datetime.now(timezone.utc)
        self.tenant_id = ''
        self.project_id = ''
        self.profile_id = ''
        self.schema_version = 1
        self.topic = ''
        self.rule_id = ''
        self.rule_code = ''

    # --- MQTT -----------------------------------------------------------------------------
    def publish(self, temperature: float, *, message_id: str | None = None, raw: str | None = None) -> str:
        message_id = message_id or str(uuid.uuid4())
        payload = raw or json.dumps({
            'message_id': message_id,
            'schema_version': self.schema_version,
            'occurred_at': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'values': {'temperature': temperature},
        })
        self.client.publish(self.topic, payload, qos=1)
        self.sent[message_id] = temperature
        LOGGER.info('publish  temperature=%-6s message_id=%s', temperature, message_id[:8])
        return payload

    async def stream(self, temperature: float, seconds: int, *, duplicate_at: tuple[int, ...] = ()) -> list[str]:
        """One sample every 5 s for `seconds` (inclusive of t=0), on a drift-free schedule."""
        ids: list[str] = []
        loop = asyncio.get_running_loop()
        start = loop.time()
        for index in range(int(seconds / INTERVAL) + 1):
            await asyncio.sleep(max(0.0, start + index * INTERVAL - loop.time()))
            message_id = str(uuid.uuid4())
            payload = self.publish(temperature, message_id=message_id)
            ids.append(message_id)
            if index in duplicate_at:
                LOGGER.info('republish identical payload (message_id=%s) to exercise dedup', message_id[:8])
                self.publish(temperature, message_id=message_id, raw=payload)
        return ids

    # --- database -------------------------------------------------------------------------
    async def settle(self, message_ids: list[str] | None = None) -> None:
        """Wait until the sent samples are persisted and the evaluator has drained the queue."""
        wanted = list(message_ids if message_ids is not None else self.sent)
        for _ in range(60):
            persisted = await self.pool.fetchval(
                'SELECT COUNT(*) FROM telemetry_samples WHERE device_id = $1::uuid AND message_id = ANY($2)', DEVICE_ID, wanted)
            pending = await self.pool.fetchval(
                'SELECT COUNT(*) FROM telemetry_evaluation_jobs WHERE device_id = $1::uuid AND evaluated_at IS NULL', DEVICE_ID)
            if persisted >= len(set(wanted)) and pending == 0:
                break
            await asyncio.sleep(0.5)
        await asyncio.sleep(1.5)

    async def alerts(self) -> list[asyncpg.Record]:
        return await self.pool.fetch(
            'SELECT * FROM alerts WHERE alert_rule_id = $1::uuid AND device_id = $2::uuid ORDER BY opened_at', self.rule_id, DEVICE_ID)

    async def event_types(self, alert_id: Any) -> list[str]:
        rows = await self.pool.fetch('SELECT type FROM alert_events WHERE alert_id = $1 ORDER BY occurred_at, id', alert_id)
        return [row['type'] for row in rows]

    async def outbox_types(self, alert_id: Any) -> list[str]:
        rows = await self.pool.fetch(
            'SELECT event_type FROM outbox_events WHERE aggregate_id = $1 ORDER BY created_at, id', alert_id)
        return [row['event_type'] for row in rows]

    async def received_at(self, message_id: str) -> datetime:
        return await self.pool.fetchval(
            'SELECT received_at FROM telemetry_samples WHERE device_id = $1::uuid AND message_id = $2', DEVICE_ID, message_id)

    # --- REST -----------------------------------------------------------------------------
    async def api(self, method: str, path: str, body: dict[str, Any] | None = None) -> dict[str, Any]:
        def call() -> dict[str, Any]:
            request = urllib.request.Request(
                f'{API_URL}/api/tenants/{self.tenant_id}/projects/{self.project_id}{path}',
                data=None if body is None else json.dumps(body).encode(),
                method=method,
                headers={'Authorization': f'Bearer {API_TOKEN}', 'Accept': 'application/json', 'Content-Type': 'application/json'},
            )
            try:
                with urllib.request.urlopen(request, timeout=20) as response:
                    return json.loads(response.read() or b'{}')
            except urllib.error.HTTPError as error:
                raise RuntimeError(f'{method} {path} -> HTTP {error.code}: {error.read().decode()[:400]}') from error

        return await asyncio.to_thread(call)


def seconds(later: datetime, earlier: datetime) -> float:
    return (later - earlier).total_seconds()


async def scenario_short_spike(h: Harness) -> None:
    LOGGER.info('=== S1  spike shorter than the 30s duration -> no alert')
    ids = await h.stream(120, 20)
    ids += await h.stream(25, 10)
    await h.settle(ids)
    alerts = await h.alerts()
    check('S1 20s above 80 does not open an alert', len(alerts) == 0, f'alerts={len(alerts)}')


async def scenario_gap_resets_window(h: Harness) -> None:
    LOGGER.info('=== S2  silence longer than max_sample_gap (15s) restarts the window -> no alert')
    ids = await h.stream(120, 10)
    LOGGER.info('silent for 25s (gap > 15s)')
    await asyncio.sleep(25 - INTERVAL)
    ids += await h.stream(120, 20)
    ids += await h.stream(25, 10)
    await h.settle(ids)
    alerts = await h.alerts()
    check('S2 hot 10s + 25s gap + hot 20s does not open an alert', len(alerts) == 0,
          f'alerts={len(alerts)} (a continuous window would have been 55s)')


async def scenario_sustained_breach(h: Harness) -> None:
    LOGGER.info('=== S3  >80 continuously for 30s opens exactly one critical alert (with duplicate publishes)')
    ids = await h.stream(120, 40, duplicate_at=(3, 6))
    await h.settle(ids)
    alerts = await h.alerts()
    check('S3 exactly one alert', len(alerts) == 1, f'alerts={len(alerts)}')
    if len(alerts) != 1:
        return
    alert = alerts[0]
    first_hot = await h.received_at(ids[0])
    elapsed = seconds(alert['opened_at'], first_hot)
    check('S3 alert is critical and active', alert['severity'] == 'critical' and alert['cleared_at'] is None,
          f"severity={alert['severity']} cleared_at={alert['cleared_at']}")
    check('S3 opened after the full 30s window', 30 <= elapsed < 36, f'opened {elapsed:.2f}s after the first hot sample')
    check('S3 history has one opened event', await h.event_types(alert['id']) == ['opened'], str(await h.event_types(alert['id'])))
    check('S3 outbox has exactly one alert.opened', await h.outbox_types(alert['id']) == ['alert.opened'],
          str(await h.outbox_types(alert['id'])))
    duplicates = await h.pool.fetchval(
        "SELECT COUNT(*) FROM telemetry_ingest_outcomes WHERE device_id = $1::uuid AND status = 'duplicate' AND received_at >= $2",
        DEVICE_ID, h.started_at)
    check('S3 duplicate publishes were deduplicated, not re-evaluated', duplicates >= 2, f'duplicate outcomes={duplicates}')


async def scenario_hysteresis_and_ack(h: Harness) -> None:
    LOGGER.info('=== S4  between the thresholds (50 < v <= 80) keeps the alert; acknowledge does not clear it')
    ids = await h.stream(65, 45)
    await h.settle(ids)
    alert = (await h.alerts())[0]
    check('S4 alert still active after 45s at 65', alert['cleared_at'] is None, f"cleared_at={alert['cleared_at']}")
    await h.api('POST', f"/alerts/{alert['id']}/acknowledge")
    await h.api('POST', f"/alerts/{alert['id']}/acknowledge")
    alert = (await h.alerts())[0]
    events = await h.event_types(alert['id'])
    check('S4 acknowledge recorded but alert not cleared', alert['acknowledged_at'] is not None and alert['cleared_at'] is None,
          f"acknowledged_at={alert['acknowledged_at']} cleared_at={alert['cleared_at']}")
    drift = abs(seconds(datetime.now(timezone.utc), alert['acknowledged_at']))
    check('S4 acknowledged_at is the real time (Laravel-written timestamp has no timezone shift)', drift < 60,
          f'differs from now by {drift:.1f}s')
    check('S4 second acknowledge is idempotent (one event, one outbox)',
          events.count('acknowledged') == 1 and (await h.outbox_types(alert['id'])).count('alert.acknowledged') == 1, str(events))


async def scenario_reheat_and_short_recovery(h: Harness) -> None:
    LOGGER.info('=== S5  re-heating keeps ONE alert; recovery shorter than 30s does not clear it')
    before = (await h.alerts())[0]['last_seen_at']
    ids = await h.stream(120, 10)
    await h.settle(ids)
    alerts = await h.alerts()
    check('S5 re-heat does not open a second alert', len(alerts) == 1, f'alerts={len(alerts)}')
    check('S5 last_seen_at advanced', alerts[0]['last_seen_at'] > before, f"{before} -> {alerts[0]['last_seen_at']}")
    ids = await h.stream(30, 20)
    ids += await h.stream(65, 10)
    await h.settle(ids)
    alert = (await h.alerts())[0]
    check('S5 20s at 30 then back to 65 does not clear', alert['cleared_at'] is None, f"cleared_at={alert['cleared_at']}")


async def scenario_recovery(h: Harness) -> None:
    LOGGER.info('=== S6  <=50 continuously for 30s clears the alert (condition_cleared)')
    ids = await h.stream(30, 35)
    await h.settle(ids)
    alert = (await h.alerts())[0]
    first_cold = await h.received_at(ids[0])
    check('S6 alert cleared as condition_cleared', alert['cleared_at'] is not None and alert['clear_reason'] == 'condition_cleared',
          f"cleared_at={alert['cleared_at']} reason={alert['clear_reason']}")
    if alert['cleared_at'] is not None:
        elapsed = seconds(alert['cleared_at'], first_cold)
        check('S6 cleared after the full 30s window', 30 <= elapsed < 36, f'cleared {elapsed:.2f}s after the first cold sample')
    check('S6 history is opened, acknowledged, cleared',
          await h.event_types(alert['id']) == ['opened', 'acknowledged', 'cleared'], str(await h.event_types(alert['id'])))
    check('S6 outbox has one alert.cleared', (await h.outbox_types(alert['id'])).count('alert.cleared') == 1,
          str(await h.outbox_types(alert['id'])))


async def scenario_new_alert_after_clear(h: Harness) -> None:
    LOGGER.info('=== S7  a fresh 30s breach after recovery opens a NEW alert')
    ids = await h.stream(120, 40)
    await h.settle(ids)
    alerts = await h.alerts()
    check('S7 second alert opened, first stays cleared',
          len(alerts) == 2 and alerts[0]['cleared_at'] is not None and alerts[1]['cleared_at'] is None and alerts[0]['id'] != alerts[1]['id'],
          f'alerts={len(alerts)}')


async def scenario_offline(h: Harness) -> None:
    LOGGER.info('=== S8  device silent for >90s goes offline and its active alert is cleared (device_offline)')
    alert_id = (await h.alerts())[1]['id']
    deadline = asyncio.get_running_loop().time() + 160
    cleared = None
    while asyncio.get_running_loop().time() < deadline:
        await asyncio.sleep(5)
        row = await h.pool.fetchrow('SELECT cleared_at, clear_reason FROM alerts WHERE id = $1', alert_id)
        if row['cleared_at'] is not None:
            cleared = row
            break
    state = await h.pool.fetchval('SELECT connection_state FROM devices WHERE id = $1::uuid', DEVICE_ID)
    check('S8 alert cleared as device_offline', cleared is not None and cleared['clear_reason'] == 'device_offline',
          f'cleared={dict(cleared) if cleared else None} device_state={state}')
    await asyncio.sleep(12)
    outbox = await h.outbox_types(alert_id)
    check('S8 exactly one alert.cleared after further presence refreshes', outbox.count('alert.cleared') == 1, str(outbox))


async def scenario_reconnect(h: Harness) -> None:
    LOGGER.info('=== S9  after reconnecting a full 30s window is needed again')
    ids = await h.stream(120, 20)
    await h.settle(ids)
    check('S9 20s after reconnect does not open an alert', len(await h.alerts()) == 2, f'alerts={len(await h.alerts())}')
    ids += await h.stream(120, 20)
    await h.settle(ids)
    alerts = await h.alerts()
    check('S9 a third alert opens once 30s have elapsed', len(alerts) == 3 and alerts[2]['cleared_at'] is None, f'alerts={len(alerts)}')


async def scenario_archive(h: Harness) -> None:
    LOGGER.info('=== S10 archiving the rule clears its active alert (rule_archived) and stops evaluation')
    await h.api('POST', f'/alert-rules/{h.rule_id}/archive')
    alerts = await h.alerts()
    check('S10 active alert cleared as rule_archived', alerts[2]['cleared_at'] is not None and alerts[2]['clear_reason'] == 'rule_archived',
          f"reason={alerts[2]['clear_reason']}")
    drift = abs(seconds(datetime.now(timezone.utc), alerts[2]['cleared_at']))
    check('S10 archive cleared_at is the real time (no timezone shift)', drift < 60, f'differs from now by {drift:.1f}s')
    ids = await h.stream(120, 40)
    await h.settle(ids)
    check('S10 archived rule opens no more alerts', len(await h.alerts()) == 3, f'alerts={len(await h.alerts())}')


async def main() -> int:
    ssl_context = ssl.create_default_context(cafile=CA_FILE)
    ssl_context.minimum_version = ssl.TLSVersion.TLSv1_2
    pool = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=3)
    client = MQTTClient(f'e2e-{uuid.uuid4().hex[:8]}')
    client.set_auth_credentials(ACCESS_TOKEN, ACCESS_TOKEN)
    await client.connect(MQTT_HOST, MQTT_PORT, ssl=ssl_context)
    h = Harness(pool, client)
    try:
        device = await pool.fetchrow(
            'SELECT d.project_id, p.tenant_id, v.device_profile_id, v.version FROM devices d '
            'JOIN projects p ON p.id = d.project_id JOIN device_profile_versions v ON v.id = d.device_profile_version_id '
            'WHERE d.id = $1::uuid', DEVICE_ID)
        h.project_id, h.tenant_id = str(device['project_id']), str(device['tenant_id'])
        h.profile_id, h.schema_version = str(device['device_profile_id']), device['version']
        h.topic = f'liteboard/{h.project_id}/devices/{DEVICE_ID}/telemetry'

        h.rule_code = f"e2e_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}"
        LOGGER.info('device=%s project=%s profile=%s schema_version=%s', DEVICE_ID, h.project_id, h.profile_id, h.schema_version)
        created = await h.api('POST', '/alert-rules', {
            'code': h.rule_code, 'name': 'E2E temperature > 80 clear <= 50', 'device_profile_id': h.profile_id,
            'definition': RULE_DEFINITION,
        })
        h.rule_id = created['data']['id']
        LOGGER.info('created rule %s (%s): %s', h.rule_code, h.rule_id, json.dumps(RULE_DEFINITION))
        check('S0 rule created through the API', created['data']['status'] == 'active', f'rule_id={h.rule_id}')

        # Prime with a cold sample so every scenario starts from a clean, known window.
        await h.stream(25, 5)
        await h.settle()

        for scenario in (
            scenario_short_spike, scenario_gap_resets_window, scenario_sustained_breach, scenario_hysteresis_and_ack,
            scenario_reheat_and_short_recovery, scenario_recovery, scenario_new_alert_after_clear, scenario_offline,
            scenario_reconnect, scenario_archive,
        ):
            await scenario(h)
    except Exception:
        LOGGER.exception('scenario aborted by an unexpected error')
        RESULTS.append(('error', False, 'unexpected exception, see traceback above'))
    finally:
        await client.disconnect()
        await pool.close()

    failed = [name for name, ok, _ in RESULTS if not ok]
    LOGGER.info('==== %d checks, %d passed, %d failed', len(RESULTS), len(RESULTS) - len(failed), len(failed))
    for name in failed:
        LOGGER.error('FAILED: %s', name)
    return 1 if failed else 0


if __name__ == '__main__':
    os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s %(levelname)-5s %(message)s',
        handlers=[logging.FileHandler(LOG_FILE, encoding='utf-8'), logging.StreamHandler(sys.stdout)],
    )
    logging.getLogger('gmqtt').setLevel(logging.WARNING)
    sys.exit(asyncio.run(main()))
