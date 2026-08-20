<?php

namespace App\Enums;

enum IfraCategorySelectionMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Legacy = 'legacy';
}
