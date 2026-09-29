<?php

namespace App\Services\Measurement;

use App\Enums\ConversionContextType;
use App\Events\Measurement\PackageConversionChanged;
use App\Models\CropType;
use App\Models\Farm;
use App\Models\MeasurementContext;
use App\Models\PackageConversion;
use App\Models\Unit;
use App\Models\User;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\ConversionContext;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\MeasurementException;
use App\Support\Measurement\PackageDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A farm's package conversions: "in THIS context, 1 <package> = N <unit>". Every read/write is scoped to the farm of the
 * FarmContext (never a client-supplied id), so another farm's definitions can neither be seen, edited nor applied.
 *
 * A context is identified by (type, id) and the id must resolve to a real entity visible to the farm: an active crop type,
 * or one of the farm's own active measurement contexts. Display names are read from those entities, never used as identity.
 *
 * Versioning: a definition is edited in place and its `version` is bumped whenever its meaning (quantity, target unit,
 * active flag) changes. History safety does NOT depend on this row: every normalization embeds the definition it used
 * in its own snapshot (see MeasurementConverter), so later edits or deactivation never change past results.
 */
class PackageConversionService
{
    public function __construct(private readonly UnitCatalogue $units) {}

    /**
     * @param  array{package_unit: string, target_unit: string, quantity_per_package: mixed, context_type: string, context_id: string}  $data
     */
    public function create(FarmContext $ctx, User $actor, array $data): PackageConversion
    {
        $package = $this->units->selectable($data['package_unit']);
        $target = $this->units->selectable($data['target_unit']);
        $this->assertPackageAndTarget($package, $target);
        $quantity = $this->ratio($data['quantity_per_package'], $target);
        $context = $this->resolveContext($ctx->farm, ConversionContextType::from($data['context_type']), $data['context_id'], 'context_id');

        $conversion = $this->guardDuplicate(fn () => DB::transaction(function () use ($ctx, $context, $package, $target, $quantity) {
            $existing = PackageConversion::where('farm_id', $ctx->farm->id)
                ->where('context_type', $context->type)->where('context_id', $context->id)
                ->where('package_unit_id', $package->id)->first();

            if ($existing) {
                throw $this->duplicate($existing);
            }

            return PackageConversion::create([
                'farm_id' => $ctx->farm->id, 'context_type' => $context->type, 'context_id' => $context->id,
                'package_unit_id' => $package->id, 'target_unit_id' => $target->id,
                'quantity_per_package' => $quantity, 'version' => 1, 'is_active' => true,
            ]);
        }), $context);

        PackageConversionChanged::dispatch(PackageConversionChanged::CREATED, $conversion, $actor);

        return $conversion->load(['packageUnit.dimension', 'targetUnit.dimension', ...PackageConversion::CONTEXT_RELATIONS]);
    }

    /**
     * The package unit and the context can never change (create another definition instead); the quantity, target
     * unit and active flag can. Renaming a context is done on the context itself.
     *
     * @param  array{target_unit?: string, quantity_per_package?: mixed, is_active?: bool}  $changes
     */
    public function update(FarmContext $ctx, User $actor, PackageConversion $conversion, array $changes): PackageConversion
    {
        if ($conversion->farm_id !== $ctx->farm->id) { // defence in depth: controllers already look it up through the farm
            throw new ApiHttpException(404, 'not_found', 'Resource not found.');
        }

        [$conversion, $diff] = DB::transaction(function () use ($conversion, $changes) {
            $conversion = PackageConversion::with(['packageUnit', 'targetUnit'])->lockForUpdate()->findOrFail($conversion->id);
            $diff = [];

            $target = isset($changes['target_unit']) ? $this->units->selectable($changes['target_unit']) : $conversion->targetUnit;
            $this->assertPackageAndTarget($conversion->packageUnit, $target);
            // The ratio is always re-validated against the (possibly new) target: an integer-only target needs a whole number.
            $quantity = $this->ratio($changes['quantity_per_package'] ?? $conversion->quantity_per_package, $target);

            if ($target->id !== $conversion->target_unit_id) {
                $diff['target_unit'] = ['old' => $conversion->targetUnit->code, 'new' => $target->code];
                $conversion->target_unit_id = $target->id;
            }
            if (Decimal::cmp($quantity, Decimal::trim((string) $conversion->quantity_per_package)) !== 0) {
                $diff['quantity_per_package'] = ['old' => Decimal::trim((string) $conversion->quantity_per_package), 'new' => $quantity];
                $conversion->quantity_per_package = $quantity;
            }
            if (isset($changes['is_active']) && (bool) $changes['is_active'] !== $conversion->is_active) {
                $diff['is_active'] = ['old' => $conversion->is_active, 'new' => (bool) $changes['is_active']];
                $conversion->is_active = (bool) $changes['is_active'];
            }

            if ($diff !== []) {
                $conversion->version++;
                $conversion->save();
            }

            return [$conversion, $diff];
        });

        foreach ($this->actionsFor($diff) as $action) {
            PackageConversionChanged::dispatch($action, $conversion, $actor, $diff);
        }

        return $conversion->load(['packageUnit.dimension', 'targetUnit.dimension', ...PackageConversion::CONTEXT_RELATIONS]);
    }

