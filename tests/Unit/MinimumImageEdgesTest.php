<?php

use App\Rules\MinimumImageEdges;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('accepts images whose shortest and longest edges meet the minimums', function (int $width, int $height): void {
    $validator = Validator::make([
        'image' => UploadedFile::fake()->image('recipe.jpg', $width, $height),
    ], [
        'image' => [new MinimumImageEdges(300, 500)],
    ]);

    expect($validator->passes())->toBeTrue();
})->with([
    'portrait' => [300, 500],
    'landscape' => [500, 300],
    'square' => [800, 800],
]);

it('rejects images whose shortest or longest edge is too small', function (int $width, int $height): void {
    $validator = Validator::make([
        'image' => UploadedFile::fake()->image('recipe.jpg', $width, $height),
    ], [
        'image' => [new MinimumImageEdges(300, 500)],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('image'))->toContain('300')
        ->and($validator->errors()->first('image'))->toContain('500');
})->with([
    'shortest edge is too small in portrait orientation' => [299, 800],
    'shortest edge is too small in landscape orientation' => [800, 299],
    'longest edge is too small in portrait orientation' => [300, 499],
    'longest edge is too small in landscape orientation' => [499, 300],
]);

it('rejects invalid uploads', function (): void {
    $validator = Validator::make([
        'image' => new UploadedFile(__FILE__, 'recipe.jpg', 'image/jpeg', UPLOAD_ERR_NO_FILE, true),
    ], [
        'image' => [new MinimumImageEdges(300, 500)],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('image'))->toContain('300')
        ->and($validator->errors()->first('image'))->toContain('500');
});

it('rejects non-image files', function (): void {
    $validator = Validator::make([
        'image' => UploadedFile::fake()->create('instructions.txt', 10, 'text/plain'),
    ], [
        'image' => [new MinimumImageEdges(300, 500)],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('image'))->toContain('300')
        ->and($validator->errors()->first('image'))->toContain('500');
});
