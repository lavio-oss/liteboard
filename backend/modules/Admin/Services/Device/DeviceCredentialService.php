<?php

namespace Modules\Admin\Services\Device;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Device\DeviceCredential;
use RuntimeException;

final class DeviceCredentialService
{
    private const string TOKEN_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    private const int DEFAULT_TOKEN_LENGTH = 20;

    public function generateAccessToken(): string
    {
        $alphabetLength = strlen(self::TOKEN_ALPHABET);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = '';

            for ($index = 0; $index < self::DEFAULT_TOKEN_LENGTH; $index++) {
                $token .= self::TOKEN_ALPHABET[random_int(0, $alphabetLength - 1)];
            }

            if (! DeviceCredential::query()->where('token_hash', $this->hashToken($token))->exists()) {
                return $token;
            }
        }

        throw new RuntimeException('Unable to generate a unique device access token.');
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function hashMqttPassword(string $plainToken): string
    {
        return str_replace('$2y$', '$2a$', Hash::make($plainToken));
    }

    public function issueForDevice(Device $device): DeviceCredential
    {
        $plainToken = $this->generateAccessToken();

        return $device->credential()->create([
            'credential_type' => DeviceCredentialType::AccessToken->value,
            'token_hash' => $this->hashToken($plainToken),
            'mqtt_password_hash' => $this->hashMqttPassword($plainToken),
            'encrypted_token' => $plainToken,
            'status' => DeviceCredentialStatus::Active->value,
            'rotated_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ]);
    }

    public function updateCredential(
        Device $device,
        DeviceCredentialStatus $status,
    ): DeviceCredential {
        return DB::transaction(function () use ($device, $status): DeviceCredential {
            $credential = $device->credential()->firstOrFail();
            $now = CarbonImmutable::now();

            $credential->fill([
                'status' => $status->value,
                'revoked_at' => $status === DeviceCredentialStatus::Revoked ? $now : null,
            ]);

            $credential->save();

            return $credential->refresh();
        });
    }

    public function rotateCredential(Device $device): DeviceCredential
    {
        return DB::transaction(function () use ($device): DeviceCredential {
            $credential = $device->credential()->firstOrFail();
            $plainToken = $this->generateAccessToken();

            $credential->fill([
                'token_hash' => $this->hashToken($plainToken),
                'mqtt_password_hash' => $this->hashMqttPassword($plainToken),
                'encrypted_token' => $plainToken,
                'status' => DeviceCredentialStatus::Active->value,
                'rotated_at' => CarbonImmutable::now(),
                'revoked_at' => null,
            ]);
            $credential->save();

            return $credential->refresh();
        });
    }

    /**
     * @return array{
     *     telemetry: string,
     *     heartbeat: string,
     *     commands: string,
     *     commands_ack: string
     * }
     */
    public function mqttTopicsFor(Device $device): array
    {
        $projectId = (string) $device->getAttribute('project_id');
        $deviceId = (string) $device->getKey();

        return [
            'telemetry' => "liteboard/$projectId/devices/$deviceId/telemetry",
            'heartbeat' => "liteboard/$projectId/devices/$deviceId/heartbeat",
            'commands' => "liteboard/$projectId/devices/$deviceId/commands",
            'commands_ack' => "liteboard/$projectId/devices/$deviceId/commands/ack",
        ];
    }
}
