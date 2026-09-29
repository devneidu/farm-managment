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

    // Reserved identifiers for later phases (granted per 34-PERMISSIONS-MATRIX; no routes use them yet)
    case LivestockBatchCreate = 'livestock.batch.create';
    case InventoryAdjust = 'inventory.adjust';
    case FinanceExpenseCreate = 'finance.expense.create';
}
