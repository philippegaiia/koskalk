<?php

namespace App\Enums;

enum WorkspaceModule: string
{
    case Formulation = 'formulation';
    case ProductionBench = 'production_bench';
    case ProductDossiers = 'product_dossiers';
}
