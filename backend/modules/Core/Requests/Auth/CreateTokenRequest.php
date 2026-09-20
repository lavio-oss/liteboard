<?php

namespace Modules\Core\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [
                'required',
                'string',
                Rule::in(['control-plane:read', 'control-plane:write']),
            ],
            'expires_in_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.config('sanctum.max_token_expiration_days'),
            ],
        ];
    }
}
