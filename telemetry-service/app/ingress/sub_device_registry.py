from __future__ import annotations

import asyncio
import json
import os
import urllib.error
import urllib.request

REQUEST_TIMEOUT_SECONDS = 5.0


class SubDeviceRejected(Exception):
    """Laravel refused the sub-device (code owned elsewhere, quota, unavailable gateway); retrying will not help."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


class SubDeviceRegistry:
    """Creates sub-devices through Laravel so quota, Default profile and tenant rules live in one place."""

    def __init__(self) -> None:
        self._base_url = os.getenv('LARAVEL_INTERNAL_URL', '').rstrip('/')
        self._secret = os.getenv('TELEMETRY_INTERNAL_SECRET', '')

    async def register(self, *, gateway_id: str, code: str) -> str:
        return await asyncio.to_thread(self._post, gateway_id, code)

    def _post(self, gateway_id: str, code: str) -> str:
        if not self._base_url or len(self._secret) < 32:
            raise RuntimeError('LARAVEL_INTERNAL_URL and TELEMETRY_INTERNAL_SECRET (32+ chars) are required for gateways.')

        request = urllib.request.Request(
            f'{self._base_url}/api/internal/gateways/{gateway_id}/sub-devices',
            data=json.dumps({'code': code}).encode(),
            headers={
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Internal-Token': self._secret,
            },
            method='POST',
        )
        try:
            with urllib.request.urlopen(request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
                body = json.load(response)
        except urllib.error.HTTPError as error:
            if error.code == 409:
                raise SubDeviceRejected(str(json.load(error).get('code'))) from error
            raise

        device_id = body.get('id') if isinstance(body, dict) else None
        if not isinstance(device_id, str):
            raise RuntimeError('The sub-device registry answered without an id.')
        return device_id
