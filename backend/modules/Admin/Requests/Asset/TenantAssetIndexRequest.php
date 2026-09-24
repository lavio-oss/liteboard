<?php

namespace Modules\Admin\Requests\Asset;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class TenantAssetIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::forUser($user)->allows('viewTenantAssets', $tenant);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'asset_type' => ['sometimes', Rule::enum(AssetType::class)],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $tenant = $this->route('tenant');

        if (! $tenant instanceof Tenant) {
            throw new LogicException('The tenant route binding is required.');
        }

        $validator->addRules([
            'project_id' => [
                Rule::exists('projects', 'id')->where(
                    static function (Builder $query) use ($tenant): void {
                        $query->where('tenant_id', $tenant->getKey());
                    },
                ),
            ],
        ]);
    }
}
