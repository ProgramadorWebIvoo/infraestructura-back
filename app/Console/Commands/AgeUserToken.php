<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AgeUserToken extends Command
{
    protected $signature = 'token:age {email? : Email del usuario}';
    protected $description = 'Envejece el token de un usuario para probar refresh silencioso (setea created_at a 23h atrás)';

    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('Email del usuario');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Usuario con email {$email} no encontrado.");
            return self::FAILURE;
        }

        $token = $user->tokens()->latest('id')->first();

        if (! $token) {
            $this->error("El usuario {$email} no tiene tokens activos.");
            return self::FAILURE;
        }

        $original = $token->created_at->format('Y-m-d H:i:s');
        $aged = Carbon::now()->subMinutes(1380); // 23 horas atrás

        $token->created_at = $aged;
        $token->save();

        $this->info("✅ Token #{$token->id} de {$email} envejecido:");
        $this->line("   Antes: {$original}");
        $this->line("   Ahora: {$token->fresh()->created_at->format('Y-m-d H:i:s')}");
        $this->line("   El próximo request autenticado debería devolver X-Refresh-Token header.");

        return self::SUCCESS;
    }
}
