<?php

namespace App\Enums;

enum OrganicStatus: string
{
    case Unknown = 'unknown';
    case Conventional = 'conventional';
    case Organic = 'organic';
}
