<?php

namespace App;

use Illuminate\Contracts\Support\Htmlable;

enum SoapFattyAcid: string
{
    case Caprylic = 'caprylic';
    case Capric = 'capric';
    case Lauric = 'lauric';
    case Myristic = 'myristic';
    case Palmitic = 'palmitic';
    case Palmitoleic = 'palmitoleic';
    case Stearic = 'stearic';
    case Ricinoleic = 'ricinoleic';
    case Oleic = 'oleic';
    case Linoleic = 'linoleic';
    case Linolenic = 'linolenic';
    case GammaLinolenic = 'gamma_linolenic';
    case Punicic = 'punicic';
    case Arachidic = 'arachidic';
    case Gondoic = 'gondoic';
    case Behenic = 'behenic';
    case Erucic = 'erucic';
    case Lignoceric = 'lignoceric';
    case Nervonic = 'nervonic';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Caprylic => 'Caprylic',
            self::Capric => 'Capric',
            self::Lauric => 'Lauric',
            self::Myristic => 'Myristic',
            self::Palmitic => 'Palmitic',
            self::Palmitoleic => 'Palmitoleic',
            self::Stearic => 'Stearic',
            self::Ricinoleic => 'Ricinoleic',
            self::Oleic => 'Oleic',
            self::Linoleic => 'Linoleic',
            self::Linolenic => 'Linolenic',
            self::GammaLinolenic => 'Gamma-Linolenic',
            self::Punicic => 'Punicic',
            self::Arachidic => 'Arachidic',
            self::Gondoic => 'Gondoic',
            self::Behenic => 'Behenic',
            self::Erucic => 'Erucic',
            self::Lignoceric => 'Lignoceric',
            self::Nervonic => 'Nervonic',
        };
    }

    public function iodineFactor(): float
    {
        return match ($this) {
            self::Caprylic => 0.0,
            self::Capric => 0.0,
            self::Lauric => 0.0,
            self::Myristic => 0.0,
            self::Palmitic => 0.0,
            self::Palmitoleic => 0.995,
            self::Stearic => 0.0,
            self::Ricinoleic => 0.901,
            self::Oleic => 0.860,
            self::Linoleic => 1.732,
            self::Linolenic => 2.616,
            self::GammaLinolenic => 2.616,
            self::Punicic => 2.616,
            self::Arachidic => 0.0,
            self::Gondoic => 0.786,
            self::Behenic => 0.0,
            self::Erucic => 0.723,
            self::Lignoceric => 0.0,
            self::Nervonic => 0.662,
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
