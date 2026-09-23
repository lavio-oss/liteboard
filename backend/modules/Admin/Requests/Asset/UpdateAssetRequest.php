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

final class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');
        $user = $this->user();

        return $asset instanceof Asset
            && $user instanceof User
            && Gate::allows('update', $asset);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'asset_type' => ['sometimes', Rule::enum(AssetType::class)],
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
        $asset = $this->route('asset');

        if (! $project instanceof Project || ! $asset instanceof Asset) {
            throw new LogicException('The project and asset route bindings are required.');
        }

        $assetType = $asset->asset_type;

        if ($this->has('asset_type')) {
            $requestedAssetType = $this->enum('asset_type', AssetType::class);

            if ($requestedAssetType instanceof AssetType) {
                $assetType = $requestedAssetType;
            }
        }

        $validator->addRules([
            'code' => [
                Rule::unique('assets', 'code')
                    ->where(static function (Builder $query) use ($project, $assetType): void {
                        $query
                            ->where('project_id', $project->getKey())
                            ->where('asset_type', $assetType->value);
                    })
                    ->ignore($asset->getKey()),
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
