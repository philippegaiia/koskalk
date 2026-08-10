<?php

use App\Support\InciName;

it('normalizes INCI names consistently for exact catalogue matching', function (): void {
    expect(InciName::normalize('  Olea   Europaea Fruit Oil '))->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and(InciName::normalize(null))->toBe('');
});
