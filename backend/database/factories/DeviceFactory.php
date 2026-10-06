<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Services\Device\DeviceCredentialService;

/** @extends Factory<Device> */
final class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array{
     *     equipment_asset_id: string|null,
     *     name: string,
     *     code: string,
     *     description: string|null,
     *     status: string,
     *     approval_status: string,
     *     connection_state: string,
     *     last_seen_at: null
     * }
     */
    public function definition(): array
    {
        return [
            'equipment_asset_id' => null,
            'name' => fake()->words(3, true),
            'code' => fake()->unique()->slug(2),
            'description' => fake()->optional()->sentence(),
            'status' => DeviceStatus::Active->value,
            'approval_status' => DeviceApprovalStatus::Approved->value,
            'connection_state' => DeviceConnectionState::Offline->value,
            'last_seen_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(static function (Device $device): void {
            if (! $device->credential()->exists()) {
                app(DeviceCredentialService::class)->issueForDevice($device);
            }
        });
    }

    public function forEquipment(Asset $equipment): static
    {
        return $this->state([
            'equipment_asset_id' => (string) $equipment->getKey(),
        ]);
    }
}
