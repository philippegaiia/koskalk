<?php

namespace App\Actions\ContextualHelp;

use App\Enums\HelpContentExportReason;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentExportService;
use Illuminate\Support\Facades\Gate;

final class RequestHelpContentExport
{
    public function __construct(private readonly HelpContentExportService $exports) {}

    public function handle(User $actor): HelpContentExport
    {
        Gate::forUser($actor)->authorize('export', HelpTopic::class);

        return $this->exports->request(HelpContentExportReason::Manual, $actor);
    }
}
