<?php

namespace App\Services;

use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Jobs\CheckExpoPushReceipts;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Notifications\AlertPushNotification;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Expo push service (docs.expo.dev/push-notifications/sending-notifications), for the mobile app's alert push and
 * the admins' report push (AdminReport).
 * Plain HTTP through Laravel's client, no SDK. EXPO_ACCESS_TOKEN is only needed when "Enhanced push security" is on
 * in the Expo dashboard; it is sent whenever it is set.
 */
class ExpoPush
{
    public const SEND_URL = 'https://exp.host/--/api/v2/push/send';

    public const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    /** Messages per send request (Expo's limit). */
    public const BATCH = 100;

    /** Expo's receipt error for a token that no longer works (app uninstalled, push turned off). */
    public const DEVICE_NOT_REGISTERED = 'DeviceNotRegistered';

    /** Same as the web push: a phone that stays offline longer than this doesn't get an old alert (seconds). */
    private const TIME_TO_LIVE = 3600;

    /**
     * The Android notification channels the app creates (CNAS-mobile src/lib/push.ts), each with its own sound.
     */
    public const CHANNEL_EMERGENCY = 'emergency';

    public const CHANNEL_DRILL = 'drill';

    public const CHANNEL_WARNING = 'warning';

    /** Reports for admins (a new concern, "I need help"): default sound, high importance. */
    public const CHANNEL_ADMIN = 'admin_reports';

    /** When Expo recommends checking receipts. */
    public const RECEIPT_DELAY_MINUTES = 15;

    /**
     * The push for one phone. Same wording and severity rules as the web push (AlertPushNotification): every level
     * is pushed; an emergency is high priority with the alarm; a drill always uses the softer drill channel; a
     * warning, an advisory and an "all clear" use the chime. The resolution has the alert's tag, so it replaces the
     * alert's notification on the phone.
     *
     * @return array<string, mixed>
     */
    public function message(string $to, Alert $alert, ?AlertResolution $resolution = null): array
    {
        $emergency = $resolution === null && $alert->severity === AlertSeverity::Emergency;
        $channel = match (true) {
            $alert->is_drill => self::CHANNEL_DRILL,
            $emergency => self::CHANNEL_EMERGENCY,
            default => self::CHANNEL_WARNING,
        };

        return [
            'to' => $to,
            'title' => AlertPushNotification::title($alert, $resolution),
            'body' => AlertPushNotification::body($alert, $resolution),
            // The app opens and checks /api/alerts/active at once (takeover for an emergency).
            'data' => ['alert_id' => $alert->id, 'screen' => 'alert'],
            'priority' => $emergency ? 'high' : 'default',
            'channelId' => $channel,
            // iOS plays the file named here; Android plays the channel's sound.
            'sound' => match ($channel) {
                self::CHANNEL_EMERGENCY => 'alarm.wav',
                self::CHANNEL_DRILL => 'drill.wav',
                default => 'chime.wav',
            },
            // Android replaces a shown notification with the same tag; iOS (and Android in transit) uses collapseId.
            'tag' => AlertPushNotification::tag($alert),
            'collapseId' => AlertPushNotification::tag($alert),
            'ttl' => self::TIME_TO_LIVE,
        ];
    }

    /**
     * The push for one admin's phone: the report's title and body, on the "admin" channel, opening its screen.
     *
     * @return array<string, mixed>
     */
    public function reportMessage(string $to, AdminReport $report): array
    {
        return [
            'to' => $to,
            'title' => $report->title,
            'body' => $report->body,
            'data' => $report->data,
            'priority' => 'high',
            'channelId' => self::CHANNEL_ADMIN,
            'sound' => 'default',
            'tag' => $report->tag,
            'collapseId' => $report->tag,
            'ttl' => self::TIME_TO_LIVE,
        ];
    }

    /**
     * Send one message to each token (up to BATCH) and look after the tokens: one Expo rejects right away as
     * DeviceNotRegistered is deleted; the others' receipts are checked RECEIPT_DELAY_MINUTES later
     * (CheckExpoPushReceipts), as Expo recommends.
     *
     * @param  list<string>  $tokens
     * @param  callable(string): array<string, mixed>  $messageFor
     *
     * @throws RuntimeException when Expo is busy or down, so the job tries again later.
     */
    public function deliver(array $tokens, callable $messageFor): void
    {
        if ($tokens === []) {
            return;
        }

        $tickets = $this->send(array_map($messageFor, $tokens));
        $gone = [];
        $receipts = [];
        foreach ($tickets as $index => $ticket) {
            $token = $tokens[$index] ?? null;
            if ($token === null) {
                continue;
            }
            if (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
                $receipts[$ticket['id']] = $token;
            } elseif (($ticket['details']['error'] ?? null) === self::DEVICE_NOT_REGISTERED) {
                $gone[] = $token;
            }
        }

        if ($gone !== []) {
            DevicePushToken::whereIn('expo_token', $gone)->delete();
        }
        if ($receipts !== []) {
            CheckExpoPushReceipts::dispatch($receipts)->delay(now()->addMinutes(self::RECEIPT_DELAY_MINUTES));
        }
    }

    /**
     * Send up to BATCH messages. Returns Expo's tickets, in the same order as the messages.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{status: string, id?: string, message?: string, details?: array<string, mixed>}>
     *
     * @throws RuntimeException when Expo is busy or down (429, 5xx), so the job tries again later.
     */
    public function send(array $messages): array
    {
        $response = $this->http()->post(self::SEND_URL, $messages);

        if ($response->failed()) {
            throw new RuntimeException("Expo push send failed with HTTP {$response->status()}: ".$response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Receipts for earlier tickets, by ticket id. Ids Expo has no receipt for yet are left out.
     *
     * @param  list<string>  $ids
     * @return array<string, array{status: string, message?: string, details?: array<string, mixed>}>
     *
     * @throws RuntimeException
     */
    public function receipts(array $ids): array
    {
        $response = $this->http()->post(self::RECEIPTS_URL, ['ids' => $ids]);

        if ($response->failed()) {
            throw new RuntimeException("Expo push receipts failed with HTTP {$response->status()}: ".$response->body());
        }

        return $response->json('data', []);
    }

    private function http(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(20);
        $token = config('services.expo.access_token');

        return filled($token) ? $request->withToken($token) : $request;
    }
}
