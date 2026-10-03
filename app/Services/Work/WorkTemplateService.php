<?php

namespace App\Services\Work;

use App\Enums\CycleKind;
use App\Enums\Permission;
use App\Enums\Recurrence;
use App\Enums\TemplateAnchor;
use App\Http\Requests\Work\ApplyWorkTemplateRequest;
use App\Http\Requests\Work\ListWorkTemplatesRequest;
use App\Http\Requests\Work\StoreWorkTemplateRequest;
use App\Http\Requests\Work\UpdateWorkTemplateRequest;
use App\Models\BreedingProject;
use App\Models\Farm;
use App\Models\ProductionCycle;
use App\Models\Schedule;
use App\Models\TemplateApplication;
use App\Models\WorkTemplate;
use App\Models\WorkTemplateItem;
use App\Services\Records\RecordTypeRegistry;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Platform templates (read-only) and farm templates (cloned or created, versioned). Applying a template materialises independent
 * schedules (and their first tasks) against ONE cycle or breeding project, relative to that target's own dates; later template
 * edits never rewrite what was applied. Applying is a recommendation the user triggers: nothing applies automatically.
 */
class WorkTemplateService
{
    public function __construct(private WorkSupport $support, private ScheduleService $schedules) {}

    private function visible(FarmContext $ctx): Builder
    {
        // Platform templates are visible only once published; a draft being prepared in Platform Admin never reaches a farm.
        return WorkTemplate::where(fn ($q) => $q->where(fn ($p) => $p->whereNull('farm_id')->whereNotNull('published_at'))->orWhere('farm_id', $ctx->farm->id));
    }

    public function find(FarmContext $ctx, string $id): WorkTemplate
    {
        $ctx->authorize(Permission::TaskView);

        return $this->visible($ctx)->with('items')->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $input): Collection
    {
        $ctx->authorize(Permission::TaskView);
        $f = Validator::make($input, (new ListWorkTemplatesRequest)->rules())->validate();
        $q = $this->visible($ctx)->with('items');
        foreach (['source', 'applies_to'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }
        if (isset($f['is_active'])) {
            $q->where('is_active', $f['is_active']);
        }

        return $q->orderBy('source')->orderBy('name')->get();
    }

    /** Templates that fit a new cycle / breeding project, flagged when already applied to it. The user may apply, clone-and-customise, or ignore them. */
    public function recommended(FarmContext $ctx, array $input): Collection
    {
        $ctx->authorize(Permission::TaskView);
        $f = Validator::make($input, (new ListWorkTemplatesRequest)->rules())->validate();
        if (isset($f['production_cycle_id']) === isset($f['breeding_project_id'])) {
            $this->support->invalid('production_cycle_id', 'Provide exactly one of production_cycle_id or breeding_project_id.');
        }
        $project = isset($f['breeding_project_id']) ? BreedingProject::where('farm_id', $ctx->farm->id)->findOrFail($f['breeding_project_id']) : null;
        $cycle = ProductionCycle::ofFarm($ctx->farm)->with(['livestock', 'crop'])->findOrFail($project?->production_cycle_id ?? $f['production_cycle_id']);
        $key = $project ? 'project:'.$project->id : 'cycle:'.$cycle->id;
        $applied = TemplateApplication::where('farm_id', $ctx->farm->id)->where('target_key', $key)->pluck('id', 'work_template_id');

        return $this->visible($ctx)->where('is_active', true)->where('applies_to', $project ? 'breeding_project' : 'production_cycle')->with('items')->orderBy('source')->orderBy('name')->get()
            ->filter(fn ($t) => $this->applicable($t, $cycle, $project))
            ->each(function ($t) use ($applied) {
                $t->setAttribute('application_id', $applied[$t->id] ?? null);
            })->values();
    }

    public function create(FarmContext $ctx, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new StoreWorkTemplateRequest)->rules())->validate();
        $this->assertShape($data, $data['applies_to']);

