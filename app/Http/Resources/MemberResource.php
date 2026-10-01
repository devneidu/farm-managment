<?php

namespace App\Http\Resources;

use App\Models\FarmMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property FarmMembership $resource */
class MemberResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, role: 'owner'|'manager'|'farm_worker'|'finance'|'vet', role_label: string, status: 'active'|'removed',
     *     joined_at: string|null, is_current_user: bool,
     *     user: array{id: string, name: string|null, email: string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'joined_at' => $this->created_at?->toIso8601String(),
            'is_current_user' => $this->user_id === $request->user()?->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
        ];
    }
}
