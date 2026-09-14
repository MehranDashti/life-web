<?php

declare(strict_types=1);

namespace App\Repositories\User;

use App\Models\User\User;
use Illuminate\Support\Carbon;
use App\Repositories\Contracts\BaseRepository;
use App\Repositories\Contracts\User\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByUsernameOrEmail(string $identifier): ?User
    {
        $user = $this->model->newQuery()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        return $user instanceof User ? $user : null;
    }

    public function touchLastLogin(User $user, ?string $ip): void
    {
        $user->forceFill([
            'last_login_ip' => $ip,
            'last_login_at' => Carbon::now(),
        ])->saveQuietly();
    }
}
