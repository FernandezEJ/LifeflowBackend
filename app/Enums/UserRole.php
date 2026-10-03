<?php

namespace App\Enums;

enum UserRole: string
{
    case Donor = 'donor';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';
}
