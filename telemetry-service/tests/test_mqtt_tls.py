from __future__ import annotations

import ssl
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from app.mqtt_tls import build_mqtt_ssl_context


class BuildMqttSslContextTest(unittest.TestCase):
    def test_requires_existing_ca_and_creates_verified_tls_context(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            ca_file = Path(directory) / 'ca.crt'
            ca_file.write_text('test CA certificate', encoding='utf-8')
            context = ssl.create_default_context()

            with patch('app.mqtt_tls.ssl.create_default_context', return_value=context) as create_context:
                result = build_mqtt_ssl_context(str(ca_file))

        create_context.assert_called_once_with(cafile=str(ca_file))
        self.assertIs(result, context)
        self.assertTrue(result.check_hostname)
        self.assertEqual(ssl.CERT_REQUIRED, result.verify_mode)
        self.assertEqual(ssl.TLSVersion.TLSv1_2, result.minimum_version)

    def test_missing_ca_fails_instead_of_falling_back_to_plain_mqtt(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            missing_ca = Path(directory) / 'missing-ca.crt'

            with self.assertRaises(FileNotFoundError):
                build_mqtt_ssl_context(str(missing_ca))

    def test_invalid_ca_file_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            ca_file = Path(directory) / 'ca.crt'
            ca_file.write_text('not a PEM certificate', encoding='utf-8')

            with self.assertRaises(ssl.SSLError):
                build_mqtt_ssl_context(str(ca_file))
