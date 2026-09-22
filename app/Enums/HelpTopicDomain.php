<?php

namespace App\Enums;

enum HelpTopicDomain: string
{
    case SharedWorkbench = 'shared_workbench';
    case SoapWorkbench = 'soap_workbench';
    case ProductionInventory = 'production_inventory';
    case CosmeticWorkbench = 'cosmetic_workbench';
}
