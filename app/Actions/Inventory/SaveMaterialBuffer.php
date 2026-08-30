<?php

namespace App\Actions\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\WorkspaceMaterialSettings;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SaveMaterialBuffer
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly WorkspaceMaterialSettings $settings,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        ?string $bufferQuantity,
    ): ?WorkspaceMaterialSetting {
        $this->access->assertWritable($actor, $workspace);
        $this->assertSubjectAccessible($actor, $workspace, $subject);

        $normalized = $this->normalize($bufferQuantity);

        return $this->settings->synchronize($workspace, $subject, $normalized);
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

    private function normalize(?string $bufferQuantity): ?string
    {
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

        return bcadd($normalized, '0', 9);
    }
}
