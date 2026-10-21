<?php

namespace App\Enums;

enum LegalEntryProductScope: string
{
    case AllProducts = 'all_products';
    case RinseOffOnly = 'rinse_off_only';
    case LeaveOnOnly = 'leave_on_only';
    case SeeConditions = 'see_conditions';
}
