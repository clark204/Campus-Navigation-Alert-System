<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Registration\UploadCorWithConsentRequest;
use App\Http\Resources\CorDraftResource;
use App\Http\Resources\UserResource;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * Stateless COR registration for the mobile app. Same service as the web flow;
 * the client holds the draft token between the two calls.
 */
class RegisterController extends Controller
{
    public function __construct(private RegistrationService $registrations) {}

    /**
     * The COR plus `consent`: the app shows the Data Privacy Act notice first, like web step 1.
     */
    public function storeCor(UploadCorWithConsentRequest $request): CorDraftResource
    {
        return new CorDraftResource($this->registrations->startFromCor($request->file('cor')));
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrations->complete(
            $request->string('draft_token')->toString(),
            $request->safe()->except('draft_token'),
        );

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
