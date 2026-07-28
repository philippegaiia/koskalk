<?php

namespace App\Services;

use App\ListingPriceBasis;
use App\MassUnit;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplierListingPriceCalculator
{
    private const int StorageScale = 9;

    private const int GuardScale = 18;

    public function __construct(private readonly MassConverter $massConverter) {}

    /**
     * @return array{
     *   canonical_quantity: string,
     *   price_per_canonical_unit: string,
     *   price_per_kg: string,
     *   total_price: string
     * }
     */
    public function forMass(
        string $netQuantity,
        string $netUnit,
        ListingPriceBasis $basis,
        string $enteredPrice,
        ?string $priceUnit,
    ): array {
        $quantity = $this->positiveDecimal($netQuantity, 'net_quantity');
        $price = $this->positiveDecimal($enteredPrice, 'price_amount');
        $netMassUnit = $this->massUnit($netUnit, 'net_unit');
        $canonicalQuantity = $this->massConverter->toGrams($quantity, $netMassUnit);
        $netUnitGrams = $this->massConverter->toGrams('1', $netMassUnit);

        if ($basis === ListingPriceBasis::TotalPurchaseFormat) {
            if ($priceUnit !== null) {
                $this->invalid('price_unit', 'A total purchase-format price cannot have a price unit.');
            }

            $pricePerCanonicalUnit = bcdiv($price, $canonicalQuantity, self::GuardScale);
            $pricePerKg = bcmul($pricePerCanonicalUnit, '1000', self::GuardScale);

            return [
                'canonical_quantity' => $canonicalQuantity,
                'price_per_canonical_unit' => $this->roundPositive($pricePerCanonicalUnit),
                'price_per_kg' => $this->roundPositive($pricePerKg),
                'total_price' => $this->roundPositive($price),
            ];
        }

        if ($priceUnit === null) {
            $this->invalid('price_unit', 'A per-unit mass price requires a supported mass unit.');
        }

        $priceMassUnit = $this->massUnit($priceUnit, 'price_unit');
        $priceUnitGrams = $this->massConverter->toGrams('1', $priceMassUnit);
        $pricePerCanonicalUnit = bcdiv($price, $priceUnitGrams, self::GuardScale);
        $pricePerKg = bcmul($pricePerCanonicalUnit, '1000', self::GuardScale);
        $totalPrice = bcmul(
            $price,
            bcdiv(bcmul($quantity, $netUnitGrams, self::GuardScale), $priceUnitGrams, self::GuardScale),
            self::GuardScale,
        );

        return [
            'canonical_quantity' => $canonicalQuantity,
            'price_per_canonical_unit' => $this->roundPositive($pricePerCanonicalUnit),
            'price_per_kg' => $this->roundPositive($pricePerKg),
            'total_price' => $this->roundPositive($totalPrice),
        ];
    }

    /**
     * @return array{
     *   canonical_quantity: string,
     *   price_per_canonical_unit: string,
     *   price_per_item: string,
     *   total_price: string
     * }
     */
    public function forCount(
        string $netQuantity,
        ListingPriceBasis $basis,
        string $enteredPrice,
    ): array {
        if (preg_match('/^[1-9]\d*$/', trim($netQuantity)) !== 1) {
            $this->invalid('net_quantity', 'Count quantities must be positive whole numbers.');
        }

        $quantity = trim($netQuantity);
        $price = $this->positiveDecimal($enteredPrice, 'price_amount');
        $canonicalQuantity = bcadd($quantity, '0', self::StorageScale);
        $pricePerItem = $basis === ListingPriceBasis::PerUnit
            ? $price
            : bcdiv($price, $quantity, self::GuardScale);
        $totalPrice = $basis === ListingPriceBasis::PerUnit
            ? bcmul($price, $quantity, self::GuardScale)
            : $price;

        return [
            'canonical_quantity' => $canonicalQuantity,
            'price_per_canonical_unit' => $this->roundPositive($pricePerItem),
            'price_per_item' => $this->roundPositive($pricePerItem),
            'total_price' => $this->roundPositive($totalPrice),
        ];
    }

    private function positiveDecimal(string $value, string $field): string
    {
        $normalized = trim($value);

        if (
            preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1
            || bccomp($normalized, '0', self::GuardScale) <= 0
        ) {
            $this->invalid($field, 'The value must be a positive decimal quantity.');
        }

        return $normalized;
    }

    private function massUnit(string $value, string $field): MassUnit
    {
        try {
            return MassUnit::fromInput(trim($value));
        } catch (InvalidArgumentException) {
            $this->invalid($field, 'The mass unit is unsupported.');
        }
    }

    private function roundPositive(string $value): string
    {
        $roundingIncrement = '0.'.str_repeat('0', self::StorageScale).'5';

        return bcadd(
            bcadd($value, $roundingIncrement, self::StorageScale + 1),
            '0',
            self::StorageScale,
        );
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
