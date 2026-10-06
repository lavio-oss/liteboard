<?php

namespace Modules\Admin\Services\DeviceProvisioning;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProvisioning\DeviceProvisioningAudit;
use Modules\Admin\Models\DeviceProvisioning\DeviceProvisioningReceipt;
use Modules\Admin\Models\DeviceProvisioning\ProjectProvisioningProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Services\Tenant\TenantContext;
use RuntimeException;

final class DeviceProvisioningService
{
    public function __construct(
        private readonly DevicePersistenceService $devicePersistenceService,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @return array{profile: ProjectProvisioningProfile, secret: string} */
    public function createProfile(Project $project, string $actorId): array
    {
        return DB::transaction(function () use ($project, $actorId): array {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedProject->provisioningProfile()->exists()) {
                throw new DeviceProvisioningException(409, 'PROVISIONING_PROFILE_ALREADY_EXISTS');
            }

            $secret = Str::random(64);
            $profile = $lockedProject->provisioningProfile()->create([
                'enabled' => false,
                'provision_key' => Str::lower(Str::random(32)),
                'provision_secret_hash' => Hash::make($secret),
                'secret_rotated_at' => CarbonImmutable::now(),
            ]);

            $lockedProject->provisioningAudits()->create([
                'profile_id' => $profile->getKey(),
                'actor_id' => $actorId,
                'action' => 'profile_created',
            ]);

            return ['profile' => $profile, 'secret' => $secret];
        });
    }

    public function updateProfile(Project $project, bool $enabled, string $actorId): ProjectProvisioningProfile
    {
        return DB::transaction(function () use ($project, $enabled, $actorId): ProjectProvisioningProfile {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $profile = $lockedProject->provisioningProfile()->lockForUpdate()->firstOrFail();
            $profile->forceFill(['enabled' => $enabled])->save();
            $lockedProject->provisioningAudits()->create([
                'profile_id' => $profile->getKey(),
                'actor_id' => $actorId,
                'action' => $enabled ? 'profile_enabled' : 'profile_disabled',
            ]);

            return $profile->refresh();
        });
    }

    /** @return array{profile: ProjectProvisioningProfile, secret: string} */
    public function rotateSecret(Project $project, string $actorId): array
    {
        return DB::transaction(function () use ($project, $actorId): array {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $profile = $lockedProject->provisioningProfile()->lockForUpdate()->firstOrFail();

            if ($lockedProject->devices()->where(
                'approval_status',
                DeviceApprovalStatus::Pending->value,
            )->exists()) {
                throw new DeviceProvisioningException(409, 'PENDING_DEVICE_APPROVALS_EXIST');
            }

            $secret = Str::random(64);
            $profile->forceFill([
                'provision_secret_hash' => Hash::make($secret),
                'secret_rotated_at' => CarbonImmutable::now(),
            ])->save();
            $lockedProject->provisioningAudits()->create([
                'profile_id' => $profile->getKey(),
                'actor_id' => $actorId,
                'action' => 'secret_rotated',
            ]);

            return ['profile' => $profile->refresh(), 'secret' => $secret];
        });
    }

    public function updateQuota(Project $project, ?int $quota, string $actorId): Project
    {
        return DB::transaction(function () use ($project, $quota, $actorId): Project {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $deviceCount = $lockedProject->devices()->count();

            if ($quota !== null && $quota < $deviceCount) {
                throw new DeviceProvisioningException(422, 'PROJECT_DEVICE_QUOTA_BELOW_DEVICE_COUNT');
            }

            $lockedProject->forceFill(['max_devices_quota' => $quota])->save();
            $lockedProject->provisioningAudits()->create([
                'actor_id' => $actorId,
                'action' => 'quota_updated',
            ]);

            return $lockedProject->refresh();
        });
    }

