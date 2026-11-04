<?php

namespace Modules\Core\Requests\Role;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\User\User;

final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');
        $user = $this->user();

        return $role instanceof Role
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $role);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:64'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(Permission::class)],
            'scope' => ['prohibited'],
            'key' => ['prohibited'],
            'is_system' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $role = $this->route('role');

        if (! $role instanceof Role) {
            throw new LogicException('The role route binding is required.');
        }

        $validator->addRules([
            'name' => [
                Rule::prohibitedIf($role->is_system),
                Rule::unique('roles', 'name')->ignore($role->getKey())->where(static function (Builder $query) use ($role): void {
                    $query->where('tenant_id', $role->getAttribute('tenant_id'))->where('scope', $role->scope->value);
                }),
            ],
            'permissions.*' => [
                Rule::in(array_map(static fn (Permission $permission): string => $permission->value, Permission::assignableTo($role->scope))),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($role): void {
            if (! $this->has('permissions')) {
                return;
            }

            $requested = $this->array('permissions');

            foreach ($role->defaultRole()?->lockedPermissions() ?? [] as $locked) {
                if (! in_array($locked->value, $requested, true)) {
                    $validator->errors()->add('permissions', __('The :role role must keep the :permission permission.', [
                        'role' => $role->name,
                        'permission' => $locked->value,
                    ]));
                }
            }
        });
    }
}
