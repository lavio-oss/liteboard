<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

final class DeleteDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('delete', $device);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
