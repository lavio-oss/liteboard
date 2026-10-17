<?php

namespace Modules\Admin\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

final class ListMessageOutcomesRequest extends FormRequest
{
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
