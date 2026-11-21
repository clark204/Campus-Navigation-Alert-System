<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ConcernReplyMail;
use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Admin > Concerns, an email-style inbox (docs/portal.md): the list (index, folders Open and Resolved via
 * ?status=resolved) and one conversation (show) with the reply box. The admin replies there (the reply is emailed to
 * the sender and, if they have an account, shows in their portal) and marks each one resolved.
 *
 * Unread = this admin still has an unread "New message" bell notification (ConcernReceived) for it. Opening the
 * conversation marks it read for this admin; replying or resolving marks it read for every admin.
 */
class ConcernController extends Controller
{
    public const PER_PAGE = 20;

    public const REPLY_MAX = 5000;

    public function index(Request $request): View
    {
        $showResolved = $request->query('status') === 'resolved';

        $concerns = Concern::query()
            ->when($showResolved, fn ($query) => $query->resolved()->latest('resolved_at'), fn ($query) => $query->open()->latest())
            ->latest('id')
            ->with('user')
            ->withCount('replies')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.concerns-page', [
            'concerns' => $concerns,
            'showResolved' => $showResolved,
            'openCount' => Concern::open()->count(),
            'resolvedCount' => Concern::resolved()->count(),
            'unreadIds' => $this->unreadIds($request->user()),
        ]);
    }

    public function show(Request $request, Concern $concern): View
    {
        $concern->load(['user', 'replies.author']);

        // Opened: its bell notification is read for this admin.
        $request->user()->unreadNotifications()
            ->where('type', ConcernReceived::class)
            ->where('data->concern_id', $concern->id)
            ->update(['read_at' => now()]);

        return view('admin.concern-page', [
            'concern' => $concern,
            'backUrl' => route('admin.concerns.index', $concern->isResolved() ? ['status' => 'resolved'] : []),
        ]);
    }

    /**
     * Reply to a concern: saved, emailed to the concern's email, and shown in the sender's portal (notification bell
     * and Help & contact) when they have an account. "Mark as resolved" is ticked by default. Errors use the bag
     * reply{id}.
     */
    public function reply(Request $request, Concern $concern): RedirectResponse
    {
        $validated = $request->validateWithBag('reply'.$concern->id, [
            'message' => ['required', 'string', 'max:'.self::REPLY_MAX],
            'resolve' => ['nullable', 'boolean'],
        ], [
            'message.required' => 'Write your reply first.',
            'message.max' => 'Keep the reply under '.number_format(self::REPLY_MAX).' characters.',
        ]);

        $reply = $concern->replies()->make(['message' => trim($validated['message'])]);
        $reply->author()->associate($request->user());
        $reply->save();

        if ($request->boolean('resolve')) {
            $concern->forceFill(['resolved_at' => now()])->save();
        }

        Mail::to($concern->email)->queue(new ConcernReplyMail($reply));
        $concern->user?->notify(new ConcernReplied($reply));
        $this->markHandled($concern);

        return to_route('admin.concerns.show', $concern)->with('status', $concern->user
            ? "Reply sent to {$concern->email}. It also shows in their portal."
            : "Reply sent to {$concern->email}.");
    }

    public function resolve(Concern $concern): RedirectResponse
    {
        $concern->forceFill(['resolved_at' => now()])->save();
        $this->markHandled($concern);

        return back()->with('status', 'Marked as resolved.');
    }

    public function reopen(Concern $concern): RedirectResponse
    {
        $concern->forceFill(['resolved_at' => null])->save();

        return back()->with('status', 'Moved back to open.');
    }

    /**
     * Concerns this admin hasn't opened yet (an unread "New message" notification), shown in bold in the list.
     *
     * @return list<int>
     */
    private function unreadIds(User $admin): array
    {
        return $admin->unreadNotifications()
            ->where('type', ConcernReceived::class)
            ->get()
            ->map(fn (DatabaseNotification $notification) => (int) ($notification->data['concern_id'] ?? 0))
            ->all();
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
