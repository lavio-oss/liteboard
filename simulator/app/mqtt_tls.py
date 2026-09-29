from __future__ import annotations

import ssl
from pathlib import Path


def build_mqtt_ssl_context(ca_file: str) -> ssl.SSLContext:
    ca_path = Path(ca_file)
    if not ca_path.is_file():
        raise FileNotFoundError(f'MQTT TLS CA certificate does not exist: {ca_path}')

    context = ssl.create_default_context(cafile=str(ca_path))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context
