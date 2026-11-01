<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Faculty = 'faculty';
    case Student = 'student';

    /**
     * Named route an approved user lands on after signing in.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            self::Faculty => 'faculty.dashboard',
            self::Student => 'student.dashboard',
            self::Staff => 'landing',
        };
    }
}
