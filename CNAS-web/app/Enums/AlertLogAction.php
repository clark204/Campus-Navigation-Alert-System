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

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Edited => 'Edited',
            self::Resolved => 'Resolved',
            self::SeverityChanged => 'Severity changed',
        };
    }
}
