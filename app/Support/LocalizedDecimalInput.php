<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;

class LocalizedDecimalInput extends TextInput
{
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

    private static function normalizedDecimalState(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        return NumberLocale::parseDecimalInput($state) ?? $state;
    }
}
