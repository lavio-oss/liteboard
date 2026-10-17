from __future__ import annotations

import asyncio
import json
import math
import os
import time
import tracemalloc
from collections import Counter
from datetime import datetime, timezone
from statistics import mean
from typing import Any
from unittest.mock import patch
from uuid import uuid4

import asyncpg
from gmqtt import Client
from gmqtt.mqtt.constants import MQTTv50

from app.ingress.mqtt_subscriber import MqttSubscriber
from app.realtime.hub import RealtimeHub
from app.realtime.presence_hub import PresenceHub
from app.mqtt_tls import build_mqtt_ssl_context
from app.storage.telemetry_ingest import IngestResult, PostgresTelemetryIngestor

DEVICE_COUNT = 10
DURATION_SECONDS = 300
MAX_CONFIGURED_BACKLOG = 1000
PROFILE_DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
}


async def main() -> None:
    database_url = os.getenv('TELEMETRY_TEST_DATABASE_URL')
    mqtt_host = os.getenv('MQTT_HOST', 'localhost')
    mqtt_port = int(os.getenv('MQTT_PORT', '8883'))
    if not database_url:
        raise RuntimeError('TELEMETRY_TEST_DATABASE_URL must point to a disposable, migrated PostgreSQL database.')

    pool = await asyncpg.create_pool(database_url, min_size=1, max_size=10)
    tenant_id, project_id, _profile_version_id, device_ids = await _seed_scope(pool)
    measured_ingestor = _MeasuredIngestor(
        PostgresTelemetryIngestor(pool),
        project_id=str(project_id),
        device_ids={str(device_id) for device_id in device_ids},
    )
    client_id = f'liteboard-it3-l0-{uuid4()}'
    environment = {
        'MQTT_CLIENT_ID': client_id,
        'MQTT_RECEIVE_MAXIMUM': '20',
        'MQTT_HOST': mqtt_host,
        'MQTT_PORT': str(mqtt_port),
    }
    username = os.getenv('MQTT_USERNAME', '')
    password = os.getenv('MQTT_PASSWORD', '')
    if username:
        environment['MQTT_USERNAME'] = username
        environment['MQTT_PASSWORD'] = password

    subscriber: MqttSubscriber | None = None
    publisher: Client | None = None
    publisher_ssl = None
    if os.getenv('TELEMETRY_L0_INSECURE_TEST_BROKER') == '1':
        tls_patch = patch('app.ingress.mqtt_subscriber.build_mqtt_ssl_context', return_value=None)
    else:
        ca_file = os.getenv('MQTT_TLS_CA_FILE', '/run/secrets/mqtt_tls_ca_certificate')
        publisher_ssl = build_mqtt_ssl_context(ca_file)
        tls_patch = None

    try:
        with patch.dict(os.environ, environment):
            if tls_patch is None:
                subscriber = MqttSubscriber(heartbeat_pipeline=[], telemetry_ingestor=measured_ingestor, realtime_hub=RealtimeHub(), presence_hub=PresenceHub())
                await subscriber.connect_and_subscribe()
            else:
                with tls_patch:
                    subscriber = MqttSubscriber(heartbeat_pipeline=[], telemetry_ingestor=measured_ingestor, realtime_hub=RealtimeHub(), presence_hub=PresenceHub())
                    await subscriber.connect_and_subscribe()

        publisher = Client(f'{client_id}-publisher', clean_session=True)
        if username:
            publisher.set_auth_credentials(username, password)
        await publisher.connect(mqtt_host, mqtt_port, ssl=publisher_ssl, version=MQTTv50)

        tracemalloc.start()
        tracemalloc.reset_peak()
        start = time.perf_counter()
        sent = 0
        max_uncommitted = 0
        for sequence in range(DURATION_SECONDS):
            batch_deadline = start + sequence
            for device_index, device_id in enumerate(device_ids):
                publish_deadline = batch_deadline + device_index / DEVICE_COUNT
                await asyncio.sleep(max(0.0, publish_deadline - time.perf_counter()))
                message_id = f'{device_id.hex}-{sequence}'
                payload = _payload(message_id, device_index, sequence)
                publisher.publish(
                    f'liteboard/{project_id}/devices/{device_id}/telemetry',
                    payload,
                    qos=1,
                )
                sent += 1
                max_uncommitted = max(max_uncommitted, sent - len(measured_ingestor.terminal_message_ids))

            if (sequence + 1) % 30 == 0:
                print(
                    f'L0 progress: sent={sent}/{DEVICE_COUNT * DURATION_SECONDS}, '
                    f'committed={measured_ingestor.completed}, elapsed={time.perf_counter() - start:.1f}s',
                    flush=True,
                )

        await asyncio.wait_for(measured_ingestor.load_complete.wait(), timeout=60)
        elapsed = time.perf_counter() - start
        _, peak_memory_bytes = tracemalloc.get_traced_memory()
        tracemalloc.stop()

        async with pool.acquire() as connection:
            samples = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_samples WHERE project_id = $1::uuid',
                project_id,
            )
            outcome_rows = await connection.fetch(
                'SELECT status, COUNT(*) AS count FROM telemetry_ingest_outcomes '
                'WHERE project_id = $1::uuid GROUP BY status',
                project_id,
            )

        outcomes = {row['status']: row['count'] for row in outcome_rows}
        latency_ms = [value * 1000 for value in measured_ingestor.latencies]
        report = {
            'load': {
                'devices': DEVICE_COUNT,
                'messages_per_device_per_second': 1,
                'duration_seconds': DURATION_SECONDS,
                'sent': sent,
                'samples_committed': samples,
                'delivery_attempts': measured_ingestor.delivery_attempts,
                'outcomes': {status: outcomes.get(status, 0) for status in (
                    'persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'skipped',
                )},
                'messages_with_terminal_outcome': len(measured_ingestor.terminal_message_ids),
                'failed': measured_ingestor.failed,
                'unexplained': sent - len(measured_ingestor.terminal_message_ids),
                'ingest_transaction_latency_ms': _percentiles(latency_ms),
                'database_latency_mean_ms': round(mean(latency_ms), 3) if latency_ms else None,
                'max_callback_inflight': measured_ingestor.max_active,
                'max_sent_without_commit': max_uncommitted,
                'configured_broker_backlog_limit': MAX_CONFIGURED_BACKLOG,
                'peak_python_tracemalloc_mb': round(peak_memory_bytes / (1024 * 1024), 3),
                'elapsed_seconds': round(elapsed, 3),
            },
        }
        if (
            sent != DEVICE_COUNT * DURATION_SECONDS
            or samples != outcomes.get('persisted', 0)
            or report['load']['unexplained'] != 0
        ):
            raise AssertionError(json.dumps(report, indent=2))
        if measured_ingestor.failed or max_uncommitted >= MAX_CONFIGURED_BACKLOG:
            raise AssertionError(json.dumps(report, indent=2))

        recovery_message_id = f'{uuid4().hex}-recovery'
        measured_ingestor.fail_once_for_message_id = recovery_message_id
        recovery_started = time.perf_counter()
        recovery_device = device_ids[0]
        publisher.publish(
            f'liteboard/{project_id}/devices/{recovery_device}/telemetry',
            _payload(recovery_message_id, 0, DURATION_SECONDS),
            qos=1,
        )
        await asyncio.wait_for(measured_ingestor.recovery_complete.wait(), timeout=60)
        report['recovery_probe'] = {
            'logical_messages_sent': 1,
            'failed_delivery_attempts': measured_ingestor.recovery_failures,
            'successful_ingest_attempts': measured_ingestor.recovery_successes,
            'recovered': measured_ingestor.recovery_successes == 1,
            'recovery_seconds': round(time.perf_counter() - recovery_started, 3),
        }
        print(json.dumps(report, indent=2), flush=True)
        if not report['recovery_probe']['recovered']:
            raise AssertionError('The MQTT persistent-session recovery probe did not persist after redelivery.')
    finally:
        if publisher is not None:
            try:
                await publisher.disconnect()
            except OSError:
                pass
        if subscriber is not None:
            try:
                await subscriber.disconnect()
            except OSError:
                pass
        async with pool.acquire() as connection:
            await connection.execute('DELETE FROM tenants WHERE id = $1::uuid', tenant_id)
        await pool.close()


