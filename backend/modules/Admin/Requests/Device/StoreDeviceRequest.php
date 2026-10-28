<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [Device::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'equipment_asset_id' => ['sometimes', 'nullable', 'bail', 'uuid'],
            'device_profile_version_id' => ['sometimes', 'nullable', 'bail', 'uuid'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'is_gateway' => ['sometimes', 'boolean'],
            'gateway_device_id' => ['missing'],
            'access_token' => ['missing'],
            'approval_status' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     attributes: array{
     *         name: string,
     *         code: string,
     *         description?: string|null,
     *         equipment_asset_id?: string|null,
     *         device_profile_version_id?: string|null,
     *         status?: string,
     *         is_gateway?: bool
     *     }
     * }
     */
    public function deviceInput(): array
    {
        /** @var array{
         *     name: string,
         *     code: string,
         *     description?: string|null,
         *     equipment_asset_id?: string|null,
         *     device_profile_version_id?: string|null,
         *     status?: string,
         *     is_gateway?: bool
         * } $validated
         */
        $validated = $this->validated();

        /** @var array{
         *     name: string,
         *     code: string,
         *     description?: string|null,
         *     equipment_asset_id?: string|null,
         *     device_profile_version_id?: string|null,
         *     status?: string,
         *     is_gateway?: bool
         * } $attributes
         */
        $attributes = $validated;

        return ['attributes' => $attributes];
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('devices', 'code')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
            'equipment_asset_id' => [
                Rule::exists('assets', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query
                            ->where('project_id', $project->getKey())
                            ->where('asset_type', AssetType::Equipment->value);
                    },
                ),
            ],
            'device_profile_version_id' => [
                Rule::exists('device_profile_versions', 'id')->where(
                    static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    },
                ),
            ],
        ]);
    }
}
