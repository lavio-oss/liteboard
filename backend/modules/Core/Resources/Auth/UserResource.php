<?php

namespace Modules\Core\Resources\Auth;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        if (! $user->relationLoaded('tenants')) {
            throw new LogicException('The user tenants relationship must be loaded before serialization.');
        }

        $emailVerifiedAt = $user->getAttribute('email_verified_at');

        return [
            'id' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'system_role' => $user->getAttribute('system_role'),
            'email_verified_at' => $emailVerifiedAt instanceof CarbonInterface
                ? $emailVerifiedAt->toISOString()
                : null,
            'tenants' => $user->tenants->map(
                static function (Tenant $tenant): array {
                    $pivot = $tenant->getRelationValue('pivot');

                    if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role'))) {
                        throw new LogicException('The tenant membership pivot must include a string role.');
                    }

                    return [
                        'id' => (string) $tenant->getKey(),
                        'name' => (string) $tenant->name,
                        'code' => (string) $tenant->code,
                        'role' => $pivot->getAttribute('role'),
                    ];
                },
            )->values()->all(),
        ];
    }
}
