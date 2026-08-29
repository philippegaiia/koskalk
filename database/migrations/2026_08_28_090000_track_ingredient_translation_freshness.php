<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->string('source_fingerprint', 64)->nullable()->after('info_markdown');
            $table->string('origin', 32)->default('legacy')->after('source_fingerprint');
            $table->string('prompt_version', 100)->nullable()->after('origin');
        });

        DB::table('ingredient_translations')
            ->orderBy('id')
            ->chunkById(100, function (Collection $translations): void {
                $ingredientIds = $translations->pluck('ingredient_id')->filter()->unique()->values();
                $ingredients = DB::table('ingredients')
                    ->whereIn('id', $ingredientIds->all())
                    ->get(['id', 'display_name', 'saponification_name', 'info_markdown'])
                    ->keyBy('id');

                foreach ($translations as $translation) {
                    $ingredient = $ingredients->get($translation->ingredient_id);
                    if ($ingredient === null) {
                        continue;
                    }

                    DB::table('ingredient_translations')
                        ->where('id', $translation->id)
                        ->update([
                            'source_fingerprint' => $this->fingerprint($ingredient),
                            'origin' => 'legacy',
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->dropColumn(['source_fingerprint', 'origin', 'prompt_version']);
        });
    }

    private function fingerprint(object $ingredient): string
    {
        return hash('sha256', json_encode([
            'display_name' => $this->normalize($ingredient->display_name),
            'saponification_name' => $this->normalize($ingredient->saponification_name),
            'info_markdown' => $this->normalize($ingredient->info_markdown),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", trim($value));

        return $value === '' ? null : $value;
    }
};
