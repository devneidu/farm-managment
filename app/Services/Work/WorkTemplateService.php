<?php

namespace App\Services\Work;

use App\Enums\CycleKind;
use App\Enums\Permission;
use App\Enums\Recurrence;
use App\Enums\TaskStatus;
use App\Enums\TemplateAnchor;
use App\Http\Requests\Work\ApplyWorkTemplateRequest;
use App\Http\Requests\Work\ListTemplateApplicationsRequest;
use App\Http\Requests\Work\ListWorkTemplatesRequest;
use App\Http\Requests\Work\PreviewWorkTemplateRequest;
use App\Http\Requests\Work\SaveAsRoutineRequest;
use App\Http\Requests\Work\StoreWorkTemplateRequest;
use App\Http\Requests\Work\UpdateWorkTemplateRequest;
use App\Models\BreedingProject;
use App\Models\CropType;
use App\Models\Farm;
use App\Models\OperationType;
use App\Models\ProductionCycle;
use App\Models\Schedule;
use App\Models\Species;
use App\Models\Task;
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
 * Routines (work templates). Ready-made (platform) routines are read-only; farm routines are created, cloned, saved from a task and versioned.
 * A routine can be for any batch, limited to a species / crop / kind, or general (no batch). Applying one materialises independent schedules
 * (and their first tasks) against ONE batch, breeding project or nothing, counting day 1 from a start date; later edits never rewrite what
 * was applied, and a routine can be removed from a batch again. Applying is a recommendation the user triggers: nothing applies automatically.
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
        $template = $this->visible($ctx)->with('items')->findOrFail($id);
        $this->decorate([$template]);

        return $template;
    }

    /**
     * Routines page: farm routines (source=farm) and ready-made ones (source=platform). With production_cycle_id / breeding_project_id each row
     * also carries suits_batch (does it fit that batch).
     */
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
        $templates = $q->orderBy('source')->orderBy('name')->get();
        $cycle = $project = null;
        if (isset($f['production_cycle_id']) || isset($f['breeding_project_id'])) {
            $project = isset($f['breeding_project_id']) ? BreedingProject::where('farm_id', $ctx->farm->id)->findOrFail($f['breeding_project_id']) : null;
            $cycle = ProductionCycle::ofFarm($ctx->farm)->with(['livestock', 'crop'])->findOrFail($project?->production_cycle_id ?? $f['production_cycle_id']);
        }
        $this->decorate($templates, $cycle, $project, $cycle !== null);

        return $templates;
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
        // The latest active application wins (a routine can be applied again, or removed).
        $applied = TemplateApplication::where('farm_id', $ctx->farm->id)->where('target_key', $key)->where('status', 'active')->orderBy('created_at')->pluck('id', 'work_template_id');

        $found = $this->visible($ctx)->where('is_active', true)->where('applies_to', $project ? 'breeding_project' : 'production_cycle')->with('items')->orderBy('source')->orderBy('name')->get()
            ->filter(fn ($t) => $this->applicable($t, $cycle, $project))
            ->each(function ($t) use ($applied) {
                $t->setAttribute('application_id', $applied[$t->id] ?? null);
            })->values();
        $this->decorate($found, $cycle, $project, true);

        return $found;
    }

    public function create(FarmContext $ctx, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new StoreWorkTemplateRequest)->rules())->validate();

        return $this->persist($ctx, $data);
    }

    /** @param  array<string, mixed>  $data validated template input (items may use the task form's repeat / reminders blocks) */
    private function persist(FarmContext $ctx, array $data): WorkTemplate
    {
        $data['items'] = $this->normalizeItems($data['items']);
        $this->assertShape($data, $data['applies_to']);

        return DB::transaction(function () use ($ctx, $data) {
            $template = WorkTemplate::create(array_replace(['is_active' => true], $this->columns($data)) + ['farm_id' => $ctx->farm->id, 'source' => WorkTemplate::FARM, 'created_by' => $ctx->membership->user_id, 'version' => 1]);
            $this->saveItems($template, $data['items']);
            $template->load('items');
            $this->decorate([$template]);

            return $template;
        }, 3);
    }

    /** Farm templates only. Any edit is version + 1; already-applied schedules keep their own copy. */
    public function update(FarmContext $ctx, string $id, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new UpdateWorkTemplateRequest)->rules())->validate();
        if (isset($data['items'])) {
            $data['items'] = $this->normalizeItems($data['items']);
        }

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
            $template->load('items');
            $this->decorate([$template]);

            return $template;
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
            $copy->load('items');
            $this->decorate([$copy]);

            return $copy;
        }, 3);
    }

    /**
     * "Save as routine": a one-step farm routine from a task, or from its whole series when it repeats (repeat, ends, reminders, category and
     * instructions are copied; the step starts on day 1 = offset 0 of whatever start date the routine is later applied with).
     */
    public function saveAsRoutine(FarmContext $ctx, string $taskId, array $input): WorkTemplate
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new SaveAsRoutineRequest)->rules())->validate();
        $task = Task::where('farm_id', $ctx->farm->id)->findOrFail($taskId);
        $schedule = $task->schedule_id ? Schedule::where('farm_id', $ctx->farm->id)->find($task->schedule_id) : null;
        $hasBatch = $task->production_cycle_id !== null;
        $scope = $data['scope'] ?? ($hasBatch ? 'any_batch' : 'general');
        if ($scope !== 'general' && ! $hasBatch) {
            $this->support->invalid('scope', 'This task has no batch; save it as a general routine.');
        }
        $source = $schedule ?? $task;
        $step = [
            'title' => $source->title, 'category' => $source->category->value, 'instructions' => $source->instructions, 'anchor' => 'start_date', 'offset_days' => 0,
            'due_time' => $source->due_time !== null ? substr($source->due_time, 0, 5) : null, 'assigned_role' => $source->assigned_role,
            'linked_record_type' => $source->linked_record_type, 'requires_evidence' => $source->requires_evidence,
            'reminder_presets' => $source->reminder_presets, 'reminder_offsets' => $source->reminder_offsets, 'recurrence' => 'none',
        ];
        if ($schedule) {
            $step = array_replace($step, ['recurrence' => $schedule->recurrence->value, 'interval_value' => $schedule->interval_value, 'weekdays' => $schedule->weekdays,
                'occurrence_limit' => $schedule->occurrence_limit, 'ends_type' => $schedule->ends_type === 'batch_end' ? 'batch_end' : null]);
            if ($schedule->ends_on !== null) {
                $step['until_offset_days'] = (int) $schedule->starts_on->diffInDays($schedule->ends_on);
            }
        }
        $template = ['name' => $data['name'], 'applies_to' => $scope === 'general' ? 'farm' : ($task->breeding_project_id ? 'breeding_project' : 'production_cycle'), 'items' => [$step]];
        if ($scope === 'same_kind') {
            $cycle = ProductionCycle::ofFarm($ctx->farm)->with(['livestock', 'crop'])->findOrFail($task->production_cycle_id);
            $template += ['cycle_kind' => $cycle->kind->value, 'species_id' => $cycle->livestock?->species_id, 'crop_type_id' => $cycle->crop?->crop_type_id];
            if ($task->breeding_project_id) {
                $template['breeding_workflow'] = BreedingProject::where('farm_id', $ctx->farm->id)->findOrFail($task->breeding_project_id)->workflow->value;
            }
        }

        return $this->persist($ctx, $template);
    }

    /**
     * Materialises the routine's schedules/tasks for one target (a batch, a breeding project, or nothing for a general routine), counting day 1 from
     * start_date. Applying the same routine to the same target again needs confirm_reapply=true (409 template_already_applied otherwise).
     * Replay-safe by idempotency_key.
     */
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
            $plan = $this->plan($ctx, $id, $data);
            ['template' => $template, 'cycle' => $cycle, 'project' => $project, 'key' => $key, 'start' => $start] = $plan;
            $earlier = TemplateApplication::where('farm_id', $ctx->farm->id)->where('work_template_id', $template->id)->where('target_key', $key)->where('status', 'active')->orderBy('created_at')->get();
            if ($earlier->isNotEmpty() && empty($data['confirm_reapply'])) {
                throw new ApiHttpException(409, 'template_already_applied', 'This routine was already applied to this target. Send confirm_reapply=true to apply it again.', details: [
                    'times_applied' => $earlier->count(), 'last_applied_at' => $earlier->last()->created_at->toISOString(),
                    'applications' => $earlier->map(fn ($a) => ['id' => $a->id, 'applied_at' => $a->created_at->toISOString(), 'start_date' => $a->start_date?->toDateString(), 'tasks_created' => $a->tasks_created])->values()->all(),
                ]);
            }
            $application = TemplateApplication::create([
                'farm_id' => $ctx->farm->id, 'work_template_id' => $template->id, 'template_version' => $template->version, 'production_cycle_id' => $cycle?->id,
                'breeding_project_id' => $project?->id, 'target_key' => $key, 'start_date' => $start, 'status' => 'active', 'tasks_cancelled' => 0, 'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $hash, 'created_by' => $ctx->membership->user_id,
            ]);
            $totals = ['schedules_created' => 0, 'tasks_created' => 0, 'skipped_past' => 0, 'skipped_no_anchor' => $plan['skipped_no_anchor']];
            foreach ($plan['steps'] as ['item' => $item, 'attributes' => $attributes]) {
                $schedule = Schedule::create($attributes + ['farm_id' => $ctx->farm->id, 'production_cycle_id' => $cycle?->id, 'breeding_project_id' => $project?->id,
                    'template_application_id' => $application->id, 'template_item_id' => $item->id, 'created_by' => $ctx->membership->user_id, 'status' => 'active']);
                $made = $this->schedules->materialize($ctx->farm, $schedule, $cycle, $project);
                $totals['schedules_created']++;
                $totals['tasks_created'] += $made['created'];
                $totals['skipped_past'] += $made['skipped_past'];
            }
            $application->update($totals);

            return $application;
        }, 3);
    }

    /**
     * Shows what applying would create, without saving anything: the jobs of the first 30 days (the same window applying materialises), a summary
     * of the repeats that continue after it, and counts.
     *
     * @return array<string, mixed>
     */
    public function preview(FarmContext $ctx, string $id, array $input): array
    {
        $ctx->authorize(Permission::TaskManage);
        $data = Validator::make($input, (new PreviewWorkTemplateRequest)->rules())->validate();
        $data = ['production_cycle_id' => $data['target'] ?? null, 'breeding_project_id' => $data['breeding_project_id'] ?? null, 'start_date' => $data['start_date'] ?? null];

        return DB::transaction(function () use ($ctx, $id, $data) {
            $plan = $this->plan($ctx, $id, $data);
            $from = $this->support->today($ctx->farm);
            $horizon = CarbonImmutable::parse($from, 'UTC')->addDays(ScheduleService::HORIZON_DAYS)->toDateString();
            $after = CarbonImmutable::parse($horizon, 'UTC')->addDay()->toDateString();
            $jobs = [];
            $coming = [];
            $skippedPast = 0;
            foreach ($plan['steps'] as ['item' => $item, 'attributes' => $a]) {
                $recurrence = Recurrence::from($a['recurrence']);
                $ends = $a['ends_on'];
                $rule = fn (string $lo, string $hi) => Occurrences::between($recurrence, $a['interval_value'], $a['weekdays'], $a['starts_on'], $ends, $a['occurrence_limit'], $lo, $hi);
                foreach ($rule($a['starts_on'], $ends !== null && $ends < $horizon ? $ends : $horizon) as $date) {
                    if ($date < $from) {
                        $skippedPast++;

                        continue;
                    }
                    $jobs[] = ['step' => $item->position, 'title' => $a['title'], 'category' => $a['category'], 'due_date' => $date, 'due_time' => $a['due_time'],
                        'reminders' => $a['reminders'], 'repeats' => $recurrence !== Recurrence::None];
                }
                if ($recurrence === Recurrence::None) {
                    continue;
                }
                $openEnded = $a['ends_type'] === 'batch_end' || ($ends === null && $a['occurrence_limit'] === null);
                $later = $rule($after, CarbonImmutable::parse($a['starts_on'], 'UTC')->addDays(3700)->toDateString());
                if (! $openEnded && $later === []) {
                    continue;
                }
                $coming[] = ['step' => $item->position, 'title' => $a['title'], 'repeat' => ['pattern' => $a['recurrence'], 'every' => $a['interval_value'], 'weekdays' => $a['weekdays']],
                    'ends' => $this->endsOf($a), 'next_date' => $later[0] ?? $after, 'remaining_after_window' => $openEnded ? null : count($later)];
            }
            usort($jobs, fn ($x, $y) => [$x['due_date'], $x['due_time'] ?? '99:99', $x['step']] <=> [$y['due_date'], $y['due_time'] ?? '99:99', $y['step']]);

            return [
                'template' => ['id' => $plan['template']->id, 'name' => $plan['template']->name, 'version' => $plan['template']->version],
                'target' => ['production_cycle_id' => $plan['cycle']?->id, 'breeding_project_id' => $plan['project']?->id, 'general' => $plan['cycle'] === null],
                'start_date' => $plan['start'], 'window' => ['from' => $from, 'to' => $horizon],
                'counts' => ['steps' => count($plan['template']->items), 'schedules' => count($plan['steps']), 'jobs_in_window' => count($jobs), 'skipped_past' => $skippedPast,
                    'skipped_no_anchor' => $plan['skipped_no_anchor'], 'coming_repeats' => count($coming)],
                'jobs' => $jobs, 'coming_repeats' => $coming,
                'times_already_applied' => TemplateApplication::where('farm_id', $ctx->farm->id)->where('work_template_id', $plan['template']->id)->where('target_key', $plan['key'])->where('status', 'active')->count(),
            ];
        });
    }

    /** @return array{type: string, date?: string, count?: int} */
    private function endsOf(array $a): array
    {
        return match (true) {
            $a['ends_type'] === 'batch_end' => ['type' => 'batch_end'],
            $a['ends_on'] !== null => ['type' => 'date', 'date' => $a['ends_on']],
            $a['occurrence_limit'] !== null => ['type' => 'count', 'count' => $a['occurrence_limit']],
            default => ['type' => 'never'],
        };
    }

    /**
     * Resolves and checks the target and start date, and works out every step's schedule attributes. Shared by apply and preview, so a preview can
     * never promise something apply would refuse. Call inside a transaction.
     *
     * @return array{template: WorkTemplate, cycle: ?ProductionCycle, project: ?BreedingProject, key: string, start: string, skipped_no_anchor: int, steps: list<array{item: WorkTemplateItem, attributes: array<string, mixed>}>}
     */
    private function plan(FarmContext $ctx, string $id, array $data): array
    {
        $template = $this->visible($ctx)->with('items')->findOrFail($id);
        if (! $template->is_active) {
            throw new ApiHttpException(409, 'template_inactive', 'This template is inactive.');
        }
        $general = $template->applies_to === 'farm';
        $projectTemplate = $template->applies_to === 'breeding_project';
        $cycle = $project = null;
        if ($general) {
            if (! empty($data['production_cycle_id']) || ! empty($data['breeding_project_id'])) {
                $this->support->invalid('production_cycle_id', 'This is a general routine; apply it without a batch.');
            }
            $key = 'general';
        } else {
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
        }
        $today = $this->support->today($ctx->farm);
        $start = $data['start_date'] ?? ($general ? $today : (($project?->start_date ?? $cycle->start_date)?->toDateString() ?? $today));
        $steps = [];
        $skipped = 0;
        foreach ($template->items as $item) {
            $anchor = $item->anchor === TemplateAnchor::StartDate ? $start : $this->anchorDate($item->anchor, $cycle, $project);
            if ($anchor === null) {
                $skipped++;

                continue;
            }
            $this->support->assertLinked($item->linked_record_type, $cycle, $project);
            $base = CarbonImmutable::parse($anchor, 'UTC');
            $steps[] = ['item' => $item, 'attributes' => $this->schedules->attributes([
                'title' => $item->title, 'category' => $item->category->value, 'instructions' => $item->instructions, 'recurrence' => $item->recurrence->value,
                'interval_value' => $item->interval_value, 'weekdays' => $item->weekdays, 'starts_on' => $base->addDays($item->offset_days)->toDateString(),
                'ends_on' => $item->ends_type === 'date' ? $item->ends_on?->toDateString() : ($item->until_offset_days !== null ? $base->addDays($item->until_offset_days)->toDateString() : null),
                'occurrence_limit' => $item->occurrence_limit, 'ends_type' => $item->ends_type === 'batch_end' ? 'batch_end' : null, 'due_time' => $item->due_time, 'reminders' => $item->reminder_presets,
                'reminder_offsets' => $item->reminder_offsets, 'assigned_role' => $item->assigned_role, 'linked_record_type' => $item->linked_record_type, 'requires_evidence' => $item->requires_evidence,
            ]) + ['reminders' => $item->reminder_presets]];
        }

        return ['template' => $template, 'cycle' => $cycle, 'project' => $project, 'key' => $key, 'start' => $start, 'skipped_no_anchor' => $skipped, 'steps' => $steps];
    }

    /** Applied routines, newest first (active ones by default), each with its task counts. */
    public function applications(FarmContext $ctx, array $input): Collection
    {
        $ctx->authorize(Permission::TaskView);
        $f = Validator::make($input, (new ListTemplateApplicationsRequest)->rules())->validate();
        $q = TemplateApplication::where('farm_id', $ctx->farm->id)->with('template:id,name,source');
        foreach (['production_cycle_id', 'breeding_project_id', 'work_template_id'] as $filter) {
            if (isset($f[$filter])) {
                $q->where($filter, $f[$filter]);
            }
        }
        if (($f['target'] ?? null) === 'general') {
            $q->where('target_key', 'general');
        }
        if (($f['status'] ?? 'active') !== 'all') {
            $q->where('status', $f['status'] ?? 'active');
        }
        $apps = $q->orderByDesc('created_at')->orderByDesc('id')->get();
        $counts = $this->taskCounts($apps->pluck('id')->all());
        foreach ($apps as $app) {
            $app->setAttribute('task_counts', $counts[$app->id]);
        }

        return $apps;
    }

    /**
     * Removes a routine from its batch: ends every schedule it created and cancels their open tasks due today or later ("Routine removed").
     * Completed tasks, and open tasks already overdue, are left alone. One transaction; removing twice returns the same result.
     */
    public function remove(FarmContext $ctx, string $id): TemplateApplication
    {
        $ctx->authorize(Permission::TaskManage);

        return DB::transaction(function () use ($ctx, $id) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $application = TemplateApplication::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if ($application->status !== 'removed') {
                $ids = Schedule::where('farm_id', $ctx->farm->id)->where('template_application_id', $application->id)->lockForUpdate()->pluck('id');
                Schedule::whereIn('id', $ids)->where('status', '!=', 'ended')->update(['status' => 'ended']);
                $cancelled = Task::whereIn('schedule_id', $ids)->where('status', TaskStatus::Open->value)->where('due_date', '>=', $this->support->today($ctx->farm))
                    ->update(['status' => TaskStatus::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => 'Routine removed']);
                $application->update(['status' => 'removed', 'removed_at' => now(), 'removed_by' => $ctx->membership->user_id, 'tasks_cancelled' => $cancelled]);
            }
            $application->load('template:id,name,source');
            $application->setAttribute('task_counts', $this->taskCounts([$application->id])[$application->id]);

            return $application;
        }, 3);
    }

    /**
     * Tasks of each application by status.
     *
     * @param  list<string>  $ids
     * @return array<string, array{open: int, completed: int, cancelled: int}>
     */
    private function taskCounts(array $ids): array
    {
        $out = array_fill_keys($ids, ['open' => 0, 'completed' => 0, 'cancelled' => 0]);
        $rows = DB::table('tasks')->join('schedules', 'schedules.id', '=', 'tasks.schedule_id')->whereIn('schedules.template_application_id', $ids)
            ->groupBy('schedules.template_application_id', 'tasks.status')->selectRaw('schedules.template_application_id as application_id, tasks.status as status, count(*) as total')->get();
        foreach ($rows as $row) {
            $out[$row->application_id][$row->status] = (int) $row->total;
        }

        return $out;
    }

    private function anchorDate(TemplateAnchor $anchor, ?ProductionCycle $cycle, ?BreedingProject $project): ?string
    {
        return match ($anchor) {
            TemplateAnchor::StartDate => null,
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

    /**
     * Adds step_count, scope (any_batch|specific|general), scope_label and, when a batch is given, suits_batch to each template.
     *
     * @param  iterable<WorkTemplate>  $templates
     */
    private function decorate(iterable $templates, ?ProductionCycle $cycle = null, ?BreedingProject $project = null, bool $withBatch = false): void
    {
        $templates = collect($templates);
        $species = Species::whereIn('id', $templates->pluck('species_id')->filter())->pluck('name', 'id');
        $crops = CropType::whereIn('id', $templates->pluck('crop_type_id')->filter())->pluck('name', 'id');
        $operations = OperationType::whereIn('id', $templates->pluck('operation_type_id')->filter())->pluck('name', 'id');
        foreach ($templates as $t) {
            $general = $t->applies_to === 'farm';
            $specific = ! $general && ($t->cycle_kind || $t->operation_type_id || $t->species_id || $t->crop_type_id || $t->breeding_workflow);
            $noun = $t->applies_to === 'breeding_project' ? 'breeding' : 'batches';
            $what = $species[$t->species_id] ?? $crops[$t->crop_type_id] ?? $operations[$t->operation_type_id] ?? ($t->cycle_kind ? ucfirst($t->cycle_kind) : null);
            $label = match (true) {
                $general => 'General (no batch)',
                ! $specific => $t->applies_to === 'breeding_project' ? 'Any breeding project' : 'Any batch',
                default => trim(($what ?? 'Any').' '.$noun.($t->breeding_workflow ? ' ('.str_replace('_', ' ', $t->breeding_workflow).')' : '')),
            };
            $t->setAttribute('step_count', $t->relationLoaded('items') ? $t->items->count() : $t->items()->count());
            $t->setAttribute('scope', $general ? 'general' : ($specific ? 'specific' : 'any_batch'));
            $t->setAttribute('scope_label', $label);
            if ($withBatch && $cycle) {
                $t->setAttribute('suits_batch', ! $general && $t->is_active && $t->applies_to === ($project ? 'breeding_project' : 'production_cycle') && $this->applicable($t, $cycle, $project));
            }
        }
    }

    private function assertEditable(WorkTemplate $template): void
    {
        if ($template->source !== WorkTemplate::FARM) {
            throw new ApiHttpException(403, 'platform_template_readonly', 'Platform templates cannot be edited; clone one to customise it.');
        }
    }

    /**
     * Turns the task form's language into item columns: `repeat` {pattern, every, weekdays, ends:{type: days|date|batch_end|never, days, date}} and `reminders`
     * presets. The flat fields (recurrence, interval_value, until_offset_days, ...) keep working for existing clients.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function normalizeItems(array $items): array
    {
        foreach ($items as &$item) {
            if (array_key_exists('reminders', $item)) {
                $item['reminder_presets'] = ! empty($item['reminders']) ? array_values(array_unique($item['reminders'])) : null;
                unset($item['reminders']);
            }
            if (array_key_exists('repeat', $item)) {
                $repeat = $item['repeat'];
                unset($item['repeat']);
                $item['until_offset_days'] = $item['occurrence_limit'] = $item['ends_on'] = $item['ends_type'] = null;
                if (empty($repeat)) {
                    $item['recurrence'] = 'none';
                    $item['weekdays'] = null;
                    $item['interval_value'] = 1;

                    continue;
                }
                $item['recurrence'] = $repeat['pattern'];
                $item['interval_value'] = $repeat['every'] ?? 1;
                $item['weekdays'] = isset($repeat['weekdays']) ? array_values(array_unique($repeat['weekdays'])) : null;
                $ends = $repeat['ends'] ?? ['type' => 'never'];
                switch ($ends['type']) {
                    case 'days':
                        $item['until_offset_days'] = ($item['offset_days'] ?? 0) + $ends['days'] - 1;
                        break;
                    case 'date':
                        $item['ends_type'] = 'date';
                        $item['ends_on'] = $ends['date'];
                        break;
                    case 'batch_end':
                        $item['ends_type'] = 'batch_end';
                        break;
                }
            }
        }

        return $items;
    }

    public function assertShape(array $data, string $appliesTo, bool $partial = false): void
    {
        $project = $appliesTo === 'breeding_project';
        if ($appliesTo === 'farm') {
            foreach (['cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow'] as $filter) {
                if (! empty($data[$filter])) {
                    $this->support->invalid($filter, 'A general routine has no batch, so it cannot be limited to a kind, species or crop.');
                }
            }
        }
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
            $anchor = TemplateAnchor::from($item['anchor'] ?? TemplateAnchor::StartDate->value);
            if ($anchor->target() !== null && $anchor->target() !== $appliesTo) {
                $this->support->invalid('items.'.$i.'.anchor', 'This anchor does not belong to a '.str_replace('_', ' ', $appliesTo).' template.');
            }
            if ($appliesTo === 'farm' && $anchor !== TemplateAnchor::StartDate) {
                $this->support->invalid('items.'.$i.'.anchor', 'A general routine counts from the start date you choose.');
            }
            $recurrence = $item['recurrence'] ?? 'none';
            if ($recurrence === Recurrence::None->value && (isset($item['until_offset_days']) || isset($item['occurrence_limit']) || isset($item['ends_on']) || ! empty($item['ends_type']) || ! empty($item['weekdays']))) {
                $this->support->invalid('items.'.$i.'.recurrence', 'A one-off item has no end offset, occurrence limit or weekdays.');
            }
            if ($recurrence !== Recurrence::Weekly->value && ! empty($item['weekdays'])) {
                $this->support->invalid('items.'.$i.'.weekdays', 'Weekdays apply to weekly items only.');
            }
            if (isset($item['until_offset_days']) && $item['until_offset_days'] < ($item['offset_days'] ?? 0)) {
                $this->support->invalid('items.'.$i.'.until_offset_days', 'The end must not be before the first task.');
            }
            if (($item['ends_type'] ?? null) === 'batch_end') {
                if ($appliesTo === 'farm') {
                    $this->support->invalid('items.'.$i.'.repeat.ends.type', 'A general routine has no batch to end with.');
                }
                if (isset($item['until_offset_days']) || isset($item['occurrence_limit'])) {
                    $this->support->invalid('items.'.$i.'.repeat.ends.type', 'Until the batch ends cannot be combined with another end.');
                }
            }
            if (isset($item['ends_on']) && (isset($item['until_offset_days']) || isset($item['occurrence_limit']))) {
                $this->support->invalid('items.'.$i.'.repeat.ends.type', 'Choose one way for the repeat to end.');
            }
            $linked = $item['linked_record_type'] ?? null;
            if ($linked !== null && $appliesTo === 'farm') {
                $this->support->invalid('items.'.$i.'.linked_record_type', 'A general routine has no batch, so its steps cannot link a record.');
            }
            if ($linked !== null && ($data['cycle_kind'] ?? null) !== null && LinkedRecords::evidenceType($linked)->value === 'operational_record') {
                $kind = app(RecordTypeRegistry::class)->definitions()[$linked]['kind'] ?? null;
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
                'anchor' => $item['anchor'] ?? TemplateAnchor::StartDate->value, 'offset_days' => $item['offset_days'] ?? 0, 'recurrence' => $item['recurrence'] ?? 'none', 'interval_value' => $item['interval_value'] ?? 1,
                'weekdays' => ! empty($item['weekdays']) ? array_values(array_unique($item['weekdays'])) : null, 'until_offset_days' => $item['until_offset_days'] ?? null,
                'occurrence_limit' => $item['occurrence_limit'] ?? null, 'ends_type' => $item['ends_type'] ?? null, 'ends_on' => $item['ends_on'] ?? null,
                'due_time' => isset($item['due_time']) ? substr($item['due_time'], 0, 5) : null,
                'reminder_offsets' => $item['reminder_offsets'] ?? null, 'reminder_presets' => $item['reminder_presets'] ?? null, 'assigned_role' => $item['assigned_role'] ?? null,
                'linked_record_type' => $item['linked_record_type'] ?? null, 'requires_evidence' => $item['requires_evidence'] ?? false,
            ]);
        }
    }

    public function itemData(WorkTemplateItem $i): array
    {
        return [
            'title' => $i->title, 'category' => $i->category->value, 'instructions' => $i->instructions, 'anchor' => $i->anchor->value, 'offset_days' => $i->offset_days,
            'recurrence' => $i->recurrence->value, 'interval_value' => $i->interval_value, 'weekdays' => $i->weekdays, 'until_offset_days' => $i->until_offset_days,
            'occurrence_limit' => $i->occurrence_limit, 'ends_type' => $i->ends_type, 'ends_on' => $i->ends_on?->toDateString(), 'due_time' => $i->due_time,
            'reminder_offsets' => $i->reminder_offsets, 'reminder_presets' => $i->reminder_presets,
            'assigned_role' => $i->assigned_role, 'linked_record_type' => $i->linked_record_type, 'requires_evidence' => $i->requires_evidence,
        ];
    }
}
