<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Registration\UploadCorRequest;
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

    public function storeCor(UploadCorRequest $request): CorDraftResource
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
