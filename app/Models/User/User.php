<?php

declare(strict_types=1);

namespace App\Models\User;

use App\Enums\UserStatus;
use Illuminate\Support\Carbon;
use Laravel\Passport\HasApiTokens;
use App\Models\Traits\BlamableTrait;
use Database\Factories\User\UserFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Passport\Contracts\OAuthenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property string $username
 * @property string $email
 * @property string|null $name
 * @property string $password
 * @property UserStatus $status
 * @property string|null $last_login_ip
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @use HasFactory<UserFactory>
 */
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use BlamableTrait, HasApiTokens, HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'username',
        'email',
        'name',
        'password',
        'status',
        'last_login_ip',
        'last_login_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
        ];
    }
}
