<?php

namespace Database\Seeders;

use App\Models\SystemKeyConfig;
use Illuminate\Database\Seeder;

/**
 * Grupo SMTP de Configuración > Keys, tomado de las variables MAIL_* del .env.
 * Sin MAIL_USERNAME/MAIL_PASSWORD no se siembra (no hay credenciales en el repo).
 */
class SystemKeyConfigSeeder extends Seeder
{
    public function run(): void
    {
        if (empty(env('MAIL_USERNAME')) || empty(env('MAIL_PASSWORD'))) {
            $this->command?->warn('MAIL_USERNAME/MAIL_PASSWORD vacíos: se omite system_key_configs (smtp).');
            return;
        }

        SystemKeyConfig::updateOrCreate(
            ['group' => 'smtp'],
            [
                'data' => [
                    'host' => env('MAIL_HOST', 'smtp.gmail.com'),
                    'port' => (string) env('MAIL_PORT', '587'),
                    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                    'username' => env('MAIL_USERNAME'),
                    'password' => env('MAIL_PASSWORD'),
                    'from_address' => env('MAIL_FROM_ADDRESS', env('MAIL_USERNAME')),
                    'from_name' => env('MAIL_FROM_NAME', config('app.name')),
                ],
                'is_active' => true,
            ]
        );
    }
}
