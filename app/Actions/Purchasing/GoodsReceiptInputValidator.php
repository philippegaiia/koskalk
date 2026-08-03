<?php

namespace App\Actions\Purchasing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GoodsReceiptInputValidator
{
    private const int NotesMaxLength = 5000;

    /**
     * @return array{idempotency_key: string, received_at: string, delivery_reference: ?string, notes: ?string}
     */
    public function header(
        string $idempotencyKey,
        ?string $receivedAt,
        ?string $deliveryReference,
        ?string $notes,
        bool $requiresReceiptDate,
    ): array {
        $values = Validator::make([
            'idempotency_key' => trim($idempotencyKey),
            'received_at' => $receivedAt,
            'delivery_reference' => $deliveryReference,
            'notes' => $notes,
        ], [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'received_at' => [$requiresReceiptDate ? 'required' : 'nullable', 'string'],
            'delivery_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:'.self::NotesMaxLength],
        ])->validate();

        $date = $values['received_at'] ?? null;

        if ($date === null) {
            $date = now()->toDateString();
        } else {
            $this->assertCalendarDate($date, 'received_at');
        }

        return [
            'idempotency_key' => $values['idempotency_key'],
            'received_at' => $date,
            'delivery_reference' => $values['delivery_reference'] ?? null,
            'notes' => $values['notes'] ?? null,
        ];
    }

    /** @param array<string, mixed> $input */
    public function line(array $input, int $index): void
    {
        Validator::make([
            'supplier_batch_number' => $input['supplier_batch_number'] ?? null,
            'expires_at' => $input['expires_at'] ?? null,
            'notes' => $input['notes'] ?? null,
        ], [
            'supplier_batch_number' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:'.self::NotesMaxLength],
        ], [], [
            'supplier_batch_number' => "lines.$index.supplier_batch_number",
            'expires_at' => "lines.$index.expires_at",
            'notes' => "lines.$index.notes",
        ])->validate();

        if (isset($input['expires_at'])) {
            $this->assertCalendarDate($input['expires_at'], "lines.$index.expires_at");
        }
    }

    public function movementKey(string $receiptKey, string $lineContext): string
    {
        return 'receipt-line:'.hash('sha256', $receiptKey."\0".$lineContext);
    }

    private function assertCalendarDate(string $date, string $field): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages([
                $field => 'The date must be a valid calendar date in YYYY-MM-DD format.',
            ]);
        }
    }
}
