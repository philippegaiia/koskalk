<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string CompactIdAlphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-_';

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('code', 16)->nullable();
        });

        DB::table('suppliers')->orderBy('id')->each(function (object $supplier): void {
            DB::table('suppliers')
                ->where('id', $supplier->id)
                ->update(['code' => $this->backfilledCode((string) $supplier->id)]);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('code', 16)->nullable(false)->change();
            $table->index(['workspace_id', 'code'], 'suppliers_workspace_code_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX suppliers_workspace_lower_code_unique ON suppliers (workspace_id, LOWER(code))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS suppliers_workspace_lower_code_unique');
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex('suppliers_workspace_code_index');
            $table->dropColumn('code');
        });
    }

    private function backfilledCode(string $supplierId): string
    {
        $code = "SUP-{$supplierId}";

        return mb_strlen($code) <= 16
            ? $code
            : 'S-'.$this->compactId($supplierId);
    }

    private function compactId(string $value): string
    {
        $result = '';

        do {
            [$value, $remainder] = $this->divide($value, mb_strlen(self::CompactIdAlphabet));
            $result = self::CompactIdAlphabet[$remainder].$result;
        } while ($value !== '0');

        return $result;
    }

    /** @return array{0: string, 1: int} */
    private function divide(string $value, int $divisor): array
    {
        $quotient = '';
        $remainder = 0;

        foreach (str_split($value) as $digit) {
            $current = ($remainder * 10) + (int) $digit;
            $quotientDigit = intdiv($current, $divisor);
            $remainder = $current % $divisor;

            if ($quotient !== '' || $quotientDigit > 0) {
                $quotient .= $quotientDigit;
            }
        }

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }
};
