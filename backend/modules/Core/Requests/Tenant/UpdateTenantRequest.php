<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::allows('update', $tenant);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('tenants', 'code')->ignore($tenant instanceof Tenant ? $tenant->getKey() : null),
            ],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'parent_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }
}
