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

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::FarmWorker => 'Farm Worker',
            self::Finance => 'Finance',
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
                Permission::LivestockBatchCreate, Permission::InventoryAdjust,
                Permission::SubscriptionView,
                Permission::MasterDataView, Permission::MasterDataManage,
                Permission::MeasurementView, Permission::MeasurementManage,
                Permission::LocationView, Permission::LocationManage,
            ],
            self::FarmWorker => [Permission::FarmView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView],
            self::Finance => [Permission::FarmView, Permission::FinanceExpenseCreate, Permission::SubscriptionView, Permission::MasterDataView, Permission::MeasurementView, Permission::LocationView],
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
            self::Owner => [self::Manager, self::FarmWorker, self::Finance],
            self::Manager => [self::FarmWorker, self::Finance],
            default => [],
        };
    }

    /** Whether a member holding this role may act on (change/remove) a member holding $target. */
    public function canManage(self $target): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Manager => in_array($target, [self::FarmWorker, self::Finance], true),
            default => false,
        };
    }
}
