<?php

namespace App\Services\Platform;

use App\Enums\BreedingWorkflow;
use App\Models\CropType;
use App\Models\OperationType;
use App\Models\Species;
use App\Models\SpeciesCapability;
use App\Models\User;
use App\Models\WorkTemplate;
use App\Services\Work\WorkTemplateService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform work templates: draft -> published -> archived (-> published again). A farm only ever sees PUBLISHED platform templates
 * (WorkTemplateService::visible). Publishing re-validates the whole template and its references; editing a published template bumps
 * `version` (schedules already applied keep their own copy, so nothing a farm already runs changes). Templates are never deleted.
 */
class PlatformTemplateService
{
    public function __construct(private WorkTemplateService $templates, private PlatformAudit $audit) {}

    /** @param  array{state?: string, applies_to?: string, q?: string, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        return $this->query()
            ->when(isset($f['applies_to']), fn (Builder $q) => $q->where('applies_to', $f['applies_to']))
            ->when(isset($f['state']), fn (Builder $q) => match ($f['state']) {
                'draft' => $q->whereNull('published_at'),
                'published' => $q->whereNotNull('published_at')->where('is_active', true),
                'archived' => $q->whereNotNull('published_at')->where('is_active', false),
            })
            ->when(isset($f['q']), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.PlatformPlanService::escapeLike($f['q']).'%')->orWhere('code', 'like', '%'.PlatformPlanService::escapeLike($f['q']).'%')))
            ->orderBy('code')->paginate($f['per_page'] ?? 25);
    }

    public function find(string $id): WorkTemplate
    {
        return $this->query()->findOrFail($id);
    }

    public static function state(WorkTemplate $t): string
    {
        return match (true) {
            $t->published_at === null => 'draft',
            $t->is_active => 'published',
            default => 'archived',
        };
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): WorkTemplate
    {
        $this->templates->assertShape($data, $data['applies_to']);

        return DB::transaction(function () use ($actor, $data) {
            if (WorkTemplate::whereNull('farm_id')->where('code', $data['code'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['code' => 'A platform template with this code already exists.']);
            }
            $template = WorkTemplate::create($this->templates->columns($data) + [
                'code' => $data['code'], 'farm_id' => null, 'source' => WorkTemplate::PLATFORM, 'created_by' => $actor->id, 'version' => 1, 'is_active' => false, 'published_at' => null,
            ]);
            $this->templates->saveItems($template, $data['items']);
            $this->audit->record($actor, 'platform.template_created', 'work_template', $template->id, $template->code, [], $this->facts($template->load('items')));

            return $this->find($template->id);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, string $id, array $data): WorkTemplate
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $template = WorkTemplate::whereNull('farm_id')->lockForUpdate()->findOrFail($id);
            $state = self::state($template);
            if ($state === 'archived') {
                throw new ApiHttpException(409, 'template_archived', 'Archived templates cannot be edited; publish it again first.');
            }
            $before = $this->facts($template->load('items'));

            $merged = array_replace($template->only(['cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow']), $data);
            $this->templates->assertShape($merged + ['items' => $data['items'] ?? []], $template->applies_to, partial: ! isset($data['items']));
            if ($state === 'published') {
                $this->assertPublishable($template, $merged, $data['items'] ?? null);
            }

            $template->update($this->templates->columns($data) + ($state === 'published' ? ['version' => $template->version + 1] : []));
            if (isset($data['items'])) {
                $template->items()->delete();
                $this->templates->saveItems($template, $data['items']);
            }
            $this->audit->record($actor, 'platform.template_updated', 'work_template', $template->id, $template->code, $before, $this->facts($template->refresh()->load('items')));

            return $this->find($template->id);
        });
    }

    /** draft | archived -> published. Re-validates everything; a published template is immediately visible to farms. */
    public function publish(User $actor, string $id): WorkTemplate
    {
        return DB::transaction(function () use ($actor, $id) {
            $template = WorkTemplate::whereNull('farm_id')->lockForUpdate()->with('items')->findOrFail($id);
            if (self::state($template) === 'published') {
                throw new ApiHttpException(409, 'template_already_published', 'This template is already published.');
            }
            $items = $template->items->map(fn ($i) => $this->templates->itemData($i))->all();
            $content = $template->only(['cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow']);
            $this->templates->assertShape($content + ['items' => $items], $template->applies_to);
            $this->assertPublishable($template, $content, $items);

            $before = $this->facts($template);
            $template->forceFill(['is_active' => true, 'published_at' => $template->published_at ?? now()])->save();
            $this->audit->record($actor, 'platform.template_published', 'work_template', $template->id, $template->code, $before, $this->facts($template->refresh()->load('items')));

            return $this->find($template->id);
        });
    }

    /** published -> archived. Hidden from new use; schedules already created from it are untouched. */
    public function archive(User $actor, string $id): WorkTemplate
    {
        return DB::transaction(function () use ($actor, $id) {
            $template = WorkTemplate::whereNull('farm_id')->lockForUpdate()->with('items')->findOrFail($id);
            if (self::state($template) !== 'published') {
                throw new ApiHttpException(409, 'template_not_published', 'Only a published template can be archived.');
            }
            $before = $this->facts($template);
            $template->update(['is_active' => false]);
            $this->audit->record($actor, 'platform.template_archived', 'work_template', $template->id, $template->code, $before, $this->facts($template->refresh()));

            return $this->find($template->id);
        });
    }

    /**
     * Publication requirements beyond shape: referenced master data must be active, and a breeding template pinned to a species needs
     * that species to support the workflow it schedules.
     *
     * @param  array<string, mixed>  $content
     */
    private function assertPublishable(WorkTemplate $template, array $content, ?array $items): void
    {
        if (($items ?? $template->items->all()) === []) {
            throw ValidationException::withMessages(['items' => 'A published template needs at least one item.']);
        }
        foreach ([['operation_type_id', OperationType::class], ['species_id', Species::class], ['crop_type_id', CropType::class]] as [$field, $class]) {
            if (! empty($content[$field]) && ! $class::whereKey($content[$field])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$field => 'The referenced record is inactive.']);
            }
        }
        if ($template->applies_to === 'breeding_project' && ! empty($content['species_id']) && ! empty($content['breeding_workflow'])) {
            $capability = BreedingWorkflow::from($content['breeding_workflow'])->capability();
            $supported = SpeciesCapability::where('species_id', $content['species_id'])->where('enabled', true)->whereHas('capability', fn ($q) => $q->where('code', $capability->value))->exists();
            if (! $supported) {
                throw ValidationException::withMessages(['breeding_workflow' => 'This species does not support the '.$content['breeding_workflow'].' workflow.']);
            }
        }
    }

    private function query(): Builder
    {
        return WorkTemplate::query()->whereNull('farm_id')->with('items');
    }

    /** @return array<string, mixed> */
    private function facts(WorkTemplate $t): array
    {
        return ['state' => self::state($t), 'name' => $t->name, 'applies_to' => $t->applies_to, 'version' => $t->version, 'items' => $t->relationLoaded('items') ? $t->items->count() : null] + $t->only(['cycle_kind', 'operation_type_id', 'species_id', 'crop_type_id', 'breeding_workflow']);
    }
}
