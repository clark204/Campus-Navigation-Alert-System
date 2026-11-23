<?php

namespace App\Http\Resources;

use App\Models\Alert;
use App\Models\AlertLog;
use Illuminate\Http\Request;

/**
 * An alert for the people who send them (the mobile app's admin and trusted senders): the public AlertResource plus
 * who sent and ended it and, when loaded, the log (alert_logs: sent, edited, severity changed, resolved).
 *
 * @mixin Alert
 */
class ManagedAlertResource extends AlertResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'sent_by' => $this->creator?->full_name,
            'resolved_by' => $this->resolver?->full_name,
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn (AlertLog $log) => [
                'action' => $log->action->value,
                'action_label' => $log->action->label(),
                'detail' => $log->detail,
                'by' => $log->user?->full_name,
                'at' => $log->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
