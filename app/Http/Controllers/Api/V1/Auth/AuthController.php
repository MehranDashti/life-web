<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use Throwable;
use App\Models\User\User;
use App\DTO\Auth\LoginDTO;
use Illuminate\Http\JsonResponse;
use App\Services\User\UserService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\User\UserResource;

class AuthController extends Controller
{
    /**
     * One service per controller: the controller's only job is to translate between
     * HTTP and the domain.
     */
    public function __construct(private readonly UserService $service) {}

    /**
     * Exchange credentials for a bearer token.
     *
     * No DB transaction here: the only write is a last-login stamp, and it must not
     * be rolled back by an unrelated failure further down.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $dto = app(LoginDTO::class)->fromRequest($request);
            $result = $this->service->login($dto, getRealIp());

            return $this->successResponse(trans('messages.login_successful'), [
                'token_type' => $result['token_type'],
                'access_token' => $result['access_token'],
                'expires_in' => $result['expires_in'],
                'user' => new UserResource($result['user']),
            ]);
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * The authenticated user's own profile.
     */
    public function me(): JsonResponse
    {
        return $this->successResponse(
            trans('messages.action_successfully_done'),
            new UserResource($this->currentUser()),
        );
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(): JsonResponse
    {
        try {
            $this->service->revokeCurrentToken($this->currentUser());

            return $this->successResponse(trans('messages.logout_successful'));
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * The caller, resolved through the api guard.
     *
     * Always the api guard, never the bare Auth facade — the default guard is still
     * `web`, which would silently return null on every request.
     */
    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        return $user;
    }
}
