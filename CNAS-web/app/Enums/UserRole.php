<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Faculty = 'faculty';
    case Student = 'student';

    /**
     * How the role reads next to a person's name, e.g. in the top bar.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
            self::Faculty => 'Faculty',
            self::Student => 'Student',
        };
    }

    /**
     * The portal this role uses: its route name prefix (student.*, faculty.*). Staff share the faculty portal until
     * they get their own. Admins have no portal (they use admin.*), so this is only for the other roles.
     */
    public function portal(): self
    {
        return match ($this) {
            self::Faculty, self::Staff => self::Faculty,
            self::Student, self::Admin => $this,
        };
    }

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
