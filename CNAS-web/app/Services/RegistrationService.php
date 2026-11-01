<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\RegistrationDraft;
use App\Models\User;
use App\Services\Cor\CorCheck;
use App\Services\Cor\CorExtractor;
use App\Services\Cor\CorParseException;
use App\Services\Cor\CorValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * COR-based student registration, shared by the web flow and the API.
 *
 * 1. startFromCor(): the COR is read, checked against the current term, and deleted.
 *    Only the student's identity is kept, in a 30-minute draft; the caller gets the
 *    details plus a draft token.
 * 2. complete(): the student's name split, email and password become an active student
 *    account. Student ID and college always come from the COR, never from the request.
 */
class RegistrationService
{
    public const DRAFT_TTL_MINUTES = 30;

    /**
     * Shared with the form requests so the web and the API apply the same file rules.
     * "bail" stops at the first failure, so the student sees one message.
     */
    public const COR_RULES = ['bail', 'required', 'file', 'mimes:pdf', 'max:5120'];

    /*
     * A COR upload is answered with exactly one message on "cor", in this priority:
     * 1. FILE_MESSAGE (not a PDF / too big), 2. INVALID_COR_MESSAGE (any format check fails),
     * 3. WRONG_TERM_MESSAGE (well-formed, wrong term), 4. DUPLICATE_STUDENT_ID_MESSAGE.
     * The failed check names are logged for debugging.
     */
    public const FILE_MESSAGE = 'Please upload your COR as a PDF file (up to 5 MB).';

    public const INVALID_COR_MESSAGE = 'Invalid COR.';

    /** Filled in by wrongTermMessage(), e.g. "Please upload your COR for AY 2026-2027, 1st Semester." */
    public const WRONG_TERM_MESSAGE = 'Please upload your COR for %s.';

    public const DUPLICATE_STUDENT_ID_MESSAGE = 'An account already exists for this student ID. If this wasn\'t you, please contact the CNAS administrator using the contact details on the home page.';

    public const CLOSED_MESSAGE = 'Registration is closed right now. Please try again later.';

    public const COR_MESSAGES = [
        'cor.required' => self::FILE_MESSAGE,
        'cor.uploaded' => self::FILE_MESSAGE,
        'cor.file' => self::FILE_MESSAGE,
        'cor.mimes' => self::FILE_MESSAGE,
        'cor.max' => self::FILE_MESSAGE,
    ];

    public function __construct(
        private CorExtractor $extractor,
        private CorValidator $validator,
    ) {}

    /**
     * @throws ValidationException (key "cor") when registration is closed or the COR is not acceptable.
     */
    public function startFromCor(UploadedFile $file): StartedRegistration
    {
        $term = AcademicTerm::current();

        if ($term === null) {
            throw ValidationException::withMessages(['cor' => self::CLOSED_MESSAGE]);
        }

        Validator::make(['cor' => $file], ['cor' => self::COR_RULES], self::COR_MESSAGES)->validate();

        $path = $file->getRealPath();

        try {
            $cor = $this->extractor->extract($path);
        } catch (CorParseException) {
            $this->rejectCor(['unreadable_pdf'], self::INVALID_COR_MESSAGE);
        } finally {
            // The PDF is never kept: once it's been read, it's gone (RA 10173 data minimisation).
            File::delete($path);
        }

        $failed = $this->validator->validate($cor, $term);
        $failedNames = array_map(fn (CorCheck $check) => $check->value, $failed);

        if (array_filter($failed, fn (CorCheck $check) => $check->isFormatCheck()) !== []) {
            $this->rejectCor($failedNames, self::INVALID_COR_MESSAGE);
        }

        if ($failed !== []) {
            $this->rejectCor($failedNames, $this->wrongTermMessage($term));
        }

        if (User::where('school_id', $cor->studentId)->exists()) {
            $this->rejectCor(['duplicate_student_id'], self::DUPLICATE_STUDENT_ID_MESSAGE);
        }

        $token = Str::random(40);

        $draft = new RegistrationDraft;
        $draft->forceFill([
            'token_hash' => RegistrationDraft::hashToken($token),
            'data' => [
                'student_id' => $cor->studentId,
                'full_name' => $cor->fullName,
                'first_name' => $cor->firstName,
                'middle_name' => $cor->middleName,
                'last_name' => $cor->lastName,
                'college_id' => $cor->collegeId,
            ],
            'expires_at' => now()->addMinutes(self::DRAFT_TTL_MINUTES),
        ])->save();

        return new StartedRegistration($token, $draft);
    }

    /**
     * e.g. "Please upload your COR for AY 2026-2027, 1st Semester."
     */
    public function wrongTermMessage(AcademicTerm $term): string
    {
        return sprintf(self::WRONG_TERM_MESSAGE, $term->label());
    }

    /**
     * Log which checks failed (check names only: no student ID, name or file contents) and
     * answer with the single message the student sees.
     *
     * @param  list<string>  $failedChecks
     *
     * @throws ValidationException
     */
    private function rejectCor(array $failedChecks, string $message): never
    {
        Log::info('COR upload rejected', ['failed_checks' => $failedChecks]);

        throw ValidationException::withMessages(['cor' => $message]);
    }

