<?php

declare(strict_types=1);

namespace App\Http\Resources\User;

use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status->value,
            'last_login_at' => isoDate($this->last_login_at),
            'created_at' => isoDate($this->created_at),
            'updated_at' => isoDate($this->updated_at),
        ];
    }
}
