<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokePlatformAdmin extends Command
{
    protected $signature = 'platform:revoke-admin {email}';

    protected $description = 'Remove a user platform-administration role (their account and farms are untouched).';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        $grant = $user ? PlatformAdmin::where('user_id', $user->id)->first() : null;
        if (! $grant) {
            $this->error('That user has no platform role.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($grant, $user, $audit) {
            $audit->record(null, null, 'platform.admin_revoked', 'user', $user->id, $user->email, ['role' => $grant->role->value]);
            $grant->delete();
        });
        $this->info("Platform role removed from {$user->email}.");

        return self::SUCCESS;
    }
}
