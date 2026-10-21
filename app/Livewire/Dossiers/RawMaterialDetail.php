<?php

namespace App\Livewire\Dossiers;

use App\Actions\Dossiers\ReplaceDossierEvidence;
use App\Actions\Dossiers\SaveSupplierQualification;
use App\Actions\Dossiers\StoreDossierEvidence;
use App\Enums\EvidenceLevel;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * One raw material: its qualified suppliers and their retained documents.
 */
class RawMaterialDetail extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $workspaceId;

    #[Locked]
    public int $ingredientId;

    /** @var array<string, mixed> */
    public array $qualificationData = [];

    /** @var array<string, mixed> */
    public array $evidenceData = [];

    public ?int $uploadQualificationId = null;

    public ?int $replaceItemId = null;

    public string $status = '';

    public function mount(string $ingredient): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;
        $this->ingredientId = Ingredient::query()
            ->where('public_id', $ingredient)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
            ->firstOrFail()
            ->id;
        $this->qualificationForm->fill(['status' => SupplierQualificationStatus::EnEvaluation->value]);
        $this->evidenceForm->fill();
    }

    public function qualificationForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label(__('dossiers.raw_materials.supplier'))
                    ->options(fn (): array => Supplier::query()->where('workspace_id', $this->workspaceId)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('supplier_reference')->label(__('dossiers.raw_materials.supplier_reference'))->maxLength(255),
                Select::make('status')
                    ->label(__('dossiers.raw_materials.status'))
                    ->options(collect(SupplierQualificationStatus::cases())->mapWithKeys(fn (SupplierQualificationStatus $status): array => [
                        $status->value => __('dossiers.raw_materials.statuses.'.$status->value),
                    ])->all())
                    ->required(),
            ])
            ->columns(['md' => 3])
            ->statePath('qualificationData');
    }

    public function evidenceForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('dossiers.raw_materials.document_type'))
                    ->options(collect(EvidenceType::forLevel(EvidenceLevel::SupplierQualification))->mapWithKeys(fn (EvidenceType $type): array => [
                        $type->value => __('dossiers.evidence_types.'.$type->value),
                    ])->all())
                    ->required(fn (): bool => $this->replaceItemId === null)
                    ->hidden(fn (): bool => $this->replaceItemId !== null),
                DatePicker::make('issued_on')->label(__('dossiers.raw_materials.issued_on')),
                DatePicker::make('revision_due_on')->label(__('dossiers.raw_materials.revision_due_on')),
                FileUpload::make('file')
                    ->label(__('dossiers.raw_materials.file'))
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize((int) config('dossiers.evidence_max_kb'))
                    ->required(),
            ])
            ->columns(['md' => 2])
            ->statePath('evidenceData');
    }

    #[On('dossier-supplier-created')]
    public function preselectCreatedSupplier(int $supplierId): void
    {
        $this->qualificationForm->fill([...$this->qualificationData, 'supplier_id' => $supplierId]);
    }

    public function saveQualification(SaveSupplierQualification $saveQualification): void
    {
        $state = $this->qualificationForm->getState();

        $this->withFormErrors('qualificationData', fn () => $saveQualification->handle($this->user(), $this->lockedWorkspace(), [
            ...$state,
            'ingredient_id' => $this->ingredientId,
        ]));

        $this->qualificationForm->fill(['status' => SupplierQualificationStatus::EnEvaluation->value]);
        $this->status = __('dossiers.raw_materials.saved');
    }

    public function uploadEvidence(StoreDossierEvidence $storeEvidence, ReplaceDossierEvidence $replaceEvidence): void
    {
        $state = $this->evidenceForm->getState();
        $file = $this->uploadedFile($state['file'] ?? null);
        $attributes = ['issued_on' => $state['issued_on'] ?? null, 'revision_due_on' => $state['revision_due_on'] ?? null];

        $this->withFormErrors('evidenceData', function () use ($storeEvidence, $replaceEvidence, $file, $state, $attributes): void {
            if ($this->replaceItemId !== null) {
                $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->replaceItemId);
                $replaceEvidence->handle($this->user(), $this->lockedWorkspace(), $item, $file, $attributes);

                return;
            }

            $storeEvidence->handle($this->user(), $this->lockedWorkspace(), $file, [
                ...$attributes,
                'type' => $state['type'],
                'supplier_qualification_id' => $this->uploadQualificationId,
            ]);
        });

        $this->reset('uploadQualificationId', 'replaceItemId');
        $this->evidenceForm->fill();
        $this->status = __('dossiers.raw_materials.uploaded');
    }

    public function startUpload(int $qualificationId): void
    {
        $this->uploadQualificationId = $qualificationId;
        $this->replaceItemId = null;
        $this->evidenceForm->fill();
    }

    public function startReplace(int $itemId): void
    {
        $this->replaceItemId = $itemId;
        $this->uploadQualificationId = null;
        $this->evidenceForm->fill();
    }

    public function render(): View
    {
        abort_unless($this->workspace()->id === $this->workspaceId, 403);

        return view('livewire.dossiers.raw-material-detail', [
            'ingredient' => Ingredient::query()->findOrFail($this->ingredientId),
            'qualifications' => SupplierQualification::query()
                ->where('workspace_id', $this->workspaceId)
                ->where('ingredient_id', $this->ingredientId)
                ->with(['supplier', 'evidenceItems' => fn ($query) => $query->active()->with('file')->latest()])
                ->orderBy('status')
                ->get(),
        ]);
    }

    /**
     * Action errors use plain keys; the forms show them under their state path.
     */
    private function withFormErrors(string $statePath, Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$statePath.'.'.$key => $messages])->all(),
            );
        }
    }

    /**
     * With storeFiles(false) Filament hands back Livewire's signed temporary-upload reference; resolve it to the file.
     */
    private function uploadedFile(mixed $state): UploadedFile
    {
        $value = is_array($state) ? collect($state)->first() : $state;

        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_string($value) && TemporaryUploadedFile::canUnserialize($value)) {
            $file = TemporaryUploadedFile::unserializeFromLivewireRequest($value);

            if ($file instanceof UploadedFile) {
                return $file;
            }
        }

        throw ValidationException::withMessages(['evidenceData.file' => __('validation.required', ['attribute' => __('dossiers.raw_materials.file')])]);
    }

    private function lockedWorkspace(): Workspace
    {
        return Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId);
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->user()->company(fresh: true);

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canEditModule($this->user(), $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
