<?php

declare(strict_types=1);

namespace App\Services\User;

use RuntimeException;
use App\Enums\UserStatus;
use App\Models\User\User;
use App\DTO\Auth\LoginDTO;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;
use Illuminate\Support\Facades\Hash;
use App\Services\Contracts\BaseService;
use App\Repositories\Contracts\User\UserRepositoryInterface;

/**
 * Sign-in and token lifecycle.
 *
 * Tokens are Passport *personal access tokens*, not the password grant — the
 * password grant was removed in Passport 13.
 */
final class UserService extends BaseService
{
    private const string TOKEN_NAME = 'api';

    /**
     * Bound to the user repository; everything generic comes from BaseService.
     */
    public function __construct(UserRepositoryInterface $repository)
    {
        parent::__construct($repository);
    }

    /**
     * Verify credentials and issue a token.
     *
     * Failures are deliberately indistinguishable — unknown user, wrong password,
     * and inactive account all return the same message, so the endpoint cannot be
     * used to enumerate accounts.
     *
     * @return array{token_type: string, access_token: string, expires_in: int, user: User}
     */
    public function login(LoginDTO $dto, ?string $ip = null): array
    {
        $user = $this->userRepository()->findByUsernameOrEmail($dto->username);

        if (! $user instanceof User || ! Hash::check($dto->password, $user->password)) {
            throw new RuntimeException(
                trans('messages.invalid_username_or_password'),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($user->status !== UserStatus::Active) {
            throw new RuntimeException(
                trans('messages.invalid_username_or_password'),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $this->userRepository()->touchLastLogin($user, $ip);

        return $this->prepareToken($user);
    }

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, user: User}
     */
    public function prepareToken(User $user): array
    {
        $token = $user->createToken(self::TOKEN_NAME);

        return [
            'token_type' => (string) $token->tokenType,
            'access_token' => (string) $token->accessToken,
            'expires_in' => (int) $token->expiresIn,
            'user' => $user,
        ];
    }

    /**
     * Revoke only the token that made this request, so signing out on one device
     * does not sign the user out everywhere.
     *
     * currentAccessToken() is typed as ScopeAuthorizable, which declares no
     * revoke(). Under the passport guard the concrete object is an AccessToken built
     * from the request — not the Token model — and that is what carries revoke(). A
     * TransientToken, from session authentication, has nothing to revoke.
     */
    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof AccessToken) {
            $token->revoke();
        }
    }

    /**
     * The injected repository, narrowed from the base contract so the user-specific
     * queries are reachable without a cast at every call site.
     */
    private function userRepository(): UserRepositoryInterface
    {
        /** @var UserRepositoryInterface $repository */
        $repository = $this->repository;

        return $repository;
    }
}
