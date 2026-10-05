<?php

namespace App\Console\Commands;

use App\Domain\Finance\UserProvisioner;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'moneytalks:user:create {wa_id : WhatsApp ID, digits with country code} {--name= : Display name}';

    protected $description = 'Create (or top up) a user and seed their default categories, aliases and merchants';

    public function handle(UserProvisioner $provisioner): int
    {
        $waId = preg_replace('/\D+/', '', (string) $this->argument('wa_id'));
        if ($waId === '') {
            $this->error('wa_id must contain digits.');

            return self::FAILURE;
        }

        $allowed = config('moneytalks.allowed_wa_ids');
        if ($allowed !== [] && ! in_array($waId, $allowed, true)) {
            $this->error('That number is not in ALLOWED_WA_IDS (personal mode).');

            return self::FAILURE;
        }

        $user = $provisioner->provision($waId, $this->option('name'));

        $this->info("User {$user->id} ready: {$user->categories()->count()} categories, {$user->aliases()->count()} aliases.");

        return self::SUCCESS;
    }
}
