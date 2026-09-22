<?php

namespace App\Enums;

enum HelpContentOrigin: string
{
    case Human = 'human';
    case Ai = 'ai';
    case Import = 'import';
}
