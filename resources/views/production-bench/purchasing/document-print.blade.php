<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isQuotation ? 'Quotation request' : 'Purchase order' }} {{ $snapshot['reference'] }}</title>
    <style>
        body { margin: 0; color: #26231f; font: 14px/1.5 ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 880px; margin: 0 auto; padding: 48px; }
        header, .addresses { display: flex; justify-content: space-between; gap: 32px; }
        h1 { margin: 0; font-size: 28px; }
        h2 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #6b655d; }
        .addresses { margin: 36px 0; }
        .addresses > section { width: 50%; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #ded9d1; text-align: left; vertical-align: top; }
        th:last-child, td:last-child { text-align: right; }
        .totals { width: 320px; margin: 24px 0 0 auto; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .total { border-top: 1px solid #26231f; margin-top: 6px; padding-top: 10px !important; font-weight: 700; }
        .print { position: fixed; right: 24px; bottom: 24px; padding: 10px 16px; border: 0; border-radius: 8px; background: #26231f; color: white; cursor: pointer; }
        @media print { main { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>{{ $isQuotation ? 'Quotation request' : 'Purchase order' }}</h1>
            <p>{{ $snapshot['reference'] }}</p>
        </div>
        <p>{{ $isQuotation ? ($snapshot['requested_at'] ?? '') : ($snapshot['issued_at'] ?? '') }}</p>
    </header>

    <div class="addresses">
        <section>
            <h2>Supplier</h2>
            <strong>{{ $snapshot['supplier']['name'] }}</strong><br>
            {{ $snapshot['supplier']['email'] ?? '' }}
        </section>
        @if (! $isQuotation && ! empty($snapshot['delivery_address']))
            <section>
                <h2>Deliver to</h2>
                <strong>{{ $snapshot['delivery_address']['name'] ?? '' }}</strong><br>
                {{ $snapshot['delivery_address']['city'] ?? '' }} {{ $snapshot['delivery_address']['country_code'] ?? '' }}
            </section>
        @endif
    </div>

    <table>
        <thead><tr><th>Item</th><th>Purchase format</th><th>Quantity</th>@unless($isQuotation)<th>Price</th><th>Line total</th>@endunless</tr></thead>
        <tbody>
        @foreach ($snapshot['lines'] as $line)
            <tr>
                <td>{{ $line['catalogue_name'] }}@if($line['supplier_sku'] ?? null)<br><small>{{ $line['supplier_sku'] }}</small>@endif</td>
                <td>{{ $line['purchase_format'] }}</td>
                <td>{{ $line['ordered_purchase_formats'] }}</td>
                @unless($isQuotation)
                    <td>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line['price'], 2, 4) }} {{ $line['currency'] }}</td>
                    <td>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line['expected_cost'], 2, 4) }} {{ $line['currency'] }}</td>
                @endunless
            </tr>
        @endforeach
        </tbody>
    </table>

    @unless($isQuotation)
        <div class="totals">
            <div><span>Subtotal</span><span>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($snapshot['subtotal'], 2, 4) }} {{ $snapshot['currency'] ?? $snapshot['lines'][0]['currency'] }}</span></div>
            <div><span>Shipping</span><span>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($snapshot['shipping'], 2, 4) }}</span></div>
            <div><span>Discount</span><span>−{{ \App\Support\NumberLocale::formatAdaptiveDecimal($snapshot['discount'], 2, 4) }}</span></div>
            <div><span>Tax</span><span>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($snapshot['tax'], 2, 4) }}</span></div>
            <div class="total"><span>Total</span><span>{{ \App\Support\NumberLocale::formatAdaptiveDecimal($snapshot['total'], 2, 4) }} {{ $snapshot['currency'] ?? $snapshot['lines'][0]['currency'] }}</span></div>
        </div>
    @endunless

    @if ($snapshot['notes'] ?? null)<p>{{ $snapshot['notes'] }}</p>@endif
</main>
<button class="print" type="button" onclick="window.print()">Print / Save PDF</button>
</body>
</html>
