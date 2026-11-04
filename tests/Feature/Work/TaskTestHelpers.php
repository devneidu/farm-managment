<?php

namespace Tests\Feature\Work;

use App\Models\OperationType;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Shared helpers for the tasks / calendar / reminders / phone-feed tests. The clock is frozen at 2026-10-14 11:00 UTC (12:00 in Lagos, a Wednesday). */
trait TaskTestHelpers
{
    protected function freezeClock(string $utc = '2026-10-14 11:00:00'): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    protected function today(): string
    {
        return CarbonImmutable::now('Africa/Lagos')->toDateString();
    }

    protected function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today(), 'UTC')->addDays($offset)->toDateString();
    }

    protected function cycle(string $species = 'chicken', array $extra = []): string
    {
        $operation = Species::where('code', $species)->firstOrFail()->operationType->code;

        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'livestock', 'name' => ucfirst($species).' '.Str::random(4), 'operation_type_id' => OperationType::where('code', $operation)->firstOrFail()->id,
            'species_id' => Species::where('code', $species)->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => 100, 'start_date' => '2026-01-01'], $extra))->assertCreated()->json('data.id');
    }

    protected function payload(array $extra = []): array
    {
        return array_replace(['title' => 'Check drinkers', 'category' => 'feeding_watering', 'due_date' => $this->today(), 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    protected function task(array $extra = [])
    {
        return $this->postJson('/api/v1/tasks', $this->payload($extra));
    }

    protected function repeating(array $repeat, array $extra = [])
    {
        return $this->task(['repeat' => $repeat] + $extra);
    }

    protected function editSeries(string $task, array $extra = [])
    {
        return $this->postJson('/api/v1/tasks/'.$task.'/edit-series', array_replace(['idempotency_key' => (string) Str::uuid()], $extra));
    }

    protected function closeCycle(string $cycle): void
    {
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Done'])->assertOk();
    }
}
