<?php

namespace Modules\Admin\Requests\DeviceProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Core\Models\User\User;

final class DeleteDeviceProfileVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('deviceProfile');
        $version = $this->route('version');
        $user = $this->user();

        return $profile instanceof DeviceProfile
            && $version instanceof DeviceProfileVersion
            && $user instanceof User
            && Gate::forUser($user)->allows('delete', $profile);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
