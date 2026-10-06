<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Device\Device;
use Modules\Core\Models\User\User;

final class RejectDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('reject', $device);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function rejectionReason(): string
    {
        /** @var array{reason: string} $validated */
        $validated = $this->validated();

        return $validated['reason'];
    }
}
