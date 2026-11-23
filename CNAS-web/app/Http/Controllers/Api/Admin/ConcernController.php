<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplyConcernRequest;
use App\Models\Concern;
use App\Models\ConcernReply;
use App\Services\ConcernInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin > Concerns for the mobile app (admin only), the same inbox as the web (ConcernInbox, docs/portal.md): the Open
 * and Resolved folders with unread, one conversation, reply (emailed, and shown in the sender's portal), resolve and
 * reopen.
 */
class ConcernController extends Controller
{
    public function __construct(private ConcernInbox $inbox) {}

    /**
     * ?status=resolved for the Resolved folder; ?page=. unread_count is this admin's new concerns (the tab badge).
     */
    public function index(Request $request): JsonResponse
    {
        $showResolved = $request->query('status') === 'resolved';
        $page = $this->inbox->folder($showResolved)->paginate(ConcernInbox::PER_PAGE);
        $unread = $this->inbox->unreadIds($request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Concern $concern) => [
                ...$this->summary($concern),
                'unread' => in_array($concern->id, $unread, true),
            ])->values(),
            'meta' => [
                'status' => $showResolved ? 'resolved' : 'open',
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'open_count' => Concern::open()->count(),
                'resolved_count' => Concern::resolved()->count(),
                'unread_count' => count($unread),
            ],
        ]);
    }

    /**
     * One conversation, with the replies in order. Opening it marks it read for this admin.
     */
    public function show(Request $request, Concern $concern): JsonResponse
    {
        $concern->load(['user.program.college', 'replies.author'])->loadCount('replies');
        $this->inbox->markOpened($request->user(), $concern);

        return response()->json(['data' => $this->conversation($concern)]);
    }

    public function reply(ReplyConcernRequest $request, Concern $concern): JsonResponse
    {
        $message = $this->inbox->reply($concern, $request->user(), $request->validated('message'), $request->boolean('resolve'));

        return response()->json(['message' => $message, 'data' => $this->conversation($concern->fresh(['user.program.college', 'replies.author'])->loadCount('replies'))]);
    }

    public function resolve(Concern $concern): JsonResponse
    {
        return response()->json(['message' => $this->inbox->resolve($concern), 'data' => $this->conversation($concern->load(['user.program.college', 'replies.author'])->loadCount('replies'))]);
    }

    public function reopen(Concern $concern): JsonResponse
    {
        return response()->json(['message' => $this->inbox->reopen($concern), 'data' => $this->conversation($concern->load(['user.program.college', 'replies.author'])->loadCount('replies'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Concern $concern): array
    {
        return [
            'id' => $concern->id,
            'topic' => $concern->topic->value,
            'topic_label' => $concern->topic->label(),
            'topic_short' => $concern->topic->shortLabel(),
            'message' => $concern->message,
            'status' => $concern->isResolved() ? 'resolved' : 'open',
            'sender' => $concern->user?->full_name,
            'email' => $concern->email,
            'reply_count' => (int) $concern->replies_count,
            'sent_at' => $concern->created_at?->toIso8601String(),
            'resolved_at' => $concern->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(Concern $concern): array
    {
        $sender = $concern->user;

        return [
            ...$this->summary($concern),
            'account' => $sender === null ? null : [
                'id' => $sender->id,
                'role' => $sender->role->label(),
                'school_id' => $sender->school_id,
                'college' => $sender->program?->college?->name,
            ],
            'replies' => $concern->replies->map(fn (ConcernReply $reply) => [
                'id' => $reply->id,
                'message' => $reply->message,
                'by' => $reply->author?->full_name,
                'sent_at' => $reply->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
