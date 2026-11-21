<?php

namespace App\Services;

/**
 * One line of "System status" on /admin/settings: OK or Needs attention, what was found, and how to fix it.
 */
final readonly class StatusCheck
{
    /**
     * @param  string  $summary  What the check found, in one plain sentence.
     * @param  string|null  $fix  How to fix it, shown only when the check is not OK.
     */
    public function __construct(
        public string $name,
        public bool $ok,
        public string $summary,
        public ?string $fix = null,
    ) {}
}
