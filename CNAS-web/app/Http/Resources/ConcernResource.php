<?php

namespace App\Http\Resources;

use App\Models\Concern;
use App\Models\ConcernReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's concerns for the mobile app's "My messages", like the portal's Help & contact page: the topic,
 * the message, Open or Resolved, and the administrator's replies in order. Needs `replies` loaded.
 *
 * @mixin Concern
 */
class ConcernResource extends JsonResource
{
    /**
     * @return array{id: int, topic: string, topic_label: string, message: string, status: string, sent_at: ?string, resolved_at: ?string, replies: list<array{id: int, message: string, sent_at: ?string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic->value,
            'topic_label' => $this->topic->label(),
            'message' => $this->message,
            'status' => $this->isResolved() ? 'resolved' : 'open',
            'sent_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn (ConcernReply $reply) => [
                'id' => $reply->id,
                'message' => $reply->message,
                'sent_at' => $reply->created_at?->toIso8601String(),
            ])->all(), []),
        ];
    }
}
