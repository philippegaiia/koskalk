<?php

namespace App\Actions\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\WorkspaceMaterialSettings;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SaveMaterialBuffer
{
    /**
     * `buffer_quantity` is decimal(20, 9): nine fractional digits leave eleven
     * integer digits in the canonical value.
     */
    private const int CanonicalIntegerDigits = 11;

    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly WorkspaceMaterialSettings $settings,
        private readonly MassConverter $massConverter,
    ) {}

    /**
     * An ingredient quantity is read in the workspace display unit (metric -> kg,
     * us customary -> lb) and persisted as canonical grams. Packaging is unit-less
     * and is stored exactly as entered.
     *
     * Format and precision are validated against the value as entered, before
     * conversion, so those checks describe what the user actually typed. Magnitude
     * is validated against the canonical value after conversion, because that is
     * what the column has to hold.
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        ?string $bufferQuantity,
    ): ?WorkspaceMaterialSetting {
        $this->access->assertWritable($actor, $workspace);
        $this->assertSubjectAccessible($actor, $workspace, $subject);

        $normalized = $this->normalize($bufferQuantity, $workspace, $subject);

        return $this->settings->synchronize($actor, $workspace, $subject, $normalized);
    }

    private function assertSubjectAccessible(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
    ): void {
        if ($subject instanceof PackagingItem) {
            if ((int) $subject->workspace_id !== (int) $workspace->id) {
                throw new AuthorizationException;
            }

            return;
        }

        $isPlatformIngredient = $subject->owner_type === null
            && $subject->workspace_id === null;
        $isWorkspaceIngredient = (int) $subject->workspace_id === (int) $workspace->id;
        $isLegacyUserIngredient = $subject->isOwnedBy($actor);

        if (($isPlatformIngredient && ! $subject->is_active)
            || (! $isPlatformIngredient && ! $isWorkspaceIngredient && ! $isLegacyUserIngredient)) {
            throw ValidationException::withMessages([
                'buffer_quantity' => __('production_bench.inventory.validation.buffer_forbidden'),
            ]);
        }
    }

    /**
     * Validates the quantity as entered, then converts an ingredient buffer from
     * the workspace display unit into canonical grams. Packaging is unit-less and
     * is stored exactly as entered.
     *
     * Validates format and precision against the entered display value, converts an
     * ingredient buffer to canonical grams, then validates the converted magnitude
     * against what decimal(20, 9) can store.
     */
    private function normalize(
        ?string $bufferQuantity,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
    ): ?string {
        if ($bufferQuantity === null || trim($bufferQuantity) === '') {
            return null;
        }

        $normalized = NumberLocale::normalizeDecimalString($bufferQuantity);

        if ($normalized === null || preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'buffer_quantity' => __('production_bench.inventory.validation.buffer_invalid'),
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        if (strlen($whole) > 11 || strlen($fraction) > 9) {
            throw ValidationException::withMessages([
                'buffer_quantity' => __('production_bench.inventory.validation.buffer_precision'),
            ]);
        }

        if (bccomp($normalized, '0', 9) < 0) {
            throw ValidationException::withMessages([
                'buffer_quantity' => __('production_bench.inventory.validation.buffer_invalid'),
            ]);
        }

        $canonical = $subject instanceof Ingredient
            ? $this->massConverter->toGrams($normalized, $workspace->mass_display_system->priceUnit())
            : bcadd($normalized, '0', 9);

        // decimal(20, 9) leaves 11 integer digits, and conversion to canonical grams
        // multiplies the entered display value by 1000 (kg) or 453.59237 (lb). The
        // magnitude check must therefore run on the canonical value, not on what the
        // user typed: 999999999 is a legal 9-digit entry that overflows as grams.
        if (strlen(explode('.', $canonical, 2)[0]) > self::CanonicalIntegerDigits) {
            throw ValidationException::withMessages([
                'buffer_quantity' => __('production_bench.inventory.validation.buffer_overflow'),
            ]);
        }

        return $canonical;
    }
}
