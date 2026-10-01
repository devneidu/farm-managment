<?php

namespace App\Enums;

/**
 * The single registry of permission identifiers. Controllers/middleware check these,
 * never role names. To add a permission: add a case here, then grant it to roles in
 * FarmRole::permissions(). No schema change is needed.
 */
enum Permission: string
{
    // Phase 2 - farm settings and team
    case FarmView = 'farm.view';
    case FarmUpdate = 'farm.update';
    case TeamView = 'team.view';
    case TeamInvite = 'team.invite';
    case TeamUpdateRole = 'team.update_role';
    case TeamRemove = 'team.remove';

    // Phase 3 - subscription (RBAC only; plan entitlements are a separate system)
    case SubscriptionView = 'subscription.view';
    case SubscriptionManage = 'subscription.manage';

    // Phase 4 - agricultural master data: view selectors (every role) / manage the farm's own custom breeds & varieties
    case MasterDataView = 'master_data.view';
    case MasterDataManage = 'master_data.manage';

    // Phase 5 - measurements: read unit selectors / conversions (every role) / manage the farm's unit preferences and package conversions
    case MeasurementView = 'measurement.view';
    case MeasurementManage = 'measurement.manage';

    // Phase 6 - places: selectors for all roles, management for owner/manager.
    case LocationView = 'location.view';
    case LocationManage = 'location.manage';

    case ProductionCycleView = 'production_cycle.view';
    case ProductionCycleCreate = 'production_cycle.create';
    case ProductionCycleUpdate = 'production_cycle.update';
    case ProductionCycleClose = 'production_cycle.close';
    case ProductionCycleReopen = 'production_cycle.reopen';

    case RecordView = 'record.view';
    case RecordCreate = 'record.create';
    case RecordReverse = 'record.reverse';
    case RecordAdjust = 'record.adjust';

    // Phase 9 - inventory: view (all roles) / use stock / manage items, stock-in, transfers, formulas / adjust and reverse
    case InventoryView = 'inventory.view';
    case InventoryUse = 'inventory.use';
    case InventoryManage = 'inventory.manage';
    case InventoryAdjust = 'inventory.adjust';

    // Reserved identifiers for later phases (granted per 34-PERMISSIONS-MATRIX; no routes use them yet)
    case LivestockBatchCreate = 'livestock.batch.create';
    case FinanceExpenseCreate = 'finance.expense.create';
}
