<?php

namespace Modules\Admin\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class ViewTenantDevicePresenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::forUser($user)->allows('viewTenantDevices', $tenant);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
