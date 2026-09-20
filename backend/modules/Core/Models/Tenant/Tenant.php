<?php

namespace Modules\Core\Models\Tenant;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Project\Project;

#[Fillable(['parent_id', 'name', 'code', 'status'])]
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /** @return Factory<Tenant> */
    protected static function newFactory(): Factory
    {
        return TenantFactory::new();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function hasRole(User $user, TenantRole ...$roles): bool
    {
        return $this->users()
            ->whereKey($user->getKey())
            ->wherePivotIn('role', array_map(
                static fn (TenantRole $role): string => $role->value,
                $roles,
            ))
            ->exists();
    }
}
