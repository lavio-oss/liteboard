<?php

namespace Modules\Core\Actions\Auth\Http;

use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Actions\Auth\AuthenticateUserAction;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\LoginRequest;
use Modules\Core\Resources\Auth\UserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class LoginAction
{
    public function __invoke(
        LoginRequest $request,
        AuthenticateUserAction $authenticate,
        AuthManager $auth,
        Responder $responder,
    ): Response {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();
        $user = $authenticate->execute($credentials['email'], $credentials['password']);

        if (! $user instanceof User) {
            Log::notice('Authentication attempt failed.', [
                'email_hash' => hash('sha256', $credentials['email']),
                'ip' => $request->ip(),
                'outcome' => 'invalid_credentials',
                'request_id' => $request->header('X-Request-ID') ?? (string) Str::uuid(),
            ]);

            return $responder->json([
                'message' => 'The provided credentials are invalid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $request->hasSession()) {
            return $responder->json([
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

        return $responder->resource(UserResource::make($user));
    }
}
