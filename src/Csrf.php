<?php
declare(strict_types=1);

namespace Vesta;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::token() . '">';
    }

    /** Bricht mit 400 ab, wenn das Token fehlt oder falsch ist. */
    public static function verify(): void
    {
        $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals(self::token(), $sent)) {
            http_response_code(400);
            exit(t('Sitzung abgelaufen. Bitte Seite neu laden.'));
        }
    }
}
