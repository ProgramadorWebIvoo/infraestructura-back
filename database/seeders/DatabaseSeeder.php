<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Localization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        User::updateOrCreate(
            ['email' => 'admin@ivoo.local'],
            [
                'name' => 'Administrador IVOO',
                'password' => Hash::make('Admin12345'),
                'role' => 'SUPERADMIN',
            ] 
        );

        // Residente y ubicaciones de ejemplo (QA y fixtures de depuración, F2-R R7a).
        $resident = User::updateOrCreate(
            ['email' => 'residente@ivoo.local'],
            [
                'name' => 'Residente Ejemplo',
                'password' => Hash::make('Residente12345'),
                'role' => 'RESIDENTE',
                'status' => 'Active',
            ]
        );

        foreach ([
            ['title' => 'Tienda Centro', 'city' => 'Caracas', 'region' => 'Distrito Capital', 'type' => 'TIENDA', 'address' => 'Av. Principal, Centro'],
            ['title' => 'Planta Valencia', 'city' => 'Valencia', 'region' => 'Carabobo', 'type' => 'PLANTA', 'address' => 'Zona Industrial'],
            ['title' => 'Oficina Principal', 'city' => 'Caracas', 'region' => 'Distrito Capital', 'type' => 'OFICINA', 'address' => 'Torre Corporativa'],
        ] as $data) {
            Localization::firstOrCreate(
                ['title' => $data['title'], 'city' => $data['city']],
                [...$data, 'is_active' => true, 'resident_user_id' => $resident->id]
            );
        }

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
