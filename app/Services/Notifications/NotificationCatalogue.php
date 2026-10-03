<?php

namespace App\Services\Notifications;

use App\Enums\Permission;
use App\Support\Access\FarmContext;

/**
 * The notification types a user can receive and switch on or off. Insight types reuse the Phase 16 insight codes one-for-one, so a
 * notification and the dashboard insight it came from are the same fact. A user is only offered (and only ever sent) the types whose
 * underlying data their permissions let them see.
 */
final class NotificationCatalogue
{
    public const TASK_REMINDER = 'task_reminder';

    public const EXPORT_READY = 'export_ready';

    public const EXPORT_FAILED = 'export_failed';

    /** @return array<string, array{label: string, description: string, permission: Permission}> */
    public static function all(): array
    {
        return [
            'mortality_threshold' => ['label' => 'High mortality', 'description' => 'Deaths in a livestock cycle crossed the mortality threshold.', 'permission' => Permission::RecordView],
            'low_stock' => ['label' => 'Low or empty stock', 'description' => 'An inventory item is at or below its low-stock threshold.', 'permission' => Permission::InventoryView],
            'lot_expiry' => ['label' => 'Stock expiring', 'description' => 'A stock lot is about to expire or has expired with stock left.', 'permission' => Permission::InventoryView],
            'overdue_tasks' => ['label' => 'Overdue work', 'description' => 'Tasks you can see are past their due time.', 'permission' => Permission::TaskView],
            self::TASK_REMINDER => ['label' => 'Task reminders', 'description' => 'A task assigned to you is coming due (at the reminder times set on the task).', 'permission' => Permission::TaskView],
            'overdue_invoices' => ['label' => 'Overdue invoices', 'description' => 'Issued invoices are past their due date and unpaid.', 'permission' => Permission::InvoiceView],
            'medicine_withdrawal' => ['label' => 'Medicine withdrawal', 'description' => 'A medicine withdrawal period is still running.', 'permission' => Permission::HealthView],
            'breeding_due_soon' => ['label' => 'Breeding outcome due soon', 'description' => 'A breeding project is expected to conclude soon.', 'permission' => Permission::BreedingView],
            'breeding_overdue' => ['label' => 'Breeding outcome overdue', 'description' => 'A breeding project is past its expected window with no outcome.', 'permission' => Permission::BreedingView],
            'cycle_past_expected_end' => ['label' => 'Cycle past its expected end', 'description' => 'A production cycle is still active after its expected end date.', 'permission' => Permission::ProductionCycleView],
            self::EXPORT_READY => ['label' => 'Export ready', 'description' => 'A report export you requested is ready to download.', 'permission' => Permission::ReportExport],
            self::EXPORT_FAILED => ['label' => 'Export failed', 'description' => 'A report export you requested could not be generated.', 'permission' => Permission::ReportExport],
        ];
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** @return array<string, array{label: string, description: string, permission: Permission}> the types this member can receive */
    public static function availableTo(FarmContext $ctx): array
    {
        return array_filter(self::all(), fn ($t) => $ctx->can($t['permission']));
    }
}
