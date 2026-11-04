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

final class StoreTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $actor = $this->user();

        return $tenant instanceof Tenant
            && $actor instanceof User
            && Gate::forUser($actor)->allows('manageUsers', $tenant);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'role_id' => ['required', 'bail', 'uuid'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $tenant = $this->route('tenant');
        $actor = $this->user();

        if (! $tenant instanceof Tenant || ! $actor instanceof User) {
            throw new LogicException('The tenant route binding is required.');
        }

        $validator->addRules([
            'role_id' => [
                Rule::exists('roles', 'id')->where(static function (Builder $query) use ($tenant): void {
                    $query->where('tenant_id', $tenant->getKey())->where('scope', RoleScope::Tenant->value);
                }),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($tenant, $actor): void {
            if (app(PermissionResolver::class)->isOwner($actor, $tenant)) {
                return;
            }

            if (Role::query()->find($this->string('role_id')->value())?->isOwner() === true) {
                $validator->errors()->add('role_id', __('Only an Owner can grant the Owner role.'));
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
