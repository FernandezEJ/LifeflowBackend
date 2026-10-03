<?php

namespace App\Console\Commands;

use App\Services\FlowieConversationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('flowie:purge-deleted')]
#[Description('Permanently purge Flowie conversations and messages deleted for at least 30 days')]
class PurgeDeletedFlowieConversations extends Command
{
    public function handle(FlowieConversationService $service): int
    {
        $count = $service->purge();
        $this->info('Purged '.$count.' Flowie conversation(s).');

        return self::SUCCESS;
    }
}
