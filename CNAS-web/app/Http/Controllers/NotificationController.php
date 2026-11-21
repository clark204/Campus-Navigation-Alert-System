<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification bell in the top bar (partials/notification-bell), for the admin and the portals. Notifications are
 * Laravel database notifications (App\Notifications\*) whose data has title, body and url (a path on this site).
 */
class NotificationController extends Controller
{
    /**
     * Open one: mark it read and go where it points. Only the user's own notifications (404 otherwise).
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        $url = (string) ($item->data['url'] ?? '');

        // Only paths on this site, never another site.
        return str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : redirect()->route($request->user()->role->homeRoute());
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
