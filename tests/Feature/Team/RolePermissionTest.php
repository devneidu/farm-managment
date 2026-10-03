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
        $this->assertSame(['owner', 'manager', 'farm_worker', 'finance', 'vet'], array_map(fn ($r) => $r->value, FarmRole::cases()));
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
            'audit.view', 'breeding.create', 'breeding.reverse', 'breeding.view', 'contact.manage', 'contact.view', 'farm.update', 'farm.view', 'finance.create', 'finance.reverse', 'finance.view', 'health.create', 'health.manage', 'health.reverse', 'health.view', 'inventory.adjust', 'inventory.manage', 'inventory.use', 'inventory.view', 'invoice.create', 'invoice.view', 'invoice.void', 'livestock.batch.create',
            'location.manage', 'location.view',
            'master_data.manage', 'master_data.view', 'measurement.manage', 'measurement.view', 'payment.create', 'payment.reverse', 'payment.view', 'production_cycle.close', 'production_cycle.create', 'production_cycle.reopen', 'production_cycle.update', 'production_cycle.view', 'purchase.cancel', 'purchase.create', 'purchase.view', 'record.adjust', 'record.create', 'record.reverse', 'record.view', 'report.export', 'report.view', 'sale.cancel', 'sale.create', 'sale.view', 'subscription.view', 'task.complete', 'task.manage', 'task.view', 'team.invite', 'team.remove', 'team.update_role', 'team.view',
        ], $this->values(FarmRole::Manager));
        $this->assertTrue(FarmRole::Manager->can(Permission::FinanceCreate));
    }

    public function test_farm_worker_can_only_view_the_farm_master_data_and_measurements(): void
    {
        $this->assertSame(['breeding.create', 'breeding.view', 'farm.view', 'health.create', 'health.view', 'inventory.use', 'inventory.view', 'location.view', 'master_data.view', 'measurement.view', 'production_cycle.view', 'record.create', 'record.view', 'report.view', 'task.complete', 'task.view'], $this->values(FarmRole::FarmWorker));
    }

    public function test_finance_permissions(): void
    {
        $this->assertSame(['contact.manage', 'contact.view', 'farm.view', 'finance.create', 'finance.reverse', 'finance.view', 'inventory.view', 'invoice.create', 'invoice.view', 'invoice.void', 'location.view', 'master_data.view', 'measurement.view', 'payment.create', 'payment.reverse', 'payment.view', 'production_cycle.view', 'purchase.cancel', 'purchase.create', 'purchase.view', 'record.view', 'report.export', 'report.view', 'sale.cancel', 'sale.create', 'sale.view', 'subscription.view', 'task.complete', 'task.view'], $this->values(FarmRole::Finance));
        $this->assertFalse(FarmRole::Finance->can(Permission::TeamView));
        $this->assertFalse(FarmRole::Finance->can(Permission::FarmUpdate));
    }

    public function test_vet_preset_does_health_work_and_is_read_only_elsewhere(): void
    {
        $this->assertSame(['breeding.create', 'breeding.view', 'farm.view', 'health.create', 'health.manage', 'health.reverse', 'health.view', 'inventory.view', 'location.view', 'master_data.view', 'measurement.view', 'production_cycle.view', 'record.view', 'report.export', 'report.view', 'task.complete', 'task.view'], $this->values(FarmRole::Vet));
        $this->assertFalse(FarmRole::Vet->can(Permission::InventoryUse));
        $this->assertFalse(FarmRole::Vet->can(Permission::RecordCreate));
        $this->assertSame([], FarmRole::Vet->assignableRoles());
    }

    public function test_owner_is_never_assignable_and_assignment_is_hierarchical(): void
    {
        foreach (FarmRole::cases() as $role) {
            $this->assertNotContains(FarmRole::Owner, $role->assignableRoles());
        }

        $this->assertSame([FarmRole::Manager, FarmRole::FarmWorker, FarmRole::Finance, FarmRole::Vet], FarmRole::Owner->assignableRoles());
        $this->assertSame([FarmRole::FarmWorker, FarmRole::Finance, FarmRole::Vet], FarmRole::Manager->assignableRoles());
        $this->assertSame([], FarmRole::FarmWorker->assignableRoles());
        $this->assertSame([], FarmRole::Finance->assignableRoles());

        $this->assertFalse(FarmRole::Manager->canManage(FarmRole::Owner));
        $this->assertFalse(FarmRole::Manager->canManage(FarmRole::Manager));
        $this->assertTrue(FarmRole::Owner->canManage(FarmRole::Manager));
    }
}
