# Installing Gumbel – step by step

No programming needed. Plan about 15 minutes. If you get stuck, see [Troubleshooting](#troubleshooting) at the end.

## What you need

1. **Your Vestaboard** and the Vestaboard app.
2. **A token for your board** – in the Vestaboard app: *Settings → API → Cloud API → Create token* (Read & Write). You enter it in Gumbel later.
3. **A device that is always on**, where Gumbel lives. Pick one:

| Device | Good for | Effort |
|---|---|---|
| **NAS** (Synology, QNAP, Unraid …) | already running 24/7 at home | ★☆☆ easiest if you have one |
| **Raspberry Pi** (3B or newer) | a small, quiet, cheap box (~€50) | ★★☆ |
| **Always-on computer** (Windows/Mac/Linux) | trying it out | ★☆☆ |
| **Web hosting with PHP** | reachable from anywhere, webhooks from the internet | ★★☆ |

> Gumbel at home (NAS, Pi, computer) can also use the **Local API** – the board is then controlled directly over your home network, without the Vestaboard cloud. To receive messages from internet services (webhooks, Slack), Gumbel must be reachable from the internet – that's easiest with web hosting.

---

## A) Computer with Docker Desktop (Windows / Mac)

1. Install **Docker Desktop** from [docker.com](https://www.docker.com/products/docker-desktop/) and start it once.
2. Create a folder, e.g. `Documents/gumbel`.
3. Save [docker-compose.yml](https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml) into this folder (right-click → *Save link as …*). Optional: open it with a text editor and change `GUMBEL_LANGUAGE` to `de` or `TZ` to your time zone.
4. Open a terminal **in this folder** – Windows: in Explorer type `cmd` into the address bar and press Enter; Mac: open *Terminal*, type `cd ` (with a space), drag the folder into the window, press Enter. Then enter:
   ```bash
   docker compose up -d
   ```
   The first start downloads Gumbel (about 200 MB).
5. Find the **setup link**: in Docker Desktop → *Containers* → **gumbel** → *Logs*. You'll see a line like `http://localhost:8080/setup.php?key=…` – open it in your browser.
6. Continue with [First setup](#first-setup).

To keep Gumbel running: in Docker Desktop turn on *Settings → General → Start Docker Desktop when you sign in* and disable sleep on the computer.

## B) Synology NAS (Container Manager)

1. In the Package Center, install **Container Manager**.
2. In *File Station*, create a folder `docker/gumbel`.
3. Container Manager → *Project* → *Create*: name `gumbel`, path `docker/gumbel`, source *Create docker-compose.yml* and paste the contents of [docker-compose.yml](https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml). Next → Done.
4. Container Manager → *Container* → **gumbel** → *Details* → *Log*: copy the setup link and replace `localhost` with the address of your NAS (e.g. `http://192.168.1.20:8080/setup.php?key=…`).
5. Continue with [First setup](#first-setup).

Other NAS systems (QNAP Container Station, Unraid, Portainer) work the same way: create a "stack" or "compose project" with the file above.

## C) Raspberry Pi

1. Flash **Raspberry Pi OS Lite (64-bit)** with the *Raspberry Pi Imager*; in the settings **set a username and password**, enable SSH and set up Wi-Fi.
2. Connect via SSH (`ssh <your-username>@raspberrypi.local`, on Windows in the command prompt) and install Docker:
   ```bash
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   ```
   Log out and back in.
3. Start Gumbel:
   ```bash
   mkdir gumbel && cd gumbel
   curl -fsSLO https://raw.githubusercontent.com/dhotzy/gumbel-for-vestaboard/main/docker-compose.yml
   docker compose up -d
   docker compose logs gumbel
   ```
4. Open the setup link from the logs, replacing `localhost` with `raspberrypi.local` (or the Pi's IP).
5. Continue with [First setup](#first-setup).

## D) Web hosting (without Docker)

Requirements: PHP 8.2 or newer **with current security updates** (at least 8.2.35, 8.3.36, 8.4.27 or 8.5.12 because of the PHP2Shell vulnerabilities from October 2026; 8.4 recommended) with `pdo_mysql` or `pdo_sqlite`, `curl`, `mbstring`, `intl`; HTTPS; cron jobs.

1. Download the project ([ZIP](https://github.com/dhotzy/gumbel-for-vestaboard/archive/refs/heads/main.zip)) and upload it, e.g. via FTP.
2. In your hosting panel, point the (sub)domain to the **`public/`** folder – never to the project folder itself.
3. In your hosting panel **create a MySQL/MariaDB database** (note name, user, password) – or use **SQLite** without a database server (`'driver' => 'sqlite', 'path' => APP_ROOT . '/var/gumbel.sqlite'`; the `var/` folder must be writable).
4. Copy `config/config.example.php` to `config/config.php` and fill in: database details, a long random `cron_key` (e.g. from a password generator), `'language' => 'en'`, `timezone`. The board token can also be entered later in the app.
5. Create a cron job **every minute**: `php /path/to/gumbel/public/cron.php` – or, if only URL cron jobs are offered: `https://your-domain/cron.php?key=<cron_key>`.
6. Open `https://your-domain/setup.php?key=<cron_key>` and continue with [First setup](#first-setup).

   With **nginx**, `.htaccess` files are ignored – the document root `public/` is mandatory there, e.g.:
   ```nginx
   server {
       root /var/www/gumbel/public;
       index index.php;
       location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
       location ~ /\. { deny all; }
   }
   ```

---

## First setup

1. **Step 1 – admin account:** choose a language, name and password (at least 8 characters). You're signed in right away.
2. **Step 2 – connect the board:** enter the Read/Write token from the Vestaboard app → *Save and test*. When it says "Connected", tap *Done – write your first message*.
   - Gumbel runs at home and you want the **Local API**? On the same page: request an enablement token at vestaboard.com/local-api, enter the board's IP address and the token → *Enable*.
3. **Add family:** *Settings → Users* – adults with a password, kids with a PIN.
4. **On the phone:** open the address in Safari/Chrome → *Share → Add to Home Screen*. Gumbel then looks like an app.

**Opening it later:** `http://<IP address>:8080` (Docker) or your domain. For a NAS or Raspberry Pi, give it a **fixed IP address** in your router (DHCP reservation) so the address stays the same.

Optional next steps: alarm, clock and weather under *Automation*; ready-made recipes (news, Bitcoin, GitHub …) under *Settings → Integrations*; Telegram/Slack under *Settings → Messenger*; the guest mailbox with QR code under *Settings → Mailbox*.

## Updates and backup

- **Update (Docker):** in the folder with `docker-compose.yml`:
  ```bash
  docker compose pull && docker compose up -d
  ```
  Synology: Container Manager → *Project* → stop gumbel, update the image `ghcr.io/dhotzy/gumbel-for-vestaboard` under *Image* (or delete and pull it again), start the project again. Your data stays.
- **Backup (Docker):** all data lives in the `gumbel-data` volume – one SQLite file:
  ```bash
  docker cp gumbel:/var/www/html/var/gumbel.sqlite ./gumbel-backup.sqlite
  ```
- **Restore a backup (Docker):**
  ```bash
  docker compose stop
  docker cp ./gumbel-backup.sqlite gumbel:/var/www/html/var/gumbel.sqlite
  docker compose start
  ```
- **Web hosting:** upload the new files (keep `config/config.php` and `var/`); database changes run automatically. Backup = database dump from your hosting panel.

## Troubleshooting

| Problem | Solution |
|---|---|
| Setup link lost | `docker compose logs gumbel` – or `docker exec gumbel cat /var/www/html/var/cron_key` and open `/setup.php?key=<that value>` |
| "Port is already allocated" | In `docker-compose.yml` change `"8080:80"` to e.g. `"8081:80"`, then `docker compose up -d` again – and use `:8081` in the setup link too |
| Page doesn't open on the phone | Use the computer's IP address instead of `localhost`; phone and computer must be on the same Wi-Fi |
| Board shows nothing | *Settings → Board connection → Test connection*. Token wrong? Board muted in the Vestaboard app? The bell at the top of Gumbel crossed out? |
| Messages wait forever | Does the cron run? *Board* shows the last check. Web hosting: check the cron job |
| Local API: "not reachable" | Gumbel must run on the same network as the board – not on web hosting |
