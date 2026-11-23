<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveEmergencyContactRequest;
use App\Http\Requests\Admin\SaveEmergencyContactRequest;
use App\Http\Requests\Admin\UpdateEmergencyContactVisibilityRequest;
use App\Models\EmergencyContact;
use Illuminate\Http\RedirectResponse;

/**
 * Settings → Emergency contacts: the numbers on the portal home and /api/emergency-contacts. The mobile app uses the
 * same requests and messages (Api\Admin\SettingsController).
 */
class EmergencyContactController extends Controller
{
    public function store(SaveEmergencyContactRequest $request): RedirectResponse
    {
        $contact = EmergencyContact::create($request->contact());

        return $this->backToList(self::message('added', $contact));
    }

    public function update(SaveEmergencyContactRequest $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->update($request->contact());

        return $this->backToList(self::message('saved', $emergencyContact));
    }

    public function destroy(EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->delete();

        return $this->backToList(self::message('deleted', $emergencyContact));
    }

    public function move(MoveEmergencyContactRequest $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->move($request->direction());

        return $this->backToList(self::message("moved {$request->direction()}", $emergencyContact));
    }

    public function visibility(UpdateEmergencyContactVisibilityRequest $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->update(['is_visible' => $request->boolean('is_visible')]);

        return $this->backToList(self::visibilityMessage($emergencyContact));
    }

    /**
     * "Campus security was added." (added, saved, deleted, moved up, moved down).
     */
    public static function message(string $done, EmergencyContact $contact): string
    {
        return "{$contact->name} was {$done}.";
    }

    public static function visibilityMessage(EmergencyContact $contact): string
    {
        return $contact->is_visible
            ? "{$contact->name} is now shown to students."
            : "{$contact->name} is now hidden from students.";
    }

    private function backToList(string $status): RedirectResponse
    {
        return redirect(SettingsController::tabUrl('emergency-contacts'))->with('section', 'emergency')->with('status', $status);
    }
}
