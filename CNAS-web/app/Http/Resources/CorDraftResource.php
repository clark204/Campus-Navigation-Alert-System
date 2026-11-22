<?php

namespace App\Http\Resources;

use App\Services\StartedRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the app shows the student to confirm after a COR upload. Student ID, program and college
 * are read-only; only the name split can change.
 *
 * @property StartedRegistration $resource
 */
class CorDraftResource extends JsonResource
{
    /**
     * Respond with the fields at the top level, not under "data".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{draft_token: string, student_id: string, first_name: ?string, middle_name: ?string, last_name: ?string, program_code: string, program_name: ?string, college_id: ?string, college_name: ?string, meeting_count: int}
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->draft->data;
        $program = $this->resource->draft->program();

        return [
            'draft_token' => $this->resource->token,
            'student_id' => $data['student_id'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'program_code' => $data['program_code'],
            'program_name' => $program?->name,
            'college_id' => $program?->college_id,
            'college_name' => $program?->college?->name,
            // The class meetings "Also save my class schedule" would save (save_schedule on /api/register).
            'meeting_count' => count($data['meetings'] ?? []),
        ];
    }
}
