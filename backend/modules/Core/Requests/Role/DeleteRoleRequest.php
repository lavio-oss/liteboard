<?php

namespace Modules\Core\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\User\User;

final class DeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');
        $user = $this->user();

        return $role instanceof Role
            && $user instanceof User
            && Gate::forUser($user)->allows('delete', $role);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $role = $this->route('role');

        if (! $role instanceof Role) {
            throw new LogicException('The role route binding is required.');
        }

        $validator->after(static function (Validator $validator) use ($role): void {
            $inUse = DB::table('tenant_user')->where('role_id', $role->getKey())->exists()
                || DB::table('project_user')->where('role_id', $role->getKey())->exists();

            if ($inUse) {
                $validator->errors()->add('role', __('Reassign everyone holding this role before deleting it.'));
            }
        });
    }
}
