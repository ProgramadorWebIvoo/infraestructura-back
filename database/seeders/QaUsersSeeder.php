<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de prueba por rol (dump QA). `firstOrCreate`: no pisa usuarios que
 * ya existan. La contraseña común es el string PASSWORD, que se hashea
 * (bcrypt) una sola vez y se asigna a todos.
 */
class QaUsersSeeder extends Seeder
{
    private const PASSWORD = '123';

    private const USERS = [
        ['Alejandro González', 'admin@ivoo.local', 'SUPERADMIN'],
        ['Test Presidencia', 'test.presidencia@ivoo.local', 'PRESIDENCIA'],
        ['Test Infraestructura', 'test.infra@ivoo.local', 'INFRAESTRUCTURA'],
        ['Test Cierre Obra', 'test.cierre@ivoo.local', 'CIERRE_DE_OBRA'],
        ['Test Procura', 'test.procura@ivoo.local', 'PROCURA'],
        ['Test Analistas', 'test.analistas@ivoo.local', 'ANALISTA'],
        ['Test Finanzas', 'test.finanzas@ivoo.local', 'FINANZAS'],
        ['Test Catalogos', 'test.catalogos@ivoo.local', 'CATALOGOS'],
        ['Test User', 'Admin@ivoo.com', 'ANALISTA'],
    ];

    public function run(): void
    {
        $passwordHash = Hash::make(self::PASSWORD);

        foreach (self::USERS as [$name, $email, $role]) {
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'status' => 'Active',
                    'password' => $passwordHash,
                ]
            );
        }
    }
}
