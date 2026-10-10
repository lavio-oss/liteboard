from __future__ import annotations

from typing import TYPE_CHECKING, Any

from app.application.validate_telemetry import Rejection, ValidationResult, validate_telemetry

if TYPE_CHECKING:
    from app.adapters.postgres_device_schema_reader import PostgresDeviceSchemaReader


class ValidateDeviceTelemetry:
    def __init__(self, schema_reader: PostgresDeviceSchemaReader) -> None:
        self._schema_reader = schema_reader

    async def execute(self, *, project_id: str, device_id: str, payload: dict[str, Any]) -> ValidationResult:
        schema = await self._schema_reader.find_for_device(device_id=device_id, project_id=project_id)
        result = (
            ValidationResult((Rejection('schema_not_assigned'),))
            if schema is None
            else validate_telemetry(definition=schema.definition, version=schema.version, payload=payload)
        )

        print(
            {
                'project_id': project_id,
                'device_id': device_id,
                'outcome': 'accepted' if result.accepted else 'rejected',
                'reasons': [{'code': reason.code, 'field': reason.field} for reason in result.reasons],
            },
            flush=True,
        )

        return result
