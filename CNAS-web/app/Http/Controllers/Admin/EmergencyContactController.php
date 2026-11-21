<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveEmergencyContactRequest;
use App\Http\Requests\Admin\UpdateEmergencyContactVisibilityRequest;
use App\Models\EmergencyContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Emergency contacts: the numbers on the portal home and /api/emergency-contacts.
 */
class EmergencyContactController extends Controller
{
    public function store(SaveEmergencyContactRequest $request): RedirectResponse
    {
        $contact = EmergencyContact::create($request->contact());

        return $this->backToList("{$contact->name} was added.");
    }

    public function update(SaveEmergencyContactRequest $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->update($request->contact());

        return $this->backToList("{$emergencyContact->name} was saved.");
    }

    public function destroy(EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->delete();

        return $this->backToList("{$emergencyContact->name} was deleted.");
    }

    public function move(Request $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $emergencyContact->move($direction);

        return $this->backToList("{$emergencyContact->name} was moved {$direction}.");
    }

    public function visibility(UpdateEmergencyContactVisibilityRequest $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $emergencyContact->update(['is_visible' => $request->boolean('is_visible')]);

        return $this->backToList($emergencyContact->is_visible
            ? "{$emergencyContact->name} is now shown to students."
            : "{$emergencyContact->name} is now hidden from students.");
    }

    private function backToList(string $status): RedirectResponse
    {
        return redirect(SettingsController::tabUrl('emergency-contacts'))->with('section', 'emergency')->with('status', $status);
    }
}
