<?php

namespace App\Services;

use App\DecimalStringFormatter;
use App\ListingPriceBasis;
use App\Models\SupplierListing;
use App\Models\Workspace;

class SupplierListingPricePresentation
{
    public function __construct(
        private readonly DecimalStringFormatter $decimalStringFormatter,
        private readonly MassConverter $massConverter,
        private readonly SupplierListingPriceCalculator $priceCalculator,
    ) {}

    /**
     * @return array{basis_label: string, entered_price: string, derived_price: string, total_price: string}
     */
    public function present(SupplierListing $listing, Workspace $workspace): array
    {
        $currency = $listing->currency;

        if ($listing->ingredient_id !== null) {
            $prices = $this->priceCalculator->forMass(
                $listing->net_quantity,
                $listing->net_unit,
                $listing->price_basis,
                $listing->price_amount,
                $listing->price_unit,
            );
            $displayUnit = $workspace->mass_display_system->priceUnit()->value;
            $pricePerDisplayUnit = bcmul(
                $prices['price_per_canonical_unit'],
                $this->massConverter->toGrams('1', $displayUnit),
                9,
            );

            return $this->formatted(
                $listing,
                $currency,
                $this->currencyAmount($currency, $pricePerDisplayUnit).' / '.$displayUnit,
                $this->currencyAmount($currency, $prices['total_price']).' total',
            );
        }

        $prices = $this->priceCalculator->forCount(
            rtrim(rtrim($listing->net_quantity, '0'), '.'),
            $listing->price_basis,
            $listing->price_amount,
        );

        return $this->formatted(
            $listing,
            $currency,
            $this->currencyAmount($currency, $prices['price_per_item']).' / item',
            $this->currencyAmount($currency, $prices['total_price']).' total',
        );
    }

    /**
     * @return array{basis_label: string, entered_price: string, derived_price: string, total_price: string}
     */
    private function formatted(
        SupplierListing $listing,
        string $currency,
        string $unitPrice,
        string $totalPrice,
    ): array {
        if ($listing->price_basis === ListingPriceBasis::TotalPurchaseFormat) {
            return [
                'basis_label' => 'Total purchase-format price',
                'entered_price' => $this->currencyAmount($currency, $listing->price_amount),
                'derived_price' => $unitPrice,
                'total_price' => $totalPrice,
            ];
        }

        return [
            'basis_label' => 'Price per unit of measure',
            'entered_price' => $this->currencyAmount($currency, $listing->price_amount).' / '.($listing->ingredient_id !== null ? $listing->price_unit : 'item'),
            'derived_price' => $totalPrice,
            'total_price' => $totalPrice,
        ];
    }

    private function formatAmount(string $amount): string
    {
        return $this->decimalStringFormatter->toFixed($amount);
    }

    private function currencyAmount(string $currency, string $amount): string
    {
        return $currency.' '.$this->formatAmount($amount);
    }
}
