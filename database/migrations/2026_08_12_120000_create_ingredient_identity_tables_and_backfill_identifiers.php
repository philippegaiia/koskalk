<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('scheme', 32);
            $table->string('value', 64);
            $table->string('normalized_value', 64);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['ingredient_id', 'scheme', 'normalized_value']);
            $table->index(['scheme', 'normalized_value']);
        });

        Schema::create('ingredient_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 16)->default('und');
            $table->string('name', 150);
            $table->string('normalized_name', 150);
            $table->string('kind', 32);
            $table->timestamps();

            $table->unique(['ingredient_id', 'locale', 'normalized_name']);
            $table->index(['locale', 'normalized_name']);
        });

        DB::table('ingredients')
            ->select(['id', 'cas_number', 'ec_number'])
            ->orderBy('id')
            ->chunkById(250, function ($ingredients): void {
                foreach ($ingredients as $ingredient) {
                    $this->backfillIdentifierValues((int) $ingredient->id, 'cas', $ingredient->cas_number);
                    $this->backfillIdentifierValues((int) $ingredient->id, 'ec', $ingredient->ec_number);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_aliases');
        Schema::dropIfExists('ingredient_identifiers');
    }

    private function backfillIdentifierValues(int $ingredientId, string $scheme, ?string $value): void
    {
        $values = preg_split('/[,;]+/u', (string) $value) ?: [];
        $seen = [];
        $isPrimary = true;

        foreach ($values as $candidate) {
            $trimmed = trim($candidate);
            $normalized = mb_strtolower($trimmed);

            if ($trimmed === '' || isset($seen[$normalized])) {
                continue;
            }

            DB::table('ingredient_identifiers')->insert([
                'ingredient_id' => $ingredientId,
                'scheme' => $scheme,
                'value' => $trimmed,
                'normalized_value' => $normalized,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $seen[$normalized] = true;
            $isPrimary = false;
        }
    }
};
