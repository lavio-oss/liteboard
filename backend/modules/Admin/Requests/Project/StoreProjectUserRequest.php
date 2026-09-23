<?php

namespace Modules\Admin\Requests\Project;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class StoreProjectUserRequest extends FormRequest
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
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $tenant = $this->route('tenant');
        $project = $this->route('project');

        if (! $tenant instanceof Tenant || ! $project instanceof Project) {
            throw new LogicException('The tenant and project route bindings are required.');
        }

        $validator->addRules([
            'user_id' => [
                Rule::exists('tenant_user', 'user_id')->where(
                    static function (Builder $query) use ($tenant): void {
                        $query->where('tenant_id', $tenant->getKey());
                    },
                ),
                Rule::unique('project_user', 'user_id')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);
    }
}
