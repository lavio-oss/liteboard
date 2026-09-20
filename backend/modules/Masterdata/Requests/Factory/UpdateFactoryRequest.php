<?php

namespace Modules\Masterdata\Requests\Factory;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;
use Modules\Masterdata\Models\Project\Project;

final class UpdateFactoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $factory = $this->route('factory');
        $user = $this->user();

        return $factory instanceof FactoryModel
            && $user instanceof User
            && Gate::allows('update', $factory);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'factory_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');
        $factory = $this->route('factory');

        if (! $project instanceof Project || ! $factory instanceof FactoryModel) {
            throw new LogicException('The project and factory route bindings are required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('factories', 'code')
                    ->where(static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    })
                    ->ignore($factory->getKey()),
            ],
        ]);
    }
}
