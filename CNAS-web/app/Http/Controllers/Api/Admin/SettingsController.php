<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Semester;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\ContactDetailsController;
use App\Http\Controllers\Admin\EmergencyContactController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveEmergencyContactRequest;
use App\Http\Requests\Admin\SaveEmergencyContactRequest;
use App\Http\Requests\Admin\StoreAcademicTermRequest;
use App\Http\Requests\Admin\UpdateContactDetailsRequest;
use App\Http\Requests\Admin\UpdateEmergencyContactVisibilityRequest;
use App\Models\AcademicTerm;
use App\Models\EmergencyContact;
use App\Services\ContactDetails;
use App\Services\StatusCheck;
use App\Services\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin's Settings in the mobile app (admin only, docs/settings.md), with the web's requests, models, services and
 * messages: Academic term, Emergency contacts, Contact details and System status. My account is the shared
 * /api/account (Api\AccountController). Every answer that changes something has `message`.
 */
class SettingsController extends Controller
{
    // ---------- Academic term

    /**
     * Every term (newest first), the current one, the semesters to choose from, and the dashboard's warning
     * (AcademicTerm::warning, null when nothing needs doing).
     */
    public function terms(): JsonResponse
    {
        $current = AcademicTerm::current();

        return response()->json([
            'current' => $current ? $this->term($current) : null,
            'terms' => AcademicTerm::newestFirst()->map(fn (AcademicTerm $term) => $this->term($term))->values(),
            'semesters' => array_map(fn (Semester $semester) => ['value' => $semester->value, 'label' => $semester->label()], Semester::cases()),
            'warning' => AcademicTerm::warning(),
        ]);
    }

    public function storeTerm(StoreAcademicTermRequest $request): JsonResponse
    {
        $term = AcademicTerm::create($request->validated());

        return response()->json(['message' => AcademicTermController::addedMessage($term), 'data' => $this->term($term)], 201);
    }

    public function makeTermCurrent(AcademicTerm $academicTerm): JsonResponse
    {
        $academicTerm->makeCurrent();

        return response()->json(['message' => AcademicTermController::currentMessage($academicTerm), 'data' => $this->term($academicTerm->fresh())]);
    }

    // ---------- Contact details

    public function contactDetails(ContactDetails $details): JsonResponse
    {
        return response()->json(['data' => $details->all()]);
    }

    public function updateContactDetails(UpdateContactDetailsRequest $request, ContactDetails $details): JsonResponse
    {
        $details->save($request->validated());

        return response()->json(['message' => ContactDetailsController::SAVED_MESSAGE, 'data' => $details->all()]);
    }

    // ---------- Emergency contacts

    /**
     * All of them in order, hidden ones too (the web settings list; students only see EmergencyContact::listed()).
     */
    public function emergencyContacts(): JsonResponse
    {
        return response()->json(['data' => EmergencyContact::ordered()->map(fn (EmergencyContact $contact) => $this->contact($contact))->values()]);
    }

    public function storeEmergencyContact(SaveEmergencyContactRequest $request): JsonResponse
    {
        $contact = EmergencyContact::create($request->contact());

        return response()->json(['message' => EmergencyContactController::message('added', $contact), 'data' => $this->contact($contact)], 201);
    }

    public function updateEmergencyContact(SaveEmergencyContactRequest $request, EmergencyContact $emergencyContact): JsonResponse
    {
        $emergencyContact->update($request->contact());

        return response()->json(['message' => EmergencyContactController::message('saved', $emergencyContact), 'data' => $this->contact($emergencyContact)]);
    }

    public function destroyEmergencyContact(EmergencyContact $emergencyContact): JsonResponse
    {
        $emergencyContact->delete();

        return response()->json(['message' => EmergencyContactController::message('deleted', $emergencyContact)]);
    }

    public function moveEmergencyContact(MoveEmergencyContactRequest $request, EmergencyContact $emergencyContact): JsonResponse
    {
        $emergencyContact->move($request->direction());

        return response()->json(['message' => EmergencyContactController::message("moved {$request->direction()}", $emergencyContact)]);
    }

    public function emergencyContactVisibility(UpdateEmergencyContactVisibilityRequest $request, EmergencyContact $emergencyContact): JsonResponse
    {
        $emergencyContact->update(['is_visible' => $request->boolean('is_visible')]);

        return response()->json(['message' => EmergencyContactController::visibilityMessage($emergencyContact), 'data' => $this->contact($emergencyContact)]);
    }

    // ---------- System status

    /**
     * The web's checks (SystemStatus), read-only: OK or Needs attention, what was found, and the fix when not OK.
     */
    public function status(Request $request, SystemStatus $status): JsonResponse
    {
        return response()->json([
            'data' => collect($status->checks($request))
                ->map(fn (StatusCheck $check, string $key) => ['key' => $key, 'name' => $check->name, 'ok' => $check->ok, 'summary' => $check->summary, 'fix' => $check->ok ? null : $check->fix])
                ->values(),
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{id: int, label: string, academic_year: string, semester: string, is_current: bool, set_current_at: ?string}
     */
    private function term(AcademicTerm $term): array
    {
        return [
            'id' => $term->id,
            'label' => $term->label(),
            'academic_year' => $term->academic_year,
            'semester' => $term->semester->value,
            'is_current' => (bool) $term->is_current,
            'set_current_at' => $term->set_current_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, phone: ?string, tel: ?string, note: ?string, is_visible: bool}
     */
    private function contact(EmergencyContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'tel' => $contact->phone ? $contact->telHref() : null,
            'note' => $contact->note,
            'is_visible' => (bool) $contact->is_visible,
        ];
    }
}
