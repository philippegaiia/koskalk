<?php

namespace App\Enums;

enum HelpContentExportReason: string
{
    case Manual = 'manual';
    case Publication = 'publication';
    case Scheduled = 'scheduled';
    case PreImport = 'pre_import';
}
