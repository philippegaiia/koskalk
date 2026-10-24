<?php

namespace App\Livewire\Dossiers;

use App\Actions\Dossiers\ReplaceDossierEvidence;
use App\Actions\Dossiers\SaveDossierEvidenceData;
use App\Actions\Dossiers\SaveSupplierQualification;
use App\Actions\Dossiers\StoreDossierEvidence;
use App\Actions\Dossiers\WithdrawDossierEvidence;
use App\Enums\ConstituentKind;
use App\Enums\EvidenceLevel;
use App\Enums\EvidenceType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\UseLimitType;
use App\Enums\WorkspaceModule;
use App\Models\Allergen;
use App\Models\DossierEvidenceItem;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\Substance;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Get;
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
class RawMaterialDetail extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
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

    public ?int $dataItemId = null;

    /** @var array<string, mixed> */
    public array $evidenceDataState = [];

    public ?int $withdrawItemId = null;

    public string $withdrawReason = '';

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
        $this->evidenceDataForm->fill();
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
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    // The largest accepted upload; the per-type limit (PDF 2 MB) is enforced when the document is saved.
                    ->maxSize((int) config('dossiers.evidence_image_upload_max_kb'))
                    ->required(),
            ])
            ->columns(['md' => 2])
            ->statePath('evidenceData');
    }

    public function evidenceDataForm(Schema $schema): Schema
    {
        $item = $this->dataItemId !== null
            ? DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->find($this->dataItemId)
            : null;
        $type = $item?->type;
        $kinds = $type?->constituentKinds() ?? [];
        $basis = $type?->useLimitBasis();
        $facts = $type?->factKeys() ?? [];

        return $schema
            ->components(array_values(array_filter([
                $kinds === [] ? null : Repeater::make('constituents')
                    ->label(__('dossiers.evidence_data.constituents'))
                    ->schema([
                        Select::make('kind')
                            ->label(__('dossiers.evidence_data.kind'))
                            ->options(collect($kinds)->mapWithKeys(fn (ConstituentKind $kind): array => [$kind->value => __('dossiers.evidence_data.kinds.'.$kind->value)])->all())
                            ->default($kinds[0]->value)
                            ->live()
                            ->required(),
                        Select::make('allergen_id')
                            ->label(__('dossiers.evidence_data.allergen'))
                            ->options(fn (): array => Allergen::query()->orderBy('inci_name')->pluck('inci_name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('kind') === ConstituentKind::Allergen->value)
                            ->required(fn (Get $get): bool => $get('kind') === ConstituentKind::Allergen->value),
                        Select::make('substance_id')
                            ->label(__('dossiers.evidence_data.substance'))
                            ->options(fn (): array => Substance::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('kind') !== ConstituentKind::Allergen->value)
                            ->required(fn (Get $get): bool => $get('kind') !== ConstituentKind::Allergen->value),
                        TextInput::make('max_value')
                            ->label(__('dossiers.evidence_data.max_value'))
                            ->numeric()
                            ->suffix(fn (Get $get): string => ConstituentKind::tryFrom((string) $get('kind'))?->unit() === 'ppm' ? 'ppm' : '%')
                            ->required(),
                        TextInput::make('note')->label(__('dossiers.evidence_data.note'))->maxLength(500)->columnSpanFull(),
                    ])
                    ->columns(['md' => 3])
                    ->defaultItems(0),
                $kinds === [] ? null : Toggle::make('constituents_complete')
                    ->label(__('dossiers.evidence_data.constituents_complete'))
                    ->helperText(__('dossiers.evidence_data.constituents_complete_help')),
                $basis === null ? null : Repeater::make('use_limits')
                    ->label(__('dossiers.evidence_data.use_limits'))
                    ->schema([
                        $basis === 'ifra_category'
                            ? Select::make('ifra_product_category_id')
                                ->label(__('dossiers.evidence_data.ifra_category'))
                                ->options(fn (): array => IfraProductCategory::query()->orderBy('code')->get()->mapWithKeys(fn (IfraProductCategory $category): array => [$category->id => $category->code.' — '.$category->localizedName()])->all())
                                ->required()
                            : TextInput::make('product_class_label')->label(__('dossiers.evidence_data.product_class'))->required()->maxLength(255),
                        Select::make('limit_type')
                            ->label(__('dossiers.evidence_data.limit_type'))
                            ->options(collect(UseLimitType::cases())->mapWithKeys(fn (UseLimitType $type): array => [$type->value => __('dossiers.evidence_data.limit_types.'.$type->value)])->all())
                            ->default(UseLimitType::Maximum->value)
                            ->live()
                            ->required(),
                        TextInput::make('max_percent')
                            ->label(__('dossiers.evidence_data.max_percent'))
                            ->numeric()
                            ->suffix('%')
                            ->visible(fn (Get $get): bool => $get('limit_type') !== UseLimitType::NoRestriction->value)
                            ->required(fn (Get $get): bool => $get('limit_type') !== UseLimitType::NoRestriction->value),
                    ])
                    ->columns(['md' => 3])
                    ->defaultItems(0),
                in_array('ifra_amendment_id', $facts, true)
                    ? Select::make('facts.ifra_amendment_id')
                        ->label(__('dossiers.evidence_data.ifra_amendment'))
                        ->options(fn (): array => $this->amendmentCodes())
                    : null,
                in_array('covers_supplied_grade', $facts, true)
                    ? Toggle::make('facts.covers_supplied_grade')
                        ->label(__('dossiers.evidence_data.covers_supplied_grade'))
                        ->helperText(__('dossiers.evidence_data.covers_supplied_grade_help'))
                    : null,
                in_array('peroxide_value_mmol_l', $facts, true)
                    ? TextInput::make('facts.peroxide_value_mmol_l')->label(__('dossiers.evidence_data.peroxide_value'))->numeric()->suffix('mmol/L')
                    : null,
                in_array('microbiology_specification', $facts, true)
                    ? Textarea::make('facts.microbiology_specification')->label(__('dossiers.evidence_data.microbiology'))->rows(2)
                    : null,
            ])))
            ->statePath('evidenceDataState');
    }

    public function startEditData(int $itemId): void
    {
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->with(['constituents', 'useLimits'])->findOrFail($itemId);
        $this->dataItemId = $item->id;
        $this->withdrawItemId = null;
        $this->evidenceDataForm->fill([
            'constituents' => $item->constituents->map(fn ($row): array => [
                'kind' => $row->kind->value,
                'allergen_id' => $row->allergen_id,
                'substance_id' => $row->substance_id,
                'max_value' => $row->max_value,
                'note' => $row->note,
            ])->all(),
            'use_limits' => $item->useLimits->map(fn ($row): array => [
                'ifra_product_category_id' => $row->ifra_product_category_id,
                'product_class_label' => $row->product_class_label,
                'limit_type' => $row->limit_type->value,
                'max_percent' => $row->max_percent,
            ])->all(),
            'facts' => $item->facts ?? [],
            'constituents_complete' => $item->constituents_complete,
        ]);
    }

    public function saveEvidenceData(SaveDossierEvidenceData $saveData): void
    {
        $state = $this->evidenceDataForm->getState();
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->dataItemId);

        $this->withFormErrors('evidenceDataState', fn () => $saveData->handle($this->user(), $this->lockedWorkspace(), $item, [
            'constituents' => array_values($state['constituents'] ?? []),
            'use_limits' => array_values($state['use_limits'] ?? []),
            'facts' => $state['facts'] ?? [],
            'constituents_complete' => (bool) ($state['constituents_complete'] ?? false),
        ]));

        $this->dataItemId = null;
        $this->status = __('dossiers.raw_materials.saved');
    }

    public function startWithdraw(int $itemId): void
    {
        $this->withdrawItemId = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($itemId)->id;
        $this->dataItemId = null;
        $this->withdrawReason = '';
    }

    public function withdrawEvidence(WithdrawDossierEvidence $withdraw): void
    {
        $item = DossierEvidenceItem::query()->where('workspace_id', $this->workspaceId)->findOrFail($this->withdrawItemId);
        $withdraw->handle($this->user(), $this->lockedWorkspace(), $item, $this->withdrawReason);
        $this->reset('withdrawItemId', 'withdrawReason');
        $this->status = __('dossiers.raw_materials.withdrawn');
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
                ->with(['supplier', 'evidenceItems' => fn ($query) => $query->active()->with(['file', 'constituents.allergen', 'constituents.substance', 'useLimits.ifraProductCategory'])->latest()])
                ->orderBy('status')
                ->get(),
            'amendmentCodes' => $this->amendmentCodes(),
        ]);
    }

    /**
     * @return array<int, string> amendment code by id
     */
    private function amendmentCodes(): array
    {
        return once(fn (): array => IfraAmendment::query()->orderByDesc('code')->pluck('code', 'id')->all());
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
