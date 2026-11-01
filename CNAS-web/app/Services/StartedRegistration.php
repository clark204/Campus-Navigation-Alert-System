<?php

namespace App\Services;

use App\Models\RegistrationDraft;

/**
 * Result of RegistrationService::startFromCor(). The raw token exists only here;
 * the database keeps its hash.
 */
final readonly class StartedRegistration
{
    public function __construct(
        public string $token,
        public RegistrationDraft $draft,
    ) {}
}
