<?php

namespace Modules\Admin\Requests\Project;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * Creating an account also makes the person a tenant member, so it needs tenant user management too.
 */
final class StoreProjectUserAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $project = $this->route('project');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('manageUsers', $project)
            && Gate::forUser($user)->allows('manageUsers', $tenant);
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
            'project_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'role_id' => [
                Rule::exists('roles', 'id')->where(static function (Builder $query) use ($project): void {
                    $query->where('tenant_id', $project->getAttribute('tenant_id'))
                        ->where('scope', RoleScope::Project->value);
                }),
            ],
        ]);
    }
}
