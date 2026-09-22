<?php

namespace Modules\Core\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\User\User;

final class DeleteTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor instanceof User
            && $target instanceof User
            && $actor->isPlatformAdmin()
            && $actor->isNot($target);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
