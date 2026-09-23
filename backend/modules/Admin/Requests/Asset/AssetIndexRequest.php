<?php

namespace Modules\Admin\Requests\Asset;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class AssetIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::allows('viewAny', [Asset::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'asset_type' => ['sometimes', Rule::enum(AssetType::class)],
            'parent_asset_id' => ['sometimes', 'integer', 'min:1'],
            'root_only' => [
                'sometimes',
                Rule::in([true, false, 1, 0, 'true', 'false', '1', '0']),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'parent_asset_id' => [
                Rule::exists('assets', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);

        $validator->after(function (Validator $validator): void {
            if ($this->filled('parent_asset_id') && $this->boolean('root_only')) {
                $validator->errors()->add(
                    'root_only',
                    'The root_only filter cannot be combined with parent_asset_id.',
                );
            }
        });
    }
}
