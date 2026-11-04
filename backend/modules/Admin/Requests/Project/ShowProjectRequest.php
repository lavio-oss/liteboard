<?php

namespace Modules\Admin\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class ShowProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('view', $project);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
