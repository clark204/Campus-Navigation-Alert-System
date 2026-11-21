<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassScheduleResource;
use App\Services\ClassSchedules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/schedule: the signed-in student's classes for the current term, for the mobile app.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request, ClassSchedules $schedules): AnonymousResourceCollection
    {
        return ClassScheduleResource::collection($schedules->forUser($request->user()));
    }
}
