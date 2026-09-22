<?php

namespace App\Filament\Resources\HelpTopics\Pages;

use App\Actions\ContextualHelp\AcceptHelpTranslation;
use App\Actions\ContextualHelp\DismissHelpTranslation;
use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Actions\ContextualHelp\RequestHelpTranslation;
use App\Actions\ContextualHelp\RestoreHelpTopicRevision;
use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Actions\ContextualHelp\SetHelpTopicArchived;
use App\Actions\ContextualHelp\WithdrawHelpTopic;
use App\Data\HelpContentInput;
use App\Enums\HelpTranslationRequestStatus;
use App\Filament\Resources\HelpTopics\HelpTopicResource;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Services\ContextualHelp\HelpContentRenderer;
use App\Services\ContextualHelp\WorkbenchHelpTopics;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditHelpTopic extends EditRecord
{
    protected static string $resource = HelpTopicResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    #[Locked]
    public string $editingLocale = 'en';

    #[Locked]
    public int $expectedLockVersion = 0;

    #[Locked]
    public ?int $sourceEnglishRevisionId = null;

    #[Locked]
    public ?int $reviewedEnglishRevisionId = null;

    #[Locked]
    public bool $intendedArchived = false;

    #[Locked]
    public ?int $translationSourceId = null;

    /** @var array<string, int> */
    #[Locked]
    public array $translationLockVersions = [];

    #[Locked]
    public ?string $candidateId = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $locale = $this->selectedLocale();
        $this->expectedLockVersion = $locale->lock_version;
        $this->sourceEnglishRevisionId = $locale->latestRevision?->source_english_revision_id;

        return ['title' => $locale->latestRevision?->title ?? '', 'summary' => $locale->latestRevision?->summary ?? '', 'body_markdown' => $locale->latestRevision?->body_markdown];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $revision = app(SaveHelpTopicDraft::class)->handle(auth()->user(), $this->selectedLocale(), new HelpContentInput($data['title'], $data['summary'], $data['body_markdown'] ?? null), $this->expectedLockVersion, $this->sourceEnglishRevisionId);
        $this->expectedLockVersion = $revision->topicLocale->lock_version;

        return $record->refresh();
    }

    public function getSubheading(): ?string
    {
        $locale = $this->selectedLocale();
        $status = ! $locale->published_revision_id ? 'Draft' : ($locale->latest_revision_id === $locale->published_revision_id ? 'Published' : 'Unpublished changes');
        if ($this->editingLocale !== 'en' && $locale->latestRevision?->source_english_revision_id !== $this->publishedEnglish()?->published_revision_id) {
            $status .= ' · Needs review against current English';
        }

        return strtoupper($this->editingLocale).' · '.$status.' · Save draft, preview, then publish.';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('language')->label('Language')->schema([
                Select::make('locale')->options(fn (): array => SupportedLocale::query()->ordered()->pluck('name', 'code')->all())->required()->default($this->editingLocale),
            ])->modalDescription('Changing language discards unsaved changes. Save your draft first.')
                ->action(function (array $data): void {
                    abort_unless(SupportedLocale::query()->where('code', $data['locale'])->exists(), 422);
                    $this->editingLocale = $data['locale'];
                    $this->fillForm();
                    if ($this->editingLocale !== 'en' && $this->sourceEnglishRevisionId === null) {
                        $this->sourceEnglishRevisionId = $this->publishedEnglish()?->published_revision_id;
                    }
                    $this->rememberData();
                }),
            Action::make('generateTranslations')->label('Generate translations')
                ->visible(fn (): bool => $this->publishedEnglish()?->published_revision_id !== null && ! $this->getRecord()->archived_at)
                ->schema([Select::make('locales')->label('Languages')->multiple()->required()->minItems(1)
                    ->options(fn (): array => SupportedLocale::query()->where('is_active', true)->where('code', '!=', 'en')->ordered()->pluck('name', 'code')->all())])
                ->mountUsing(function (Schema $schema): void {
                    $this->translationSourceId = $this->publishedEnglish()?->published_revision_id;
                    $this->translationLockVersions = $this->getRecord()->locales()->pluck('lock_version', 'locale')->all();
                    $schema->fill(['locales' => $this->editingLocale === 'en' ? [] : [$this->editingLocale]]);
                })
                ->modalDescription('Translate the published English revision into reviewable candidates. Existing drafts and published help stay in place. Each language uses an AI request.')
                ->action(function (array $data): void {
                    Gate::authorize('translate', $this->getRecord());
                    if (! $this->translationSourceId || $this->publishedEnglish()?->published_revision_id !== $this->translationSourceId) {
                        throw ValidationException::withMessages(['content' => __('help_admin.validation.translation_source')]);
                    }
                    DB::transaction(function () use ($data): void {
                        $topic = HelpTopic::query()->lockForUpdate()->findOrFail($this->getRecord()->id);
                        foreach ($data['locales'] as $code) {
                            abort_unless($code !== 'en' && SupportedLocale::query()->where('code', $code)->where('is_active', true)->exists(), 422);
                            $locale = $topic->locales()->firstOrCreate(['locale' => $code], ['lock_version' => 0]);
                            app(RequestHelpTranslation::class)->handle(auth()->user(), $locale, $this->translationSourceId, $this->translationLockVersions[$code] ?? 0);
                        }
                    }, attempts: 5);
                    Notification::make()->title('Translations queued for review')->success()->send();
                }),
            Action::make('preview')->label('Preview draft')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (): View => $this->previewContent(false)),
            Action::make('english')->label('Published English')->visible(fn (): bool => $this->editingLocale !== 'en')
                ->modalSubmitAction(false)->modalContent(fn (): View => view('filament.help-topics.preview', ['content' => $this->publishedEnglish()?->publishedRevision ? app(HelpContentRenderer::class)->render($this->publishedEnglish()->publishedRevision) : null])),
            Action::make('publish')->label('Publish')->requiresConfirmation()->modalHeading('Publish this saved revision?')
                ->modalDescription('The preview below is the saved revision. Unsaved editor changes are not included.')
                ->modalContent(fn (): View => $this->previewContent(true))
                ->action(function (): void {
                    $locale = $this->selectedLocale();
                    abort_unless($locale->latest_revision_id !== null, 422);
                    $this->expectedLockVersion = app(PublishHelpTopic::class)->handle(auth()->user(), $locale, $locale->latest_revision_id, $this->expectedLockVersion);
                    Notification::make()->title('Help published; backup pending')->success()->send();
                }),
            Action::make('withdraw')->label('Withdraw')->requiresConfirmation()->visible(fn (): bool => $this->selectedLocale()->published_revision_id !== null)
                ->action(function (): void {
                    $this->expectedLockVersion = app(WithdrawHelpTopic::class)->handle(auth()->user(), $this->selectedLocale(), $this->expectedLockVersion);
                }),
            Action::make('history')->label('Revision history')->modalSubmitAction(false)
                ->modalContent(fn (): View => view('filament.help-topics.revision-history', ['revisions' => $this->selectedLocale()->revisions()->latest('revision_number')->get()])),
            Action::make('restore')->label('Restore as draft')->schema([
                Select::make('revision')->options(fn (): array => $this->selectedLocale()->revisions()->latest('revision_number')->get()->mapWithKeys(fn ($revision): array => [$revision->public_id => '#'.$revision->revision_number.' · '.$revision->created_at->format('Y-m-d H:i')])->all())->required(),
            ])->modalDescription('Restoration creates a new draft and preserves the existing history.')
                ->action(function (array $data): void {
                    $locale = $this->selectedLocale();
                    $revision = $locale->revisions()->where('public_id', $data['revision'])->firstOrFail();
                    app(RestoreHelpTopicRevision::class)->handle(auth()->user(), $locale, $revision, $this->expectedLockVersion);
                    $this->fillForm();
                }),
            Action::make('reviewed')->label('Mark reviewed against current English')->visible(fn (): bool => $this->editingLocale !== 'en')
                ->requiresConfirmation()->mountUsing(function (): void {
                    $this->reviewedEnglishRevisionId = $this->publishedEnglish()?->published_revision_id;
                })
                ->modalContent(fn (): View => view('filament.help-topics.preview', ['content' => $this->reviewedEnglishRevisionId ? app(HelpContentRenderer::class)->render(HelpTopicRevision::query()->findOrFail($this->reviewedEnglishRevisionId)) : null]))
                ->action(function (): void {
                    if (! $this->reviewedEnglishRevisionId || $this->publishedEnglish()?->published_revision_id !== $this->reviewedEnglishRevisionId) {
                        throw ValidationException::withMessages(['content' => __('help_admin.validation.translation_source')]);
                    }
                    $this->sourceEnglishRevisionId = $this->reviewedEnglishRevisionId;
                    $this->save();
                }),
            Action::make('archive')->label(fn (): string => $this->getRecord()->fresh()->archived_at ? 'Unarchive' : 'Archive')
                ->requiresConfirmation()->mountUsing(function (): void {
                    $this->intendedArchived = $this->getRecord()->fresh()->archived_at === null;
                })
                ->action(function (): void {
                    $topic = $this->getRecord()->fresh();
                    app(SetHelpTopicArchived::class)->handle(auth()->user(), $topic, $this->intendedArchived);
                    $this->record = $topic->fresh();
                }),
        ];
    }

    /** @return Collection<int, HelpTranslationRequest> */
    public function translationRequests(): Collection
    {
        return $this->selectedLocale()->translationRequests()->latest('id')->limit(20)->get();
    }

    public function acceptTranslationAction(): Action
    {
        return Action::make('acceptTranslation')->label('Review candidate')->modalHeading('Review translation candidate')
            ->modalSubmitActionLabel('Accept as draft')
            ->modalDescription('Acceptance replaces the saved draft. Unsaved editor changes will be discarded. Review the draft before publishing.')
            ->mountUsing(function (array $arguments): void {
                $this->candidateId = $this->selectedLocale()->translationRequests()->where('public_id', $arguments['request'] ?? '')->where('status', HelpTranslationRequestStatus::Completed)->firstOrFail()->public_id;
            })
            ->modalContent(function (): View {
                $request = $this->selectedCandidate();
                $render = app(HelpContentRenderer::class);
                $source = $request->sourceEnglishRevision;
                $current = $this->selectedLocale()->latestRevision;
                $candidate = $request->result;

                return view('filament.help-topics.translation-comparison', [
                    'source' => $render->render($source),
                    'current' => $current ? $render->render($current) : null,
                    'candidate' => $render->renderInput(new HelpContentInput($candidate['title'], $candidate['summary'], $candidate['body_markdown'])),
                ]);
            })
            ->action(function (): void {
                app(AcceptHelpTranslation::class)->handle(auth()->user(), $this->selectedCandidate(), $this->expectedLockVersion);
                $this->fillForm();
                $this->rememberData();
                Notification::make()->title('Translation accepted as draft')->success()->send();
            });
    }

    public function dismissTranslationAction(): Action
    {
        return Action::make('dismissTranslation')->label('Dismiss')->requiresConfirmation()
            ->action(function (array $arguments): void {
                $request = $this->selectedLocale()->translationRequests()->where('public_id', $arguments['request'] ?? '')->firstOrFail();
                app(DismissHelpTranslation::class)->handle(auth()->user(), $request);
            });
    }

    private function selectedCandidate(): HelpTranslationRequest
    {
        return $this->selectedLocale()->translationRequests()->where('public_id', $this->candidateId)->firstOrFail();
    }

    /** @return array{domain: string, locations: list<string>, english: ?array} */
    public function editorReference(): array
    {
        Gate::authorize('update', $this->getRecord());
        $english = $this->publishedEnglish()?->publishedRevision;

        return [
            'domain' => str_replace('_', ' ', $this->getRecord()->domain->value),
            'locations' => app(WorkbenchHelpTopics::class)->locations($this->getRecord()->key),
            'english' => $english ? app(HelpContentRenderer::class)->render($english) : null,
        ];
    }

    private function selectedLocale(): HelpTopicLocale
    {
        Gate::authorize('update', $this->getRecord());

        return DB::transaction(function (): HelpTopicLocale {
            $topic = HelpTopic::query()->lockForUpdate()->findOrFail($this->getRecord()->id);
            abort_unless(SupportedLocale::query()->where('code', $this->editingLocale)->exists(), 422);

            return $topic->locales()->firstOrCreate(['locale' => $this->editingLocale], ['lock_version' => 0]);
        }, attempts: 5);
    }

    private function publishedEnglish(): ?HelpTopicLocale
    {
        return $this->getRecord()->locales()->where('locale', 'en')->with('publishedRevision')->first();
    }

    private function previewContent(bool $saved): View
    {
        $locale = $this->selectedLocale();
        $content = $saved
            ? ($locale->latestRevision ? app(HelpContentRenderer::class)->render($locale->latestRevision) : null)
            : app(HelpContentRenderer::class)->renderInput(new HelpContentInput($this->data['title'] ?? '', $this->data['summary'] ?? '', $this->data['body_markdown'] ?? null));

        return view('filament.help-topics.preview', ['content' => $content]);
    }
}
