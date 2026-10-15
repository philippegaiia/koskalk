<?php

namespace App\Enums;

/**
 * Acids that neutralize the free KOH left in a liquid soap paste.
 *
 * Citric and lactic acid turn free KOH into a potassium salt. A fatty acid
 * turns it into more soap, so its capacity comes from its own KOH SAP value.
 */
enum LiquidSoapNeutralizer: string
{
    case CitricAcidAnhydrous = 'citric_acid_anhydrous';
    case CitricAcidMonohydrate = 'citric_acid_monohydrate';
    case LacticAcid = 'lactic_acid';
    case FattyAcid = 'fatty_acid';

    public const KOH_MOLAR_MASS = '56.1056';

    public const WATER_MOLAR_MASS = '18.0153';

    private const SCALE = 18;

    /**
     * Grams of KOH neutralized by one gram of acid, from molar masses and
     * the number of acid groups. Fatty acids use their KOH SAP value instead.
     */
    public function kohCapacity(): ?string
    {
        return match ($this) {
            self::CitricAcidAnhydrous => bcdiv(bcmul('3', self::KOH_MOLAR_MASS, self::SCALE), '192.1235', self::SCALE),
            self::CitricAcidMonohydrate => bcdiv(bcmul('3', self::KOH_MOLAR_MASS, self::SCALE), '210.1388', self::SCALE),
            self::LacticAcid => bcdiv(self::KOH_MOLAR_MASS, '90.0779', self::SCALE),
            self::FattyAcid => null,
        };
    }

    /**
     * Crystal water released per gram of acid that reacts.
     */
    public function hydrateWaterFraction(): string
    {
        return match ($this) {
            self::CitricAcidMonohydrate => bcdiv(self::WATER_MOLAR_MASS, '210.1388', self::SCALE),
            default => '0',
        };
    }

    public function label(): string
    {
        return __('ingredients.liquid_soap_neutralizers.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $neutralizer): array => [$neutralizer->value => $neutralizer->label()])
            ->all();
    }

    /**
     * INCI name of the potassium salt the acid becomes; a fatty acid becomes
     * soap and takes its own saponified name instead.
     */
    public function saltInciName(): ?string
    {
        return match ($this) {
            self::CitricAcidAnhydrous, self::CitricAcidMonohydrate => 'POTASSIUM CITRATE',
            self::LacticAcid => 'POTASSIUM LACTATE',
            self::FattyAcid => null,
        };
    }

    public function formsSoap(): bool
    {
        return $this === self::FattyAcid;
    }
}
