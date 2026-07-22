<?php

use App\Casts\OriginalFilename;
use App\Models\Ingredient;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\UserPackagingItem;

it('round trips a harmless Unicode filename on a recipe', function () {
    $recipe = new Recipe;

    $recipe->featured_image_original_name = 'Sérum visage 01.png';

    expect($recipe->featured_image_original_name)->toBe('Sérum visage 01.png');
});

it('sanitizes mapped original filename attributes through each model', function (string $modelClass, string $attribute) {
    $model = new $modelClass;

    $model->{$attribute} = "../../private/serum\0\nphoto.png";

    expect($model->{$attribute})->toBe('serumphoto.png')
        ->and($model->getAttributes()[$attribute])->toBe('serumphoto.png');
})->with([
    'recipe featured image' => [Recipe::class, 'featured_image_original_name'],
    'ingredient featured image' => [Ingredient::class, 'featured_image_original_name'],
    'ingredient icon image' => [Ingredient::class, 'icon_image_original_name'],
    'packaging item featured image' => [UserPackagingItem::class, 'featured_image_original_name'],
    'product type fallback image' => [ProductType::class, 'fallback_image_original_name'],
]);

it('stores blank names as null and limits multibyte names to 255 characters', function () {
    $cast = new OriginalFilename;
    $recipe = new Recipe;

    $limitedName = $cast->set(
        $recipe,
        'featured_image_original_name',
        str_repeat('é', 300),
        [],
    );

    expect($cast->set($recipe, 'featured_image_original_name', '   ', []))->toBeNull()
        ->and($limitedName)->toBe(str_repeat('é', 255));
});

it('preserves a valid CJK filename within the character limit', function () {
    $cast = new OriginalFilename;
    $validName = str_repeat('界', 200).'.png';

    expect($cast->set(new Recipe, 'featured_image_original_name', $validName, []))->toBe($validName);
});
