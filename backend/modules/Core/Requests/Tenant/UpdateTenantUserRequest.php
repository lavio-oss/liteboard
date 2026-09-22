<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $actor = $this->user();

        return $tenant instanceof Tenant
            && $actor instanceof User
            && Gate::forUser($actor)->allows('manageUsers', $tenant);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user instanceof User ? $user->getKey() : null),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
            'role' => ['prohibited'],
            'system_role' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => $this->string('email')->lower()->value(),
            ]);
        }
    }
}
