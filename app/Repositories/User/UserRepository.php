<?php

declare(strict_types=1);

namespace App\Repositories\User;

use App\Models\User\User;
use Illuminate\Support\Carbon;
use App\Repositories\Contracts\BaseRepository;
use App\Repositories\Contracts\User\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /**
     * Bound to the User model; everything generic comes from BaseRepository.
     */
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    /**
     * Resolve a user by whichever credential they signed in with.
     *
     * The task allows either a username or an email, so both are accepted at one
     * entry point rather than making the caller guess which was supplied.
     */
    public function findByUsernameOrEmail(string $identifier): ?User
    {
        $user = $this->model->newQuery()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * Record a successful sign-in.
     *
     * Written quietly and separately from update(): the sign-in is itself the audit
     * event, so it must not restamp `updated_by` as though someone edited the
     * account.
     */
    public function touchLastLogin(User $user, ?string $ip): void
    {
        $user->forceFill([
            'last_login_ip' => $ip,
            'last_login_at' => Carbon::now(),
        ])->saveQuietly();
    }
}
