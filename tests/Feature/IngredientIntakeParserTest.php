<?php

use App\Data\IngredientIntakeRow;
use App\Services\IngredientIntake\IngredientIntakeParser;
use Illuminate\Validation\ValidationException;

it('accepts current name only, inci name only, and both identities', function (): void {
    $contents = <<<'CSV'
current_name,inci_name
Coconut oil,
,Cocos Nucifera Oil
Shea butter,Butyrospermum Parkii Butter
CSV;

    $rows = app(IngredientIntakeParser::class)->parsePasted($contents);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBeInstanceOf(IngredientIntakeRow::class)
        ->and($rows[0]->originalCurrentName)->toBe('Coconut oil')
        ->and($rows[0]->normalizedCurrentName)->toBe('coconut oil')
        ->and($rows[0]->normalizedInciName)->toBeNull()
        ->and($rows[1]->originalCurrentName)->toBeNull()
        ->and($rows[1]->originalInciName)->toBe('Cocos Nucifera Oil')
        ->and($rows[1]->normalizedInciName)->toBe('cocos nucifera oil')
        ->and($rows[2]->rowNumber)->toBe(4);
});

it('accepts one ten and seventy identities with the same rules', function (int $count): void {
    $rows = collect(range(1, $count))
        ->map(fn (int $index): string => "Ingredient {$index}\t")
        ->prepend("current_name\tinci_name")
        ->implode("\n");

    expect(app(IngredientIntakeParser::class)->parsePasted($rows))->toHaveCount($count);
})->with([1, 10, 70]);

it('removes blank rows while retaining physical source row numbers', function (): void {
    $contents = "current_name\tinci_name\n\n\t\n  Cocoa butter  \tTheobroma Cacao Seed Butter\n\nJojoba oil\t\n";

    $rows = app(IngredientIntakeParser::class)->parsePasted($contents);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rowNumber)->toBe(4)
        ->and($rows[0]->normalizedCurrentName)->toBe('cocoa butter')
        ->and($rows[0]->originalCurrentName)->toBe('  Cocoa butter  ')
        ->and($rows[1]->rowNumber)->toBe(6);
});

it('accepts case insensitive header aliases and quoted commas in csv', function (): void {
    $contents = <<<'CSV'
NAME,"INCI NAME"
"Oil, refined","Cocos Nucifera (Coconut) Oil"
CSV;

    $path = tempnam(sys_get_temp_dir(), 'ingredient-intake-');
    file_put_contents($path, $contents);

    try {
        $rows = app(IngredientIntakeParser::class)->parseCsvFile($path);
    } finally {
        unlink($path);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->originalCurrentName)->toBe('Oil, refined')
        ->and($rows[0]->normalizedInciName)->toBe('cocos nucifera (coconut) oil');
});

it('normalizes unicode and preserves identity qualifiers', function (): void {
    $contents = "current_name,inci_name\nCafé\u{00A0}oil,  Cocos Nucifera (Coconut) Oil CI 12345  \n";

    $row = app(IngredientIntakeParser::class)->parsePasted($contents)[0];

    expect($row->normalizedCurrentName)->toBe('café oil')
        ->and($row->normalizedInciName)->toBe('cocos nucifera (coconut) oil ci 12345');
});

it('returns row-specific errors for malformed rows', function (): void {
    $contents = "current_name,inci_name\nCoconut oil,\nShea butter,Butyrospermum Parkii Butter,unexpected\n";

    expect(fn () => app(IngredientIntakeParser::class)->parsePasted($contents))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(IngredientIntakeParser::class)->parsePasted($contents))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('rows.3.columns');
        });
});

it('rejects unsupported or duplicate headers', function (string $header): void {
    expect(fn () => app(IngredientIntakeParser::class)->parsePasted("{$header}\nCoconut oil\n"))
        ->toThrow(ValidationException::class);
})->with([
    'display_name',
    'current_name,name',
]);
