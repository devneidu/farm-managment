<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property User $resource */
class AccountResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{id: string, name: string|null, email: string, email_verified_at: string|null, has_password: bool, providers: string[], locale: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'has_password' => $this->password !== null,
            'providers' => $this->socialAccounts()->pluck('provider')->all(),
            'locale' => $this->locale,
        ];
    }
}
