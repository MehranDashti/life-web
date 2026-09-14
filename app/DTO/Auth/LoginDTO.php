<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use Illuminate\Database\Eloquent\Model;
use App\DTO\Contracts\ToArrayDTOInterface;
use Illuminate\Foundation\Http\FormRequest;
use App\DTO\Contracts\FromRequestDTOInterface;

/**
 * The credentials a sign-in attempt carries, kept out of the raw request so the
 * service never sees unvalidated input.
 */
final readonly class LoginDTO implements FromRequestDTOInterface, ToArrayDTOInterface
{
    public function __construct(
        public string $username = '',
        public string $password = '',
    ) {}

    public function fromRequest(FormRequest $request): static
    {
        return new self(
            username: (string) $request->input('username'),
            password: (string) $request->input('password'),
        );
    }

    /**
     * @return array{username: string, password: string}
     */
    public function toArray(?Model $model = null): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
