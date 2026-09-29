<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/** The permission matrix is the contract later modules rely on; pin it. */
class RolePermissionTest extends TestCase
{
    private function values(FarmRole $role): array
    {
        $values = array_map(fn (Permission $p) => $p->value, $role->permissions());
        sort($values);

        return $values;
    }

    public function test_mvp_roles_are_exactly_these_identifiers(): void
    {
        $this->assertSame(['owner', 'manager', 'farm_worker', 'finance'], array_map(fn ($r) => $r->value, FarmRole::cases()));
        $this->assertNull(FarmRole::tryFrom('admin'));
        $this->assertNull(FarmRole::tryFrom('Owner'));
    }

    public function test_owner_has_every_permission(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(FarmRole::Owner->can($permission), $permission->value);
        }
    }

    public function test_manager_permissions(): void
    {
        $this->assertSame([
            'farm.update', 'farm.view', 'inventory.adjust', 'livestock.batch.create',
            'location.manage', 'location.view',
            'master_data.manage', 'master_data.view', 'measurement.manage', 'measurement.view', 'subscription.view', 'team.invite', 'team.remove', 'team.update_role', 'team.view',
        ], $this->values(FarmRole::Manager));
        $this->assertFalse(FarmRole::Manager->can(Permission::FinanceExpenseCreate));
    }

    public function test_farm_worker_can_only_view_the_farm_master_data_and_measurements(): void
    {
        $this->assertSame(['farm.view', 'location.view', 'master_data.view', 'measurement.view'], $this->values(FarmRole::FarmWorker));
    }

    public function test_finance_permissions(): void
    {
        $this->assertSame(['farm.view', 'finance.expense.create', 'location.view', 'master_data.view', 'measurement.view', 'subscription.view'], $this->values(FarmRole::Finance));
        $this->assertFalse(FarmRole::Finance->can(Permission::TeamView));
        $this->assertFalse(FarmRole::Finance->can(Permission::FarmUpdate));
    }

    public function test_owner_is_never_assignable_and_assignment_is_hierarchical(): void
    {
        foreach (FarmRole::cases() as $role) {
            $this->assertNotContains(FarmRole::Owner, $role->assignableRoles());
        }

        $this->assertSame([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance], FarmRole::Owner->assignableRoles());
        $this->assertSame([FarmRole::FarmWorker, FarmRole::Finance], FarmRole::Manager->assignableRoles());
        $this->assertSame([], FarmRole::FarmWorker->assignableRoles());
        $this->assertSame([], FarmRole::Finance->assignableRoles());

        $this->assertFalse(FarmRole::Manager->canManage(FarmRole::Owner));
        $this->assertFalse(FarmRole::Manager->canManage(FarmRole::Manager));
        $this->assertTrue(FarmRole::Owner->canManage(FarmRole::Manager));
    }
}
