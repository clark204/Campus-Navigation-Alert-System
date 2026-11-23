<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notification bell for the mobile app, like the web's (partials/notification-bell, NotificationController): the
 * unread count and the newest AppServiceProvider::BELL_NOTIFICATIONS. Each has the kind, title and body the web shows
 * ("concern_reply" opens Help & contact in the app), plus concern_id when it is about a concern.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => $user->notifications()->limit(AppServiceProvider::BELL_NOTIFICATIONS)->get()
                ->map(fn (DatabaseNotification $notification) => [
                    'id' => $notification->id,
                    'kind' => $notification->data['kind'] ?? null,
                    'concern_id' => $notification->data['concern_id'] ?? null,
                    'title' => (string) ($notification->data['title'] ?? ''),
                    'body' => (string) ($notification->data['body'] ?? ''),
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Tapping one: mark it read. Only the user's own notifications (404 otherwise).
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
