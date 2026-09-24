<?php

namespace App\Filament\Pages;

use App\Actions\ContextualHelp\ImportHelpContent;
use App\Actions\ContextualHelp\RequestHelpContentExport;
use App\Enums\HelpContentExportStatus;
use App\Enums\HelpContentImportMode;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentBackupRemoval;
use App\Services\ContextualHelp\HelpContentExportService;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class HelpContentMaintenance extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected string $view = 'filament.pages.help-content-maintenance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowDownTray;

    protected static ?int $navigationSort = 83;

    protected static ?string $title = 'Help imports & backups';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $previewState = [];

    /** @var list<string> */
    public array $selectedChanges = [];

    /** @var list<string> */
    public array $selectedBackups = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('export', HelpTopic::class) ?? false;
    }

    public function boot(): void
    {
        Gate::authorize('export', HelpTopic::class);
    }

    public function mount(): void
    {
        $this->form->fill(['mode' => HelpContentImportMode::Bootstrap->value]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Import a help package')
                ->description('Upload a JSON export, review the differences, then choose what to import.')
                ->schema([
                    FileUpload::make('manifest')
                        ->label('JSON package')
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->rules(['extensions:json'])
                        ->maxSize(10240)
                        ->disk('local')
                        ->visibility('private')
                        ->storeFiles(false)
                        ->previewable(false)
                        ->required()
                        ->helperText('Private temporary upload. Maximum 10 MiB.')
                        ->columnSpanFull(),
                    Select::make('mode')
                        ->label('Import mode')
                        ->options([
                            HelpContentImportMode::Bootstrap->value => 'Bootstrap missing English drafts',
                            HelpContentImportMode::MergeDrafts->value => 'Merge selected drafts',
                            HelpContentImportMode::RestoreEmpty->value => 'Restore an empty help store',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Merges preserve published content and require a verified offsite backup. Restore requires all help content tables to be empty.')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
        ]);
    }

    public function previewImport(HelpContentManifest $manifests, HelpContentImporter $importer): void
    {
        Gate::authorize('import', HelpTopic::class);
        $this->previewState = [];
        $this->selectedChanges = [];
        $state = $this->form->getState();
        $mode = $this->mode($state);
        $manifest = $this->readUpload($state, $manifests);
        $this->previewState = $importer->preview($manifest, $mode);
        $this->selectedChanges = collect($this->previewState['changes'])
            ->filter(fn (array $change): bool => $change['will_change'])
            ->keys()->map(fn (int $index): string => (string) $index)->values()->all();
    }

    public function applyImport(HelpContentManifest $manifests, ImportHelpContent $import): void
    {
        Gate::authorize('import', HelpTopic::class);
        if ($this->previewState === []) {
            throw ValidationException::withMessages(['manifest' => 'Preview the package before importing.']);
        }
        $state = $this->form->getState();
        $mode = $this->mode($state);
        if ($mode->value !== $this->previewState['mode']) {
            throw ValidationException::withMessages(['manifest' => 'The import mode changed. Preview the package again.']);
        }
        $manifest = $this->readUpload($state, $manifests);
        $selection = [];
        if ($mode === HelpContentImportMode::RestoreEmpty) {
            $selection = $this->previewState['selection'];
        } else {
            $this->validate(['selectedChanges' => ['required', 'array', 'max:100000'], 'selectedChanges.*' => ['required', 'string', 'distinct', 'regex:/^(0|[1-9][0-9]*)$/']]);
            foreach ($this->selectedChanges as $index) {
                if (! ($this->previewState['changes'][$index]['will_change'] ?? false)) {
                    throw ValidationException::withMessages(['selectedChanges' => 'Select changes from the current preview.']);
                }
                $selection[] = $this->previewState['selection'][$index];
            }
        }
        try {
            $result = $import->handle($this->user(), $manifest, $mode, $selection, $this->previewState['manifest_hash']);
        } catch (RuntimeException $exception) {
            report($exception);
            $this->addError('manifest', 'The import could not be completed. Check backup status and try again.');

            return;
        }
        $this->previewState = [];
        $this->selectedChanges = [];
        $this->form->fill(['mode' => HelpContentImportMode::Bootstrap->value]);
        Notification::make()->title('Help import complete')->body("Imported: {$result['imported']}. Skipped: {$result['skipped']}.")->success()->send();
    }

    public function requestExport(RequestHelpContentExport $request): void
    {
        Gate::authorize('export', HelpTopic::class);
        $request->handle($this->user());
        Notification::make()->title('Backup requested')->body('The backup will appear here after remote verification.')->success()->send();
    }

    public function retryExport(string $publicId, HelpContentExportService $exports): void
    {
        Gate::authorize('export', HelpTopic::class);
        $export = HelpContentExport::query()->where('public_id', $publicId)->whereNull('removed_at')->where('status', HelpContentExportStatus::Failed)->firstOrFail();
        $exports->dispatch($export);
        Notification::make()->title('Backup retry requested')->success()->send();
    }

    public function removeExport(string $publicId, HelpContentBackupRemoval $removal): void
    {
        $export = HelpContentExport::query()->where('public_id', $publicId)->firstOrFail();
        $removal->remove($this->user(), $publicId);
        $this->selectedBackups = array_values(array_diff($this->selectedBackups, [$publicId]));
        Notification::make()->title('Backup #'.$export->id.' deleted')->success()->send();
    }

    public function selectVisibleBackups(): void
    {
        Gate::authorize('export', HelpTopic::class);
        $data = $this->getViewData();
        $this->selectedBackups = $data['recentExports']->when($data['lastSuccessfulExport'], fn ($exports) => $exports->push($data['lastSuccessfulExport']))
            ->filter(fn (HelpContentExport $export): bool => in_array($export->status, [HelpContentExportStatus::Succeeded, HelpContentExportStatus::Failed], true))
            ->pluck('public_id')->unique()->values()->all();
    }

    public function removeSelectedExports(HelpContentBackupRemoval $removal): void
    {
        Gate::authorize('export', HelpTopic::class);
        $this->validate(['selectedBackups' => ['required', 'array', 'max:21'], 'selectedBackups.*' => ['required', 'uuid', 'distinct']]);
        $count = count($this->selectedBackups);
        $eligible = HelpContentExport::query()->whereIn('public_id', $this->selectedBackups)->whereNull('removed_at')
            ->whereIn('status', [HelpContentExportStatus::Succeeded, HelpContentExportStatus::Failed])->count();
        if ($eligible !== $count) {
            throw ValidationException::withMessages(['selectedBackups' => 'Some selected backups are no longer available for deletion. Clear the selection and select again.']);
        }
        foreach ($this->selectedBackups as $publicId) {
            $removal->remove($this->user(), $publicId);
            $this->selectedBackups = array_values(array_diff($this->selectedBackups, [$publicId]));
        }
        Notification::make()->title($count.' backups deleted')->success()->send();
    }

    public function clearFailedExports(HelpContentBackupRemoval $removal): void
    {
        Gate::authorize('export', HelpTopic::class);
        $ids = HelpContentExport::query()->whereNull('removed_at')->where('status', HelpContentExportStatus::Failed)->pluck('public_id');
        foreach ($ids as $publicId) {
            $removal->remove($this->user(), $publicId, failedOnly: true);
        }
        $this->selectedBackups = array_values(array_diff($this->selectedBackups, $ids->all()));
        Notification::make()->title('Failed backups cleared')->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $failedCount = HelpContentExport::query()->whereNull('removed_at')->where('status', HelpContentExportStatus::Failed)->count();

        return [
            'backupCount' => HelpContentExport::query()->whereNull('removed_at')->count(),
            'failedCount' => $failedCount,
            'lastSuccessfulExport' => HelpContentExport::query()->whereNull('removed_at')->where('status', HelpContentExportStatus::Succeeded)->latest('completed_at')->latest('id')->first(),
            'hasFailedExports' => $failedCount > 0,
            'recentExports' => HelpContentExport::query()->whereNull('removed_at')->latest('id')->limit(20)->get(),
        ];
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function readUpload(array $state, HelpContentManifest $manifests): array
    {
        $file = $state['manifest'] ?? null;
        if (! $file instanceof TemporaryUploadedFile || $file->getSize() > HelpContentManifest::MAX_BYTES) {
            throw ValidationException::withMessages(['manifest' => 'Upload a JSON package no larger than 10 MiB.']);
        }

        return $manifests->decode($file->get());
    }

    /** @param array<string, mixed> $state */
    private function mode(array $state): HelpContentImportMode
    {
        $mode = HelpContentImportMode::tryFrom((string) ($state['mode'] ?? ''));
        if ($mode === null) {
            throw ValidationException::withMessages(['manifest' => 'Choose a valid import mode.']);
        }

        return $mode;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
