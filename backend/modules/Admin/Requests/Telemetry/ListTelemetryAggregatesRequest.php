<?php

namespace Modules\Admin\Requests\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Admin\Enums\Telemetry\TelemetryInterval;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

final class ListTelemetryAggregatesRequest extends FormRequest
{
    public const int MAX_BUCKETS = 1000;

    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('view', $device);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', ListTelemetryHistoryRequest::TIMESTAMP_FORMAT],
            'to' => ['required', 'string', ListTelemetryHistoryRequest::TIMESTAMP_FORMAT, 'after:from'],
            'interval' => ['required', Rule::enum(TelemetryInterval::class)],
            'metrics' => ['sometimes', 'array', 'max:20'],
            'metrics.*' => ['required', 'string', 'distinct', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $buckets = $this->from()->diffInSeconds($this->to()) / $this->bucketInterval()->seconds();

                if ($buckets > self::MAX_BUCKETS) {
                    $validator->errors()->add(
                        'interval',
                        'The range produces more than '.self::MAX_BUCKETS.' buckets; choose a larger interval.',
                    );
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('from')->value())->utc();
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('to')->value())->utc();
    }

    public function bucketInterval(): TelemetryInterval
    {
        return TelemetryInterval::from($this->string('interval')->value());
    }

    /** @return list<string> */
    public function metrics(): array
    {
        return array_values(array_map(strval(...), $this->array('metrics')));
    }
}
