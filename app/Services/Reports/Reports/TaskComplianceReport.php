<?php

namespace App\Services\Reports\Reports;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Reports\Report;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Services\Work\WorkSupport;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;

/** Task completion and punctuality by category for tasks due in the period. Only the tasks the viewer may see are counted (the same visibility as the task list). */
class TaskComplianceReport extends Report
{
    public function __construct(private WorkSupport $work) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition('task_compliance', 'Task completion', 'work', 'Tasks due in the period by category: completed on time, completed late, still open and overdue, and the completion rate. Cancelled tasks are not counted.',
            [Permission::TaskView], ['from', 'to', 'production_cycle_id']);
    }

    public function run(FarmContext $ctx, ReportFilters $f): ReportResult
    {
        $open = TaskStatus::Open->value;
        $done = TaskStatus::Completed->value;
        $cancelled = TaskStatus::Cancelled->value;
        $now = $f->clock->nowSql();
        $data = $this->work->visible(Task::where('farm_id', $ctx->farm->id), $ctx)->whereBetween('due_date', [$f->from, $f->to])
            ->when($f->productionCycleId, fn ($q, $v) => $q->where('production_cycle_id', $v))->toBase()
            ->selectRaw("category, COALESCE(SUM(status <> '$cancelled'), 0) as total, COALESCE(SUM(status = '$done'), 0) as completed, "
                ."COALESCE(SUM(status = '$done' AND completed_at <= due_at), 0) as on_time, COALESCE(SUM(status = '$done' AND completed_at > due_at), 0) as late, "
                ."COALESCE(SUM(status = '$open' AND due_at <= ?), 0) as overdue, COALESCE(SUM(status = '$open' AND due_at > ?), 0) as upcoming, COALESCE(SUM(status = '$cancelled'), 0) as cancelled", [$now, $now])
            ->groupBy('category')->orderBy('category')->get();

        $keys = ['total', 'completed', 'on_time', 'late', 'overdue', 'upcoming', 'cancelled'];
        $sum = array_fill_keys($keys, 0);
        $rows = [];
        foreach ($data as $d) {
            $row = ['category' => $d->category];
            foreach ($keys as $k) {
                $row[$k] = (int) $d->{$k};
                $sum[$k] += $row[$k];
            }
            $row['completion_rate'] = $this->rate($row['completed'], $row['total']);
            $rows[] = $row;
        }

        return new ReportResult([
            ReportResult::col('category', 'Category'), ReportResult::col('total', 'Tasks due', 'integer'), ReportResult::col('completed', 'Completed', 'integer'), ReportResult::col('on_time', 'On time', 'integer'),
            ReportResult::col('late', 'Late', 'integer'), ReportResult::col('overdue', 'Overdue (open)', 'integer'), ReportResult::col('upcoming', 'Not yet due (open)', 'integer'),
            ReportResult::col('cancelled', 'Cancelled', 'integer'), ReportResult::col('completion_rate', 'Completion rate %', 'percent'),
        ], $rows, $sum + ['completion_rate' => $this->rate($sum['completed'], $sum['total'])], ['Cancelled tasks are listed but not part of "Tasks due" or the completion rate.']);
    }

    private function rate(int $part, int $whole): string
    {
        return $whole > 0 ? Decimal::trim(Decimal::round(Decimal::div(Decimal::mul((string) $part, '100'), (string) $whole), 2)) : '0';
    }
}
