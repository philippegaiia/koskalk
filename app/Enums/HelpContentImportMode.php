<?php

namespace App\Enums;

enum HelpContentImportMode: string
{
    case Bootstrap = 'bootstrap';
    case RestoreEmpty = 'restore-empty';
    case MergeDrafts = 'merge-drafts';
}
