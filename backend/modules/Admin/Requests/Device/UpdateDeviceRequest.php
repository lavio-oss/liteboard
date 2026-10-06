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

final class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $device);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'equipment_asset_id' => ['sometimes', 'nullable', 'bail', 'uuid'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'approval_status' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     name?: string,
     *     code?: string,
     *     description?: string|null,
     *     equipment_asset_id?: string|null,
     *     status?: string
     * }
     */
    public function deviceAttributes(): array
    {
        /** @var array{
         *     name?: string,
         *     code?: string,
         *     description?: string|null,
         *     equipment_asset_id?: string|null,
         *     status?: string
         * } $validated
         */
        $validated = $this->validated();

        return $validated;
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');
        $device = $this->route('device');

        if (! $project instanceof Project || ! $device instanceof Device) {
            throw new LogicException('The project and device route bindings are required.');
        }

        $validator->addRules([
            'code' => [
                Rule::unique('devices', 'code')
                    ->where(static function (Builder $query) use ($project): void {
                        $query->where('project_id', $project->getKey());
                    })
                    ->ignore($device->getKey()),
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
        ]);
    }
}
