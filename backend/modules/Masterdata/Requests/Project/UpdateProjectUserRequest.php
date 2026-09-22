<?php

namespace Modules\Masterdata\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;

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
            'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
