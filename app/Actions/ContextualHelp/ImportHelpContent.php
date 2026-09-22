<?php

namespace App\Actions\ContextualHelp;

use App\Enums\HelpContentImportMode;
use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentImporter;
use Illuminate\Support\Facades\Gate;

final class ImportHelpContent
{
    public function __construct(private readonly HelpContentImporter $importer) {}

    /** @param array<string, mixed> $manifest @param list<array<string, mixed>> $selection @return array{imported:int, skipped:int} */
    public function handle(User $actor, array $manifest, HelpContentImportMode $mode, array $selection, string $expectedManifestHash): array
    {
        Gate::forUser($actor)->authorize('import', HelpTopic::class);

        return $this->importer->apply($manifest, $mode, $selection, $expectedManifestHash);
    }
}
