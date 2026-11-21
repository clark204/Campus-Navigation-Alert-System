<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmergencyContactResource;
use App\Models\EmergencyContact;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The emergency numbers students see on the portal home, for the mobile app. Public and cached (EmergencyContact::listed).
 */
class EmergencyContactController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return EmergencyContactResource::collection(EmergencyContact::listed());
    }
}
