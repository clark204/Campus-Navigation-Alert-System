<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The current alert for the web pages (banner, takeover, emergency map) and the mobile app. No admin names.
 * Location: latitude/longitude/danger_radius_m are null for whole-campus alerts; building_codes are codes from
 * the buildings table. has_location: a pin or affected buildings (a warning shows "Show route to safety" for it).
 * severity: advisory, warning or emergency (what people get: see App\Enums\AlertSeverity). banner_expires_at: for a
 * resolved alert, when its green banner stops showing.
 *
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'title' => $this->title,
            'message' => $this->message,
            'instructions' => $this->instructions,
            'is_drill' => $this->is_drill,
            'status' => $this->status->value,
            'location' => $this->locationLabel(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'danger_radius_m' => $this->danger_radius_m,
            'building_codes' => $this->building_codes ?? [],
            'has_location' => $this->hasLocation(),
            'sent_at' => $this->sent_at->toIso8601String(),
            'resolution' => $this->resolution?->value,
            'resolution_label' => $this->resolution?->label(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_message' => $this->resolved_message,
            'banner_expires_at' => $this->bannerExpiresAt()?->toIso8601String(),
        ];
    }
}
