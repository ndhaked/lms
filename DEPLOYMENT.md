# Deploying to Hostinger shared hosting

No queue worker, Redis or WebSocket server is needed. Everything runs from one cron entry.

## 1. Requirements
- PHP 8.2 or newer (hPanel → Advanced → PHP Configuration). Enable: `intl`, `mbstring`, `pdo_mysql`, `zip`, `gd`, `fileinfo`, `bcmath`, `xml`, `ctype`.
- MySQL 8 / MariaDB 10.6+ database created in hPanel.

## 2. Build locally, then upload
```
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```
Upload the project (including `vendor/` and `public/build/`, excluding `node_modules/`, `.env`, `tests/`) to a folder **outside** `public_html`, e.g. `~/laravel`.
Point the domain's document root at `~/laravel/public` (or copy `public/*` into `public_html` and edit the two paths in `index.php`).

## 3. Configure
```
cp .env.production.example .env
php artisan key:generate
```
Fill in DB and mail settings. `APP_DEBUG` must stay `false`; `SESSION_SECURE_COOKIE=true` requires HTTPS (enable the free SSL in hPanel).

## 4. First run
```
php artisan migrate --force
php artisan db:seed --force        # creates the super admin and plans; CHANGE THE PASSWORD IMMEDIATELY
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
```
After the first login change `admin@example.com` to a real address and set a strong password (or create a new platform admin and disable the seeded one).

## 5. Cron (hPanel → Advanced → Cron Jobs, every minute)
```
* * * * * cd /home/USER/laravel && php artisan schedule:run >> /dev/null 2>&1
```
Runs: `subscriptions:sync` (00:30), `library:send-reminders` (07:00), `db:backup` (02:00, keeps 14).

## 6. Backups
- `php artisan db:backup` writes `storage/app/backups/<db>-<date>.sql` (not web accessible). Also download copies off-server regularly, and enable Hostinger's own backups.
- Restore: `mysql -u USER -p DBNAME < backup.sql`, or import via phpMyAdmin.
- Test a restore on a scratch database before you depend on it.
- Uploaded files (payment proofs, expense attachments) live in `storage/app` — back that folder up too.

## 7. Updating
```
php artisan down
# upload new files
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## 8. Logs & security notes
- Logs rotate daily (`LOG_CHANNEL=daily`, 14 days) in `storage/logs`.
- HTTPS is forced in production, security headers are sent on every response, and login attempts are rate limited.
- Never expose `.env`, `storage/` or `vendor/` through the document root.
