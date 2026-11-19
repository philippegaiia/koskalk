<?php

namespace App\Services\Production;

use App\Enums\PackedLotSuffix;
use App\Models\ProductionRunNumberSetting;
use App\Models\ProductSize;
use App\Models\Workspace;
use App\Services\Inventory\StockLotCodeAvailability;
use Illuminate\Support\Str;

/**
 * Suggests the lot code of a packed lot from the batch number and the
 * workspace's packed lot suffix, and checks maker-typed codes.
 */
class PackedLotCodeGenerator
{
    public const MAX_LENGTH = 64;

    public const ALLOWED_PATTERN = '/\A[A-Za-z0-9._\/-]+\z/D';

    private const TOKEN_MAX_LENGTH = 32;

    public function __construct(
        private readonly StockLotCodeAvailability $availability,
        private readonly ProductionRunNumberService $numbers,
    ) {}

    public function suggest(Workspace $workspace, string $batchNumber, ProductSize $size): string
    {
        $settings = $this->settings($workspace);
        $suffix = $settings->packed_lot_suffix;
        $nextBatchNumber = $this->numbers->permanentCandidates($settings, 1)[0];
        $token = match ($suffix) {
            PackedLotSuffix::Content => $this->contentToken($size),
            PackedLotSuffix::Sku => $this->skuToken($size),
            PackedLotSuffix::Letter => null,
        };

        for ($serial = 1; ; $serial++) {
            $tail = match (true) {
                $token === null => $this->letters($serial),
                $serial === 1 => $token,
                default => $token.'-'.$serial,
            };
            $code = substr($batchNumber, 0, self::MAX_LENGTH - 1 - strlen($tail)).'-'.$tail;

            if (strcasecmp($code, $nextBatchNumber) !== 0 && ! $this->isTaken($workspace, $code)) {
                return $code;
            }
        }
    }

    /**
     * Lot codes are compared without regard to case, so B-00001-a and
     * B-00001-A cannot both label stock.
     */
    public function isTaken(Workspace $workspace, string $code): bool
    {
        return $this->availability->isTaken($workspace->id, $code);
    }

    public function nextBatchNumber(Workspace $workspace): string
    {
        return $this->numbers->permanentCandidates($this->settings($workspace), 1)[0];
    }

    private function settings(Workspace $workspace): ProductionRunNumberSetting
    {
        return ProductionRunNumberSetting::query()->firstOrNew(['workspace_id' => $workspace->id]);
    }

    public function isWellFormed(string $code): bool
    {
        return strlen($code) <= self::MAX_LENGTH && preg_match(self::ALLOWED_PATTERN, $code) === 1;
    }

    private function letters(int $serial): string
    {
        $letters = '';
        for ($remaining = $serial; $remaining > 0; $remaining = intdiv($remaining - 1, 26)) {
            $letters = chr(65 + ($remaining - 1) % 26).$letters;
        }

        return $letters;
    }

    private function contentToken(ProductSize $size): ?string
    {
        if ($size->declared_content_value === null || $size->declared_content_unit === null) {
            return null;
        }

        $value = (string) $size->declared_content_value;
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $this->token($value.str_replace('_', '', $size->declared_content_unit->value));
    }

    private function skuToken(ProductSize $size): ?string
    {
        return $size->sku === null ? null : $this->token($size->sku);
    }

    private function token(string $value): ?string
    {
        $token = preg_replace('/[^A-Z0-9.\/]+/', '-', Str::upper($value));
        $token = trim(substr(trim((string) $token, '-'), 0, self::TOKEN_MAX_LENGTH), '-');

        return $token === '' ? null : $token;
    }
}
