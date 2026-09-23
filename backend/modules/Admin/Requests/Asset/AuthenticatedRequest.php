<?php

namespace Modules\Admin\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

final class AuthenticatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
