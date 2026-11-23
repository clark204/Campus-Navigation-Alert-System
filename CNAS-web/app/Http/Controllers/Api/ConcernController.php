<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConcernTopic;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\HelpController;
use App\Http\Resources\ConcernResource;
use App\Notifications\ConcernReplied;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/concerns: the mobile app's "My messages" on Help & contact, like the portal page: the user's newest
 * HelpController::RECENT_CONCERNS concerns with the administrator's replies, and the topics the form offers. Like
 * opening the page, it marks the user's reply notifications read. Sending one uses the web's ConcernController.
 */
class ConcernController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $concerns = $user->concerns()->with('replies')->latest()->latest('id')->limit(HelpController::RECENT_CONCERNS)->get();

        // The replies are on this screen now, so their bell notifications are read.
        $user->unreadNotifications()->where('type', ConcernReplied::class)->update(['read_at' => now()]);

        return ConcernResource::collection($concerns)->additional(['meta' => [
            'email' => $user->email,
            'topics' => array_map(fn (ConcernTopic $topic) => ['value' => $topic->value, 'label' => $topic->label()], ConcernTopic::cases()),
        ]]);
    }
}
