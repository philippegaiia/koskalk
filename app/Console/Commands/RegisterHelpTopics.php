<?php

namespace App\Console\Commands;

use App\Services\ContextualHelp\HelpTopicRegistry;
use Illuminate\Console\Command;

class RegisterHelpTopics extends Command
{
    protected $signature = 'help:register';

    protected $description = 'Register missing contextual help topic identities without changing content';

    public function handle(HelpTopicRegistry $registry): int
    {
        $this->info('Registered '.$registry->register().' missing help topics.');

        return self::SUCCESS;
    }
}
