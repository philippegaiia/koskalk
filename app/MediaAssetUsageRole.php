<?php

namespace App;

enum MediaAssetUsageRole: string
{
    case RecipeFeatured = 'recipe_featured';
    case RecipeSop = 'recipe_sop';
    case RecipeSopDocument = 'recipe_sop_document';
    case IngredientMain = 'ingredient_main';
    case IngredientIconOverride = 'ingredient_icon_override';
    case IngredientDocument = 'ingredient_document';
    case PackagingMain = 'packaging_main';
}
