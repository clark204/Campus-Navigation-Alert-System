<?php

namespace App\Enums;

/**
 * What an admin did to an alert (alert_logs.action).
 */
enum AlertLogAction: string
{
    case Sent = 'sent';
    case Edited = 'edited';
    case Resolved = 'resolved';
    case SeverityChanged = 'severity';
    /** An admin tapped "I'm responding" to someone's "I need help" (detail: the person's name). */
    case HelpResponding = 'help_responding';
    /** An admin marked someone's "I need help" as helped (detail: the person's name). */
    case HelpResolved = 'help_resolved';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Edited => 'Edited',
            self::Resolved => 'Resolved',
            self::SeverityChanged => 'Severity changed',
            self::HelpResponding => 'Responding to',
            self::HelpResolved => 'Helped',
        };
    }

    /**
     * The entry as one line: "Responding to Ana Cruz", "Helped Ana Cruz", "Severity changed (Warning to Emergency)".
     */
    public function describe(?string $detail): string
    {
        return match (true) {
            blank($detail) => $this->label(),
            $this === self::HelpResponding, $this === self::HelpResolved => "{$this->label()} {$detail}",
            default => "{$this->label()} ({$detail})",
        };
    }
}
