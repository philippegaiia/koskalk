<?php

namespace App\Services;

use App\Enums\MassUnit;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Support\NumberLocale;
use App\Support\RichContentAttachmentPaths;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchOutputBuilder
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly RecipeWorkbenchPreviewService $previewService,
        private readonly FormulaDocumentBuilder $formulaDocumentBuilder,
        private readonly FormulaDocumentOptions $formulaDocumentOptions,
        private readonly RecipeExportDataBuilder $recipeExportDataBuilder,
        private readonly RecipeVersionCostPreviewBuilder $costPreviewBuilder,
        private readonly RecipeCostingExportDataBuilder $costingExportDataBuilder,
        private readonly CanonicalSoapAlkaliResolver $alkaliResolver,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly RecipeRichContentAttachmentProvider $attachmentProvider,
        private readonly MassConverter $massConverter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function build(User $user, array $payload): array
    {
        $workspace = $this->resolveSelectedWorkspace($user, (string) $payload['workspace']);
        $recipe = $this->resolveRecipe($user, $workspace, $payload['recipe'] ?? null);
        $productFamily = $this->resolveProductFamily($recipe, (string) $payload['product_family']);
        $productType = $this->resolveProductType($recipe, $productFamily, $payload['draft']['product_type_id'] ?? null);
        $draft = $payload['draft'];
        $isCosmetic = $productFamily->calculation_basis === 'total_formula';

        if ($isCosmetic && $draft['manufacturing_mode'] !== 'blend_only') {
            throw ValidationException::withMessages([
                'draft.manufacturing_mode' => __('formula_documents.validation.output_formula_unusable'),
            ]);
        }

        $oilUnit = MassUnit::fromInput($draft['oil_unit']);
        $oilWeight = $this->decimal($draft['oil_weight'], 'draft.oil_weight');

        if (bccomp($oilWeight, '0', 18) <= 0) {
            throw ValidationException::withMessages([
                'draft.oil_weight' => __('formula_documents.validation.output_formula_unusable'),
            ]);
        }

        $this->massConverter->toGrams($oilWeight, $oilUnit);
        $ingredients = $this->resolveIngredients($user, $workspace, $draft, $payload['costing'] ?? null);
        $packagingItems = $this->resolvePackagingItems($workspace, $draft, $payload['costing'] ?? null);
        $phaseItems = $this->canonicalPhaseItems($draft, $ingredients, $oilWeight, $oilUnit, $productFamily);
        $phases = $this->snapshotPhases($draft, $phaseItems, $isCosmetic);
        $previewPayload = $this->previewPayload($draft, $phaseItems, $oilWeight, $productType);
        $isSoap = ! $isCosmetic;

        if (! $this->hasUsableFormula($phaseItems, $isSoap)) {
            throw ValidationException::withMessages([
                'draft.phase_items' => __('formula_documents.validation.output_formula_unusable'),
            ]);
        }

        try {
            $calculation = $isSoap ? $this->previewService->previewSoapCalculation($previewPayload, $user) : null;

            if ($isSoap && $calculation === null) {
                throw ValidationException::withMessages([
                    'draft.phase_items' => __('formula_documents.validation.output_formula_unusable'),
                ]);
            }

            if ($isSoap) {
                $phaseItems = $this->applyLyeLiquidAllocation($phaseItems, $calculation);
            }

            $labeling = $this->previewService->previewInci($previewPayload, $calculation);
            $restrictions = $this->previewService->previewRestrictions($previewPayload, $calculation);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'draft' => $exception->getMessage(),
            ]);
        }
        $snapshotDraft = $this->snapshotDraft($draft, $phaseItems, $phases, $oilWeight, $oilUnit, $productType, $packagingItems);
        $snapshot = [
            'draft' => $snapshotDraft,
            'calculation' => $calculation,
            'labeling' => $labeling,
            'restrictions' => $restrictions,
        ];
        $title = filled(trim((string) ($draft['name'] ?? '')))
            ? trim((string) $draft['name'])
            : ($recipe?->name ?? __($isCosmetic ? 'workbench.header.untitled_cosmetic' : 'workbench.header.untitled_soap'));
        $instructions = $payload['manufacturing_instructions'] ?? null;
        $renderedInstructions = $this->renderInstructions($instructions, $recipe, $workspace);
        $formulaDocument = $this->formulaDocumentBuilder->build($snapshot, [
            'name' => $title,
            'product_family' => $productFamily->name,
            'product_type' => $productType?->localizedName(),
            'calculation_basis' => $productFamily->calculation_basis,
            'state' => 'current_working_copy',
            'saved_at' => null,
            'description' => $recipe?->description,
            'manufacturing_procedure' => $renderedInstructions,
        ]);
        $formulaDocument['metadata'] = [
            'version_number' => null,
            'generated_at' => now()->format('Y-m-d H:i'),
        ];

        $costingProjection = null;
        $costingExportData = null;
        $costingIsLoaded = is_array($payload['costing'] ?? null) && ($payload['costing']['loaded'] ?? false) === true;

        if ($costingIsLoaded) {
            $currentFormula = $this->currentCostingFormula($snapshotDraft, $formulaDocument, $calculation, $ingredients, $payload['costing']);
            $currentCosting = $this->currentCostingPayload($payload['costing'], $snapshotDraft);
            $costingProjection = $this->costPreviewBuilder->buildFromTransientRows($currentFormula, $currentCosting);
            $costingExportData = $this->costingExportDataBuilder->build($formulaDocument, $costingProjection);
        }

        $availableSections = $this->formulaDocumentOptions->availableSections([
            'procedure' => $this->hasPrintableContent($renderedInstructions),
            'label' => filled($formulaDocument['label_text'] ?? null),
            'costing' => $costingIsLoaded,
            'analysis' => is_array($formulaDocument['soap_analysis'] ?? null)
                && (filled($formulaDocument['soap_analysis']['qualities'] ?? [])
                    || filled($formulaDocument['soap_analysis']['fatty_acids'] ?? [])),
        ]);
        $selectedSections = array_key_exists('sections', $payload)
            ? $this->formulaDocumentOptions->resolveSelection($payload['sections'], $availableSections)
            : $this->formulaDocumentOptions->defaultSections($availableSections, (string) ($payload['print_context'] ?? 'formula'));
        $filenameSlug = Str::slug($title) ?: Str::slug($productFamily->slug.'-formula');

        return [
            'workspace' => $workspace,
            'recipe' => $recipe,
            'snapshot' => $snapshot,
            'formulaDocument' => $formulaDocument,
            'exportData' => $this->recipeExportDataBuilder->fromDocument($formulaDocument),
            'availableSections' => $availableSections,
            'selectedSections' => $selectedSections,
            'numberLocale' => NumberLocale::resolve($user->number_locale),
            'returnUrl' => $recipe instanceof Recipe ? route('recipes.saved', ['recipe' => $recipe]) : null,
            'filename' => $filenameSlug.'-current-formula-'.now()->format('Y-m-d'),
            'capturedCosting' => $payload['costing'] ?? null,
            'costingProjection' => $costingProjection,
            'costingExportData' => $costingExportData,
            'hasLoadedCosting' => $costingIsLoaded,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshotDraft
     * @param  array<string, mixed>  $formulaDocument
     * @param  array<string, mixed>|null  $calculation
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  array<string, mixed>  $costing
     * @return array<string, mixed>
     */
    private function currentCostingFormula(
        array $snapshotDraft,
        array $formulaDocument,
        ?array $calculation,
        Collection $ingredients,
        array $costing,
    ): array {
        $basisWeight = (string) ($snapshotDraft['oilWeight'] ?? 0);
        $basisUnit = (string) ($snapshotDraft['oilUnit'] ?? 'g');
        $isSoap = data_get($formulaDocument, 'identity.calculation_basis') !== 'total_formula';
        $phasesByKey = collect($snapshotDraft['phases'] ?? [])->keyBy('key');
        $phases = [];

        foreach (($snapshotDraft['phaseItems'] ?? []) as $phaseKey => $rows) {
            if (! is_string($phaseKey) || ! is_array($rows)) {
                continue;
            }

            $phases[$phaseKey] = [
                'label' => (string) ($phasesByKey->get($phaseKey)['name'] ?? $phaseKey),
                'rows' => collect($rows)->values()->map(fn (array $row, int $index): array => [
                    'ingredient_id' => (int) ($row['ingredient_id'] ?? 0),
                    'position' => $index + 1,
                    'name' => (string) ($row['name'] ?? ''),
                    'percentage' => (string) ($row['percentage'] ?? 0),
                    'weight' => (string) ($row['weight'] ?? 0),
                ])->all(),
            ];
        }

        $syntheticRows = $isSoap
            ? $this->currentSyntheticCostingRows($snapshotDraft, $calculation, $ingredients, $costing)
            : [];

        return [
            'family' => $isSoap ? 'soap' : 'cosmetic',
            'basis_weight' => $basisWeight,
            'basis_unit' => $basisUnit,
            'phases' => $phases,
            'packaging_items' => collect($snapshotDraft['packagingItems'] ?? [])
                ->values()
                ->map(fn (array $row, int $index): array => [
                    'packaging_item_id' => isset($row['packaging_item_id']) ? (int) $row['packaging_item_id'] : null,
                    'position' => $index + 1,
                    'name' => (string) ($row['name'] ?? ''),
                    'material_code' => $row['material_code'] ?? null,
                    'components_per_unit' => (string) ($row['components_per_unit'] ?? 0),
                ])
                ->all(),
            'synthetic_rows' => $syntheticRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshotDraft
     * @param  array<string, mixed>|null  $calculation
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  array<string, mixed>  $costing
     * @return list<array<string, mixed>>
     */
    private function currentSyntheticCostingRows(
        array $snapshotDraft,
        ?array $calculation,
        Collection $ingredients,
        array $costing,
    ): array {
        if (! is_array($calculation)) {
            return [];
        }

        $selected = data_get($calculation, 'lye.selected', []);
        $lyeTypes = match ((string) ($snapshotDraft['lyeType'] ?? 'naoh')) {
            'dual' => ['naoh', 'koh'],
            'koh' => ['koh'],
            default => ['naoh'],
        };
        $rows = [];
        $alkalis = $this->alkaliResolver->resolveMany($lyeTypes);

        foreach ($lyeTypes as $index => $type) {
            $ingredient = $alkalis->get($type);
            $weight = $this->decimal($type === 'koh'
                ? data_get($selected, 'koh_to_weigh', 0)
                : data_get($selected, 'naoh_weight', 0), 'costing');

            if (! $ingredient instanceof Ingredient || bccomp($weight, '0', 18) <= 0) {
                continue;
            }

            $rows[] = [
                'ingredient_id' => (int) $ingredient->id,
                'position' => $index + 1,
                'phase_key' => 'lye_alkali',
                'phase_name' => __('workbench.costing.phases.alkali'),
                'name' => $type === 'koh'
                    ? __('workbench.costing.ingredients.koh_with_purity', [
                        'name' => $ingredient->localizedDisplayName() ?? $ingredient->display_name,
                        'purity' => NumberLocale::formatDecimal($snapshotDraft['kohPurity'] ?? 90, 0, app()->getLocale()),
                    ])
                    : ($ingredient->localizedDisplayName() ?? $ingredient->display_name),
                'percentage' => '0',
                'weight' => $weight,
                'is_derived' => true,
            ];
        }

        $substitutions = collect($snapshotDraft['phaseItems']['lye_water'] ?? [])
            ->map(fn (array $row): array => ['percentage' => (string) ($row['percentage'] ?? 0)])
            ->all();
        $allocation = $this->lyeLiquidAllocationService->allocateFresh(data_get($calculation, 'lye.water.weight', 0), $substitutions);
        $waterWeight = $this->decimal($allocation['water_weight'] ?? 0, 'costing');
        $waterPriceRow = collect($costing['items'] ?? [])
            ->first(fn (mixed $row): bool => is_array($row) && ($row['phase_key'] ?? null) === 'implicit_water');
        $waterIngredient = is_array($waterPriceRow) ? $ingredients->get((int) ($waterPriceRow['ingredient_id'] ?? 0)) : null;

        if ($waterIngredient instanceof Ingredient && bccomp($waterWeight, '0', 18) > 0) {
            $rows[] = [
                'ingredient_id' => (int) $waterIngredient->id,
                'position' => 1,
                'phase_key' => 'implicit_water',
                'phase_name' => __('workbench.costing.phases.implicit_water'),
                'name' => $waterIngredient->localizedDisplayName() ?? $waterIngredient->display_name,
                'percentage' => '0',
                'weight' => $waterWeight,
                'is_derived' => true,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $costing
     * @param  array<string, mixed>  $snapshotDraft
     * @return array<string, mixed>
     */
    private function currentCostingPayload(array $costing, array $snapshotDraft): array
    {
        $basisWeight = $costing['oil_weight_for_costing'] ?? null;
        $basisUnit = is_string($costing['oil_unit_for_costing'] ?? null) && $costing['oil_unit_for_costing'] !== ''
            ? MassUnit::fromInput($costing['oil_unit_for_costing'])
            : MassUnit::fromInput((string) ($snapshotDraft['oilUnit'] ?? 'g'));
        $hasPositiveOverride = is_numeric($basisWeight) && (float) $basisWeight > 0;
        $effectiveWeight = $hasPositiveOverride
            ? (string) $basisWeight
            : $this->massConverter->convert(
                (string) ($snapshotDraft['oilWeight'] ?? 0),
                (string) ($snapshotDraft['oilUnit'] ?? 'g'),
                $basisUnit,
            );

        return [
            'oil_weight_for_costing' => $effectiveWeight,
            'oil_unit_for_costing' => $basisUnit->value,
            'units_produced' => array_key_exists('units_produced', $costing) ? $costing['units_produced'] : null,
            'currency' => (string) ($costing['currency'] ?? 'EUR'),
            'items' => collect($costing['items'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values()->all(),
            'packaging_items' => collect($costing['packaging_items'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values()->all(),
        ];
    }

    private function resolveSelectedWorkspace(User $user, string $submittedPublicId): Workspace
    {
        $selectedWorkspace = $this->workspaceAuthorization->selectedWorkspace($user);

        if (! $selectedWorkspace instanceof Workspace) {
            abort(403);
        }

        $requestedWorkspace = Workspace::withoutGlobalScopes()
            ->where('public_id', $submittedPublicId)
            ->first();

        if (! $requestedWorkspace instanceof Workspace || ! $this->workspaceAuthorization->canAccessWorkspace($user, $requestedWorkspace)) {
            abort(403);
        }

        if ($requestedWorkspace->isNot($selectedWorkspace)) {
            abort(409, __('formula_documents.validation.output_workspace_changed'));
        }

        return $selectedWorkspace;
    }

    private function resolveRecipe(User $user, Workspace $workspace, mixed $publicId): ?Recipe
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $recipe = Recipe::withoutGlobalScopes()->where('public_id', $publicId)->first();

        if (! $recipe instanceof Recipe
            || ($recipe->workspace_id !== null && (int) $recipe->workspace_id !== (int) $workspace->id)) {
            abort(403);
        }

        Gate::forUser($user)->authorize('view', $recipe);

        return $recipe->loadMissing(['productFamily', 'productType']);
    }

    private function resolveProductFamily(?Recipe $recipe, string $submittedSlug): ProductFamily
    {
        if ($recipe instanceof Recipe) {
            $productFamily = $recipe->productFamily;

            if (! $productFamily instanceof ProductFamily || $productFamily->slug !== $submittedSlug) {
                throw ValidationException::withMessages([
                    'product_family' => __('formula_documents.validation.output_family_mismatch'),
                ]);
            }

            return $productFamily;
        }

        return ProductFamily::query()
            ->where('slug', $submittedSlug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function resolveProductType(?Recipe $recipe, ProductFamily $family, mixed $submittedId): ?ProductType
    {
        if ($recipe instanceof Recipe) {
            return $recipe->productType;
        }

        if ($submittedId === null || $submittedId === '') {
            return null;
        }

        $productType = ProductType::query()
            ->whereKey((int) $submittedId)
            ->where('is_active', true)
            ->whereHas('productFamilies', fn ($query) => $query->whereKey($family->id))
            ->first();

        if (! $productType instanceof ProductType) {
            throw ValidationException::withMessages([
                'draft.product_type_id' => __('formula_documents.validation.output_product_type_unavailable'),
            ]);
        }

        return $productType;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $costing
     * @return Collection<int, Ingredient>
     */
    private function resolveIngredients(User $user, Workspace $workspace, array $draft, ?array $costing): Collection
    {
        $ids = collect($this->ingredientReferences($draft, $costing))
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $ingredients = Ingredient::withoutGlobalScopes()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $ingredient = $ingredients->get($id);

            if (! $ingredient instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'draft.phase_items' => __('formula_documents.validation.output_ingredient_unavailable'),
                ]);
            }

            if ($ingredient->isPublicCatalog()) {
                if (! $ingredient->is_active) {
                    abort(403);
                }

                continue;
            }

            $isSelectedWorkspaceIngredient = $ingredient->tenantWorkspaceId() === $workspace->id
                || ($ingredient->tenantWorkspaceId() === null
                    && $ingredient->tenantOwnerType() === OwnerType::Workspace
                    && $ingredient->tenantOwnerId() === $workspace->id);
            $isLegacyPersonalIngredient = $ingredient->tenantWorkspaceId() === null
                && $ingredient->tenantOwnerType() === OwnerType::User
                && $ingredient->tenantOwnerId() === $user->id;

            if (! $isSelectedWorkspaceIngredient && ! $isLegacyPersonalIngredient) {
                abort(403);
            }
        }

        return $ingredients;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $costing
     * @return list<mixed>
     */
    private function ingredientReferences(array $draft, ?array $costing): array
    {
        $references = [];

        foreach (($draft['phase_items'] ?? []) as $rows) {
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $references[] = $row['ingredient_id'] ?? null;
                }
            }
        }

        $references[] = $draft['output_ingredient_id'] ?? null;

        foreach (($costing['items'] ?? []) as $row) {
            if (is_array($row)) {
                $references[] = $row['ingredient_id'] ?? null;
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $costing
     * @return Collection<int, PackagingItem>
     */
    private function resolvePackagingItems(Workspace $workspace, array $draft, ?array $costing): Collection
    {
        $rows = [
            ...($draft['packaging_items'] ?? []),
            ...($costing['packaging_items'] ?? []),
        ];
        $ids = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->pluck('packaging_item_id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $items = PackagingItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
        $allItems = PackagingItem::withoutGlobalScopes()->whereKey($ids)->pluck('id')->map(fn (int $id): int => $id);

        if ($allItems->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'draft.packaging_items' => __('formula_documents.validation.output_packaging_unavailable'),
            ]);
        }

        if ($items->count() !== $ids->count()) {
            abort(403);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  Collection<int, Ingredient>  $ingredients
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function canonicalPhaseItems(
        array $draft,
        Collection $ingredients,
        string $oilWeight,
        MassUnit $oilUnit,
        ProductFamily $family,
    ): array {
        $phaseItems = [];
        $editingMode = $draft['editing_mode'];
        $isCosmetic = $family->calculation_basis === 'total_formula';

        foreach (($draft['phase_items'] ?? []) as $phaseKey => $rows) {
            if (! is_string($phaseKey) || ! is_array($rows)) {
                continue;
            }

            $phaseItems[$phaseKey] = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $ingredientId = isset($row['ingredient_id']) && is_numeric($row['ingredient_id'])
                    ? (int) $row['ingredient_id']
                    : null;
                $ingredient = $ingredientId === null ? null : $ingredients->get($ingredientId);
                $percentageInput = $this->decimal($row['percentage'] ?? 0, "draft.phase_items.{$phaseKey}.percentage");
                $weightInput = $this->decimal($row['weight'] ?? 0, "draft.phase_items.{$phaseKey}.weight");
                $activeValue = $editingMode === 'weight' ? $weightInput : $percentageInput;
                $activeIsNonzero = bccomp($activeValue, '0', 18) > 0;

                if ($activeIsNonzero && ! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        "draft.phase_items.{$phaseKey}" => __('formula_documents.validation.output_ingredient_required'),
                    ]);
                }

                if ($ingredientId !== null && ! $ingredient instanceof Ingredient) {
                    throw ValidationException::withMessages([
                        "draft.phase_items.{$phaseKey}" => __('formula_documents.validation.output_ingredient_unavailable'),
                    ]);
                }

                if ($phaseKey === 'lye_water') {
                    $phaseItems[$phaseKey][] = $this->ingredientRow($row, $ingredient, $percentageInput, $weightInput);

                    continue;
                }

                $weight = $editingMode === 'weight'
                    ? $weightInput
                    : bcdiv(bcmul($oilWeight, $percentageInput, 18), '100', 18);
                $percentage = $editingMode === 'weight'
                    ? bcdiv(bcmul($weightInput, '100', 18), $oilWeight, 18)
                    : $percentageInput;

                $this->massConverter->toGrams($weight, $oilUnit);
                $phaseItems[$phaseKey][] = $this->ingredientRow($row, $ingredient, $percentage, $weight);
            }
        }

        if ($isCosmetic && $phaseItems === []) {
            throw ValidationException::withMessages([
                'draft.phase_items' => __('formula_documents.validation.output_formula_unusable'),
            ]);
        }

        return $phaseItems;
    }

    /** @return array<string, mixed> */
    private function ingredientRow(array $row, ?Ingredient $ingredient, string $percentage, string $weight): array
    {
        return [
            'id' => $row['id'] ?? null,
            'ingredient_id' => $ingredient?->id,
            'name' => $ingredient?->localizedDisplayName() ?? '',
            'inci_name' => $ingredient?->displayInciName(),
            'is_user_owned' => $ingredient instanceof Ingredient && ! $ingredient->isPublicCatalog(),
            'percentage' => (float) $percentage,
            'weight' => (float) $weight,
            'note' => $row['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
     * @return array<string, mixed>
     */
    private function previewPayload(array $draft, array $phaseItems, string $oilWeight, ?ProductType $productType): array
    {
        return [
            'manufacturing_mode' => $draft['manufacturing_mode'],
            'exposure_mode' => $draft['exposure_mode'],
            'regulatory_regime' => $draft['regulatory_regime'],
            'product_type_id' => $productType?->id,
            'ifra_category_selection_mode' => $draft['ifra_category_selection_mode'] ?? 'automatic',
            'ifra_product_category_id' => $draft['ifra_product_category_id'] ?? null,
            'oil_weight' => (float) $oilWeight,
            'lye_type' => $draft['lye_type'],
            'koh_purity_percentage' => (float) $draft['koh_purity_percentage'],
            'dual_lye_koh_percentage' => (float) $draft['dual_lye_koh_percentage'],
            'water_mode' => $draft['water_mode'],
            'water_value' => (float) $draft['water_value'],
            'superfat' => (float) $draft['superfat'],
            'final_ingredient_list' => $draft['final_ingredient_list'] ?? null,
            'final_ingredient_list_basis_hash' => $draft['final_ingredient_list_basis_hash'] ?? null,
            'final_plain_ingredient_list' => $draft['final_plain_ingredient_list'] ?? null,
            'final_plain_ingredient_list_basis_hash' => $draft['final_plain_ingredient_list_basis_hash'] ?? null,
            'phase_items' => collect($phaseItems)->map(fn (array $rows): array => array_map(
                fn (array $row): array => [
                    'ingredient_id' => $row['ingredient_id'],
                    'percentage' => $row['percentage'],
                    'weight' => $row['weight'],
                    'note' => $row['note'],
                ],
                $rows,
            ))->all(),
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function applyLyeLiquidAllocation(array $phaseItems, array $calculation): array
    {
        $substitutions = data_get($calculation, 'lye.liquid_composition.substitutions', []);
        $substitutionIndex = 0;

        foreach ($phaseItems['lye_water'] ?? [] as $index => $row) {
            if (bccomp($this->decimal($row['percentage'] ?? 0, 'draft.phase_items.lye_water.percentage'), '0', 18) <= 0) {
                $phaseItems['lye_water'][$index]['weight'] = 0.0;

                continue;
            }

            if (isset($substitutions[$substitutionIndex]) && is_array($substitutions[$substitutionIndex])) {
                $phaseItems['lye_water'][$index]['weight'] = (float) ($substitutions[$substitutionIndex]['weight'] ?? 0);
            }

            $substitutionIndex++;
        }

        return $phaseItems;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
     * @return array<int, array{key: string, name: string}>
     */
    private function snapshotPhases(array $draft, array $phaseItems, bool $isCosmetic): array
    {
        $phases = collect(is_array($draft['phases'] ?? null) ? $draft['phases'] : [])
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->values();
        $phaseKeys = $phases->pluck('key')->filter(fn (mixed $key): bool => is_string($key) && $key !== '');

        if ($phaseKeys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'draft.phases' => __('formula_documents.validation.output_phases_invalid'),
            ]);
        }

        if (! $isCosmetic) {
            return $phases->all();
        }

        $phaseKeysInPayload = $phaseKeys->all();

        foreach (array_keys($phaseItems) as $phaseKey) {
            if (! is_string($phaseKey) || $phaseKey === '' || strlen($phaseKey) > 64) {
                throw ValidationException::withMessages([
                    'draft.phase_items' => __('formula_documents.validation.output_phases_invalid'),
                ]);
            }

            if (! in_array($phaseKey, $phaseKeysInPayload, true)) {
                $phases->push([
                    'key' => $phaseKey,
                    'name' => str($phaseKey)->replace('_', ' ')->title()->toString(),
                ]);
                $phaseKeysInPayload[] = $phaseKey;
            }
        }

        return $phases->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, array<int, array<string, mixed>>>  $phaseItems
     * @param  array<int, array{key: string, name: string}>  $phases
     * @param  Collection<int, PackagingItem>  $packagingItems
     * @return array<string, mixed>
     */
    private function snapshotDraft(
        array $draft,
        array $phaseItems,
        array $phases,
        string $oilWeight,
        MassUnit $oilUnit,
        ?ProductType $productType,
        Collection $packagingItems,
    ): array {
        return [
            'formulaName' => trim((string) ($draft['name'] ?? '')),
            'oilUnit' => $oilUnit->value,
            'oilWeight' => (float) $oilWeight,
            'manufacturingMode' => $draft['manufacturing_mode'],
            'manufacturingInstructions' => null,
            'exposureMode' => $draft['exposure_mode'],
            'regulatoryRegime' => $draft['regulatory_regime'],
            'productTypeId' => $productType?->id,
            'ifraCategorySelectionMode' => $draft['ifra_category_selection_mode'] ?? 'automatic',
            'editMode' => $draft['editing_mode'],
            'lyeType' => $draft['lye_type'],
            'kohPurity' => (float) $draft['koh_purity_percentage'],
            'dualKohPercentage' => (float) $draft['dual_lye_koh_percentage'],
            'waterMode' => $draft['water_mode'],
            'waterValue' => (float) $draft['water_value'],
            'superfat' => (float) $draft['superfat'],
            'selectedIfraProductCategoryId' => $draft['ifra_product_category_id'] ?? null,
            'finalIngredientList' => $draft['final_ingredient_list'] ?? null,
            'finalIngredientListBasisHash' => $draft['final_ingredient_list_basis_hash'] ?? null,
            'finalPlainIngredientList' => $draft['final_plain_ingredient_list'] ?? null,
            'finalPlainIngredientListBasisHash' => $draft['final_plain_ingredient_list_basis_hash'] ?? null,
            'phases' => $phases,
            'phaseItems' => $phaseItems,
            'packagingItems' => collect($draft['packaging_items'] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(function (array $row) use ($packagingItems): array {
                    $item = isset($row['packaging_item_id']) ? $packagingItems->get((int) $row['packaging_item_id']) : null;
                    $capturedName = trim((string) ($row['name'] ?? ''));

                    return [
                        'packaging_item_id' => $item?->id,
                        'name' => $capturedName !== '' ? $capturedName : ($item?->name ?? ''),
                        'material_code' => $item?->material_code,
                        'components_per_unit' => (float) ($row['components_per_unit'] ?? 0),
                        'notes' => $row['notes'] ?? null,
                    ];
                })
                ->values()
                ->all(),
            'productionOutputType' => $draft['production_output_type'] ?? null,
            'outputIngredientId' => $draft['output_ingredient_id'] ?? null,
            'readyDelayDays' => $draft['ready_delay_days'] ?? null,
            'productReference' => $draft['product_reference'] ?? null,
            'nominalContentValue' => $draft['nominal_content_value'] ?? null,
            'nominalContentUnit' => $draft['nominal_content_unit'] ?? null,
        ];
    }

    private function renderInstructions(mixed $content, ?Recipe $recipe, Workspace $workspace): string
    {
        if ($content === null || $content === '' || $content === []) {
            return '';
        }

        $contextRecipe = $recipe ?? new Recipe(['workspace_id' => $workspace->id]);
        $renderer = RichContentRenderer::make($content);
        $identityContent = is_string($content) ? $renderer->toArray() : $content;
        $identities = RichContentAttachmentPaths::extractImageIdentities($identityContent);

        foreach ($identities as $identity) {
            if (str_starts_with($identity, RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX)) {
                continue;
            }

            if (! $recipe instanceof Recipe || ! MediaStorage::isRecipePath($recipe, $identity)) {
                abort(403);
            }
        }

        $assetIds = $identities
            ->filter(fn (string $identity): bool => str_starts_with($identity, RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX))
            ->map(fn (string $identity): string => substr($identity, strlen(RichContentAttachmentPaths::MEDIA_ASSET_IDENTITY_PREFIX)))
            ->unique()
            ->values();

        if ($assetIds->isNotEmpty()) {
            if ($assetIds->contains(fn (string $assetId): bool => ! Str::isUuid($assetId))) {
                throw ValidationException::withMessages([
                    'manufacturing_instructions' => __('formula_documents.validation.output_media_unavailable'),
                ]);
            }

            $authorizedAssets = MediaAsset::query()
                ->where('workspace_id', $recipe instanceof Recipe ? $recipe->workspace_id : $workspace->id)
                ->whereIn('public_id', $assetIds)
                ->where('status', 'ready')
                ->pluck('public_id');

            if ($authorizedAssets->count() !== $assetIds->count()) {
                abort(403);
            }
        }

        return $renderer
            ->fileAttachmentProvider(
                $this->attachmentProvider->attribute(
                    RichContentAttribute::make($contextRecipe, 'manufacturing_instructions'),
                )->content($content),
            )
            ->toHtml();
    }

    private function hasPrintableContent(mixed $content): bool
    {
        $html = (string) $content;
        $plainText = html_entity_decode(strip_tags($html));

        return filled(preg_replace('/\s+/u', '', $plainText)) || preg_match('/<img\b/i', $html) === 1;
    }

    private function hasUsableFormula(array $phaseItems, bool $isSoap): bool
    {
        $sourceRows = $isSoap ? ($phaseItems['saponified_oils'] ?? []) : collect($phaseItems)->flatten(1)->all();

        return collect($sourceRows)->contains(fn (mixed $row): bool => is_array($row)
            && (int) ($row['ingredient_id'] ?? 0) > 0
            && (float) ($row['weight'] ?? 0) > 0);
    }

    private function decimal(mixed $value, string $field): string
    {
        $normalized = NumberLocale::normalizeDecimalString($value);

        if ($normalized === null || ! is_finite((float) $normalized) || abs((float) $normalized) > 1_000_000) {
            throw ValidationException::withMessages([
                $field => __('validation.numeric', ['attribute' => $field]),
            ]);
        }

        return $normalized;
    }
}
