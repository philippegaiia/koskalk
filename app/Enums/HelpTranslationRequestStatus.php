<?php

namespace App\Enums;

enum HelpTranslationRequestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
