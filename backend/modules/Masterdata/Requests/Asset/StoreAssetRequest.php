<?php

namespace Modules\Masterdata\Requests\Asset;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\Project\Project;

final class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::allows('create', [Asset::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'asset_type' => ['required', Rule::enum(AssetType::class)],
            'parent_asset_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $assetType = $this->string('asset_type')->value();

        $validator->addRules([
            'code' => [
                Rule::unique('assets', 'code')->where(
                    static function (Builder $query) use ($project, $assetType): void {
                        $query
                            ->where('project_id', $project->getKey())
                            ->where('asset_type', $assetType);
                    },
                ),
            ],
            'parent_asset_id' => [
                Rule::exists('assets', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);
    }
}
