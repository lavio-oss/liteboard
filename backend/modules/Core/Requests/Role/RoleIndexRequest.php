<?php

namespace Modules\Core\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class RoleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::forUser($user)->allows('viewAny', [Role::class, $tenant]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::enum(RoleScope::class)],
        ];
    }
}
