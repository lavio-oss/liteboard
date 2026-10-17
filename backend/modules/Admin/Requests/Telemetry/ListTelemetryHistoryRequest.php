<?php

namespace Modules\Admin\Requests\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

final class ListTelemetryHistoryRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 31;

    // Laravel re-formats and compares, so "p" (Z for UTC) and "P" (+00:00) are both listed.
    public const string TIMESTAMP_FORMAT = 'date_format:Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:s.up,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.uP';

    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('view', $device);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', self::TIMESTAMP_FORMAT],
            'to' => ['required', 'string', self::TIMESTAMP_FORMAT, 'after:from'],
            'metrics' => ['sometimes', 'array', 'max:20'],
            'metrics.*' => ['required', 'string', 'distinct', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'max:256'],
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

                if ($this->from()->addDays(self::MAX_RANGE_DAYS)->lessThan($this->to())) {
                    $validator->errors()->add('to', 'The time range may not exceed '.self::MAX_RANGE_DAYS.' days.');
                }

                if ($this->has('cursor') && $this->cursor() === null) {
                    $validator->errors()->add('cursor', 'The cursor is invalid.');
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

    /** @return list<string> */
    public function metrics(): array
    {
        return array_values(array_map(strval(...), $this->array('metrics')));
    }

    /** @return array{occurred_at: CarbonImmutable, id: string}|null */
    public function cursor(): ?array
    {
        $parts = explode('|', (string) base64_decode($this->string('cursor')->value(), true), 2);

        if (count($parts) !== 2
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $parts[1]) !== 1) {
            return null;
        }

        $occurredAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $parts[0]);

        return $occurredAt === null ? null : ['occurred_at' => $occurredAt->utc(), 'id' => $parts[1]];
    }
}
