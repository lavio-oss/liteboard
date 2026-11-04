<?php

namespace Modules\Core\Requests\Role;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [Role::class, $tenant]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:64'],
            'scope' => ['required', 'string', Rule::enum(RoleScope::class)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(Permission::class)],
            'key' => ['prohibited'],
            'is_system' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $tenant = $this->route('tenant');

        if (! $tenant instanceof Tenant) {
            throw new LogicException('The tenant route binding is required.');
        }

        $scope = $this->enum('scope', RoleScope::class);

        if ($scope === null) {
            return;
        }

        $validator->addRules([
            'name' => [
                Rule::unique('roles', 'name')->where(static function (Builder $query) use ($tenant, $scope): void {
                    $query->where('tenant_id', $tenant->getKey())->where('scope', $scope->value);
                }),
            ],
            'permissions.*' => [
                Rule::in(array_map(static fn (Permission $permission): string => $permission->value, Permission::assignableTo($scope))),
            ],
        ]);
    }
}
