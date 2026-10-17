<?php

namespace Modules\Admin\Services\Telemetry;

use Carbon\CarbonImmutable;
use LogicException;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

/**
 * Signs short-lived tickets the telemetry service accepts for one device stream.
 * Revoked access takes effect within TTL_SECONDS because clients must renew.
 */
final class TelemetryStreamTicketService
{
    public const int TTL_SECONDS = 60;

    /** @return array{ticket: string, expires_at: string} */
    public function issue(User $user, Device $device): array
    {
        $secret = config('services.telemetry.ws_secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new LogicException('services.telemetry.ws_secret must be at least 32 characters.');
        }

        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);
        $payload = $this->base64Url(json_encode([
            'sub' => (string) $user->getKey(),
            'project_id' => (string) $device->getAttribute('project_id'),
            'device_id' => (string) $device->getKey(),
            'exp' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return [
            'ticket' => $payload.'.'.$this->base64Url(hash_hmac('sha256', $payload, $secret, true)),
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
