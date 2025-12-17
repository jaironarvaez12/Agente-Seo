<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Permisos (ejemplos por módulo)
        $permisos = [
             //Empresas
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.eliminar',

            // Almacenes
            'cuentas.ver',
            'cuentas.crear',
            'cuentas.editar',
            'cuentas.eliminar',

           
        ];

        foreach ($permisos as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // Roles
        $admin   = Role::firstOrCreate(['name' => 'administrador', 'guard_name' => 'web']);
      

        // Asignar permisos a roles
        $admin->givePermissionTo(Permission::all());

       
    }
}
