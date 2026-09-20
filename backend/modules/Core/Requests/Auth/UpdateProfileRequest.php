<?php

namespace Modules\Core\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\User\User;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->string('email')->lower()->value(),
        ]);
    }
}
