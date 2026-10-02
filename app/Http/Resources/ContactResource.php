<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'kind' => $this->kind,
            /** @var list<string> supplier and/or customer */
            'roles' => $this->roles(),
            'phone' => $this->phone, 'email' => $this->email, 'address' => $this->address, 'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
