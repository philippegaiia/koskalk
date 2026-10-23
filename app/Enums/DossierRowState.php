<?php

namespace App\Enums;

/**
 * State of one DIP Part A row (spec §4.2). `route_suggere` prints as « À justifier par l'évaluateur ».
 */
enum DossierRowState: string
{
    case NonConforme = 'non_conforme';
    case RouteSuggere = 'route_suggere';
    case Conforme = 'conforme';
    case AJustifier = 'a_justifier';
}
