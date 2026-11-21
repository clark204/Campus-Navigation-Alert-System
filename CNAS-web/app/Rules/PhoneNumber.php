<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number written for people: digits, spaces and dashes, with an optional + in front ("+63 912-345-6789",
 * "911"). It must start and end with a digit. The tel: link is made by dropping everything but digits and +.
 */
class PhoneNumber implements ValidationRule
{
    public const MESSAGE = 'Use digits, spaces and dashes only, with an optional + in front (e.g. +63 912-345-6789).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\+?\d(?:[\d \-]*\d)?$/', $value) !== 1) {
            $fail(self::MESSAGE);
        }
    }
}
