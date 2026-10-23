<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Breeding fixes: a readable project title, and where an outcome's offspring went (the mother's batch, or a new batch created for them).
 * Existing outcomes all went to the project's own batch; existing projects get the title the API would have generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breeding_projects', function (Blueprint $t) {
            $t->string('title', 120)->nullable()->after('reference');
        });
        Schema::table('breeding_outcomes', function (Blueprint $t) {
            $t->string('destination', 16)->default('mother_batch')->after('kind');
        });
        DB::statement("UPDATE breeding_projects p JOIN livestock_batch_details l ON l.production_cycle_id = p.production_cycle_id JOIN species s ON s.id = l.species_id SET p.title = CONCAT(s.name, IF(p.workflow = 'incubation', ' hatch', ' breeding'), ' – ', DATE_FORMAT(p.start_date, '%Y-%m-%d'))");
    }

    public function down(): void
    {
        Schema::table('breeding_outcomes', function (Blueprint $t) {
            $t->dropColumn('destination');
        });
        Schema::table('breeding_projects', function (Blueprint $t) {
            $t->dropColumn('title');
        });
    }
};
