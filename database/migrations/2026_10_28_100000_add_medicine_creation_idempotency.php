<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request-level idempotency of POST /health/medicines: the key and a hash of the request are kept on the medicine's profile (one profile
 * per medicine, created in the same transaction). Nullable: medicines created without a key, or before this existed, carry NULL, and MySQL
 * allows many NULLs under the unique index. Same key convention as inventory movements and health records (farm-scoped, case-sensitive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_profiles', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable()->collation('utf8mb4_bin')->after('medicine_type');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['farm_id', 'idempotency_key'], 'medicine_profiles_farm_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('medicine_profiles', function (Blueprint $table) {
            $table->dropUnique('medicine_profiles_farm_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
