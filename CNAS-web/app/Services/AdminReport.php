<?php

namespace App\Services;

use App\Models\Concern;
use App\Models\SafetyCheck;
use App\Notifications\ConcernReceived;
use Illuminate\Support\Str;

/**
 * Something an admin should see now ("reports", docs/settings.md): pushed to every admin's phone (SendAdminReportPush)
 * on the app's "admin" channel. `data.screen` tells the app what to open: "concern" (concern_id) or "safety"
 * (alert_id). The bell and email notifications stay as they are; this is only the phone push.
 */
final readonly class AdminReport
{
    /**
     * @param  array<string, string|int>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data,
        public string $tag,
    ) {}

    /**
     * A new concern from Help & contact, the app or the site footer: the bell's wording (ConcernReceived).
     */
    public static function concernReceived(Concern $concern): self
    {
        return new self(
            ConcernReceived::title($concern),
            ConcernReceived::body($concern),
            ['screen' => 'concern', 'concern_id' => $concern->id],
            "cnas-concern-{$concern->id}",
        );
    }

    /**
     * "I need help" during an emergency, with the person's note.
     */
    public static function needsHelp(SafetyCheck $check): self
    {
        $check->loadMissing(['user', 'alert']);
        $where = filled($check->note) ? Str::limit($check->note, 140) : 'No note. Find them on the live view.';

        return new self(
            "{$check->user->full_name} needs help",
            ($check->alert->is_drill ? 'DRILL. ' : '').$where,
            ['screen' => 'safety', 'alert_id' => $check->alert_id],
            "cnas-help-{$check->alert_id}-{$check->user_id}",
        );
    }
}
