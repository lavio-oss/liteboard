from __future__ import annotations

from app.application.presence_writer import PresenceWriter


class UpdateDevicePresence:
    def __init__(self, presence_writer: PresenceWriter) -> None:
        self._presence_writer = presence_writer

    async def execute(
        self,
        *,
        project_id: str,
        device_id: str,
        channel: str,
        payload_size: int,
    ) -> None:
        await self._presence_writer.mark_online(device_id=device_id, project_id=project_id)

        print(
            {
                'project_id': project_id,
                'device_id': device_id,
                'channel': channel,
                'payload_size': payload_size,
            },
            flush=True,
        )
