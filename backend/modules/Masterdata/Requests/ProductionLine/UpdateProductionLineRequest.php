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

final class UpdateProductionLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productionLine = $this->route('production_line');
        $user = $this->user();

        return $productionLine instanceof ProductionLine
            && $user instanceof User
            && Gate::allows('update', $productionLine);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'factory_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $factory = $this->route('factory');
        $productionLine = $this->route('production_line');

        if (! $factory instanceof Factory || ! $productionLine instanceof ProductionLine) {
            throw new LogicException('The factory and production line route bindings are required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('production_lines', 'code')
                    ->where(static function (Builder $query) use ($factory): void {
                        $query->where('factory_id', $factory->getKey());
                    })
                    ->ignore($productionLine->getKey()),
            ],
        ]);
    }
}
