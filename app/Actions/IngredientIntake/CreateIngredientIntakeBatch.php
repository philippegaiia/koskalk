<?php

namespace App\Actions\IngredientIntake;

use App\Data\IngredientIntakeRow;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeInputMethod;
use App\Enums\IngredientIntakeItemStatus;
use App\Enums\IngredientResearchFamily;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientIntake\IngredientDuplicateDetector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateIngredientIntakeBatch
{
    public function __construct(
        private readonly IngredientDuplicateDetector $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<IngredientIntakeRow>  $rows
     */
    public function handle(User $actor, array $metadata, array $rows): IngredientIntakeBatch
    {
        Gate::forUser($actor)->authorize('create', IngredientIntakeBatch::class);

        $validated = $this->validateMetadata($metadata, count($rows));
        $upload = $metadata['upload'] ?? null;

        if ($upload !== null && ! $upload instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'upload' => __('ingredient_intake_admin.validation.file_unreadable'),
            ]);
        }

        $storageDisk = (string) config('ingredient-enrichment.intake.artifacts.disk', 'local');
        $storagePath = null;

        if ($upload instanceof UploadedFile) {
            $storagePath = Storage::disk($storageDisk)->putFileAs(
                (string) config('ingredient-enrichment.intake.artifacts.directory', 'ingredient-intake'),
                $upload,
                Str::uuid()->toString().'.csv',
            );

            if (! is_string($storagePath)) {
                throw ValidationException::withMessages([
                    'upload' => __('ingredient_intake_admin.validation.file_unreadable'),
                ]);
            }
        }

        try {
            return DB::transaction(function () use (
                $actor,
                $rows,
                $validated,
                $storageDisk,
                $storagePath,
                $upload,
            ): IngredientIntakeBatch {
                $batch = IngredientIntakeBatch::query()->create([
                    'created_by_user_id' => $actor->id,
                    'status' => IngredientIntakeBatchStatus::Draft,
                    'name' => $validated['name'],
                    'notes' => $validated['notes'],
                    'input_method' => $validated['input_method'],
                    'family_hint' => $validated['family_hint'],
                    'allow_gap_research' => $validated['allow_gap_research'],
                    'original_filename' => $upload instanceof UploadedFile
                        ? $upload->getClientOriginalName()
                        : null,
                    'storage_disk' => $storagePath === null ? null : $storageDisk,
                    'storage_path' => $storagePath,
                    'total_count' => count($rows),
                    'draft_count' => count($rows),
                ]);

                foreach ($rows as $row) {
                    $batch->items()->create([
                        'row_number' => $row->rowNumber,
                        'original_current_name' => $row->originalCurrentName,
                        'normalized_current_name' => $row->normalizedCurrentName,
                        'original_inci_name' => $row->originalInciName,
                        'normalized_inci_name' => $row->normalizedInciName,
                        'status' => IngredientIntakeItemStatus::Draft,
                        'duplicate_candidates' => [],
                    ]);
                }

                $batch->load('items');
                $batch->items->each(fn (IngredientIntakeItem $item): IngredientIntakeItem => $this->duplicates->refresh($item));

                return $batch->load('items');
            }, attempts: 5);
        } catch (Throwable $exception) {
            if ($storagePath !== null) {
                Storage::disk($storageDisk)->delete($storagePath);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     name: string,
     *     notes: string|null,
     *     input_method: IngredientIntakeInputMethod,
     *     family_hint: IngredientResearchFamily|null,
     *     allow_gap_research: bool
     * }
     */
    private function validateMetadata(array $metadata, int $rowCount): array
    {
        $errors = [];
        $name = is_string($metadata['name'] ?? null) ? trim($metadata['name']) : '';

        if ($name === '') {
            $errors['name'] = __('ingredient_intake_admin.validation.batch_name_required');
        }

        if ($rowCount < 1) {
            $errors['rows'] = __('ingredient_intake_admin.validation.batch_rows_required');
        }

        $maximum = max(1, (int) config('ingredient-enrichment.intake.maximum_batch_size', 100));

        if ($rowCount > $maximum) {
            $errors['rows'] = __('ingredient_intake_admin.validation.batch_too_large', [
                'maximum' => $maximum,
            ]);
        }

        $inputMethod = $this->enumValue(
            $metadata['input_method'] ?? null,
            IngredientIntakeInputMethod::class,
        );
        $familyHint = $this->enumValue(
            $metadata['family_hint'] ?? null,
            IngredientResearchFamily::class,
            nullable: true,
        );

        if (! $inputMethod instanceof IngredientIntakeInputMethod) {
            $errors['input_method'] = __('ingredient_intake_admin.validation.input_method_invalid');
        }

        if (($metadata['family_hint'] ?? null) !== null && ! $familyHint instanceof IngredientResearchFamily) {
            $errors['family_hint'] = __('ingredient_intake_admin.validation.research_family_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'notes' => is_string($metadata['notes'] ?? null) ? trim($metadata['notes']) : null,
            'input_method' => $inputMethod,
            'family_hint' => $familyHint,
            'allow_gap_research' => (bool) ($metadata['allow_gap_research'] ?? false),
        ];
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumValue(mixed $value, string $enum, bool $nullable = false): ?\BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        if (is_string($value)) {
            return $enum::tryFrom($value);
        }

        return $nullable ? null : null;
    }
}
