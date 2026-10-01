<?php

namespace App\Http\Resources;

use App\Models\FarmInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The invitation token is never returned; it only exists in the email.
 *
 * @property FarmInvitation $resource
 */
class InvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, email: string, role: 'manager'|'farm_worker'|'finance'|'vet', role_label: string,
     *     status: 'pending'|'expired'|'accepted'|'revoked', expires_at: string, last_sent_at: string|null,
     *     created_at: string|null, invited_by: array{id: string, name: string|null, email: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $inviter = $this->invitedBy;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invited_by' => $inviter ? ['id' => $inviter->id, 'name' => $inviter->name, 'email' => $inviter->email] : null,
        ];
    }
}
