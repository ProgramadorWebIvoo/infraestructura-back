<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Política de contraseña compartida: mínimo 8, mayúscula+minúscula y número.
 * Sin `->uncompromised()` (llamada de red a HaveIBeenPwned) para no
 * introducir una dependencia externa en cada alta de usuario/test.
 */
class StrongPassword
{
    public static function rule(): PasswordRule
    {
        return PasswordRule::min(8)->mixedCase()->numbers();
    }
}
