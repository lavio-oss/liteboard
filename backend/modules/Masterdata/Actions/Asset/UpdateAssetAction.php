<?php

namespace Modules\Masterdata\Actions\Asset;

use Illuminate\Validation\ValidationException;
use Modules\Masterdata\Models\Asset\Asset;

final class UpdateAssetAction
{
    /**
     * @param  array{
     *     parent_asset_id?: int|null,
     *     asset_type?: string,
     *     name?: string,
     *     code?: string,
     *     location?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function execute(Asset $asset, array $attributes): Asset
    {
        if (array_key_exists('parent_asset_id', $attributes) && $attributes['parent_asset_id'] !== null) {
            $this->ensureParentDoesNotCreateCycle($asset, $attributes['parent_asset_id']);
        }

        $asset->fill($attributes);
        $asset->save();

        return $asset->refresh();
    }

    private function ensureParentDoesNotCreateCycle(Asset $asset, int $parentAssetId): void
    {
        $ancestor = Asset::query()->findOrFail($parentAssetId);
        $visited = [];

        while (true) {
            $ancestorId = $ancestor->getKey();

            if ($ancestorId === $asset->getKey()) {
                throw ValidationException::withMessages([
                    'parent_asset_id' => 'The selected parent would create an asset cycle.',
                ]);
            }

            if (isset($visited[$ancestorId])) {
                throw ValidationException::withMessages([
                    'parent_asset_id' => 'The asset hierarchy already contains a cycle.',
                ]);
            }

            $visited[$ancestorId] = true;

            if ($ancestor->parent_asset_id === null) {
                return;
            }

            $ancestor = Asset::query()->findOrFail($ancestor->parent_asset_id);
        }
    }
}
