<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Privacy\PrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportUserData extends Command
{
    protected $signature = 'moneytalks:user:export {wa_id : the user\'s WhatsApp number} {--to= : output file (default storage/app/private/exports/...)}';

    protected $description = 'Write everything stored about a user as JSON (privacy export)';

    public function handle(PrivacyService $privacy): int
    {
        $user = User::findByWaId((string) $this->argument('wa_id'));
        if (! $user || $user->status === 'deleted') {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $path = $this->option('to') ?: storage_path('app/private/exports/user-export-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path), 0700);
        touch($path);
        chmod($path, 0600); // before the data is written
        file_put_contents($path, json_encode($privacy->export($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        $this->info("Wrote {$path}. It contains personal data: move it somewhere safe and delete it when done.");

        return self::SUCCESS;
    }
}
