#!/bin/sh
# Start: Cron-Schlüssel sicherstellen, Datenbank anlegen, minütlichen Cron starten, dann Apache.
set -e
DATA=/var/www/html/var
mkdir -p "$DATA/sessions"

if [ -z "$GUMBEL_CRON_KEY" ] && [ ! -s "$DATA/cron_key" ]; then
    php -r 'echo bin2hex(random_bytes(24));' > "$DATA/cron_key"
fi
KEY="${GUMBEL_CRON_KEY:-$(cat "$DATA/cron_key")}"
chown -R www-data:www-data "$DATA"

# Tabellen anlegen/aktualisieren (idempotent)
su -s /bin/sh www-data -c "php -r 'require \"/var/www/html/src/bootstrap.php\"; Vesta\\Db::install(Vesta\\App::pdo()); Vesta\\Db::migrate(Vesta\\App::pdo());'"

USERS=$(su -s /bin/sh www-data -c "php -r 'require \"/var/www/html/src/bootstrap.php\"; echo Vesta\\App::users()->count();'" 2>/dev/null || echo 0)
if [ "$USERS" = "0" ]; then
    echo "------------------------------------------------------------------"
    echo " Gumbel for Vestaboard is running."
    echo " First-time setup – open this link in your browser:"
    echo "   http://localhost:8080/setup.php?key=$KEY"
    echo " (on another device: replace localhost with this computer's address)"
    echo "------------------------------------------------------------------"
fi

# Minütlicher Cron im Hintergrund (Board-Abgleich, Automatik, Abrufe, Warteschlange) –
# per Kommandozeile, damit kein Schlüssel im Access-Log landet
(
    sleep 20
    while true; do
        su -s /bin/sh www-data -c "php /var/www/html/public/cron.php > /dev/null" || echo "cron: run failed" >&2
        sleep 60
    done
) &

exec "$@"
