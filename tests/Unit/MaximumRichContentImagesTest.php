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

it('accepts two temporary image identities in TipTap state', function (): void {
    $validator = Validator::make([
        'description' => tipTapRichContent([
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d1',
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d2',
        ]),
    ], [
        'description' => [new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects three temporary image identities in TipTap state', function (): void {
    $validator = Validator::make([
        'description' => tipTapRichContent([
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d1',
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d2',
            '018fa7f2-91aa-74a5-a665-18f8f3bf42d3',
        ]),
    ], [
        'description' => [new MaximumRichContentImages(2, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('description'))->toBe('The product description may contain up to 2 images.');
});

it('counts a repeated temporary TipTap image identity once', function (): void {
    $temporaryId = '018fa7f2-91aa-74a5-a665-18f8f3bf42d1';
    $validator = Validator::make([
        'description' => tipTapRichContent([$temporaryId, $temporaryId]),
    ], [
        'description' => [new MaximumRichContentImages(1, 'workbench.instructions.description_image_limit')],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('ignores non-image TipTap nodes and link marks', function (): void {
    $validator = Validator::make([
        'description' => [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'marks' => [[
                                'type' => 'link',
                                'attrs' => [
                                    'href' => '/dashboard/recipes/recipe-1/media/recipes/recipe-1/rich-content/notes.pdf',
                                    'target' => null,
                                    'rel' => null,
                                    'class' => null,
                                ],
                            ]],
                            'text' => 'Manufacturing notes',
                        ],
                    ],
                ],
                [
                    'type' => 'horizontalRule',
                ],
            ],
        ],
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

/**
 * @param  array<int, string>  $temporaryIds
 * @return array{type: string, content: array<int, array<string, mixed>>}
 */
function tipTapRichContent(array $temporaryIds): array
{
    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => collect($temporaryIds)
                ->map(fn (string $temporaryId): array => [
                    'type' => 'image',
                    'attrs' => [
                        'src' => '/livewire/preview-file/'.$temporaryId,
                        'alt' => null,
                        'title' => null,
                        'id' => $temporaryId,
                        'width' => null,
                        'height' => null,
                    ],
                ])
                ->all(),
        ]],
    ];
}
