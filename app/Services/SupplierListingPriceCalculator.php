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

    private const string StorageLimit = '100000000000';

    private const string StorageValidationMessage = 'The value must fit a positive DECIMAL(20,9) amount.';

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
        $quantity = $this->normalizeMassQuantity($netQuantity);
        $price = $this->storageDecimal(
            $this->positiveDecimal($enteredPrice, 'price_amount'),
            'price_amount',
        );
        $netMassUnit = $this->massUnit($netUnit, 'net_unit');
        $canonicalQuantity = $this->storageDecimal(
            $this->massConverter->toGrams($quantity, $netMassUnit),
            'net_quantity',
        );

        if ($basis === ListingPriceBasis::TotalPurchaseFormat) {
            if ($priceUnit !== null) {
                $this->invalid('price_unit', 'A total purchase-format price cannot have a price unit.');
            }

            $calculatedPricePerCanonicalUnit = bcdiv($price, $canonicalQuantity, self::GuardScale);
            $pricePerCanonicalUnit = $this->storageDecimal(
                $calculatedPricePerCanonicalUnit,
                'price_per_canonical_unit',
            );
            $pricePerKg = $this->storageDecimal(
                bcmul($calculatedPricePerCanonicalUnit, '1000', self::GuardScale),
                'price_per_kg',
            );
            $totalPrice = $this->storageDecimal($price, 'total_price');

            return [
                'canonical_quantity' => $canonicalQuantity,
                'price_per_canonical_unit' => $pricePerCanonicalUnit,
                'price_per_kg' => $pricePerKg,
                'total_price' => $totalPrice,
            ];
        }

        if ($priceUnit === null) {
            $this->invalid('price_unit', 'A per-unit mass price requires a supported mass unit.');
        }

        $priceMassUnit = $this->massUnit($priceUnit, 'price_unit');
        $priceUnitGrams = $this->massConverter->toGrams('1', $priceMassUnit);
        $calculatedPricePerCanonicalUnit = bcdiv($price, $priceUnitGrams, self::GuardScale);
        $pricePerCanonicalUnit = $this->storageDecimal(
            $calculatedPricePerCanonicalUnit,
            'price_per_canonical_unit',
        );
        $pricePerKg = $this->storageDecimal(
            bcmul($calculatedPricePerCanonicalUnit, '1000', self::GuardScale),
            'price_per_kg',
        );
        $totalPrice = $this->storageDecimal(
            bcmul(
                $price,
                bcdiv($canonicalQuantity, $priceUnitGrams, self::GuardScale),
                self::GuardScale,
            ),
            'total_price',
        );

        return [
            'canonical_quantity' => $canonicalQuantity,
            'price_per_canonical_unit' => $pricePerCanonicalUnit,
            'price_per_kg' => $pricePerKg,
            'total_price' => $totalPrice,
        ];
    }

    public function normalizeMassQuantity(string $netQuantity): string
    {
        return $this->storageDecimal(
            $this->positiveDecimal($netQuantity, 'net_quantity'),
            'net_quantity',
        );
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
        $normalizedQuantity = trim($netQuantity);

        if (preg_match('/^[1-9]\d*(?:\.0+)?$/', $normalizedQuantity) !== 1) {
            $this->invalid('net_quantity', 'Count quantities must be positive whole numbers.');
        }

        $quantity = explode('.', $normalizedQuantity, 2)[0];
        $price = $this->storageDecimal(
            $this->positiveDecimal($enteredPrice, 'price_amount'),
            'price_amount',
        );
        $canonicalQuantity = $this->storageDecimal(
            bcadd($quantity, '0', self::StorageScale),
            'net_quantity',
        );
        $calculatedPricePerItem = $basis === ListingPriceBasis::PerUnit
            ? $price
            : bcdiv($price, $quantity, self::GuardScale);
        $pricePerItem = $this->storageDecimal($calculatedPricePerItem, 'price_per_item');
        $pricePerCanonicalUnit = $this->storageDecimal(
            $calculatedPricePerItem,
            'price_per_canonical_unit',
        );
        $totalPrice = $this->storageDecimal(
            $basis === ListingPriceBasis::PerUnit
                ? bcmul($price, $quantity, self::GuardScale)
                : $price,
            'total_price',
        );

        return [
            'canonical_quantity' => $canonicalQuantity,
            'price_per_canonical_unit' => $pricePerCanonicalUnit,
            'price_per_item' => $pricePerItem,
            'total_price' => $totalPrice,
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

    private function storageDecimal(string $value, string $field): string
    {
        $normalized = trim($value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            $this->invalid($field, self::StorageValidationMessage);
        }

        $storedValue = $this->roundPositive($normalized);

        if (
            bccomp($storedValue, '0', self::StorageScale) <= 0
            || bccomp($storedValue, self::StorageLimit, self::StorageScale) >= 0
        ) {
            $this->invalid($field, self::StorageValidationMessage);
        }

        return $storedValue;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
