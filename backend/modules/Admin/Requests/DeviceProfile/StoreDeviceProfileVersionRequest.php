<?php

namespace Modules\Admin\Requests\DeviceProfile;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Core\Models\User\User;

final class StoreDeviceProfileVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('deviceProfile');
        $user = $this->user();

        return $profile instanceof DeviceProfile
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $profile);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'schema_code' => ['required', 'string', 'min:1', 'max:64'],
            'definition' => ['required', 'array:strict_mode,fields'],
            'definition.strict_mode' => ['required', 'boolean'],
            'definition.fields' => ['required', 'array', 'min:1'],
            'definition.fields.*' => ['required', 'array:type,unit,required,min,max,allowed_values'],
            'definition.fields.*.type' => ['required', Rule::in(['integer', 'float', 'string', 'boolean'])],
            'definition.fields.*.unit' => ['required', 'string', 'min:1', 'max:64'],
            'definition.fields.*.required' => ['required', 'boolean'],
            'definition.fields.*.min' => ['sometimes', 'numeric'],
            'definition.fields.*.max' => ['sometimes', 'numeric'],
            'definition.fields.*.allowed_values' => ['sometimes', 'array', 'min:1', 'max:100'],
            'definition.fields.*.allowed_values.*' => ['required', 'string', 'min:1', 'max:64'],
            'compatibility_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'version' => ['prohibited'],
            'project_id' => ['prohibited'],
            'device_profile_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     schema_code: string,
     *     definition: array{
     *         strict_mode: bool,
     *         fields: array<string, array{type: string, unit: string, required: bool, min?: int|float, max?: int|float, allowed_values?: list<string>}>
     *     },
     *     compatibility_note?: string|null
     * }
     */
    public function versionInput(): array
    {
        /** @var array{
         *     schema_code: string,
         *     definition: array{
         *         strict_mode: bool,
         *         fields: array<string, array{type: string, unit: string, required: bool, min?: int|float, max?: int|float, allowed_values?: list<string>}>
         *     },
         *     compatibility_note?: string|null
         * } $input
         */
        $input = $this->validated();

        return $input;
    }

    public function withValidator(Validator $validator): void
    {
        $profile = $this->route('deviceProfile');

        if (! $profile instanceof DeviceProfile) {
            throw new LogicException('The device profile route binding is required.');
        }

        $validator->addRules([
            'schema_code' => [
                Rule::unique('device_profile_versions', 'schema_code')->where(
                    static function (Builder $query) use ($profile): void {
                        $query->where('project_id', $profile->getAttribute('project_id'));
                    },
                ),
            ],
        ]);

        $validator->after(static function (Validator $validator): void {
            $data = $validator->getData();
            $definition = $data['definition'] ?? null;

            if (! is_array($definition)) {
                return;
            }

            $fields = $definition['fields'] ?? null;

            if (! is_array($fields)) {
                return;
            }

            foreach ($fields as $fieldName => $field) {
                if (! is_string($fieldName) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $fieldName) !== 1) {
                    $validator->errors()->add('definition.fields', 'Field names must use letters, numbers, and underscores.');

                    continue;
                }

                if (! is_array($field)) {
                    continue;
                }

                $type = $field['type'] ?? null;

                if (($type === 'integer' || $type === 'float')
                    && array_key_exists('min', $field)
                    && array_key_exists('max', $field)
                    && is_numeric($field['min'])
                    && is_numeric($field['max'])
                    && (float) $field['min'] > (float) $field['max']) {
                    $validator->errors()->add(
                        "definition.fields.$fieldName.max",
                        'The maximum must be greater than or equal to the minimum.',
                    );
                }

                if (($type === 'string' || $type === 'boolean')
                    && (array_key_exists('min', $field) || array_key_exists('max', $field))) {
                    $validator->errors()->add(
                        "definition.fields.$fieldName",
                        'Minimum and maximum values are only supported for numeric fields.',
                    );
                }

                if (! array_key_exists('allowed_values', $field)) {
                    continue;
                }

                if ($type !== 'string') {
                    $validator->errors()->add(
                        "definition.fields.$fieldName.allowed_values",
                        'Allowed values are only supported for string fields.',
                    );

                    continue;
                }

                $allowedValues = $field['allowed_values'];

                if (! is_array($allowedValues)) {
                    continue;
                }

                if (! array_is_list($allowedValues)) {
                    $validator->errors()->add(
                        "definition.fields.$fieldName.allowed_values",
                        'Allowed values must be a list.',
                    );

                    continue;
                }

                $seenValues = [];

                foreach ($allowedValues as $allowedValue) {
                    if (! is_string($allowedValue)) {
                        continue;
                    }

                    if ($allowedValue === '' || $allowedValue !== trim($allowedValue)
                        || in_array($allowedValue, $seenValues, true)) {
                        $validator->errors()->add(
                            "definition.fields.$fieldName.allowed_values",
                            'Allowed values must be non-empty, trimmed, and unique.',
                        );

                        break;
                    }

                    $seenValues[] = $allowedValue;
                }
            }
        });
    }
}
