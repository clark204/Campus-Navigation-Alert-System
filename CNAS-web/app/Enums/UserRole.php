<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Faculty = 'faculty';
    case Student = 'student';

    /**
     * Named route a signed-in user lands on (after signing in, and when opening a guest page).
     * Never a guest-only route such as 'landing', or the guest middleware would redirect in a loop.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            // Staff share the faculty portal until they get their own.
            self::Faculty, self::Staff => 'faculty.dashboard',
            self::Student => 'student.dashboard',
        };
    }
}
