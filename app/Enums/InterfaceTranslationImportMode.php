<?php

namespace App\Enums;

enum InterfaceTranslationImportMode: string
{
    case Authoritative = 'authoritative';
    case PreserveExisting = 'preserve-existing';
}
