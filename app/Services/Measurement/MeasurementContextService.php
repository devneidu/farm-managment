<?php

namespace App\Services\Measurement;

use App\Models\MeasurementContext;
use App\Support\Access\FarmContext;
use App\Support\Measurement\MeasurementException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A farm's own measurement contexts ("Feed Grower Mash", "Eggs"): the stable, farm-scoped identity package conversions
 * hang on until real domain entities (inventory items, Phase 9) exist. Names are display only and may change; the id
 * never does. Names are unique per farm ignoring case and extra spaces. Nothing is deleted - a context is deactivated.
 */
class MeasurementContextService
{
    public function create(FarmContext $ctx, string $name): MeasurementContext
    {
        $this->assertNameFree($ctx, $name);

        try {
            return MeasurementContext::create(['farm_id' => $ctx->farm->id, 'name' => $name, 'is_active' => true]);
        } catch (UniqueConstraintViolationException) { // simultaneous creates
            throw $this->duplicate($this->existing($ctx, $name));
        }
    }

    /** @param  array{name?: string, is_active?: bool}  $changes */
    public function update(FarmContext $ctx, MeasurementContext $context, array $changes): MeasurementContext
    {
        if (isset($changes['name'])) {
            $this->assertNameFree($ctx, $changes['name'], except: $context->id);
            $context->name = $changes['name'];
        }
        if (isset($changes['is_active'])) {
            $context->is_active = (bool) $changes['is_active'];
        }

        try {
            $context->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($this->existing($ctx, $changes['name'] ?? $context->name));
        }

        return $context;
    }

    private function assertNameFree(FarmContext $ctx, string $name, ?string $except = null): void
    {
        $existing = $this->existing($ctx, $name);

        if ($existing && $existing->id !== $except) {
            throw $this->duplicate($existing);
        }
    }

    private function existing(FarmContext $ctx, string $name): ?MeasurementContext
    {
        return MeasurementContext::ofFarm($ctx->farm)->where('normalized_name', MeasurementContext::normalizeName($name))->first();
    }

    private function duplicate(?MeasurementContext $existing): MeasurementException
    {
        return new MeasurementException(409, MeasurementException::CONTEXT_EXISTS, 'A measurement context with this name already exists.', details: $existing
            ? ['existing_id' => $existing->id, 'is_active' => $existing->is_active]
            : []);
    }
}
