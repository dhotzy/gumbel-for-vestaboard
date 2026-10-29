# Screenshots

Regenerates the images in `docs/screenshots/` from a demo installation (English, sample data).

```bash
php scripts/demo.php en
VESTA_CONFIG=var/demo-config.php php -S 127.0.0.1:8777 -t public &
npm i -D playwright && npx playwright install chromium
node scripts/screenshots/capture.cjs   # phone screenshots → docs/screenshots/raw/ (not in Git)
node scripts/screenshots/compose.cjs   # hero.png and integrations.png
```

Demo logins: Alex / demo-password (admin), Jordan / demo-password (adult), Mia / PIN 1234 (kid).
