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
                Permission::MasterDataView, Permission::MasterDataManage,
                Permission::MeasurementView, Permission::MeasurementManage,
                Permission::LocationView, Permission::LocationManage, Permission::ProductionCycleView, Permission::ProductionCycleCreate, Permission::ProductionCycleUpdate, Permission::ProductionCycleClose, Permission::ProductionCycleReopen,
            ],
            self::FarmWorker => [Permission::FarmView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::RecordCreate, Permission::InventoryView, Permission::InventoryUse, Permission::HealthView, Permission::HealthCreate],
            self::Finance => [Permission::FarmView, Permission::FinanceExpenseCreate, Permission::SubscriptionView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::InventoryView],
            self::Vet => [Permission::FarmView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView, Permission::ProductionCycleView, Permission::RecordView, Permission::InventoryView, Permission::HealthView, Permission::HealthCreate, Permission::HealthReverse, Permission::HealthManage],
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
