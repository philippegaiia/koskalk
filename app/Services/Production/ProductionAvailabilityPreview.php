<?php

namespace App\Services\Production;

use App\MassUnit;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Workspace;
use App\ProductionBasisKind;
use App\Services\MassConverter;
use App\Services\StockPositionService;
use Illuminate\Validation\ValidationException;

class ProductionAvailabilityPreview
{
    public function __construct(
        private readonly MassConverter $massConverter,
        private readonly ProductionRequirementBuilder $requirementBuilder,
        private readonly ProductionWorkingCalendar $calendar,
        private readonly StockPositionService $stockPositions,
    ) {}

    /**
     * @return array{requirements: list<array<string, mixed>>, tasks: list<array<string, mixed>>, display_unit: string, non_working_date: bool, error: ?string}
     */
    public function for(
        Workspace $workspace,
        ?Recipe $recipe,
        string $basisInputValue,
        MassUnit|string $basisInputUnit,
        string $expectedUnits,
        ?ProductionTaskSet $taskSet,
        ?string $plannedFor,
    ): array {
        $displayUnit = $workspace->mass_display_system->priceUnit();
        $preview = [
            'requirements' => [],
            'tasks' => [],
            'display_unit' => $displayUnit->value,
            'non_working_date' => $plannedFor !== null && $this->isDate($plannedFor)
                ? ! $this->calendar->isWorkingDate($workspace, $plannedFor)
                : false,
            'error' => null,
        ];

        if (! $recipe instanceof Recipe || ! $this->validQuantity($basisInputValue) || ! preg_match('/^[1-9]\d*$/', trim($expectedUnits))) {
            return $preview;
        }

        if ($plannedFor !== null && ! $this->isDate($plannedFor)) {
            return $preview;
        }

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('workspace_id', $workspace->id)
            ->where('is_current', false)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();

        if (! $version instanceof RecipeVersion) {
            return $preview;
        }

        try {
            $basisUnit = MassUnit::fromInput($basisInputUnit);
            $basisKind = $recipe->productFamily?->calculation_basis === 'total_formula'
                ? ProductionBasisKind::TotalFormulaMass
                : ProductionBasisKind::OilMass;
            $requirements = $this->requirementBuilder->build(
                version: $version,
                basisKind: $basisKind,
                basisQuantityGrams: $this->massConverter->toGrams($basisInputValue, $basisUnit),
                expectedUnits: (int) $expectedUnits,
            );
        } catch (ValidationException|\InvalidArgumentException) {
            return $preview;
        }

        foreach ($requirements as $requirement) {
            $isIngredient = $requirement['kind'] === 'ingredient';
            $subject = $isIngredient
                ? Ingredient::withoutGlobalScopes()->find($requirement['ingredient_id'])
                : PackagingItem::query()->find($requirement['packaging_item_id']);

            if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
                continue;
            }

            $positions = $this->stockPositions->forWorkspaceSubject($workspace, $subject);
            $requiredCanonical = $isIngredient
                ? (string) $requirement['required_mass_grams']
                : (string) $requirement['required_units'];
            $available = (string) $positions['available'];
            $incoming = (string) $positions['incoming'];
            $shortage = bccomp($requiredCanonical, bcadd($available, $incoming, 9), 9) > 0
                ? bcsub($requiredCanonical, bcadd($available, $incoming, 9), 9)
                : '0.000000000';

            $preview['requirements'][] = [
                'kind' => $requirement['kind'],
                'subject_name' => $requirement['subject_name_snapshot'],
                'percentage' => $requirement['percentage_snapshot'],
                'required' => $this->formatQuantity($requiredCanonical, $isIngredient, $displayUnit),
                'available' => $this->formatQuantity($available, $isIngredient, $displayUnit),
                'incoming' => $this->formatQuantity($incoming, $isIngredient, $displayUnit),
                'shortage' => $this->formatQuantity($shortage, $isIngredient, $displayUnit),
                'unit' => $isIngredient ? $displayUnit->value : 'unit',
            ];
        }

        if ($taskSet instanceof ProductionTaskSet && $plannedFor !== null && $this->isDate($plannedFor)) {
            $items = $taskSet->items()->with('taskType')->get();

            foreach ($items as $item) {
                if ($item->taskType === null) {
                    continue;
                }

                $scheduledFor = $this->calendar->dateRelativeToProduction(
                    $workspace,
                    $plannedFor,
                    (int) $item->days_after_production,
                )->toDateString();

                $preview['tasks'][] = [
                    'name' => $item->taskType->name,
                    'scheduled_for' => $scheduledFor,
                    'duration_minutes' => $item->duration_minutes ?? $item->taskType->default_duration_minutes,
                ];
            }
        }

        return $preview;
    }

    private function validQuantity(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', trim($value)) === 1
            && bccomp(trim($value), '0', 18) > 0;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function formatQuantity(string $quantity, bool $isIngredient, MassUnit $displayUnit): string
    {
        if (! $isIngredient) {
            return number_format((float) $quantity, 0, '.', '');
        }

        return number_format(
            (float) $this->massConverter->fromGramsSigned($quantity, $displayUnit),
            2,
            '.',
            '',
        );
    }
}
