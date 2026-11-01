<?php

namespace App\Http\Resources;

use App\Services\StartedRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the app shows the student to confirm after a COR upload. Student ID and college
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
     * @return array{draft_token: string, student_id: string, first_name: ?string, middle_name: ?string, last_name: ?string, college_id: string}
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->draft->data;

        return [
            'draft_token' => $this->resource->token,
            'student_id' => $data['student_id'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'college_id' => $data['college_id'],
        ];
    }
}