        return DB::transaction(function () use ($ctx, $data) {
            $template = WorkTemplate::create($this->columns($data) + ['farm_id' => $ctx->farm->id, 'source' => WorkTemplate::FARM, 'created_by' => $ctx->membership->user_id, 'version' => 1]);
            $this->saveItems($template, $data['items']);

            return $template->load('items');
        }, 3);
    }

    /** Farm templates only. Any edit is version + 1; already-applied schedules keep their own copy. */
    public function update(FarmContext $ctx, string $id, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new UpdateWorkTemplateRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            $template = $this->visible($ctx)->lockForUpdate()->findOrFail($id);
            $this->assertEditable($template);
            $merged = array_replace($template->only(['cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow']), $data);
            $this->assertShape($merged + ['items' => $data['items'] ?? []], $template->applies_to, partial: ! isset($data['items']));
            $template->update($this->columns($data) + ['version' => $template->version + 1]);
            if (isset($data['items'])) {
                $template->items()->delete();
                $this->saveItems($template, $data['items']);
            }

            return $template->load('items');
        }, 3);
    }

    /** A farm-owned editable copy of any visible template ("customise"). */
    public function clone(FarmContext $ctx, string $id, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $name = Validator::make($input, ['name' => ['sometimes', 'string', 'max:150']])->validate()['name'] ?? null;

        return DB::transaction(function () use ($ctx, $id, $name) {
            $source = $this->visible($ctx)->with('items')->findOrFail($id);
            $copy = WorkTemplate::create([
                'farm_id' => $ctx->farm->id, 'source' => WorkTemplate::FARM, 'name' => $name ?? $source->name.' (copy)', 'description' => $source->description, 'applies_to' => $source->applies_to,
                'cycle_kind' => $source->cycle_kind, 'operation_type_id' => $source->operation_type_id, 'species_id' => $source->species_id, 'crop_type_id' => $source->crop_type_id,
                'breeding_workflow' => $source->breeding_workflow, 'version' => 1, 'is_active' => true, 'cloned_from_id' => $source->id, 'created_by' => $ctx->membership->user_id,
            ]);
            $this->saveItems($copy, $source->items->map(fn ($i) => $this->itemData($i))->all());

            return $copy->load('items');
        }, 3);
    }

    /** Materialises the template's schedules/tasks for one target. Once per template and target; replay-safe by idempotency_key. */
    public function apply(FarmContext $ctx, string $id, array $input): TemplateApplication
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new ApplyWorkTemplateRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['template' => $id] + $data);
            $replay = TemplateApplication::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                if (! hash_equals($replay->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $replay;
            }
            $template = $this->visible($ctx)->with('items')->findOrFail($id);
            if (! $template->is_active) {
                throw new ApiHttpException(409, 'template_inactive', 'This template is inactive.');
            }
            $projectTemplate = $template->applies_to === 'breeding_project';
            $targetField = $projectTemplate ? 'breeding_project_id' : 'production_cycle_id';
            $other = $projectTemplate ? 'production_cycle_id' : 'breeding_project_id';
            if (empty($data[$targetField]) || ! empty($data[$other])) {
                $this->support->invalid($targetField, 'This template applies to a '.str_replace('_', ' ', $template->applies_to).'; provide '.$targetField.' only.');
            }
            [$cycle, $project] = $this->support->context($ctx->farm, $data['production_cycle_id'] ?? null, $data['breeding_project_id'] ?? null);
            $this->support->assertWritable($cycle, $project);
            if (! $this->applicable($template, $cycle, $project)) {
                $this->support->invalid($targetField, 'This template does not fit the selected '.str_replace('_', ' ', $template->applies_to).'.');
            }
            $key = $project ? 'project:'.$project->id : 'cycle:'.$cycle->id;
            if (TemplateApplication::where('farm_id', $ctx->farm->id)->where('work_template_id', $template->id)->where('target_key', $key)->exists()) {
                throw new ApiHttpException(409, 'template_already_applied', 'This template was already applied to this target.');
            }
            $application = TemplateApplication::create([
                'farm_id' => $ctx->farm->id, 'work_template_id' => $template->id, 'template_version' => $template->version, 'production_cycle_id' => $cycle->id,
                'breeding_project_id' => $project?->id, 'target_key' => $key, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'created_by' => $ctx->membership->user_id,
            ]);
            $totals = ['schedules_created' => 0, 'tasks_created' => 0, 'skipped_past' => 0, 'skipped_no_anchor' => 0];
            foreach ($template->items as $item) {
                $anchor = $this->anchorDate($item->anchor, $cycle, $project);
                if ($anchor === null) {
                    $totals['skipped_no_anchor']++;

                    continue;
                }
                $this->support->assertLinked($item->linked_record_type, $cycle, $project);
                $base = CarbonImmutable::parse($anchor, 'UTC');
                $schedule = Schedule::create($this->schedules->attributes([
                    'title' => $item->title, 'category' => $item->category->value, 'instructions' => $item->instructions, 'recurrence' => $item->recurrence->value,
                    'interval_value' => $item->interval_value, 'weekdays' => $item->weekdays, 'starts_on' => $base->addDays($item->offset_days)->toDateString(),
                    'ends_on' => $item->until_offset_days !== null ? $base->addDays($item->until_offset_days)->toDateString() : null, 'occurrence_limit' => $item->occurrence_limit,
                    'due_time' => $item->due_time, 'reminder_offsets' => $item->reminder_offsets, 'assigned_role' => $item->assigned_role,
                    'linked_record_type' => $item->linked_record_type, 'requires_evidence' => $item->requires_evidence,
                ]) + ['farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle->id, 'breeding_project_id' => $project?->id, 'template_application_id' => $application->id,
                    'template_item_id' => $item->id, 'created_by' => $ctx->membership->user_id, 'status' => 'active']);
                $made = $this->schedules->materialize($ctx->farm, $schedule, $cycle, $project);
                $totals['schedules_created']++;
                $totals['tasks_created'] += $made['created'];
                $totals['skipped_past'] += $made['skipped_past'];
            }
            $application->update($totals);

            return $application;
        }, 3);
    }

    private function anchorDate(TemplateAnchor $anchor, ?ProductionCycle $cycle, ?BreedingProject $project): ?string
    {
        return match ($anchor) {
            TemplateAnchor::CycleStart => $cycle?->start_date?->toDateString(),
            TemplateAnchor::CycleExpectedEnd => $cycle?->expected_end_date?->toDateString(),
            TemplateAnchor::BreedingStart => $project?->start_date?->toDateString(),
            TemplateAnchor::BreedingExpected => ($project?->expected_date ?? $project?->expected_from)?->toDateString(),
            TemplateAnchor::BreedingExpectedTo => ($project?->expected_date ?? $project?->expected_to)?->toDateString(),
        };
    }

    private function applicable(WorkTemplate $t, ProductionCycle $cycle, ?BreedingProject $project): bool
    {
        return ($t->cycle_kind === null || $t->cycle_kind === $cycle->kind->value)
            && ($t->operation_type_id === null || $t->operation_type_id === $cycle->operation_type_id)
            && ($t->species_id === null || $t->species_id === $cycle->livestock?->species_id)
            && ($t->crop_type_id === null || $t->crop_type_id === $cycle->crop?->crop_type_id)
            && ($t->breeding_workflow === null || $t->breeding_workflow === $project?->workflow->value);
    }

    private function assertEditable(WorkTemplate $template): void
    {
        if ($template->source !== WorkTemplate::FARM) {
            throw new ApiHttpException(403, 'platform_template_readonly', 'Platform templates cannot be edited; clone one to customise it.');
        }
    }

    public function assertShape(array $data, string $appliesTo, bool $partial = false): void
    {
        $project = $appliesTo === 'breeding_project';
        if (! $project && ! empty($data['breeding_workflow'])) {
            $this->support->invalid('breeding_workflow', 'A breeding workflow applies to breeding templates only.');
        }
        if ($project && (($data['cycle_kind'] ?? null) === CycleKind::Crop->value || ! empty($data['crop_type_id']))) {
            $this->support->invalid('cycle_kind', 'Breeding templates apply to livestock only.');
        }
        if (! empty($data['species_id']) && ! in_array($data['cycle_kind'] ?? null, [null, CycleKind::Livestock->value], true)) {
            $this->support->invalid('species_id', 'A species needs a livestock template.');
        }
        if (! empty($data['crop_type_id']) && ! in_array($data['cycle_kind'] ?? null, [null, CycleKind::Crop->value], true)) {
            $this->support->invalid('crop_type_id', 'A crop type needs a crop template.');
        }
        if ($partial) {
            return;
        }
        foreach ($data['items'] as $i => $item) {
            $anchor = TemplateAnchor::from($item['anchor']);
            if ($anchor->target() !== $appliesTo) {
                $this->support->invalid('items.'.$i.'.anchor', 'This anchor does not belong to a '.str_replace('_', ' ', $appliesTo).' template.');
            }
            $recurrence = $item['recurrence'] ?? 'none';
            if ($recurrence === Recurrence::None->value && (isset($item['until_offset_days']) || isset($item['occurrence_limit']) || ! empty($item['weekdays']))) {
                $this->support->invalid('items.'.$i.'.recurrence', 'A one-off item has no end offset, occurrence limit or weekdays.');
            }
            if ($recurrence !== Recurrence::Weekly->value && ! empty($item['weekdays'])) {
                $this->support->invalid('items.'.$i.'.weekdays', 'Weekdays apply to weekly items only.');
            }
            if (isset($item['until_offset_days']) && $item['until_offset_days'] < ($item['offset_days'] ?? 0)) {
                $this->support->invalid('items.'.$i.'.until_offset_days', 'The end must not be before the first task.');
            }
            $linked = $item['linked_record_type'] ?? null;
            if ($linked !== null && ($data['cycle_kind'] ?? null) !== null && LinkedRecords::evidenceType($linked)->value === 'operational_record') {
                $kind = (new RecordTypeRegistry)->definitions()[$linked]['kind'] ?? null;
                if ($kind !== null && $kind !== $data['cycle_kind']) {
                    $this->support->invalid('items.'.$i.'.linked_record_type', 'This record type does not apply to the template\'s cycle kind.');
                }
            }
        }
    }

    public function columns(array $data): array
    {
        $out = [];
        foreach (['name', 'description', 'applies_to', 'cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }

    public function saveItems(WorkTemplate $template, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            WorkTemplateItem::create([
                'work_template_id' => $template->id, 'position' => $position + 1, 'title' => $item['title'], 'category' => $item['category'], 'instructions' => $item['instructions'] ?? null,
                'anchor' => $item['anchor'], 'offset_days' => $item['offset_days'] ?? 0, 'recurrence' => $item['recurrence'] ?? 'none', 'interval_value' => $item['interval_value'] ?? 1,
                'weekdays' => ! empty($item['weekdays']) ? array_values($item['weekdays']) : null, 'until_offset_days' => $item['until_offset_days'] ?? null,
                'occurrence_limit' => $item['occurrence_limit'] ?? null, 'due_time' => isset($item['due_time']) ? substr($item['due_time'], 0, 5) : null,
                'reminder_offsets' => $item['reminder_offsets'] ?? null, 'assigned_role' => $item['assigned_role'] ?? null,
                'linked_record_type' => $item['linked_record_type'] ?? null, 'requires_evidence' => $item['requires_evidence'] ?? false,
            ]);
        }
    }

    public function itemData(WorkTemplateItem $i): array
    {
        return [
            'title' => $i->title, 'category' => $i->category->value, 'instructions' => $i->instructions, 'anchor' => $i->anchor->value, 'offset_days' => $i->offset_days,
            'recurrence' => $i->recurrence->value, 'interval_value' => $i->interval_value, 'weekdays' => $i->weekdays, 'until_offset_days' => $i->until_offset_days,
            'occurrence_limit' => $i->occurrence_limit, 'due_time' => $i->due_time, 'reminder_offsets' => $i->reminder_offsets,
            'assigned_role' => $i->assigned_role, 'linked_record_type' => $i->linked_record_type, 'requires_evidence' => $i->requires_evidence,
        ];
    }
}
