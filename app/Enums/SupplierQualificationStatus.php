<?php

namespace App\Enums;

enum SupplierQualificationStatus: string
{
    case EnEvaluation = 'en_evaluation';
    case Valide = 'valide';
    case Retire = 'retire';
}
