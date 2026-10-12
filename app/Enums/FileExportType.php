<?php

namespace App\Enums;

enum FileExportType: string
{
    case Ingredients = 'ingredients';
    case Packaging = 'packaging';
    case InventoryMaterials = 'inventory_materials';
    case StockLots = 'stock_lots';
}
