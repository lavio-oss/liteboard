<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && Gate::allows('create', Tenant::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'max:64', Rule::unique('tenants', 'code')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'parent_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'factory_id' => ['prohibited'],
        ];
    }
}
