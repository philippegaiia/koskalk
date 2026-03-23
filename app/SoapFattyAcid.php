<?php

namespace App;

use Illuminate\Contracts\Support\Htmlable;

enum SoapFattyAcid: string
{
    case Lauric = 'lauric';
    case Myristic = 'myristic';
    case Palmitic = 'palmitic';
    case Stearic = 'stearic';
    case Ricinoleic = 'ricinoleic';
    case Oleic = 'oleic';
    case Linoleic = 'linoleic';
    case Linolenic = 'linolenic';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Lauric => 'Lauric',
            self::Myristic => 'Myristic',
            self::Palmitic => 'Palmitic',
            self::Stearic => 'Stearic',
            self::Ricinoleic => 'Ricinoleic',
            self::Oleic => 'Oleic',
            self::Linoleic => 'Linoleic',
            self::Linolenic => 'Linolenic',
        };
    }

    public function iodineFactor(): float
    {
        return match ($this) {
            self::Lauric => 0.0,
            self::Myristic => 0.0,
            self::Palmitic => 0.0,
            self::Stearic => 0.0,
            self::Ricinoleic => 0.901,
            self::Oleic => 0.860,
            self::Linoleic => 1.732,
            self::Linolenic => 2.616,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function coreSet(): array
    {
        return self::cases();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::coreSet())
            ->mapWithKeys(fn (self $fattyAcid): array => [$fattyAcid->value => (string) $fattyAcid->getLabel()])
            ->all();
    }
}
