<?php

namespace App\Enums;

enum MaterialPriceSource: string
{
    case ManualCosting = 'manual_costing';
    case SupplierListing = 'supplier_listing';
    case ProcurementDocument = 'procurement_document';
    case Receipt = 'receipt';
    case OpeningStock = 'opening_stock';
}
