<?php

namespace App\Enums;

enum IfraAmendmentStatus: string
{
    case Consultation = 'consultation';
    case Notified = 'notified';
    case Superseded = 'superseded';
}
