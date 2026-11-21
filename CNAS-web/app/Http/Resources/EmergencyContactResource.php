<?php

namespace App\Http\Resources;

use App\Models\EmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An emergency number for the mobile app. phone is written for people; tel is the link to dial it ("tel:+63…").
 *
 * @mixin EmergencyContact
 */
class EmergencyContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'tel' => $this->telHref(),
            'note' => $this->note,
        ];
    }
}
