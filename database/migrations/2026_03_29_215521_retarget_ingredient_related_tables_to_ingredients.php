<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ingredient_version_fatty_acids', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ingredient_allergen_entries', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $ingredientIdsByVersion = DB::table('ingredient_versions')
            ->pluck('ingredient_id', 'id');

        DB::table('ingredient_sap_profiles')
            ->select(['id', 'ingredient_version_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $profile) use ($ingredientIdsByVersion): void {
                DB::table('ingredient_sap_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'ingredient_id' => $ingredientIdsByVersion->get($profile->ingredient_version_id),
                    ]);
            });

        DB::table('ingredient_version_fatty_acids')
            ->select(['id', 'ingredient_version_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $entry) use ($ingredientIdsByVersion): void {
                DB::table('ingredient_version_fatty_acids')
                    ->where('id', $entry->id)
                    ->update([
                        'ingredient_id' => $ingredientIdsByVersion->get($entry->ingredient_version_id),
                    ]);
            });

        DB::table('ingredient_allergen_entries')
            ->select(['id', 'ingredient_version_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $entry) use ($ingredientIdsByVersion): void {
                DB::table('ingredient_allergen_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'ingredient_id' => $ingredientIdsByVersion->get($entry->ingredient_version_id),
                    ]);
            });

        DB::table('ifra_certificates')
            ->select(['id', 'ingredient_version_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $certificate) use ($ingredientIdsByVersion): void {
                DB::table('ifra_certificates')
                    ->where('id', $certificate->id)
                    ->update([
                        'ingredient_id' => $ingredientIdsByVersion->get($certificate->ingredient_version_id),
                    ]);
            });

        DB::table('recipe_items')
            ->select(['id', 'ingredient_version_id'])
            ->whereNotNull('ingredient_version_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $item) use ($ingredientIdsByVersion): void {
                DB::table('recipe_items')
                    ->where('id', $item->id)
                    ->update([
                        'ingredient_id' => $ingredientIdsByVersion->get($item->ingredient_version_id),
                    ]);
            });

        $preferredVersionIdsByIngredient = $this->preferredVersionIdsByIngredient();

        $this->collapseVersionScopedDuplicates(
            'ingredient_sap_profiles',
            [],
            $preferredVersionIdsByIngredient,
        );

        $this->collapseVersionScopedDuplicates(
            'ingredient_version_fatty_acids',
            ['fatty_acid_id'],
            $preferredVersionIdsByIngredient,
        );

        $this->collapseVersionScopedDuplicates(
            'ingredient_allergen_entries',
            ['allergen_id'],
            $preferredVersionIdsByIngredient,
        );

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropUnique(['ingredient_version_id']);
            $table->dropConstrainedForeignId('ingredient_version_id');
            $table->unique('ingredient_id');
        });

        Schema::table('ingredient_version_fatty_acids', function (Blueprint $table) {
            $table->dropUnique(['ingredient_version_id', 'fatty_acid_id']);
            $table->dropConstrainedForeignId('ingredient_version_id');
            $table->unique(['ingredient_id', 'fatty_acid_id']);
        });

        Schema::table('ingredient_allergen_entries', function (Blueprint $table) {
            $table->dropUnique(['ingredient_version_id', 'allergen_id']);
            $table->dropConstrainedForeignId('ingredient_version_id');
            $table->unique(['ingredient_id', 'allergen_id']);
        });

        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_version_id');
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->foreignId('ingredient_version_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ingredient_version_fatty_acids', function (Blueprint $table) {
            $table->foreignId('ingredient_version_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ingredient_allergen_entries', function (Blueprint $table) {
            $table->foreignId('ingredient_version_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->foreignId('ingredient_version_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('ingredient_version_id')->nullable()->after('ingredient_id')->constrained()->cascadeOnDelete();
        });

        $currentVersionIdsByIngredient = DB::table('ingredient_versions')
            ->orderBy('ingredient_id')
            ->orderByDesc('is_current')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn ($versions): mixed => $versions->first()?->id);

        DB::table('ingredient_sap_profiles')
            ->select(['id', 'ingredient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $profile) use ($currentVersionIdsByIngredient): void {
                DB::table('ingredient_sap_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'ingredient_version_id' => $currentVersionIdsByIngredient->get($profile->ingredient_id),
                    ]);
            });

        DB::table('ingredient_version_fatty_acids')
            ->select(['id', 'ingredient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $entry) use ($currentVersionIdsByIngredient): void {
                DB::table('ingredient_version_fatty_acids')
                    ->where('id', $entry->id)
                    ->update([
                        'ingredient_version_id' => $currentVersionIdsByIngredient->get($entry->ingredient_id),
                    ]);
            });

        DB::table('ingredient_allergen_entries')
            ->select(['id', 'ingredient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $entry) use ($currentVersionIdsByIngredient): void {
                DB::table('ingredient_allergen_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'ingredient_version_id' => $currentVersionIdsByIngredient->get($entry->ingredient_id),
                    ]);
            });

        DB::table('ifra_certificates')
            ->select(['id', 'ingredient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $certificate) use ($currentVersionIdsByIngredient): void {
                DB::table('ifra_certificates')
                    ->where('id', $certificate->id)
                    ->update([
                        'ingredient_version_id' => $currentVersionIdsByIngredient->get($certificate->ingredient_id),
                    ]);
            });

        DB::table('recipe_items')
            ->select(['id', 'ingredient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $item) use ($currentVersionIdsByIngredient): void {
                DB::table('recipe_items')
                    ->where('id', $item->id)
                    ->update([
                        'ingredient_version_id' => $currentVersionIdsByIngredient->get($item->ingredient_id),
                    ]);
            });

        Schema::table('ingredient_sap_profiles', function (Blueprint $table) {
            $table->dropUnique(['ingredient_id']);
            $table->dropConstrainedForeignId('ingredient_id');
            $table->unique('ingredient_version_id');
        });

        Schema::table('ingredient_version_fatty_acids', function (Blueprint $table) {
            $table->dropUnique(['ingredient_id', 'fatty_acid_id']);
            $table->dropConstrainedForeignId('ingredient_id');
            $table->unique(['ingredient_version_id', 'fatty_acid_id']);
        });

        Schema::table('ingredient_allergen_entries', function (Blueprint $table) {
            $table->dropUnique(['ingredient_id', 'allergen_id']);
            $table->dropConstrainedForeignId('ingredient_id');
            $table->unique(['ingredient_version_id', 'allergen_id']);
        });

        Schema::table('ifra_certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
        });
    }

    private function preferredVersionIdsByIngredient(): Collection
    {
        return DB::table('ingredient_versions')
            ->orderBy('ingredient_id')
            ->orderByDesc('is_current')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn (Collection $versions): mixed => $versions->first()?->id);
    }

    /**
     * @param  array<int, string>  $groupColumns
     */
    private function collapseVersionScopedDuplicates(
        string $table,
        array $groupColumns,
        Collection $preferredVersionIdsByIngredient,
    ): void {
        $rows = DB::table($table)
            ->select(array_merge(['id', 'ingredient_id', 'ingredient_version_id'], $groupColumns))
            ->whereNotNull('ingredient_id')
            ->orderBy('id')
            ->get()
            ->groupBy(function (object $row) use ($groupColumns): string {
                $parts = [(string) $row->ingredient_id];

                foreach ($groupColumns as $column) {
                    $parts[] = (string) ($row->{$column} ?? '');
                }

                return implode(':', $parts);
            });

        $idsToDelete = [];

        foreach ($rows as $group) {
            if (! $group instanceof Collection || $group->count() <= 1) {
                continue;
            }

            $ingredientId = $group->first()?->ingredient_id;
            $preferredVersionId = $preferredVersionIdsByIngredient->get($ingredientId);
            $rowToKeep = $this->preferredVersionScopedRow($group, $preferredVersionId);

            $group
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $rowToKeep->id)
                ->each(function (int $id) use (&$idsToDelete): void {
                    $idsToDelete[] = $id;
                });
        }

        if ($idsToDelete !== []) {
            DB::table($table)
                ->whereIn('id', $idsToDelete)
                ->delete();
        }
    }

    private function preferredVersionScopedRow(Collection $rows, mixed $preferredVersionId): object
    {
        return $rows->reduce(function (?object $carry, object $row) use ($preferredVersionId): object {
            if (! $carry instanceof stdClass) {
                return $row;
            }

            return $this->isPreferredVersionScopedRow($row, $carry, $preferredVersionId)
                ? $row
                : $carry;
        });
    }

    private function isPreferredVersionScopedRow(
        object $candidate,
        object $current,
        mixed $preferredVersionId,
    ): bool {
        $candidateMatchesPreferred = $candidate->ingredient_version_id === $preferredVersionId;
        $currentMatchesPreferred = $current->ingredient_version_id === $preferredVersionId;

        if ($candidateMatchesPreferred !== $currentMatchesPreferred) {
            return $candidateMatchesPreferred;
        }

        $candidateVersionId = (int) ($candidate->ingredient_version_id ?? 0);
        $currentVersionId = (int) ($current->ingredient_version_id ?? 0);

        if ($candidateVersionId !== $currentVersionId) {
            return $candidateVersionId > $currentVersionId;
        }

        return (int) $candidate->id > (int) $current->id;
    }
};
