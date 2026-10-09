<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;

/** @extends Factory<DeviceProfileVersion> */
final class DeviceProfileVersionFactory extends Factory
{
    protected $model = DeviceProfileVersion::class;

    /**
     * @return array{
     *     version: int,
     *     schema_code: string,
     *     definition: array{
     *         strict_mode: bool,
     *         fields: array{
     *             temperature: array{type: string, unit: string, required: bool, min: int, max: int},
     *             vibration: array{type: string, unit: string, required: bool},
     *             rpm: array{type: string, unit: string, required: bool, min: int}
     *         }
     *     },
     *     compatibility_note: null
     * }
     */
    public function definition(): array
    {
        return [
            'version' => 1,
            'schema_code' => fake()->unique()->slug(2).'-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'temperature' => [
                        'type' => 'float',
                        'unit' => 'celsius',
                        'required' => true,
                        'min' => -20,
                        'max' => 150,
                    ],
                    'vibration' => [
                        'type' => 'float',
                        'unit' => 'mm/s RMS',
                        'required' => true,
                    ],
                    'rpm' => [
                        'type' => 'integer',
                        'unit' => 'rpm',
                        'required' => true,
                        'min' => 0,
                    ],
                ],
            ],
            'compatibility_note' => null,
        ];
    }

    public function forProfile(DeviceProfile $profile): static
    {
        $projectId = $profile->getAttribute('project_id');

        if (! is_string($projectId)) {
            throw new LogicException('The device profile must belong to a persisted Project.');
        }

        return $this->state([
            'project_id' => $projectId,
            'device_profile_id' => (string) $profile->getKey(),
        ]);
    }
}
