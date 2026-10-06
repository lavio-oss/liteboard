<?php

namespace Modules\Admin\Requests\DeviceProvisioning;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class ManageProjectQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('manageQuota', $project);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'max_devices_quota' => ['present', 'nullable', 'integer', 'min:0'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    public function quota(): ?int
    {
        $validated = $this->validated();
        $quota = $validated['max_devices_quota'] ?? null;

        if ($quota !== null && ! is_int($quota)) {
            throw new LogicException('The validated device quota must be an integer or null.');
        }

        return $quota;
    }
}
