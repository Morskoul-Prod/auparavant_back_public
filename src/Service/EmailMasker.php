<?php

namespace App\Service;

/**
 * Masque une adresse email pour l'afficher dans un message sans la révéler en entier
 * (jean.dupont@gmail.com → j***@gmail.com).
 */
final class EmailMasker
{
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
