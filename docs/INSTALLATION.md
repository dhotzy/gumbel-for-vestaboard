# Gumbel installieren – Schritt für Schritt

Ohne Programmierkenntnisse, etwa 15 Minuten. Wenn etwas hakt: [Hilfe bei Problemen](#hilfe-bei-problemen) ganz unten.

## Was du brauchst

1. **Dein Vestaboard** und die Vestaboard-App.
2. **Einen Token für dein Board** – in der Vestaboard-App: *Einstellungen → API → Cloud API → Token erstellen* (Lesen & Schreiben). Den trägst du später in Gumbel ein.
3. **Ein Gerät, das immer läuft** und auf dem Gumbel wohnt. Such dir eins aus:

| Gerät | Gut für | Aufwand |
|---|---|---|
| **NAS** (Synology, QNAP, Unraid …) | läuft eh rund um die Uhr | ★☆☆ am einfachsten, wenn vorhanden |
| **Raspberry Pi** (ab 3B) | kleine, leise, günstige Box (~50 €) | ★★☆ |
| **Computer, der immer an ist** (Windows/Mac/Linux) | zum Ausprobieren | ★☆☆ |
| **Webhosting mit PHP** | von überall erreichbar, Webhooks aus dem Internet | ★★☆ |

> Gumbel zu Hause (NAS, Pi, Computer) kann auch die **Local API** nutzen – das Board wird dann direkt im Heimnetz gesteuert, ohne Vestaboard-Cloud. Damit Dienste aus dem Internet Nachrichten schicken können (Webhooks, Slack), muss Gumbel aus dem Internet erreichbar sein – am einfachsten mit Webhosting.

---

## A) Computer mit Docker Desktop (Windows / Mac)

1. **Docker Desktop** von [docker.com](https://www.docker.com/products/docker-desktop/) installieren und einmal starten.
2. Einen Ordner anlegen, z. B. `Dokumente/gumbel`.
3. Die Datei [docker-compose.yml](https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml) in diesem Ordner speichern (Rechtsklick → *Link speichern unter …*). Optional mit einem Texteditor öffnen und `TZ` auf deine Zeitzone stellen – die Sprache wählst du gleich bei der Einrichtung.
4. Ein Terminal **in diesem Ordner** öffnen – Windows: im Explorer oben in die Adresszeile `cmd` tippen und Enter; Mac: *Terminal* öffnen, `cd ` (mit Leerzeichen) tippen, den Ordner ins Fenster ziehen, Enter. Dann eingeben:
   ```bash
   docker compose up -d
   ```
   Beim ersten Start wird Gumbel heruntergeladen (ca. 200 MB).
5. Den **Einrichtungslink** finden: Docker Desktop → *Containers* → **gumbel** → *Logs*. Dort steht eine Zeile wie `http://localhost:8080/setup.php?key=…` – im Browser öffnen.
6. Weiter mit [Ersteinrichtung](#ersteinrichtung).

Damit Gumbel immer läuft: in Docker Desktop *Settings → General → Start Docker Desktop when you sign in* einschalten und den Ruhezustand des Computers abschalten.

## B) Synology-NAS (Container Manager)

1. Im Paket-Zentrum **Container Manager** installieren.
2. In der *File Station* einen Ordner `docker/gumbel` anlegen.
3. Container Manager → *Projekt* → *Erstellen*: Name `gumbel`, Pfad `docker/gumbel`, Quelle *docker-compose.yml erstellen* und den Inhalt von [docker-compose.yml](https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml) einfügen. Weiter → Fertig.
4. Container Manager → *Container* → **gumbel** → *Details* → *Protokoll*: den Einrichtungslink kopieren und `localhost` durch die Adresse deines NAS ersetzen (z. B. `http://192.168.1.20:8080/setup.php?key=…`).
5. Weiter mit [Ersteinrichtung](#ersteinrichtung).

Andere NAS (QNAP Container Station, Unraid, Portainer) funktionieren genauso: einen „Stack“ bzw. „Compose-Projekt“ mit der Datei oben anlegen.

## C) Raspberry Pi

1. Mit dem *Raspberry Pi Imager* **Raspberry Pi OS Lite (64-bit)** aufspielen; in den Einstellungen **Benutzername und Passwort festlegen**, SSH aktivieren und WLAN eintragen.
2. Per SSH verbinden (`ssh <dein-benutzername>@raspberrypi.local`, unter Windows in der Eingabeaufforderung) und Docker installieren:
   ```bash
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   ```
   Einmal ab- und wieder anmelden.
3. Gumbel starten:
   ```bash
   mkdir gumbel && cd gumbel
   curl -fsSLO https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml
   docker compose up -d
   docker compose logs gumbel
   ```
4. Den Einrichtungslink aus dem Log öffnen, `localhost` durch `raspberrypi.local` (oder die IP des Pi) ersetzen.
5. Weiter mit [Ersteinrichtung](#ersteinrichtung).

## D) Webhosting (ohne Docker)

Voraussetzungen: PHP 8.2 oder neuer **mit aktuellen Sicherheitsupdates** (mindestens 8.2.35, 8.3.36, 8.4.27 bzw. 8.5.12 wegen der PHP2Shell-Lücken vom Oktober 2026; empfohlen 8.4) mit `pdo_mysql` oder `pdo_sqlite`, `curl`, `mbstring`, `intl`; HTTPS; Cronjobs.

1. Projekt herunterladen ([ZIP](https://github.com/dhotzy/gumbel-for-vestaboard/archive/refs/heads/main.zip)) und z. B. per FTP hochladen.
2. Im Hosting-Panel die (Sub-)Domain auf den Ordner **`public/`** zeigen lassen – nie auf den Projektordner selbst.
3. Im Hosting-Panel eine **MySQL/MariaDB-Datenbank anlegen** (Name, Benutzer, Passwort notieren) – oder ohne Datenbank-Server **SQLite** nutzen (`'driver' => 'sqlite', 'path' => APP_ROOT . '/var/gumbel.sqlite'`; der Ordner `var/` muss beschreibbar sein).
4. `config/config.example.php` nach `config/config.php` kopieren und ausfüllen: Datenbank-Zugang, einen langen zufälligen `cron_key` (z. B. aus einem Passwort-Generator), `timezone`. Den Board-Token kannst du auch später in der App eintragen.
5. Einen Cronjob **jede Minute** anlegen: `php /pfad/zu/gumbel/public/cron.php` – oder, wenn nur URL-Cronjobs gehen: `https://deine-domain/cron.php?key=<cron_key>`.
6. `https://deine-domain/setup.php?key=<cron_key>` öffnen und weiter mit [Ersteinrichtung](#ersteinrichtung).

   Bei **nginx** werden `.htaccess`-Dateien ignoriert – dort ist der Document-Root `public/` Pflicht, z. B.:
   ```nginx
   server {
       root /var/www/gumbel/public;
       index index.php;
       location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
       location ~ /\. { deny all; }
   }
   ```

---

## Ersteinrichtung

1. **Schritt 1 – Admin-Zugang:** Sprache, Name und Passwort (mind. 8 Zeichen). Danach bist du direkt angemeldet.
2. **Schritt 2 – Board verbinden:** den Read/Write-Token aus der Vestaboard-App eintragen → *Speichern und testen*. Steht da „Verbindung steht“, auf *Fertig – erste Nachricht schreiben* tippen.
   - Gumbel läuft zu Hause und du willst die **Local API**? Auf derselben Seite: auf vestaboard.com/local-api einen Freischalt-Token anfordern, IP-Adresse des Boards und Token eintragen → *Freischalten*.
3. **Familie anlegen:** *Einstellungen → Nutzer* – Erwachsene mit Passwort, Kinder mit PIN.
4. **Aufs Handy:** Adresse in Safari/Chrome öffnen → *Teilen → Zum Home-Bildschirm*. Dann sieht Gumbel aus wie eine App.

**Später wieder öffnen:** `http://<IP-Adresse>:8080` (Docker) bzw. deine Domain. Für NAS und Raspberry Pi im Router eine **feste IP-Adresse** (DHCP-Reservierung) vergeben, dann bleibt die Adresse gleich.

Danach optional: Wecker, Uhr und Wetter unter *Automatik*; fertige Rezepte (Nachrichten, Bitcoin, GitHub …) unter *Einstellungen → Integrationen*; Telegram/Slack unter *Einstellungen → Messenger*; der Briefkasten mit QR-Code für Gäste unter *Einstellungen → Briefkasten*.

## Updates und Sicherung

- **Update (Docker):** im Ordner mit der `docker-compose.yml`:
  ```bash
  docker compose pull && docker compose up -d
  ```
  Synology: Container Manager → *Projekt* → gumbel stoppen, unter *Image* `ghcr.io/dhotzy/gumbel-for-vestaboard` aktualisieren (bzw. löschen und neu laden), Projekt wieder starten. Deine Daten bleiben erhalten.
- **Sicherung (Docker):** Alle Daten liegen im Volume `gumbel-data` – eine SQLite-Datei:
  ```bash
  docker cp gumbel:/var/www/html/var/gumbel.sqlite ./gumbel-sicherung.sqlite
  ```
- **Sicherung zurückspielen (Docker):**
  ```bash
  docker compose stop
  docker cp ./gumbel-sicherung.sqlite gumbel:/var/www/html/var/gumbel.sqlite
  docker compose start
  ```
- **Webhosting:** neue Dateien hochladen (`config/config.php` und `var/` behalten); Datenbank-Änderungen laufen automatisch. Sicherung = Datenbank-Export im Hosting-Panel.

## Hilfe bei Problemen

| Problem | Lösung |
|---|---|
| Einrichtungslink verloren | `docker compose logs gumbel` – oder `docker exec gumbel cat /var/www/html/var/cron_key` und `/setup.php?key=<dieser Wert>` öffnen |
| „Port is already allocated“ | In `docker-compose.yml` `"8080:80"` z. B. in `"8081:80"` ändern, dann erneut `docker compose up -d` – und im Einrichtungslink ebenfalls `:8081` verwenden |
| Seite geht auf dem Handy nicht auf | Statt `localhost` die IP-Adresse des Computers nehmen; Handy und Computer im selben WLAN |
| Board zeigt nichts | *Einstellungen → Board-Verbindung → Verbindung testen*. Token falsch? Board in der Vestaboard-App stummgeschaltet? Glocke oben in Gumbel durchgestrichen? |
| Nachrichten warten ewig | Läuft der Cron? Auf *Board* steht der letzte Abgleich. Webhosting: Cronjob prüfen |
| Local API: „nicht erreichbar“ | Gumbel muss im selben Netz wie das Board laufen – nicht beim Webhoster |
