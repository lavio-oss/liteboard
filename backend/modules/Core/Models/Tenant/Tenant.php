<?php

namespace Modules\Core\Models\Tenant;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Actions\Role\SeedTenantRolesAction;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\User\User;

#[Fillable(['parent_id', 'name', 'code', 'status'])]
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    /** @return Factory<Tenant> */
    protected static function newFactory(): Factory
    {
        return TenantFactory::new();
    }

    protected static function booted(): void
    {
        self::created(static function (Tenant $tenant): void {
            app(SeedTenantRolesAction::class)->execute($tenant);
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role_id')
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

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function defaultRole(DefaultRole $role): Role
    {
        return $this->roles()
            ->where('scope', $role->scope()->value)
            ->where('key', $role->key())
            ->firstOrFail();
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }
}