    public function findDraft(string $token): ?RegistrationDraft
    {
        return RegistrationDraft::active()->where('token_hash', RegistrationDraft::hashToken($token))->first();
    }

    /**
     * Throw away a draft the student replaced (e.g. uploaded a different COR).
     */
    public function discard(string $token): void
    {
        RegistrationDraft::where('token_hash', RegistrationDraft::hashToken($token))->delete();
    }

    /**
     * The student may only re-split the COR name into first, middle and last name.
     *
     * TODO: "Edit name" on the student Profile page should apply the same re-split rules
     * (nameMatchesCor). The COR name isn't stored, so compare against the account's current
     * first + middle + last words instead.
     *
     * @param  array{first_name: ?string, middle_name: ?string, last_name: ?string}  $names
     *
     * @throws ValidationException (key "first_name")
     */
    public function ensureNameMatchesCor(RegistrationDraft $draft, array $names): void
    {
        if (blank($names['first_name']) || blank($names['last_name']) || ! $this->nameMatchesCor($draft->data['full_name'], $names)) {
            throw ValidationException::withMessages([
                'first_name' => "Your name must match your COR ({$draft->data['full_name']}). You can only change how it is split into first, middle and last name, and write your middle initial out in full.",
            ]);
        }
    }

    /**
     * The name the account will get: the parser's split, with any name field the student sent replacing it.
     *
     * @param  array<string, mixed>  $data
     * @return array{first_name: ?string, middle_name: ?string, last_name: ?string}
     */
    public function resolveNames(RegistrationDraft $draft, array $data): array
    {
        $names = [];

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            $value = array_key_exists($field, $data) ? $data[$field] : $draft->data[$field];
            $names[$field] = blank($value) ? null : trim((string) $value);
        }

        return $names;
    }

    /**
     * Create the active student account from a draft. Name fields are optional: any left out
     * keep the parser's split.
     *
     * @param  array{first_name?: ?string, middle_name?: ?string, last_name?: ?string, email: string, password: string}  $data
     *
     * @throws ValidationException (keys "draft_token", "first_name", "student_id" or "email").
     */
    public function complete(string $draftToken, array $data): User
    {
        return DB::transaction(function () use ($draftToken, $data) {
            $draft = RegistrationDraft::active()
                ->where('token_hash', RegistrationDraft::hashToken($draftToken))
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw ValidationException::withMessages([
                    'draft_token' => 'Your registration expired or is invalid. Please upload your COR again.',
                ]);
            }

            $names = $this->resolveNames($draft, $data);
            $this->ensureNameMatchesCor($draft, $names);
            $this->ensureStudentIdIsFree($draft->data['student_id']);

            $user = new User([
                'school_id' => $draft->data['student_id'],
                'college_id' => $draft->data['college_id'],
                ...$names,
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->forceFill(['role' => UserRole::Student, 'verified_at' => now()]);

            try {
                $user->save();
            } catch (UniqueConstraintViolationException) {
                // Another request registered the same email or student ID after validation passed.
                throw ValidationException::withMessages(['email' => 'An account with this email or student ID already exists.']);
            }

            $draft->delete();

            return $user;
        });
    }

    /**
     * Someone may have registered this student ID after the COR was checked.
     */
    private function ensureStudentIdIsFree(string $studentId): void
    {
        if (User::where('school_id', $studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => self::DUPLICATE_STUDENT_ID_MESSAGE]);
        }
    }

    /**
     * The COR prints "First Names Last Name M", so the order of words is ignored: the words of
     * first + middle + last must be the COR name's words as a multiset (case-insensitive, dots
     * ignored). The COR's trailing middle initial may instead be replaced by a full middle name
     * that starts with that letter.
     *
     * @param  array{first_name: ?string, middle_name: ?string, last_name: ?string}  $names
     */
    private function nameMatchesCor(string $corName, array $names): bool
    {
        $words = function (?string $text): array {
            $words = preg_split("/\s+/u", mb_strtolower(str_replace('.', ' ', (string) $text)), -1, PREG_SPLIT_NO_EMPTY);
            sort($words);

            return $words;
        };

        $corWords = $words($corName);
        $given = $words($names['first_name'].' '.$names['last_name']);
        $middle = $words($names['middle_name']);

        if ($words(implode(' ', [...$given, ...$middle])) === $corWords) {
            return true;
        }

        $initial = $this->middleInitial($corName);

        if ($initial === null || $middle === [] || ! str_starts_with(mb_strtolower(trim((string) $names['middle_name'])), $initial)) {
            return false;
        }

        $withoutInitial = $corWords;
        unset($withoutInitial[array_search($initial, $withoutInitial, true)]);

        return $given === array_values($withoutInitial);
    }

    /**
     * The trailing single letter of a COR name ("Pedro Jose Ramos L" → "l"), if any.
     */
    private function middleInitial(string $corName): ?string
    {
        $words = preg_split("/\s+/u", mb_strtolower(trim($corName)), -1, PREG_SPLIT_NO_EMPTY);
        $last = rtrim((string) end($words), '.');

        return count($words) > 1 && mb_strlen($last) === 1 ? $last : null;
    }
}