class _MeasuredIngestor:
    def __init__(
        self,
        ingestor: PostgresTelemetryIngestor,
        *,
        project_id: str,
        device_ids: set[str],
    ) -> None:
        self._ingestor = ingestor
        self._project_id = project_id
        self._device_ids = device_ids
        self.completed = 0
        self.delivery_attempts = 0
        self.outcome_counts: Counter[str] = Counter()
        self.terminal_message_ids: set[str] = set()
        self.failed = 0
        self.max_active = 0
        self._active = 0
        self.latencies: list[float] = []
        self.load_complete = asyncio.Event()
        self.fail_once_for_message_id: str | None = None
        self.recovery_failures = 0
        self.recovery_successes = 0
        self.recovery_complete = asyncio.Event()

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        raw: bytes,
        retained: bool,
        received_wall: datetime,
    ) -> IngestResult:
        if project_id != self._project_id or device_id not in self._device_ids:
            return await self._ingestor.ingest(
                project_id=project_id,
                device_id=device_id,
                raw=raw,
                retained=retained,
                received_wall=received_wall,
            )

        payload = json.loads(raw)
        message_id = payload.get('message_id') if isinstance(payload, dict) else None
        self.delivery_attempts += 1
        if message_id == self.fail_once_for_message_id and self.recovery_failures == 0:
            self.failed += 1
            self.recovery_failures += 1
            raise RuntimeError('L0 recovery probe: simulated database outage')

        started = time.perf_counter()
        self._active += 1
        self.max_active = max(self.max_active, self._active)
        try:
            result = await self._ingestor.ingest(
                project_id=project_id,
                device_id=device_id,
                raw=raw,
                retained=retained,
                received_wall=received_wall,
            )
        except Exception:
            self.failed += 1
            raise
        else:
            self.completed += 1
            self.outcome_counts[result.status] += 1
            if isinstance(message_id, str):
                self.terminal_message_ids.add(message_id)
            self.latencies.append(time.perf_counter() - started)
            if len(self.terminal_message_ids) == DEVICE_COUNT * DURATION_SECONDS:
                self.load_complete.set()
            if message_id == self.fail_once_for_message_id:
                self.recovery_successes += 1
                self.recovery_complete.set()
            return result
        finally:
            self._active -= 1


