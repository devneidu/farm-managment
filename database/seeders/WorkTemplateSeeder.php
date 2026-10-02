<?php

namespace Database\Seeders;

use App\Models\Species;
use App\Models\WorkTemplate;
use App\Models\WorkTemplateItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Platform starter templates. They are configuration data (reminders to review work), not veterinary or agronomic guarantees,
 * and are never overwritten once present; farms clone them to customise.
 */
class WorkTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $chicken = Species::where('code', 'chicken')->first();

        $this->template('chicken-starter', 'Chicken starter routine', 'Placement check, early-care checks, daily feeding and water checks and weekly weighing for a chicken batch.', 'production_cycle', [
            'cycle_kind' => 'livestock', 'species_id' => $chicken?->id,
        ], [
            ['title' => 'Placement and setup check', 'category' => 'record_keeping', 'anchor' => 'cycle_start', 'offset_days' => 0, 'instructions' => 'Confirm housing, heat, water and feed are ready for the batch.'],
            ['title' => 'Early-care check', 'category' => 'growth_monitoring', 'anchor' => 'cycle_start', 'offset_days' => 1, 'recurrence' => 'daily', 'until_offset_days' => 3],
            ['title' => 'Feeding', 'category' => 'feeding_watering', 'anchor' => 'cycle_start', 'offset_days' => 0, 'recurrence' => 'daily', 'due_time' => '06:00', 'linked_record_type' => 'feed_use'],
            ['title' => 'Water and house check', 'category' => 'feeding_watering', 'anchor' => 'cycle_start', 'offset_days' => 0, 'recurrence' => 'daily', 'due_time' => '10:00', 'linked_record_type' => 'water'],
            ['title' => 'Weigh a sample', 'category' => 'growth_monitoring', 'anchor' => 'cycle_start', 'offset_days' => 7, 'recurrence' => 'weekly', 'linked_record_type' => 'weight'],
        ]);

        $this->template('crop-starter', 'Crop routine', 'Weekly field walk with pest observation and fortnightly weeding for a crop project.', 'production_cycle', [
            'cycle_kind' => 'crop',
        ], [
            ['title' => 'Field walk and pest check', 'category' => 'crop_care', 'anchor' => 'cycle_start', 'offset_days' => 7, 'recurrence' => 'weekly', 'linked_record_type' => 'pest_observation'],
            ['title' => 'Weeding', 'category' => 'crop_care', 'anchor' => 'cycle_start', 'offset_days' => 14, 'recurrence' => 'weekly', 'interval_value' => 2, 'linked_record_type' => 'weeding'],
        ]);

        $this->template('chicken-incubation', 'Chicken incubation checks', 'Candling and hatch-day tasks relative to the incubation start and the expected hatch date.', 'breeding_project', [
            'cycle_kind' => 'livestock', 'species_id' => $chicken?->id, 'breeding_workflow' => 'incubation',
        ], [
            ['title' => 'First candling', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_start', 'offset_days' => 7, 'linked_record_type' => 'breeding_check'],
            ['title' => 'Second candling', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_start', 'offset_days' => 14, 'linked_record_type' => 'breeding_check'],
            ['title' => 'Prepare hatching conditions', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_start', 'offset_days' => 18],
            ['title' => 'Hatch day: record the outcome', 'category' => 'breeding_reproduction', 'anchor' => 'breeding_expected', 'offset_days' => 0, 'linked_record_type' => 'breeding_outcome', 'requires_evidence' => true],
        ]);
    }

    private function template(string $code, string $name, string $description, string $appliesTo, array $scope, array $items): void
    {
        if (WorkTemplate::whereNull('farm_id')->where('code', $code)->exists()) {
            return;
        }
        DB::transaction(function () use ($code, $name, $description, $appliesTo, $scope, $items) {
            $template = WorkTemplate::create(['farm_id' => null, 'source' => WorkTemplate::PLATFORM, 'code' => $code, 'name' => $name, 'description' => $description, 'applies_to' => $appliesTo] + $scope);
            foreach ($items as $position => $item) {
                WorkTemplateItem::create($item + ['work_template_id' => $template->id, 'position' => $position + 1]);
            }
        });
    }
}
