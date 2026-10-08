<?php

namespace App\Enums;

/**
 * MVP farm roles (stable machine identifiers). A role is a preset of permissions;
 * the role->permission mapping below is the only place roles are compared to capabilities.
 */
enum FarmRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case FarmWorker = 'farm_worker';
    case Finance = 'finance';
    /** Veterinary preset: health and medicine work, read-only elsewhere. */
    case Vet = 'vet';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::FarmWorker => 'Farm Worker',
            self::Finance => 'Finance',
            self::Vet => 'Vet',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => [
                Permission::FarmView, Permission::FarmUpdate,
                Permission::TeamView, Permission::TeamInvite, Permission::TeamUpdateRole, Permission::TeamRemove,
                Permission::LivestockBatchCreate, Permission::InventoryAdjust, Permission::InventoryView, Permission::InventoryUse, Permission::InventoryManage,
                Permission::SubscriptionView,
                Permission::RecordView, Permission::RecordCreate, Permission::RecordReverse, Permission::RecordAdjust,
                Permission::HealthView, Permission::HealthCreate, Permission::HealthReverse, Permission::HealthManage,
                Permission::BreedingView, Permission::BreedingCreate, Permission::BreedingReverse,
                Permission::TaskView, Permission::TaskComplete, Permission::TaskManage,
                Permission::ContactView, Permission::ContactManage, Permission::PurchaseView, Permission::PurchaseCreate, Permission::PurchaseCancel, Permission::FinanceView, Permission::FinanceCreate, Permission::FinanceReverse,
                Permission::SaleView, Permission::SaleCreate, Permission::SaleCancel, Permission::InvoiceView, Permission::InvoiceCreate, Permission::InvoiceVoid, Permission::PaymentView, Permission::PaymentCreate, Permission::PaymentReverse,
                Permission::ReportView, Permission::ReportExport, Permission::AuditView, Permission::MarketplaceManage,
                Permission::MasterDataView, Permission::MasterDataManage,
                Permission::MeasurementView, Permission::MeasurementManage,
                Permission::LocationView, Permission::LocationManage, Permission::ProductionCycleView, Permission::ProductionCycleCreate, Permission::ProductionCycleUpdate, Permission::ProductionCycleClose, Permission::ProductionCycleReopen,
            ],
            self::FarmWorker => [Permission::FarmView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::RecordCreate, Permission::InventoryView, Permission::InventoryUse, Permission::HealthView, Permission::HealthCreate, Permission::BreedingView, Permission::BreedingCreate, Permission::TaskView, Permission::TaskComplete, Permission::ReportView],
            self::Finance => [Permission::FarmView, Permission::ContactView, Permission::ContactManage, Permission::PurchaseView, Permission::PurchaseCreate, Permission::PurchaseCancel, Permission::FinanceView, Permission::FinanceCreate, Permission::FinanceReverse, Permission::SaleView, Permission::SaleCreate, Permission::SaleCancel, Permission::InvoiceView, Permission::InvoiceCreate, Permission::InvoiceVoid, Permission::PaymentView, Permission::PaymentCreate, Permission::PaymentReverse, Permission::SubscriptionView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::InventoryView, Permission::TaskView, Permission::TaskComplete, Permission::ReportView, Permission::ReportExport],
            self::Vet => [Permission::FarmView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::InventoryView, Permission::HealthView, Permission::HealthCreate, Permission::HealthReverse, Permission::HealthManage, Permission::BreedingView, Permission::BreedingCreate, Permission::TaskView, Permission::TaskComplete, Permission::ReportView, Permission::ReportExport],
        };
    }

    /**
     * Task categories this role also sees beyond tasks assigned to it (own/relevant work); roles with task.manage see every task.
     *
     * @return list<TaskCategory>
     */
    public function relevantTaskCategories(): array
    {
        return match ($this) {
            self::Finance => [TaskCategory::Payment, TaskCategory::ProcurementOrders, TaskCategory::RecordKeeping],
            self::Vet => [TaskCategory::VaccinationMedication, TaskCategory::BreedingReproduction, TaskCategory::GrowthMonitoring],
            default => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles this role may grant (invite / change to). Owner is never assignable: ownership
     * transfer is not supported yet, so nobody can create a second owner or self-promote.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => [self::Manager, self::FarmWorker, self::Finance, self::Vet],
            self::Manager => [self::FarmWorker, self::Finance, self::Vet],
            default => [],
        };
    }

    /** Whether a member holding this role may act on (change/remove) a member holding $target. */
    public function canManage(self $target): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Manager => in_array($target, [self::FarmWorker, self::Finance, self::Vet], true),
            default => false,
        };
    }
}
