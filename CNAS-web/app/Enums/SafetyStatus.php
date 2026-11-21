<?php

namespace App\Enums;

/**
 * A user's answer to the safety check during an alert. "I need help" comes with the admin live view (alert plan step 3).
 */
enum SafetyStatus: string
{
    case Safe = 'safe';
}
