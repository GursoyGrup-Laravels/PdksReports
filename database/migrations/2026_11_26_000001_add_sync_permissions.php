<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissions = ['sync_employees', 'sync_reports', 'import_reports'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Bu projede super_admin izinleri role tek tek yazılıdır
        Role::where('name', 'super_admin')->first()?->givePermissionTo($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::whereIn('name', $this->permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
