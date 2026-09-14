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
    /**
     * Both fields default to an empty string so the container can resolve an empty
     * instance for `fromRequest()` to build from.
     */
    public function __construct(
        public string $username = '',
        public string $password = '',
    ) {}

    /**
     * Build the credentials from an already-validated request.
     *
     * `username` accepts either a username or an email; deciding which is the
     * service's job, not the DTO's.
     */
    public function fromRequest(FormRequest $request): static
    {
        return new self(
            username: (string) $request->input('username'),
            password: (string) $request->input('password'),
        );
    }

    /**
     * The credentials as a plain array, for the guard's attempt() call.
     *
     * Nothing persists a LoginDTO, so the model argument required by the contract
     * is unused here.
     *
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
