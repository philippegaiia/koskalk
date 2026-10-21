<?php

namespace App\Enums;

enum LegalEntryReviewStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
}