async def _seed_scope(pool: asyncpg.Pool) -> tuple[Any, Any, Any, tuple[Any, ...]]:
    tenant_id = uuid4()
    project_id = uuid4()
    profile_id = uuid4()
    profile_version_id = uuid4()
    device_ids = tuple(uuid4() for _ in range(DEVICE_COUNT))
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    async with pool.acquire() as connection:
        async with connection.transaction():
            await connection.execute(
                "INSERT INTO tenants (id, name, code, status, created_at, updated_at) "
                "VALUES ($1, 'IT3 L0', $2, 'active', $3, $3)",
                tenant_id,
                f'it3-l0-{tenant_id.hex}',
                now,
            )
            await connection.execute(
                "INSERT INTO projects (id, tenant_id, name, status, created_at, updated_at) "
                "VALUES ($1, $2, 'IT3 L0', 'active', $3, $3)",
                project_id,
                tenant_id,
                now,
            )
            await connection.execute(
                "INSERT INTO device_profiles (id, project_id, code, name, created_at, updated_at) "
                "VALUES ($1, $2, $3, 'IT3 L0', $4, $4)",
                profile_id,
                project_id,
                f'it3-l0-{profile_id.hex[:12]}',
                now,
            )
            await connection.execute(
                'INSERT INTO device_profile_versions '
                '(id, project_id, device_profile_id, version, schema_code, definition, created_at, updated_at) '
                'VALUES ($1, $2, $3, 1, $4, $5::jsonb, $6, $6)',
                profile_version_id,
                project_id,
                profile_id,
                f'it3-l0-{profile_version_id.hex[:12]}-v1',
                json.dumps(PROFILE_DEFINITION),
                now,
            )
            for index, device_id in enumerate(device_ids):
                await connection.execute(
                    "INSERT INTO devices "
                    "(id, project_id, device_profile_version_id, name, code, status, approval_status, "
                    "connection_state, created_at, updated_at) "
                    "VALUES ($1, $2, $3, $4, $5, 'active', 'approved', 'offline', $6, $6)",
                    device_id,
                    project_id,
                    profile_version_id,
                    f'IT3 L0 {index}',
                    f'it3-l0-{device_id.hex[:12]}',
                    now,
                )
    return tenant_id, project_id, profile_version_id, device_ids


def _payload(message_id: str, device_index: int, sequence: int) -> bytes:
    return json.dumps(
        {
            'message_id': message_id,
            'schema_version': 1,
            'occurred_at': datetime.now(timezone.utc).isoformat(),
            'values': {'temperature': 20 + device_index + sequence % 20},
        },
        separators=(',', ':'),
    ).encode()


def _percentiles(values: list[float]) -> dict[str, float | None]:
    if not values:
        return {'p50': None, 'p95': None, 'p99': None}
    ordered = sorted(values)
    return {
        f'p{percentile}': round(ordered[max(0, math.ceil(percentile / 100 * len(ordered)) - 1)], 3)
        for percentile in (50, 95, 99)
    }


if __name__ == '__main__':
    asyncio.run(main())
