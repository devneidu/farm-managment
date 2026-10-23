<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livestock exits: the "moved in" record of a batch-to-batch move points at the "moved out" record of the other batch.
 * Records are append-only, so the link is written once, on the later record, at insert time. Same farm, any production cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_records', function (Blueprint $t) {
            $t->uuid('linked_record_id')->nullable()->after('corrects_record_id');
            $t->index('linked_record_id', 'records_linked_record_idx');
            $t->foreign(['farm_id', 'linked_record_id'], 'records_linked_record_fk')->references(['farm_id', 'id'])->on('operational_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operational_records', function (Blueprint $t) {
            $t->dropForeign('records_linked_record_fk');
            $t->dropIndex('records_linked_record_idx');
            $t->dropColumn('linked_record_id');
        });
    }
};
