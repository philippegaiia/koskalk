<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;

class LocalizedDecimalInput extends TextInput
{
    public const KEEP_DIGITS_ON_INPUT = "if (\$event.data && /[^0-9.,]/.test(\$event.data)) { \$event.preventDefault(); let clean = \$event.data.replace(/[^0-9.,]/g, ''); if (clean) document.execCommand('insertText', false, clean) }";

    public const KEEP_DIGITS_ON_PASTE = "\$event.preventDefault(); let clean = (\$event.clipboardData?.getData('text') ?? '').replace(/[^0-9.,]/g, ''); if (clean) document.execCommand('insertText', false, clean)";

    protected function setUp(): void
    {
        parent::setUp();

        $this->type('text')
            ->inputMode('decimal')
            ->rule('numeric')
            ->mutateStateForValidationUsing(
                static fn (mixed $state): mixed => static::normalizedDecimalState($state),
            )
            ->dehydrateStateUsing(
                static fn (mixed $state): mixed => static::normalizedDecimalState($state),
            );
    }

    /**
     * Block every keystroke or pasted character other than digits and a decimal
     * point or comma; a paste keeps only its numeric characters. Filament renders
     * these values unescaped, so the expressions must never contain double quotes.
     */
    public function digitsAndSeparatorsOnly(): static
    {
        return $this->extraInputAttributes([
            'x-on:beforeinput' => self::KEEP_DIGITS_ON_INPUT,
            'x-on:paste' => self::KEEP_DIGITS_ON_PASTE,
        ], merge: true);
    }

    private static function normalizedDecimalState(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        return NumberLocale::parseDecimalInput($state) ?? $state;
    }
}
