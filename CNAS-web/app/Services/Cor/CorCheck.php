<?php

namespace App\Services\Cor;

/**
 * One COR check. Every check is pass/fail: any failure rejects the upload.
 *
 * The student only ever sees one message (RegistrationService decides which); the check
 * names are logged so a rejection can be debugged.
 */
enum CorCheck: string
{
    case Header = 'header';
    case Labels = 'labels';
    case StudentIdFormat = 'student_id_format';
    case Semester = 'semester';
    case AcademicTerm = 'academic_term';
    case TotalUnits = 'total_units';
    case DateEnrolled = 'date_enrolled';
    case CopiesMatch = 'copies_match';
    case KnownProgram = 'known_program';

    /**
     * Whether the COR itself is malformed or tampered with ("Invalid COR."), as opposed to a
     * well-formed COR for the wrong term.
     */
    public function isFormatCheck(): bool
    {
        return $this !== self::AcademicTerm;
    }
}
