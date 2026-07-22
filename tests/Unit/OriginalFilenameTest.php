<?php

use App\Casts\OriginalFilename;
use App\Models\Recipe;

it('round trips a harmless Unicode filename on a recipe', function () {
    $recipe = new Recipe;

    $recipe->featured_image_original_name = 'Sérum visage 01.png';

    expect($recipe->featured_image_original_name)->toBe('Sérum visage 01.png');
});

it('removes path components and Unicode control characters from filenames', function () {
    $recipe = new Recipe;

    $recipe->featured_image_original_name = "../../private/serum\0\nphoto.png";

    expect($recipe->featured_image_original_name)->toBe('serumphoto.png')
        ->and($recipe->getAttributes()['featured_image_original_name'])->toBe('serumphoto.png');
});

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
