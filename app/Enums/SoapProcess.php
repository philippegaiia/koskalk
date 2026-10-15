<?php

namespace App\Enums;

/**
 * How a soap Product Family is made, which selects its bench. Bar soap opens
 * the soap bench; liquid paste opens the dedicated liquid soap paste bench.
 * The lye type of a formula never changes its process.
 */
enum SoapProcess: string
{
    case Bar = 'bar';
    case LiquidPaste = 'liquid_paste';

    public function label(): string
    {
        return match ($this) {
            self::Bar => 'Bar soap',
            self::LiquidPaste => 'Liquid soap paste',
        };
    }
}
