<?php

namespace Modules\Admin\Requests\Overview;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

final class ShowProjectOverviewRequest extends FormRequest
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

    /** Pipeline and webhook health follow the webhook endpoint visibility (Admin/Engineer). */
    public function includesPipeline(Project $project): bool
    {
        $user = $this->user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', [WebhookEndpoint::class, $project]);
    }
}
