<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreConcernRequest;
use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a concern, suggestion or feedback to the admin inbox (Admin > Concerns). Signed-in users send from the
 * portal's Help & contact page; guests from the site footer. Every admin gets it in their notification bell.
 */
class ConcernController extends Controller
{
    public function store(StoreConcernRequest $request): RedirectResponse
    {
        $user = $request->user();

        $concern = new Concern($request->safe()->only(['topic', 'message']));
        $concern->email = $user->email ?? $request->validated('email');
        $concern->user()->associate($user);
        $concern->save();

        Notification::send(User::query()->where('role', UserRole::Admin)->get(), new ConcernReceived($concern));

        return redirect(StoreConcernRequest::backToForm($user !== null))
            ->with('concern_status', "Sent. The administrator will reply to {$concern->email}.");
    }
}
