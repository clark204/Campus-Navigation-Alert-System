<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContactDetailsRequest;
use App\Services\ContactDetails;
use Illuminate\Http\RedirectResponse;

/**
 * Settings → Contact details (site footer and portal Help & contact page).
 */
class ContactDetailsController extends Controller
{
    public const SAVED_MESSAGE = 'Contact details saved. The site footer shows them now.';

    public function update(UpdateContactDetailsRequest $request, ContactDetails $contactDetails): RedirectResponse
    {
        $contactDetails->save($request->validated());

        return redirect(SettingsController::tabUrl('contact-details'))
            ->with('section', 'contact')->with('status', self::SAVED_MESSAGE);
    }
}