    /**
     * @param  array{name: string, code: string, firmware_version: string|null}  $input
     * @return array{device: Device, access_token: string, created: bool, request_id: string}
     */
    public function bootstrap(string $key, string $secret, string $requestId, array $input): array
    {
        $profile = ProjectProvisioningProfile::query()->where('provision_key', $key)->first();

        if ($profile === null || ! Hash::check($secret, $profile->provision_secret_hash)) {
            throw new DeviceProvisioningException(401, 'INVALID_PROVISIONING_CREDENTIALS');
        }

        $this->hitProfileLimit($profile);

        return DB::transaction(function () use ($profile, $secret, $requestId, $input): array {
            $project = Project::query()
                ->whereKey($profile->getAttribute('project_id'))
                ->lockForUpdate()
                ->firstOrFail();
            $lockedProfile = $project->provisioningProfile()->lockForUpdate()->firstOrFail();
            $tenant = $project->tenant()->firstOrFail();

            if (
                ! $lockedProfile->enabled
                || $project->status !== 'active'
                || $tenant->status !== 'active'
                || ! Hash::check($secret, $lockedProfile->provision_secret_hash)
            ) {
                throw new DeviceProvisioningException(403, 'PROVISIONING_UNAVAILABLE');
            }

            $this->tenantContext->set($tenant);
            $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = DeviceProvisioningReceipt::query()
                ->where('profile_id', $lockedProfile->getKey())
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if (! hash_equals($receipt->payload_fingerprint, $fingerprint)) {
                    throw new DeviceProvisioningException(409, 'IDEMPOTENCY_KEY_REUSED');
                }

                if (CarbonImmutable::parse($receipt->getRawOriginal('expires_at'))->isPast()) {
                    throw new DeviceProvisioningException(410, 'PROVISIONING_RECEIPT_EXPIRED');
                }

                $device = $receipt->device;
                $credential = $device?->credential()->lockForUpdate()->first();
                $deviceStatus = $device?->getAttribute('status');
                $approvalStatus = $device?->getAttribute('approval_status');
                $credentialStatus = $credential?->getAttribute('status');

                if ($approvalStatus === DeviceApprovalStatus::Rejected) {
                    throw new DeviceProvisioningException(409, 'PROVISIONING_CLAIM_REJECTED');
                }

                if (
                    $device === null
                    || ! $deviceStatus instanceof DeviceStatus
                    || $deviceStatus !== DeviceStatus::Active
                    || ! $approvalStatus instanceof DeviceApprovalStatus
                    || $credential === null
                    || $credentialStatus !== DeviceCredentialStatus::Active
                    || ! hash_equals($receipt->token_hash, $credential->token_hash)
                ) {
                    throw new DeviceProvisioningException(409, 'PROVISIONING_CREDENTIAL_UNAVAILABLE');
                }

                return [
                    'device' => $device->load('credential'),
                    'access_token' => $credential->encrypted_token,
                    'created' => false,
                    'request_id' => $requestId,
                ];
            }

            if ($project->devices()->where('code', $input['code'])->exists()) {
                throw new DeviceProvisioningException(409, 'DEVICE_ALREADY_PROVISIONED');
            }

            $quota = $project->getAttribute('max_devices_quota');

            if ($quota !== null && $project->devices()->count() >= (int) $quota) {
                throw new DeviceProvisioningException(409, 'PROJECT_DEVICE_QUOTA_EXCEEDED');
            }

            $device = $this->devicePersistenceService->create($project, [
                'name' => $input['name'],
                'code' => $input['code'],
            ]);
            $device->forceFill(['approval_status' => DeviceApprovalStatus::Pending->value])->save();
            $device->refresh()->load('credential');
            $credential = $device->credential;
            $now = CarbonImmutable::now();

            $lockedProfile->receipts()->create([
                'device_id' => $device->getKey(),
                'request_id' => $requestId,
                'device_code' => $input['code'],
                'firmware_version' => $input['firmware_version'],
                'payload_fingerprint' => $fingerprint,
                'token_hash' => $credential->token_hash,
                'expires_at' => $now->addHours(24),
            ]);

            return [
                'device' => $device,
                'access_token' => $credential->encrypted_token,
                'created' => true,
                'request_id' => $requestId,
            ];
        });
    }

    /** @return array{approval_status: DeviceApprovalStatus, rejection_reason: string|null} */
    public function readApprovalStatus(string $key, string $secret, string $requestId): array
    {
        $profile = ProjectProvisioningProfile::query()->where('provision_key', $key)->first();

        if ($profile === null || ! Hash::check($secret, $profile->provision_secret_hash)) {
            throw new DeviceProvisioningException(401, 'INVALID_PROVISIONING_CREDENTIALS');
        }

        $this->hitProfileLimit($profile);

        $project = $profile->project()->firstOrFail();
        $tenant = $project->tenant()->firstOrFail();
        $this->tenantContext->set($tenant);

        $receipt = $profile->receipts()
            ->with('device')
            ->where('request_id', $requestId)
            ->first();

        if ($receipt === null || $receipt->device === null) {
            throw new DeviceProvisioningException(404, 'PROVISIONING_REQUEST_NOT_FOUND');
        }

        $device = $receipt->device;
        $approvalStatus = $device->getAttribute('approval_status');

        if (! $approvalStatus instanceof DeviceApprovalStatus) {
            throw new RuntimeException('The device approval_status attribute is invalid.');
        }

        $rejectionReason = null;

        if ($approvalStatus === DeviceApprovalStatus::Rejected) {
            $reason = DeviceProvisioningAudit::query()
                ->where('project_id', $project->getKey())
                ->where('device_id', $device->getKey())
                ->where('action', 'device_rejected')
                ->value('reason');

            if (! is_string($reason)) {
                throw new RuntimeException('A rejected device must have an audit reason.');
            }

            $rejectionReason = $reason;
        }

        return [
            'approval_status' => $approvalStatus,
            'rejection_reason' => $rejectionReason,
        ];
    }

    private function hitProfileLimit(ProjectProvisioningProfile $profile): void
    {
        $profileLimitKey = 'device-provisioning-profile:'.$profile->getKey();
        $profileLimit = (int) config('device_provisioning.rate_limits.profile_per_minute', 60);

        if (RateLimiter::tooManyAttempts($profileLimitKey, $profileLimit)) {
            throw new DeviceProvisioningException(
                429,
                'PROVISIONING_RATE_LIMITED',
                RateLimiter::availableIn($profileLimitKey),
            );
        }

        RateLimiter::hit($profileLimitKey, 60);
    }
}
