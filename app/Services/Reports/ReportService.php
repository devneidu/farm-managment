<?php

namespace App\Services\Reports;

use App\Enums\CycleKind;
use App\Enums\Feature;
use App\Enums\OperationCategory;
use App\Enums\Permission;
use App\Models\FarmOperation;
use App\Models\OperationType;
use App\Models\ProductionCycle;
use App\Services\Dashboard\DashboardClock;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Validation\ValidationException;

/**
 * The report catalogue and the single place a report is authorised and run. Every report is a read over authoritative data; nothing a
 * report produces is stored. Permission = report.view + the permissions of the data the report reads; the plan entitlement is a separate gate.
 */
class ReportService
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        Reports\CyclePerformanceReport::class,
        Reports\LivestockPopulationReport::class,
        Reports\MortalityReport::class,
        Reports\LivestockGrowthReport::class,
        Reports\ProductionOutputReport::class,
        Reports\CropPerformanceReport::class,
        Reports\BreedingOutcomesReport::class,
        Reports\HealthTreatmentReport::class,
        Reports\InventoryStockReport::class,
        Reports\InputConsumptionReport::class,
        Reports\IncomeExpenseReport::class,
        Reports\CycleProfitabilityReport::class,
        Reports\SalesSummaryReport::class,
        Reports\ReceivablesReport::class,
        Reports\TaskComplianceReport::class,
        Reports\ContactHistoryReport::class,
    ];

    public function __construct(private EntitlementService $entitlements) {}

    /** @return array<string, Report> keyed by code */
    private function reports(): array
    {
        $out = [];
        foreach (self::REPORTS as $class) {
            $report = app($class);
            $out[$report->definition()->code] = $report;
        }

        return $out;
    }

    public function find(string $code): Report
    {
        return $this->reports()[$code] ?? throw new ApiHttpException(404, 'report_not_found', 'That report does not exist.');
    }

    public function permitted(FarmContext $ctx, ReportDefinition $d): bool
    {
        if (! $ctx->can(Permission::ReportView)) {
            return false;
        }
        foreach ($d->permissions as $permission) {
            if (! $ctx->can($permission)) {
                return false;
            }
        }
        if ($d->anyOf !== [] && ! array_filter($d->anyOf, fn ($p) => $ctx->can($p))) {
            return false;
        }

        return true;
    }

    /** 403 when the viewer may not read the data behind the report (the same answer as any other forbidden action). */
    public function authorize(FarmContext $ctx, ReportDefinition $d): void
    {
        if (! $this->permitted($ctx, $d)) {
            throw new ApiHttpException(403, 'forbidden', 'You are not allowed to perform this action.');
        }
        if ($d->feature !== null) {
            $this->entitlements->assertAllows($ctx->farm, $d->feature);
        }
    }

    /**
     * The reports the viewer may run. A report whose data the viewer cannot see is omitted entirely (its existence is not advertised);
     * a report the plan does not include is listed with available=false so the client can explain why. `relevant` says whether the farm's
     * operations / cycles make the report meaningful (a crop-only farm gets relevant=false for livestock reports).
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(FarmContext $ctx): array
    {
        $relevance = $this->relevance($ctx);
        $canExport = $ctx->can(Permission::ReportExport) && $this->entitlements->allows($ctx->farm, Feature::DataExport);
        $out = [];
        foreach ($this->reports() as $report) {
            $d = $report->definition();
            if (! $this->permitted($ctx, $d)) {
                continue;
            }
            $entitled = $d->feature === null || $this->entitlements->allows($ctx->farm, $d->feature);
            $out[] = [
                'code' => $d->code, 'title' => $d->title, 'family' => $d->family, 'description' => $d->description, 'filters' => $d->filters, 'applies_to' => $d->kind,
                'relevant' => $d->kind === null || $relevance[$d->kind],
                'available' => $entitled, 'unavailable_reason' => $entitled ? null : 'feature_not_available', 'required_feature' => $d->feature?->value,
                'exportable' => $entitled && $canExport, 'export_formats' => ExportService::FORMATS,
            ];
        }

        return $out;
    }

    /**
     * Run a report. $input is the validated filter input; omitted dates default to the last 30 farm-local days.
     *
     * @return array{report: ReportDefinition, filters: ReportFilters, result: ReportResult, generated_at: string}
     */
    public function run(FarmContext $ctx, string $code, array $input): array
    {
        $report = $this->find($code);
        $d = $report->definition();
        $this->authorize($ctx, $d);
        $filters = $this->filters($ctx, $d, $input);

        return ['report' => $d, 'filters' => $filters, 'result' => $report->run($ctx, $filters), 'generated_at' => $filters->clock->now->toISOString()];
    }

    public function filters(FarmContext $ctx, ReportDefinition $d, array $input): ReportFilters
    {
        $input = array_intersect_key($input, array_flip($d->filters));
        $clock = new DashboardClock($ctx->farm);
        if (isset($input['production_cycle_id']) && ! ProductionCycle::ofFarm($ctx->farm)->whereKey($input['production_cycle_id'])->exists()) {
            // Same answer for "does not exist" and "belongs to another farm".
            throw ValidationException::withMessages(['production_cycle_id' => 'The selected production cycle is invalid.']);
        }
        if (isset($input['from'], $input['to']) && $input['from'] > $input['to']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        if (isset($input['from']) && ! isset($input['to']) && $input['from'] > $clock->today) {
            throw ValidationException::withMessages(['from' => 'The start date cannot be in the future when no end date is given.']);
        }

        return ReportFilters::resolve($clock, $input);
    }

    /** @return array{livestock: bool, crop: bool} */
    private function relevance(FarmContext $ctx): array
    {
        $categories = OperationType::whereIn('id', FarmOperation::where('farm_id', $ctx->farm->id)->select('operation_type_id'))->pluck('category')
            ->map(fn ($c) => $c instanceof OperationCategory ? $c : OperationCategory::from($c))->all();
        $kinds = ProductionCycle::ofFarm($ctx->farm)->distinct()->pluck('kind')->map(fn ($k) => $k instanceof CycleKind ? $k : CycleKind::from($k))->all();

        return [
            'livestock' => (bool) array_filter($categories, fn ($c) => in_array($c, [OperationCategory::Livestock, OperationCategory::Aquaculture], true)) || in_array(CycleKind::Livestock, $kinds, true),
            'crop' => in_array(OperationCategory::Crop, $categories, true) || in_array(CycleKind::Crop, $kinds, true),
        ];
    }
}
