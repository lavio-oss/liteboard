<?php

namespace Modules\Masterdata\Requests\ProductionLine;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

final class StoreProductionLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $factory = $this->route('factory');
        $user = $this->user();

        return $factory instanceof Factory
            && $user instanceof User
            && Gate::allows('create', [ProductionLine::class, $factory]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'factory_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $factory = $this->route('factory');

        if (! $factory instanceof Factory) {
            throw new LogicException('The factory route binding is required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('production_lines', 'code')->where(
                    static function (Builder $query) use ($factory): void {
                        $query->where('factory_id', $factory->getKey());
                    },
                ),
            ],
        ]);
    }
}
