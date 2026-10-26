from __future__ import annotations

import json
import os
import ssl
import tempfile
import time
import urllib.error
import urllib.request
import uuid
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Any
from urllib.parse import urlencode, urlsplit, urlunsplit


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req: urllib.request.Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> None:
        return None


def atomic_write(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    temporary_path: Path | None = None

    try:
        with tempfile.NamedTemporaryFile('w', encoding='utf-8', dir=path.parent, delete=False) as temporary_file:
            temporary_path = Path(temporary_file.name)
            os.chmod(temporary_path, 0o600)
            json.dump(value, temporary_file, separators=(',', ':'))
            temporary_file.flush()
            os.fsync(temporary_file.fileno())
        os.replace(temporary_path, path)
        os.chmod(path, 0o600)
        if os.name == 'posix':
            directory = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()


def read_state(path: Path) -> dict[str, Any] | None:
    if not path.exists():
        return None

    state = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(state, dict):
        raise ValueError('The saved provisioning state must be a JSON object.')

    return state


def request_body(url: str, payload: dict[str, Any], ca_file: str) -> dict[str, Any]:
    if not url.startswith('https://'):
        raise ValueError('DEVICE_PROVISIONING_URL must use HTTPS.')

    context = ssl.create_default_context(cafile=ca_file)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    request = urllib.request.Request(
        url,
        data=json.dumps(payload, separators=(',', ':')).encode('utf-8'),
        headers={'Accept': 'application/json', 'Content-Type': 'application/json'},
        method='POST',
    )

    opener = urllib.request.build_opener(
        NoRedirectHandler,
        urllib.request.HTTPSHandler(context=context),
    )
    with opener.open(request, timeout=15) as response:
        if response.status not in (200, 201):
            raise RuntimeError(f'Provisioning returned unexpected HTTP status {response.status}.')
        result = json.loads(response.read())

    if not isinstance(result, dict) or result.get('status') != 'success':
        raise ValueError('Provisioning returned an invalid response contract.')

    provisioning = result.get('provisioning')
    if not isinstance(provisioning, dict):
        raise ValueError('Provisioning omitted its approval state.')
    if provisioning.get('request_id') != payload['request_id']:
        raise ValueError('Provisioning returned a different request ID.')
    if provisioning.get('approval_status') not in ('pending', 'approved', 'rejected'):
        raise ValueError('Provisioning returned an invalid approval state.')

    return result


def approval_status_url(bootstrap_url: str, request_id: str) -> str:
    parsed = urlsplit(bootstrap_url)
    if parsed.scheme != 'https':
        raise ValueError('DEVICE_PROVISIONING_URL must use HTTPS.')
    if not parsed.path.endswith('/bootstrap'):
        raise ValueError('DEVICE_PROVISIONING_URL must end with /bootstrap.')

    status_path = f'{parsed.path[:-len("bootstrap")]}status'
    return urlunsplit((parsed.scheme, parsed.netloc, status_path, urlencode({'request_id': request_id}), ''))


def request_approval_status(
    bootstrap_url: str,
    request_id: str,
    provision_key: str,
    provision_secret: str,
    ca_file: str,
) -> dict[str, Any]:
    url = approval_status_url(bootstrap_url, request_id)
    context = ssl.create_default_context(cafile=ca_file)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    request = urllib.request.Request(
        url,
        headers={
            'Accept': 'application/json',
            'X-Provision-Key': provision_key,
            'X-Provision-Secret': provision_secret,
        },
        method='GET',
    )
    opener = urllib.request.build_opener(
        NoRedirectHandler,
        urllib.request.HTTPSHandler(context=context),
    )

    with opener.open(request, timeout=15) as response:
        if response.status != 200:
            raise RuntimeError(f'Approval status returned unexpected HTTP status {response.status}.')
        result = json.loads(response.read())

    if not isinstance(result, dict):
        raise ValueError('Approval status returned an invalid response contract.')

    approval_status = result.get('approval_status')
    rejection_reason = result.get('rejection_reason')
    if approval_status not in ('pending', 'approved', 'rejected'):
        raise ValueError('Approval status returned an invalid approval state.')
    if approval_status == 'rejected' and not isinstance(rejection_reason, str):
        raise ValueError('Rejected approval status omitted its reason.')
    if approval_status != 'rejected' and rejection_reason is not None:
        raise ValueError('Approval status returned a reason for a non-rejected device.')

    return result


def retry_after_seconds(value: str | None) -> float:
    if value is None:
        return 1.0
    try:
        return max(float(value), 0.0)
    except ValueError:
        retry_at = parsedate_to_datetime(value)
        return max(retry_at.timestamp() - time.time(), 0.0)


def enroll(url: str, payload: dict[str, Any], ca_file: str, state_path: Path) -> dict[str, Any]:
    delay = 1.0

    while True:
        try:
            result = request_body(url, payload, ca_file)
            atomic_write(state_path, result)
            return result
        except urllib.error.HTTPError as error:
            if error.code == 429 or error.code >= 500:
                retry_after = error.headers.get('Retry-After')
                wait = retry_after_seconds(retry_after) if retry_after is not None else delay
                time.sleep(min(wait, 60.0))
                delay = min(delay * 2, 60.0)
                continue
            raise RuntimeError(f'Provisioning was rejected with HTTP status {error.code}.') from None
        except ssl.SSLError:
            raise RuntimeError('Provisioning TLS certificate validation failed.') from None
        except urllib.error.URLError as error:
            if isinstance(error.reason, ssl.SSLError):
                raise RuntimeError('Provisioning TLS certificate validation failed.') from None
            time.sleep(delay)
            delay = min(delay * 2, 60.0)
        except (TimeoutError, OSError):
            time.sleep(delay)
            delay = min(delay * 2, 60.0)


def load_or_enroll() -> dict[str, Any]:
    mode = os.getenv('DEVICE_CONNECTION_MODE', 'manual')
    if mode == 'manual':
        return {
            'device': {
                'project_id': os.environ['PROJECT_ID'],
                'id': os.environ['DEVICE_ID'],
            },
            'credentials': {'access_token': os.environ['DEVICE_ACCESS_TOKEN']},
            'provisioning': {
                'request_id': None,
                'approval_status': 'approved',
                'rejection_reason': None,
            },
            'mqtt': {
                'host': os.getenv('MQTT_HOST', 'localhost'),
                'endpoints': [{'port': int(os.getenv('MQTT_PORT', '8883')), 'tls': True}],
                'topics': {'telemetry': f"liteboard/{os.environ['PROJECT_ID']}/devices/{os.environ['DEVICE_ID']}/telemetry"},
            },
        }
    if mode != 'provisioning':
        raise ValueError('DEVICE_CONNECTION_MODE must be manual or provisioning.')

    state_path = Path(os.getenv('DEVICE_STATE_FILE', '/var/lib/liteboard/device.json'))
    state = read_state(state_path)
    if state is not None:
        if 'provisioning' not in state:
            state['provisioning'] = {
                'request_id': None,
                'approval_status': 'approved',
                'rejection_reason': None,
            }
            atomic_write(state_path, state)
        return state

    payload = {
        'provision_key': os.environ['PROVISION_KEY'],
        'provision_secret': os.environ['PROVISION_SECRET'],
        'device_name': os.environ['DEVICE_NAME'],
        'device_code': os.environ['DEVICE_CODE'],
        'request_id': str(uuid.uuid4()),
        'firmware_version': os.getenv('FIRMWARE_VERSION'),
    }
    if payload['firmware_version'] is None:
        del payload['firmware_version']

    pending_path = state_path.with_suffix('.pending.json')
    pending = read_state(pending_path)
    if pending is None:
        pending = payload
        atomic_write(pending_path, pending)
    elif any(pending.get(key) != payload.get(key) for key in ('provision_key', 'device_code')):
        raise ValueError('Pending enrollment does not match the configured profile and device code.')

    result = enroll(
        os.environ['DEVICE_PROVISIONING_URL'],
        pending,
        os.environ['DEVICE_PROVISIONING_CA_FILE'],
        state_path,
    )
    pending_path.unlink()
    if os.name == 'posix':
        directory = os.open(pending_path.parent, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    return result


def wait_for_approval(enrollment: dict[str, Any]) -> dict[str, Any]:
    state_path = Path(os.getenv('DEVICE_STATE_FILE', '/var/lib/liteboard/device.json'))
    provisioning = enrollment['provisioning']
    approval_status = provisioning['approval_status']

    if approval_status == 'approved':
        return enrollment
    if approval_status == 'rejected':
        raise RuntimeError(f"Device provisioning was rejected: {provisioning['rejection_reason']}")
    if approval_status != 'pending':
        raise ValueError('Saved enrollment has an invalid approval state.')

    request_id = provisioning['request_id']
    if not isinstance(request_id, str):
        raise ValueError('A pending enrollment must include its request ID.')

    bootstrap_url = os.environ['DEVICE_PROVISIONING_URL']
    provision_key = os.environ['PROVISION_KEY']
    provision_secret = os.environ['PROVISION_SECRET']
    ca_file = os.environ['DEVICE_PROVISIONING_CA_FILE']
    delay = 1.0

    while True:
        try:
            status = request_approval_status(
                bootstrap_url,
                request_id,
                provision_key,
                provision_secret,
                ca_file,
            )
        except urllib.error.HTTPError as error:
            if error.code != 429 and error.code < 500:
                raise RuntimeError(f'Approval status was rejected with HTTP status {error.code}.') from None
            retry_after = error.headers.get('Retry-After')
            wait = retry_after_seconds(retry_after) if retry_after is not None else delay
            time.sleep(min(wait, 60.0))
            delay = min(delay * 2, 60.0)
            continue
        except ssl.SSLError:
            raise RuntimeError('Approval status TLS certificate validation failed.') from None
        except urllib.error.URLError as error:
            if isinstance(error.reason, ssl.SSLError):
                raise RuntimeError('Approval status TLS certificate validation failed.') from None
            time.sleep(delay)
            delay = min(delay * 2, 60.0)
            continue
        except (TimeoutError, OSError):
            time.sleep(delay)
            delay = min(delay * 2, 60.0)
            continue

        next_provisioning = {
            'request_id': request_id,
            'approval_status': status['approval_status'],
            'rejection_reason': status['rejection_reason'],
        }
        next_enrollment = {**enrollment, 'provisioning': next_provisioning}
        atomic_write(state_path, next_enrollment)

        if status['approval_status'] == 'rejected':
            raise RuntimeError(f"Device provisioning was rejected: {status['rejection_reason']}")
        if status['approval_status'] == 'approved':
            return next_enrollment

        time.sleep(min(delay, 60.0))
        delay = min(delay * 2, 60.0)
