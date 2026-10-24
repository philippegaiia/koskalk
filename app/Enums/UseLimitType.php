<?php

namespace App\Enums;

/**
 * What a document states for one IFRA category or product class: a maximum (0 means not permitted) or, explicitly,
 * no restriction. Nothing else concludes a use.
 */
enum UseLimitType: string
{
    case Maximum = 'maximum';
    case NoRestriction = 'no_restriction';
}
