<?php

namespace App\Console\Commands;

use App\Services\Conversation\ConversationStore;
use Illuminate\Console\Command;

class PurgeConversations extends Command
{
    protected $signature = 'moneytalks:conversation:purge';

    protected $description = 'Delete expired pending conversation items (questions and confirmations nobody answered)';

    public function handle(ConversationStore $store): int
    {
        $this->info('Purged '.$store->purgeExpired().' expired conversation item(s).');

        return self::SUCCESS;
    }
}
