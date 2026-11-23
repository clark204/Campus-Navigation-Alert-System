<?php

namespace App\Services;

use App\Mail\ConcernReplyMail;
use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Admin > Concerns (docs/portal.md), shared by the web inbox (Admin\ConcernController) and the mobile app
 * (Api\Admin\ConcernController): the folders, unread, replying, resolving and reopening.
 *
 * Unread = this admin still has an unread "New message" bell notification (ConcernReceived) for it. Opening a concern
 * marks it read for this admin; replying or resolving marks it read for every admin.
 */
class ConcernInbox
{
    public const PER_PAGE = 20;

    public const REPLY_MAX = 5000;

    /**
     * A folder: Open (newest first) or Resolved (most recently resolved first), with the sender and the reply count.
     *
     * @return Builder<Concern>
     */
    public function folder(bool $resolved): Builder
    {
        return Concern::query()
            ->when($resolved, fn (Builder $query) => $query->resolved()->latest('resolved_at'), fn (Builder $query) => $query->open()->latest())
            ->latest('id')
            ->with('user')
            ->withCount('replies');
    }

    /**
     * Concerns this admin hasn't opened yet (an unread "New message" notification).
     *
     * @return list<int>
     */
    public function unreadIds(User $admin): array
    {
        return $admin->unreadNotifications()
            ->where('type', ConcernReceived::class)
            ->get()
            ->map(fn (DatabaseNotification $notification) => (int) ($notification->data['concern_id'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * The admin opened the conversation: its bell notification is read for them.
     */
    public function markOpened(User $admin, Concern $concern): void
    {
        $admin->unreadNotifications()
            ->where('type', ConcernReceived::class)
            ->where('data->concern_id', $concern->id)
            ->update(['read_at' => now()]);
    }

    /**
     * Save the reply, resolve the concern when asked, email it to the concern's email and, when the sender has an
     * account, put it in their bell and Help & contact. Answers with the message to show.
     */
    public function reply(Concern $concern, User $admin, string $message, bool $resolve): string
    {
        $reply = $concern->replies()->make(['message' => trim($message)]);
        $reply->author()->associate($admin);
        $reply->save();

        if ($resolve) {
            $concern->forceFill(['resolved_at' => now()])->save();
        }

        Mail::to($concern->email)->queue(new ConcernReplyMail($reply));
        $concern->user?->notify(new ConcernReplied($reply));
        $this->markHandled($concern);

        return $concern->user
            ? "Reply sent to {$concern->email}. It also shows in their portal."
            : "Reply sent to {$concern->email}.";
    }

    public function resolve(Concern $concern): string
    {
        $concern->forceFill(['resolved_at' => now()])->save();
        $this->markHandled($concern);

        return 'Marked as resolved.';
    }

    public function reopen(Concern $concern): string
    {
        $concern->forceFill(['resolved_at' => null])->save();

        return 'Moved back to open.';
    }

    /**
     * Once one admin has dealt with a concern, its "new message" notification is read for every admin.
     */
    private function markHandled(Concern $concern): void
    {
        DatabaseNotification::query()
            ->where('type', ConcernReceived::class)
            ->where('data->concern_id', $concern->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
