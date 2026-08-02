<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\ProcurementStage;
use App\Support\NumberLocale;

class ProcurementDocumentFormatter
{
    public function emailText(PurchaseOrder $order): string
    {
        $isQuotation = $order->stage === ProcurementStage::Quotation
            || ($order->purchase_order_snapshot === null && $order->quotation_snapshot !== null);
        $snapshot = $isQuotation ? $order->quotation_snapshot : $order->purchase_order_snapshot;
        $reference = $isQuotation ? $order->quotation_reference : $order->reference;
        $title = $isQuotation ? 'Quotation request' : 'Purchase order';
        $lines = [
            $title.' '.$reference,
            '',
            'Hello '.($snapshot['supplier']['name'] ?? $order->supplier?->name).',',
            '',
            $isQuotation
                ? 'Please quote the following purchase formats:'
                : 'Please process the following order:',
            '',
        ];

        foreach ($snapshot['lines'] ?? [] as $line) {
            $description = $line['ordered_purchase_formats'].' × '.$line['purchase_format'].' — '.$line['catalogue_name'];

            if (($line['supplier_sku'] ?? null) !== null) {
                $description .= ' ('.$line['supplier_sku'].')';
            }

            if (! $isQuotation && ($line['price'] ?? null) !== null) {
                $description .= ' — '.$this->money($line['price']).' '.$line['currency'].' each';
            }

            $lines[] = $description;
        }

        if (! $isQuotation && isset($snapshot['total'])) {
            $currency = $snapshot['currency'] ?? ($snapshot['lines'][0]['currency'] ?? $order->currency);
            $lines[] = '';
            $lines[] = 'Total: '.$this->money($snapshot['total']).' '.$currency;
        }

        if (($snapshot['notes'] ?? null) !== null) {
            $lines[] = '';
            $lines[] = $snapshot['notes'];
        }

        return implode("\n", $lines);
    }

    private function money(string $amount): string
    {
        return NumberLocale::formatAdaptiveDecimal($amount, 2, 4);
    }
}
