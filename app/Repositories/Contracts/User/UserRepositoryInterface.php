<?php

declare(strict_types=1);

namespace App\Repositories\Contracts\User;

use App\Models\User\User;
use App\Repositories\Contracts\BaseRepositoryInterface;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Resolve a user by the credential they logged in with. The task allows
     * username or email, so both are accepted at one entry point.
     */
    public function findByUsernameOrEmail(string $identifier): ?User;

    /**
     * Record a successful sign-in. Separate from `update()` because it must not
     * touch the blamable columns — the login itself is the audit event.
     */
    public function touchLastLogin(User $user, ?string $ip): void;
}
