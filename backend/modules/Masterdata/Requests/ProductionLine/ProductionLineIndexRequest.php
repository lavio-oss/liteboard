<?php

namespace Modules\Masterdata\Requests\ProductionLine;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

final class ProductionLineIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $factory = $this->route('factory');
        $user = $this->user();

        return $factory instanceof Factory
            && $user instanceof User
            && Gate::allows('viewAny', [ProductionLine::class, $factory]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
