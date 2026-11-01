<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * "YYYY-YYYY" where the second year is the first plus one, e.g. 2026-2027.
 */
class AcademicYear implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{4})$/', $value, $years) !== 1
            || (int) $years[2] !== (int) $years[1] + 1) {
            $fail('The academic year must look like 2026-2027, with the second year one after the first.');
        }
    }
}
