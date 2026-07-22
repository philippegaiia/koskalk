<?php

use App\Rules\MaximumRichContentImages;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('accepts two distinct private images in a product description', function (): void {
    $validator = Validator::make([
        'description' => richContentImages([
            'recipes/recipe-1/rich-content/presentation-1.webp',
            'recipes/recipe-1/rich-content/presentation-2.webp',
        ]),
    ], [
        'description' => [new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects three distinct private images in a product description', function (): void {
    $validator = Validator::make([
        'description' => richContentImages([
            'recipes/recipe-1/rich-content/presentation-1.webp',
            'recipes/recipe-1/rich-content/presentation-2.webp',
            'recipes/recipe-1/rich-content/presentation-3.webp',
        ]),
    ], [
        'description' => [new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('description'))->toBe('The product description may contain up to 2 images.');
});

it('accepts eight distinct private images in manufacturing instructions', function (): void {
    $validator = Validator::make([
        'manufacturing_instructions' => richContentImages(range(1, 8), 'procedure'),
    ], [
        'manufacturing_instructions' => [new MaximumRichContentImages(8, 'workbench.instructions.procedure_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects nine distinct private images in manufacturing instructions', function (): void {
    $validator = Validator::make([
        'manufacturing_instructions' => richContentImages(range(1, 9), 'procedure'),
    ], [
        'manufacturing_instructions' => [new MaximumRichContentImages(8, 'workbench.instructions.procedure_image_limit')],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('manufacturing_instructions'))->toBe('The manufacturing procedure may contain up to 8 images.');
});

it('counts a repeated rich content attachment once', function (): void {
    $path = 'recipes/recipe-1/rich-content/repeated.webp';
    $validator = Validator::make([
        'description' => '<p><img data-id="'.$path.'"><img data-id="'.$path.'"></p>',
    ], [
        'description' => [new MaximumRichContentImages(1, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('counts image source URLs', function (): void {
    $imagePath = 'recipes/recipe-1/rich-content/from-url.webp';
    $validator = Validator::make([
        'description' => '<p><img src="/dashboard/recipes/recipe-1/media/'.$imagePath.'"></p>',
    ], [
        'description' => [new MaximumRichContentImages(0, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->fails())->toBeTrue();
});

it('does not count non-image rich content links', function (): void {
    $documentPath = 'recipes/recipe-1/rich-content/notes.pdf';
    $validator = Validator::make([
        'description' => '<p><a href="/dashboard/recipes/recipe-1/media/'.$documentPath.'">Notes</a></p>',
    ], [
        'description' => [new MaximumRichContentImages(0, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

/**
 * @param  array<int, int|string>  $images
 */
function richContentImages(array $images, string $prefix = 'presentation'): string
{
    return collect($images)
        ->map(function (int|string $image) use ($prefix): string {
            $path = is_int($image)
                ? 'recipes/recipe-1/rich-content/'.$prefix.'-'.$image.'.webp'
                : $image;

            return '<img data-id="'.$path.'">';
        })
        ->implode('');
}
