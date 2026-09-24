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

    /**
     * @return array{
     *     attributes: array{
     *         asset_type: string,
     *         name: string,
     *         code: string,
     *         location?: string|null,
     *         status?: string
     *     },
     *     parent_asset_id: int|null
     * }
     */
    public function assetInput(): array
    {
        /** @var array{
         *     asset_type: string,
         *     name: string,
         *     code: string,
         *     parent_asset_id?: int|null,
         *     location?: string|null,
         *     status?: string
         * } $validated
         */
        $validated = $this->validated();
        $parentAssetId = $validated['parent_asset_id'] ?? null;
        unset($validated['parent_asset_id']);

        /** @var array{
         *     asset_type: string,
         *     name: string,
         *     code: string,
         *     location?: string|null,
         *     status?: string
         * } $attributes
         */
        $attributes = $validated;

        return [
            'attributes' => $attributes,
            'parent_asset_id' => $parentAssetId,
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
