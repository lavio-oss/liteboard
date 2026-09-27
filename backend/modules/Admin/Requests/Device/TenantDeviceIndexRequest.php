<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class TenantDeviceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');
        $user = $this->user();

        return $tenant instanceof Tenant
            && $user instanceof User
            && Gate::forUser($user)->allows('viewTenantDevices', $tenant);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'connection_state' => ['sometimes', Rule::enum(DeviceConnectionState::class)],
            'project_id' => ['sometimes', 'bail', 'uuid'],
            'equipment_asset_id' => ['sometimes', 'bail', 'uuid'],
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
            'equipment_asset_id' => [
                Rule::exists('assets', 'id')->where(
                    static function (Builder $query) use ($tenant): void {
                        $query
                            ->whereIn('project_id', $tenant->projects()->select('projects.id'))
                            ->where('asset_type', AssetType::Equipment->value);
                    },
                ),
            ],
        ]);
    }
}
