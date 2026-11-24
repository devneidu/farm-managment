<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Account deletion is anonymisation: the user row stays (records, ledgers and audit logs point at it) but carries no personal data.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable()->after('suspended_at');
        });

        // A farm whose only member deleted their account is archived: it keeps every record but nobody can open it.
        Schema::table('farms', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('farms', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymised_at'));
    }
};
