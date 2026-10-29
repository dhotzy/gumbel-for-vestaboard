<?php
declare(strict_types=1);

namespace Vesta;

use PDO;

/**
 * Einfacher Service-Container für Web-Seiten und Cron.
 */
final class App
{
    private static array $instances = [];
    /** true, wenn eine Datenbank-Migration gescheitert ist (Admin bekommt einen Hinweis) */
    public static bool $migrationError = false;

    public static function pdo(): PDO
    {
        if (!isset(self::$instances[__FUNCTION__])) {
            $pdo = Db::connect((array) (app_config()['db'] ?? []));
            if (Db::isInstalled($pdo)) {
                try {
                    Db::migrate($pdo);
                } catch (\Throwable $e) {
                    error_log('Datenbank-Migration fehlgeschlagen: ' . $e->getMessage());
                    self::$migrationError = true;
                }
            }
            self::$instances[__FUNCTION__] = $pdo;
        }
        return self::$instances[__FUNCTION__];
    }

    public static function clock(): Clock
    {
        return self::$instances[__FUNCTION__] ??= new SystemClock();
    }

    public static function settings(): Settings
    {
        if (!isset(self::$instances[__FUNCTION__])) {
            $settings = new Settings(self::pdo());
            // Sprache: Einstellung, sonst config 'language', sonst Deutsch (bestehende Installationen)
            I18n::set((string) ($settings->get('language') ?? app_config()['language'] ?? 'de'));
            self::$instances[__FUNCTION__] = $settings;
        }
        return self::$instances[__FUNCTION__];
    }

    public static function messages(): MessageRepo
    {
        return self::$instances[__FUNCTION__] ??= new MessageRepo(self::pdo());
    }

    public static function users(): UserRepo
    {
        return self::$instances[__FUNCTION__] ??= new UserRepo(self::pdo());
    }

    public static function loginThrottle(): LoginThrottle
    {
        return self::$instances[__FUNCTION__] ??= new LoginThrottle(self::pdo());
    }

    public static function apiLog(): ApiLog
    {
        return self::$instances[__FUNCTION__] ??= new ApiLog(self::pdo());
    }

    public static function messageService(): MessageService
    {
        return self::$instances[__FUNCTION__] ??= new MessageService(self::messages(), self::clock());
    }

    /** 'log' in der Konfiguration = kein echtes Board; sonst Cloud oder Local API laut Einstellung */
    public static function board(): BoardClient
    {
        return self::$instances[__FUNCTION__] ??= (app_config()['board_driver'] ?? 'vestaboard') === 'log'
            ? new LogBoard(APP_ROOT . '/var/board.log')
            : self::boardConnection()->client();
    }

    public static function messenger(): Messenger
    {
        return self::$instances[__FUNCTION__] ??= new Messenger(self::pdo(), self::settings(), self::messages(), self::clock());
    }

    public static function guestPost(): GuestPost
    {
        return self::$instances[__FUNCTION__] ??= new GuestPost(self::pdo(), self::settings(), self::messages(), self::clock());
    }

    public static function boardConnection(): BoardConnection
    {
        return self::$instances[__FUNCTION__] ??= new BoardConnection(self::settings());
    }

    public static function arbiter(): Arbiter
    {
        return self::$instances[__FUNCTION__] ??= new Arbiter(
            self::messages(),
            self::settings(),
            self::board(),
            self::clock(),
            new Lock(APP_ROOT . '/var/arbiter.lock'),
            self::apiLog(),
        );
    }

    public static function automation(): Automation
    {
        return self::$instances[__FUNCTION__] ??= new Automation(self::messages(), self::settings(), self::clock());
    }

    public static function templates(): TemplateRepo
    {
        return self::$instances[__FUNCTION__] ??= new TemplateRepo(self::pdo());
    }

    public static function inbound(): Inbound
    {
        return self::$instances[__FUNCTION__] ??= new Inbound(self::pdo(), self::messages(), self::clock());
    }

    public static function boardSync(): BoardSync
    {
        return self::$instances[__FUNCTION__] ??= new BoardSync(self::messages(), self::settings(), self::board(), self::clock(), self::apiLog());
    }

    /**
     * Arbiter direkt nach einer Aktion anstoßen, damit niemand auf den Cron warten muss.
     * Bei Force wird das 15-Sekunden-Limit abgewartet (max. 15 s).
     */
    public static function triggerArbiter(bool $waitForRateLimit = false): array
    {
        try {
            $result = self::arbiter()->run();
            if ($waitForRateLimit && $result['action'] === 'rate_limited' && (int) $result['retry_in'] <= Arbiter::RATE_LIMIT_SECONDS) {
                $hadSession = session_status() === PHP_SESSION_ACTIVE;
                if ($hadSession) {
                    session_write_close(); // andere Tabs nicht blockieren, solange wir warten
                }
                sleep((int) $result['retry_in']);
                if ($hadSession) {
                    session_start(); // für die Erfolgsmeldung danach
                }
                $result = self::arbiter()->run();
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('Arbiter-Fehler: ' . $e->getMessage());
            return ['action' => 'error', 'message_id' => null, 'detail' => 'Interner Fehler beim Senden.', 'retry_in' => null];
        }
    }
}
