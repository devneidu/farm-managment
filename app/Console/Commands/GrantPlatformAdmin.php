<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The only way to create a platform-admin grant: an operator with server access, never an API caller. */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform:grant-admin {email : Email of an existing, verified user} {--role=admin : admin (read+write) or support (read only)}';

    protected $description = 'Grant (or change) a user platform-administration role.';

    public function handle(AuditLogger $audit): int
    {
        $role = PlatformRole::tryFrom((string) $this->option('role'));
        if (! $role) {
            $this->error('Role must be admin or support.');

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! $user->hasVerifiedEmail() || $user->isSuspended()) {
            $this->error('No verified, active user has that email.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $role, $audit) {
            $grant = PlatformAdmin::firstOrNew(['user_id' => $user->id]);
            $before = $grant->role?->value;
            $grant->role = $role;
            $grant->save();
            $audit->record(null, null, 'platform.admin_granted', 'user', $user->id, $user->email, ['from' => $before, 'to' => $role->value]);
        });
        $this->info("{$user->email} is now a platform {$role->value}.");

        return self::SUCCESS;
    }
}