    /**
     * Resolves a client-supplied (type, id) to a context this farm may use for NEW definitions and entries, carrying the
     * current display name. Anything that does not resolve - unknown id, another farm's context, an inactive crop or
     * context - is a validation error on `$field`; the reasons are deliberately not distinguished for foreign ids.
     */
    public function resolveContext(Farm $farm, ConversionContextType $type, string $id, string $field = 'context.id'): ConversionContext
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw ValidationException::withMessages([$field => 'The context id is not valid.']);
        }

        $label = match ($type) {
            ConversionContextType::CropType => CropType::where('id', $id)->where('is_active', true)->value('name'),
            ConversionContextType::Custom => MeasurementContext::ofFarm($farm)->where('id', $id)->where('is_active', true)->value('name'),
        };

        return $label !== null
            ? new ConversionContext($type, $id, $label)
            : throw ValidationException::withMessages([$field => 'Choose an existing, active context of this farm.']);
    }

    /**
     * The definitions in force for one context, keyed by package unit code, for the given package units. Active
     * definitions of THIS farm only.
     *
     * @param  list<Unit>  $packageUnits
     * @return array<string, PackageDefinition>
     */
    public function definitionsFor(Farm $farm, ConversionContext $context, array $packageUnits): array
    {
        $definitions = [];

        foreach ($packageUnits as $unit) {
            $row = PackageConversion::with(['packageUnit.dimension', 'targetUnit.dimension', ...PackageConversion::CONTEXT_RELATIONS])
                ->where('farm_id', $farm->id)
                ->where('context_type', $context->type)->where('context_id', $context->id)
                ->where('package_unit_id', $unit->id)->first();

            if (! $row) {
                throw MeasurementException::conversionNotConfigured($unit->code, $context);
            }
            if (! $row->is_active) {
                throw MeasurementException::conversionNotConfigured($unit->code, $row->conversionContext(), inactive: true);
            }

            $definitions[$unit->code] = new PackageDefinition(
                $row->id, $row->version, $row->conversionContext(),
                $row->packageUnit->spec(), $row->targetUnit->spec(), Decimal::trim((string) $row->quantity_per_package),
            );
        }

        return $definitions;
    }

    /** Contexts in which this farm has an ACTIVE definition for the package unit (used to explain a missing context). */
    public function candidateContexts(Farm $farm, Unit $package): array
    {
        return PackageConversion::with(PackageConversion::CONTEXT_RELATIONS)
            ->where('farm_id', $farm->id)->where('package_unit_id', $package->id)->where('is_active', true)
            ->get()->sortBy(fn (PackageConversion $c) => [$c->context_label, $c->id])
            ->map(fn (PackageConversion $c) => $c->conversionContext()->toArray())
            ->values()->all();
    }

    private function assertPackageAndTarget(Unit $package, Unit $target): void
    {
        if ($package->dimension->code !== 'package') {
            throw MeasurementException::unitDimensionMismatch($package->code, 'package');
        }
        if ($target->dimension->code === 'package' || $target->family === null) {
            throw new MeasurementException(422, MeasurementException::INCOMPATIBLE_UNITS, "A package cannot contain another package ({$target->code}); choose a weight, volume, area or count unit.", details: [
                'from_unit' => $package->code, 'to_unit' => $target->code,
            ]);
        }
    }

    /** Exact, strictly positive; whole when the target unit is discrete (a crate holds 30 eggs, not 30.5). */
    private function ratio(mixed $value, Unit $target): string
    {
        $parsed = Decimal::parse((string) $value);
        if ($parsed === null) {
            throw MeasurementException::invalidRatio('The quantity per package must be a decimal number with at most 12 digits before and 6 after the decimal point.');
        }
        if (Decimal::cmp($parsed, '0') <= 0) {
            throw MeasurementException::invalidRatio('The quantity per package must be greater than zero.');
        }
        if ($target->integer_only && ! Decimal::isInteger($parsed)) {
            throw MeasurementException::invalidRatio("A package holds a whole number of {$target->code}.");
        }

        return $parsed;
    }

    private function duplicate(PackageConversion $existing): ApiHttpException
    {
        return new MeasurementException(409, MeasurementException::CONVERSION_EXISTS, $existing->is_active
            ? 'A conversion for this package in this context already exists; update it instead.'
            : 'An inactive conversion for this package in this context exists; reactivate it instead.', details: [
                'existing_id' => $existing->id, 'is_active' => $existing->is_active,
            ]);
    }

    /** Turns the unique-index race (two simultaneous creates) into the same 409 as the pre-check. */
    private function guardDuplicate(callable $work, ConversionContext $context): PackageConversion
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw new MeasurementException(409, MeasurementException::CONVERSION_EXISTS, 'A conversion for this package in this context already exists; update it instead.', details: ['context' => $context->toArray()]);
        }
    }

    /** @return list<string> */
    private function actionsFor(array $diff): array
    {
        $actions = [];
        if (isset($diff['is_active'])) {
            $actions[] = $diff['is_active']['new'] ? PackageConversionChanged::REACTIVATED : PackageConversionChanged::DEACTIVATED;
        }
        if (array_diff_key($diff, ['is_active' => 1]) !== []) {
            $actions[] = PackageConversionChanged::UPDATED;
        }

        return $actions;
    }
}
