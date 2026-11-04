<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class DeleteTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $member = $this->route('user');
        $actor = $this->user();

        return $tenant instanceof Tenant
            && $member instanceof User
            && $actor instanceof User
            && Gate::forUser($actor)->allows('manageMember', [$tenant, $member]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
