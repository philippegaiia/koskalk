<?php

namespace App\Enums;

enum AssessmentReviewState: string
{
    case NotReviewed = 'not_reviewed';
    case Sufficient = 'sufficient';
    case Insufficient = 'insufficient';
}
