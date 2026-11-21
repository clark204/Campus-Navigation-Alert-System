<?php

namespace App\Policies;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\User;

/**
 * A student (My schedule) or teacher (My classes) changes only their own rows, and only those of the current term
 * (older terms stay hidden).
 */
class ClassSchedulePolicy
{
    public function update(User $user, ClassSchedule $classSchedule): bool
    {
        return $this->owns($user, $classSchedule);
    }

    public function delete(User $user, ClassSchedule $classSchedule): bool
    {
        return $this->owns($user, $classSchedule);
    }

    private function owns(User $user, ClassSchedule $classSchedule): bool
    {
        return $classSchedule->user_id === $user->id
            && $classSchedule->academic_term_id === AcademicTerm::current()?->id;
    }
}
