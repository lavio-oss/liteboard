<?php

namespace Modules\Core\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\User\User;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
