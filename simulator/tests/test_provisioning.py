from __future__ import annotations

import os
import ssl
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from app.provisioning import (
    approval_status_url,
    atomic_write,
    enroll,
    load_or_enroll,
    read_state,
    request_approval_status,
    request_body,
    wait_for_approval,
)


class ProvisioningTests(unittest.TestCase):
    def test_atomic_state_round_trip(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_path = Path(directory) / 'state' / 'device.json'
            state = {'credentials': {'access_token': 'token'}}

            atomic_write(state_path, state)

            self.assertEqual(read_state(state_path), state)
            self.assertEqual(list(state_path.parent.iterdir()), [state_path])
            if os.name == 'posix':
                self.assertEqual(state_path.stat().st_mode & 0o777, 0o600)
                self.assertEqual(state_path.parent.stat().st_mode & 0o777, 0o700)

    def test_bootstrap_requires_https_before_network_access(self) -> None:
        with self.assertRaisesRegex(ValueError, 'must use HTTPS'):
            request_body('http://device.local/bootstrap', {}, 'ca.pem')

    def test_certificate_validation_errors_stop_instead_of_retrying(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch(
            'app.provisioning.request_body', side_effect=ssl.SSLError('hostname mismatch')
        ), patch('app.provisioning.time.sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError, 'TLS certificate validation failed'):
                enroll('https://device.local/bootstrap', {}, 'ca.pem', Path(directory) / 'state.json')
            sleep.assert_not_called()

    def test_saved_credential_prevents_bootstrap_after_restart(self) -> None:
        state = {
            'device': {'project_id': 'project', 'id': 'device'},
            'credentials': {'access_token': 'token'},
            'mqtt': {'host': 'mqtt.local', 'endpoints': [{'port': 8883, 'tls': True}], 'topics': {'telemetry': 'topic'}},
        }

        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            'DEVICE_CONNECTION_MODE': 'provisioning',
            'DEVICE_STATE_FILE': str(Path(directory) / 'device.json'),
        }, clear=False):
            state_path = Path(directory) / 'device.json'
            atomic_write(state_path, state)
            with patch('app.provisioning.enroll') as enroll:
                migrated_state = load_or_enroll()
                enroll.assert_not_called()

            self.assertEqual(migrated_state['credentials'], state['credentials'])
            self.assertEqual(migrated_state['provisioning']['approval_status'], 'approved')
            self.assertEqual(read_state(state_path), migrated_state)

    def test_pending_enrollment_reuses_the_saved_request_id(self) -> None:
        result = {
            'status': 'success',
            'device': {'project_id': 'project', 'id': 'device'},
            'provisioning': {
                'request_id': 'stable-request-id',
                'approval_status': 'pending',
            },
            'credentials': {'access_token': 'token'},
            'mqtt': {'host': 'mqtt.local', 'endpoints': [{'port': 8883, 'tls': True}], 'topics': {'telemetry': 'topic'}},
        }

        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            'DEVICE_CONNECTION_MODE': 'provisioning',
            'DEVICE_STATE_FILE': str(Path(directory) / 'device.json'),
            'PROVISION_KEY': 'key',
            'PROVISION_SECRET': 'secret',
            'DEVICE_NAME': 'Sensor',
            'DEVICE_CODE': 'serial-1',
            'DEVICE_PROVISIONING_URL': 'https://device.local/bootstrap',
            'DEVICE_PROVISIONING_CA_FILE': 'ca.pem',
        }, clear=False):
            pending_path = Path(directory) / 'device.pending.json'
            atomic_write(pending_path, {
                'provision_key': 'key',
                'provision_secret': 'secret',
                'device_name': 'Sensor',
                'device_code': 'serial-1',
                'request_id': 'stable-request-id',
            })
            with patch('app.provisioning.enroll', return_value=result) as enroll:
                self.assertEqual(load_or_enroll(), result)

            sent_payload = enroll.call_args.args[1]
            self.assertEqual(sent_payload['request_id'], 'stable-request-id')
            self.assertFalse(pending_path.exists())

    def test_approval_status_url_uses_the_status_route_and_request_id(self) -> None:
        self.assertEqual(
            approval_status_url('https://api.local/api/device-provisioning/bootstrap', 'request-1'),
            'https://api.local/api/device-provisioning/status?request_id=request-1',
        )

    def test_approval_status_request_uses_provisioning_headers(self) -> None:
        class FakeResponse:
            status = 200

            def __enter__(self):
                return self

            def __exit__(self, *_args: object) -> None:
                return None

            def read(self) -> bytes:
                return b'{"approval_status":"rejected","rejection_reason":"Unknown device"}'

        with patch('app.provisioning.ssl.create_default_context') as create_context, patch(
            'app.provisioning.urllib.request.build_opener'
        ) as build_opener:
            build_opener.return_value.open.return_value = FakeResponse()

            result = request_approval_status(
                'https://api.local/api/device-provisioning/bootstrap',
                'request-1',
                'profile-key',
                'profile-secret',
                'ca.pem',
            )

        request = build_opener.return_value.open.call_args.args[0]
        create_context.assert_called_once_with(cafile='ca.pem')
        self.assertEqual(request.get_method(), 'GET')
        self.assertEqual(request.get_header('X-provision-key'), 'profile-key')
        self.assertEqual(request.get_header('X-provision-secret'), 'profile-secret')
        self.assertEqual(result['approval_status'], 'rejected')

    def test_waiting_for_approval_persists_approval_before_returning(self) -> None:
        enrollment = {
            'device': {'project_id': 'project', 'id': 'device'},
            'provisioning': {
                'request_id': 'request-1',
                'approval_status': 'pending',
                'rejection_reason': None,
            },
            'credentials': {'access_token': 'token'},
        }

        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            'DEVICE_STATE_FILE': str(Path(directory) / 'device.json'),
            'DEVICE_PROVISIONING_URL': 'https://api.local/api/device-provisioning/bootstrap',
            'PROVISION_KEY': 'profile-key',
            'PROVISION_SECRET': 'profile-secret',
            'DEVICE_PROVISIONING_CA_FILE': 'ca.pem',
        }, clear=False), patch('app.provisioning.request_approval_status', side_effect=[
            {'approval_status': 'pending', 'rejection_reason': None},
            {'approval_status': 'approved', 'rejection_reason': None},
        ]), patch('app.provisioning.time.sleep') as sleep:
            result = wait_for_approval(enrollment)

            self.assertEqual(result['provisioning']['approval_status'], 'approved')
            self.assertEqual(read_state(Path(directory) / 'device.json'), result)
            sleep.assert_called_once_with(1.0)

    def test_waiting_for_approval_stops_and_persists_rejection(self) -> None:
        enrollment = {
            'device': {'project_id': 'project', 'id': 'device'},
            'provisioning': {
                'request_id': 'request-1',
                'approval_status': 'pending',
                'rejection_reason': None,
            },
            'credentials': {'access_token': 'token'},
        }

        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            'DEVICE_STATE_FILE': str(Path(directory) / 'device.json'),
            'DEVICE_PROVISIONING_URL': 'https://api.local/api/device-provisioning/bootstrap',
            'PROVISION_KEY': 'profile-key',
            'PROVISION_SECRET': 'profile-secret',
            'DEVICE_PROVISIONING_CA_FILE': 'ca.pem',
        }, clear=False), patch('app.provisioning.request_approval_status', return_value={
            'approval_status': 'rejected',
            'rejection_reason': 'Unknown device',
        }), patch('app.provisioning.time.sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError, 'Unknown device'):
                wait_for_approval(enrollment)

            saved = read_state(Path(directory) / 'device.json')
            self.assertEqual(saved['provisioning']['approval_status'], 'rejected')
            self.assertEqual(saved['provisioning']['rejection_reason'], 'Unknown device')
            sleep.assert_not_called()


if __name__ == '__main__':
    unittest.main()
