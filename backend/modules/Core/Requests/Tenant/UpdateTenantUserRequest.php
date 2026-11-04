<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $member = $this->route('user');
        $actor = $this->user();

        return $tenant instanceof Tenant
            && $member instanceof User
            && $actor instanceof User
            && Gate::forUser($actor)->allows('manageMember', [$tenant, $member]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user instanceof User ? $user->getKey() : null),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
            'role_id' => ['sometimes', 'bail', 'uuid'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $tenant = $this->route('tenant');
        $member = $this->route('user');
        $actor = $this->user();

        if (! $tenant instanceof Tenant || ! $member instanceof User || ! $actor instanceof User) {
            throw new LogicException('The tenant and user route bindings are required.');
        }

        $validator->addRules([
            'role_id' => [
                Rule::exists('roles', 'id')->where(static function (Builder $query) use ($tenant): void {
                    $query->where('tenant_id', $tenant->getKey())->where('scope', RoleScope::Tenant->value);
                }),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($tenant, $member, $actor): void {
            $permissions = app(PermissionResolver::class);

            // A person who also belongs to another tenant owns credentials this tenant must not change.
            $changesAccount = ($this->has('name') && $this->string('name')->value() !== $member->name)
                || ($this->has('email') && $this->string('email')->value() !== $member->email)
                || $this->filled('password');

            if ($changesAccount && $member->tenants()->whereKeyNot($tenant->getKey())->exists()) {
                $validator->errors()->add('email', __('Only the person can change the account details of a user who belongs to another tenant.'));
            }

            if ($this->has('role_id') && ! $permissions->isOwner($actor, $tenant)) {
                $role = Role::query()->find($this->string('role_id')->value());

                if ($role?->isOwner() === true) {
                    $validator->errors()->add('role_id', __('Only an Owner can grant the Owner role.'));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => $this->string('email')->lower()->value(),
            ]);
        }
    }
}
