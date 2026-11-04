<?php

namespace Modules\Admin\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class ListUnresolvedMessagesRequest extends FormRequest
{
    // Central data carrying unverified topic IDs, so it needs the system diagnostics permission.
    public function authorize(PermissionResolver $permissions): bool
    {
        $user = $this->user();

        return $user instanceof User && $permissions->allowsInAnyTenant($user, Permission::SystemDiagnostics);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
