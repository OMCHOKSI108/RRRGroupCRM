<?php

namespace Tests\Unit\Models;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class UserRbacTest extends TestCase
{
    public function test_user_without_loaded_role_returns_false_on_has_role(): void
    {
        $this->createTenantWithAdmin();

        // An unpersisted user instance or a user where role relation is null
        $user = new User(['tenant_id' => $this->tenant->id]);

        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isAgent());
    }

    public function test_admin_has_all_permissions_automatically(): void
    {
        $this->createTenantWithAdmin();

        $this->assertTrue($this->adminUser->hasPermission('leads.view'));
        $this->assertTrue($this->adminUser->hasPermission('leads.delete'));
        $this->assertTrue($this->adminUser->hasPermission('settings.view'));
        $this->assertTrue($this->adminUser->hasPermission('non_existent_permission_key'));
    }

    public function test_user_without_role_returns_false_for_has_permission(): void
    {
        $this->createTenantWithAdmin();

        $user = new User(['tenant_id' => $this->tenant->id]);

        // In hasPermission(), null check is present: $this->role && ...
        $this->assertFalse($user->hasPermission('leads.view'));
    }

    public function test_custom_role_with_granular_permissions(): void
    {
        $this->createTenantWithAdmin();

        $permission = Permission::firstOrCreate([
            'key' => 'leads.export',
        ], [
            'group' => 'leads',
            'display_name' => 'Export Leads',
        ]);

        $customRole = Role::create([
            'name' => 'data_analyst',
            'display_name' => 'Data Analyst',
            'is_system' => false,
            'tenant_id' => $this->tenant->id,
        ]);

        $customRole->permissions()->attach($permission->id);

        $analyst = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $customRole->id,
        ]);

        $this->assertTrue($analyst->hasPermission('leads.export'));
        $this->assertFalse($analyst->hasPermission('leads.delete'));
    }
}
