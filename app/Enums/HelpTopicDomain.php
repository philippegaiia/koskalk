<?php

namespace App\Enums;

enum HelpTopicDomain: string
{
    case SharedWorkbench = 'shared_workbench';
    case SoapWorkbench = 'soap_workbench';
    case ProductionRuns = 'production_runs';
    case ProductionPurchasing = 'production_purchasing';
    case ProductionInventory = 'production_inventory';
    case CosmeticWorkbench = 'cosmetic_workbench';
}
