<?php

namespace App\Enums;

enum EvidenceLevel: string
{
    case SupplierQualification = 'supplier_qualification';
    case Product = 'product';
    case Workspace = 'workspace';
}
