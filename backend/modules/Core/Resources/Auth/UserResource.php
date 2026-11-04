<?php

namespace Modules\Core\Resources\Auth;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\User\User;
use Modules\Core\Resources\Tenant\TenantResource;

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
            'email_verified_at' => $emailVerifiedAt instanceof CarbonInterface
                ? $emailVerifiedAt->toISOString()
                : null,
            'tenants' => TenantResource::collection($user->tenants),
        ];
    }
}
