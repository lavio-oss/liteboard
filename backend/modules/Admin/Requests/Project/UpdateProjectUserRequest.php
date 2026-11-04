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
use Modules\Core\Models\User\User;

final class UpdateProjectUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('manageUsers', $project);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
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
