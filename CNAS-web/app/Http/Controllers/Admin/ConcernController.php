<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplyConcernRequest;
use App\Models\Concern;
use App\Services\ConcernInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin > Concerns, an email-style inbox (docs/portal.md): the list (index, folders Open and Resolved via
 * ?status=resolved) and one conversation (show) with the reply box. The admin replies there (the reply is emailed to
 * the sender and, if they have an account, shows in their portal) and marks each one resolved. The rules are in
 * ConcernInbox, shared with the mobile app.
 */
class ConcernController extends Controller
{
    public function __construct(private ConcernInbox $inbox) {}

    public function index(Request $request): View
    {
        $showResolved = $request->query('status') === 'resolved';

        return view('admin.concerns-page', [
            'concerns' => $this->inbox->folder($showResolved)->paginate(ConcernInbox::PER_PAGE)->withQueryString(),
            'showResolved' => $showResolved,
            'openCount' => Concern::open()->count(),
            'resolvedCount' => Concern::resolved()->count(),
            'unreadIds' => $this->inbox->unreadIds($request->user()),
        ]);
    }

    /**
     * The open count for the sidebar badge (resources/js/admin-badges.js polls it every 30 s).
     */
    public function count(): JsonResponse
    {
        return response()->json(['count' => $this->inbox->openCount()])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Concern $concern): View
    {
        $concern->load(['user', 'replies.author']);
        $this->inbox->markOpened($request->user(), $concern);

        return view('admin.concern-page', [
            'concern' => $concern,
            'backUrl' => route('admin.concerns.index', $concern->isResolved() ? ['status' => 'resolved'] : []),
        ]);
    }

    /**
     * Reply ("Mark as resolved" is ticked by default). Errors use the bag reply{id} (ReplyConcernRequest).
     */
    public function reply(ReplyConcernRequest $request, Concern $concern): RedirectResponse
    {
        $status = $this->inbox->reply($concern, $request->user(), $request->validated('message'), $request->boolean('resolve'));

        return to_route('admin.concerns.show', $concern)->with('status', $status);
    }

    public function resolve(Concern $concern): RedirectResponse
    {
        return back()->with('status', $this->inbox->resolve($concern));
    }

    public function reopen(Concern $concern): RedirectResponse
    {
        return back()->with('status', $this->inbox->reopen($concern));
    }
}
