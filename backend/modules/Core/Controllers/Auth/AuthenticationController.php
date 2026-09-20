<?php

namespace Modules\Core\Controllers\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Actions\Auth\AuthenticateUserAction;
use Modules\Core\Actions\Auth\ChangePasswordAction;
use Modules\Core\Actions\Auth\RevokeAllAuthenticationAction;
use Modules\Core\Actions\Auth\RevokeCurrentAuthenticationAction;
use Modules\Core\Actions\Auth\UpdateProfileAction;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Requests\Auth\ChangePasswordRequest;
use Modules\Core\Requests\Auth\LoginRequest;
use Modules\Core\Requests\Auth\UpdateProfileRequest;
use Modules\Core\Resources\Auth\UserResource;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthenticateUserAction $authenticate,
        AuthManager $auth,
    ): JsonResponse {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();
        $user = $authenticate->execute($credentials['email'], $credentials['password']);

        if ($user === null) {
            Log::notice('Authentication attempt failed.', [
                'email_hash' => hash('sha256', $credentials['email']),
                'ip' => $request->ip(),
                'outcome' => 'invalid_credentials',
                'request_id' => $request->header('X-Request-ID') ?? (string) Str::uuid(),
            ]);

            return response()->json([
                'message' => 'The provided credentials are invalid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A stateful session is required for browser authentication.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $auth->guard('web')->login($user);
        $request->session()->regenerate();
        $user->load('tenants');

        Log::info('User authenticated.', [
            'outcome' => 'success',
            'user_id' => $user->getKey(),
            'ip' => $request->ip(),
            'request_id' => $request->header('X-Request-ID') ?? (string) Str::uuid(),
        ]);

        return UserResource::make($user)->response();
    }

    public function me(AuthenticatedRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $user->load('tenants');

        return UserResource::make($user)->response();
    }

    public function updateProfile(
        UpdateProfileRequest $request,
        UpdateProfileAction $updateProfile,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name: string, email: string} $attributes */
        $attributes = $request->validated();
        $user = $updateProfile->execute($user, $attributes);

        return UserResource::make($user)->response();
    }

    public function updatePassword(
        ChangePasswordRequest $request,
        ChangePasswordAction $changePassword,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{password: string} $attributes */
        $attributes = $request->validated();
        $changePassword->execute($user, $attributes);

        return response()->noContent();
    }

    public function logout(
        AuthenticatedRequest $request,
        RevokeCurrentAuthenticationAction $revokeCurrent,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $revokeCurrent->execute($request, $user);

        return response()->noContent();
    }

    public function logoutAll(
        AuthenticatedRequest $request,
        RevokeAllAuthenticationAction $revokeAll,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $revokeAll->execute($request, $user);

        return response()->noContent();
    }
}
