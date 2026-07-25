<?php

namespace App;

enum MediaAssetUsageRole: string
{
    case RecipeFeatured = 'recipe_featured';
    case RecipeSop = 'recipe_sop';
    case IngredientMain = 'ingredient_main';
    case IngredientIconOverride = 'ingredient_icon_override';
    case PackagingMain = 'packaging_main';
}
