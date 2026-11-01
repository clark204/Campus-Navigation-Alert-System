<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in or newly registered user, for the mobile app. Never includes the password
 * or any access token.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Respond with the fields at the top level, not under "data".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: int, school_id: ?string, first_name: ?string, middle_name: ?string, last_name: ?string, email: string, role: string, college: array{id: string, name: string}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'role' => $this->role->value,
            'college' => $this->college === null ? null : [
                'id' => $this->college->id,
                'name' => $this->college->name,
            ],
        ];
    }
}
