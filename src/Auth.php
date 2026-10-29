<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Login per Name + Passwort/PIN, Sperre nach Fehlversuchen, Session-Verwaltung.
 */
final class Auth
{
    public const MAX_FAILED = 5;
    /** Fehlversuche je IP (über alle Namen) bis zur Sperre */
    public const MAX_FAILED_PER_IP = 20;
    public const LOCK_MINUTES = 15;
    private const SESSION_DAYS = 30;
    private const TIMING_DUMMY_HASH = '$2y$12$/nLVqo3vmd5eN27IBgl95e9JVj9/2mtg34y2F6cbmb3NKWnYmu6jy';

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = APP_ROOT . '/var/sessions';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        $lifetime = self::SESSION_DAYS * 86400;
        session_save_path($path);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        session_name('gumbel');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['user_id'] ?? 0);
            $user = $id > 0 ? App::users()->find($id) : null;
            // deaktiviert oder PIN/Passwort inzwischen geändert → Session ungültig
            $valid = $user !== null && (int) $user['active'] === 1
                && hash_equals(self::secretSignature($user), (string) ($_SESSION['secret_sig'] ?? ''));
            self::$user = $valid ? $user : null;
        }
        return self::$user;
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function login(string $name, string $secret): ?string
    {
        $users = App::users();
        $throttle = App::loginThrottle();
        $now = App::clock()->now();
        $user = $users->findByName($name);
        $generic = t('Name oder Passwort/PIN falsch.');
        $ip = self::clientIp();
        // Gesperrt wird je IP und je Konto+IP: Fremde können so niemanden aussperren, und massenhaftes Raten
        // (auch über viele Namen) bremst die IP-Sperre. Unbekannte Namen verhalten sich genauso wie bekannte.
        $ipKey = 'ip:' . $ip;
        $accountKey = 'u:' . ($user !== null ? (string) $user['id'] : 'name:' . mb_strtolower(trim($name))) . '|' . $ip;
        $locked = max($throttle->lockedFor($ipKey, $now), $throttle->lockedFor($accountKey, $now));
        if ($locked > 0) {
            return t('Zu viele Fehlversuche. Bitte in {duration} erneut versuchen.', ['duration' => Time::duration($locked)]);
        }
        // Versuch vor der Prüfung zählen: parallele Requests bekommen so je eine eigene Nummer
        if (!$throttle->hit($accountKey, self::MAX_FAILED, self::LOCK_MINUTES, $now)) {
            return t('Zu viele Fehlversuche. Bitte in {minutes} Min erneut versuchen.', ['minutes' => self::LOCK_MINUTES]);
        }
        $valid = $user !== null && (int) $user['active'] === 1
            ? password_verify($secret, $user['secret_hash'])
            : password_verify($secret, self::TIMING_DUMMY_HASH) && false; // gleiche Laufzeit wie bei existierendem Nutzer
        if (!$valid) {
            $throttle->hit($ipKey, self::MAX_FAILED_PER_IP, self::LOCK_MINUTES, $now);
            return $generic;
        }

        $throttle->reset($accountKey);
        if (random_int(1, 20) === 1) {
            $throttle->cleanup($now);
        }
        session_regenerate_id(true);
        unset($_SESSION['csrf']); // neues CSRF-Token nach der Anmeldung
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['secret_sig'] = self::secretSignature($user);
        self::$loaded = false;
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            header('Location: login.php');
            exit;
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (!MessageService::isAdmin($user)) {
            http_response_code(403);
            exit(t('Kein Zugriff.'));
        }
        return $user;
    }

    /** Nach eigener Passwortänderung die laufende Session gültig halten. */
    public static function rememberSecret(array $user): void
    {
        $_SESSION['secret_sig'] = self::secretSignature($user);
    }

    /**
     * IP des Clients. Hinter einem Reverse-Proxy (z. B. Docker mit Caddy/Traefik) nur dann X-Forwarded-For auswerten,
     * wenn der Proxy in config 'trusted_proxies' steht – sonst könnte jeder seine IP fälschen.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = self::trustedProxies();
        if ($trusted === [] || !in_array($remote, $trusted, true) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }
        // von rechts: die erste Adresse, die kein vertrauenswürdiger Proxy ist
        foreach (array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trusted, true)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** HTTPS direkt, über einen vertrauenswürdigen Proxy (X-Forwarded-Proto) oder per config 'force_https' */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $config = function_exists('app_config') ? app_config() : [];
        if (!empty($config['force_https'])) {
            return true;
        }
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), self::trustedProxies(), true)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @return list<string> */
    private static function trustedProxies(): array
    {
        $value = app_config()['trusted_proxies'] ?? [];
        return array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value))));
    }

    private static function secretSignature(array $user): string
    {
        return substr(hash('sha256', (string) $user['secret_hash']), 0, 16);
    }

    public static function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_DEFAULT);
    }

    /** @return string|null Fehlermeldung, wenn Passwort/PIN nicht den Regeln entspricht */
    public static function validateSecret(string $loginType, string $secret): ?string
    {
        if ($loginType === 'pin') {
            return preg_match('/^\d{4,8}$/', $secret) ? null : t('Die PIN muss aus 4 bis 8 Ziffern bestehen.');
        }
        return mb_strlen($secret) >= 8 ? null : t('Das Passwort muss mindestens 8 Zeichen haben.');
    }
}
