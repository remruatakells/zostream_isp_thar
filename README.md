# ZoStream ISP Control

Laravel 13 admin panel and JSON API for managing MikroTik PPPoE subscribers through central FreeRADIUS, a shared MySQL database and private WireGuard-connected routers.

## Included

- Secure administrator login
- Dashboard with customer, revenue, expiry and router summaries
- MikroTik router management and connection testing
- Internet packages mapped to per-user RADIUS speed attributes
- Automatic FreeRADIUS customer, package and NAS synchronization
- PPPoE customer creation, update, activation, suspension and recovery sync
- Payment ledger with one-click package renewal
- Daily midnight expired-customer suspension command
- Bearer-token protected JSON API
- Encrypted router and PPPoE passwords at rest
- Responsive UI with no Node/Vite build requirement

## Production documentation

- [Canonical Laravel + FreeRADIUS + MikroTik production setup](docs/RADIUS_CUTOVER_SETUP.md)
- [WireGuard + REST infrastructure setup](docs/MIKROTIK_VPS_LARAVEL_FULL_SETUP.md)
- [Legacy local-LAN REST reference](docs/MIKROTIK_LARAVEL_SETUP.md)

The current subscriber path is Laravel → MySQL RADIUS tables → FreeRADIUS → MikroTik PPPoE. RouterOS REST is used for health, live sessions and disconnect actions; normal customer operations do not provision local MikroTik PPP Secrets.

## Local setup

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Open <http://127.0.0.1:8000>. The development seed defaults are:

```text
Email: admin@example.com
Password: change-me-now
```

Change `ADMIN_EMAIL`, `ADMIN_PASSWORD`, and `ISP_API_TOKEN` before seeding a production database. Do not rotate `APP_KEY` after saving router credentials; it is used to encrypt them.

## MikroTik RouterOS 7 preparation

The commands below cover only REST. Production subscriber authentication also requires the central FreeRADIUS procedure in [docs/RADIUS_CUTOVER_SETUP.md](docs/RADIUS_CUTOVER_SETUP.md).

Use a dedicated API account limited to the admin-panel server's IP. In Winbox Terminal, replace the example IP and password:

```routeros
/user group add name=isp-panel policy=read,write,rest-api
/user add name=isp-panel group=isp-panel address=192.168.88.10/32 password="REPLACE_WITH_A_STRONG_PASSWORD"
```

Configure a certificate and enable `www-ssl` for REST API access. Restrict the service to the Laravel server network:

```routeros
/ip service enable www-ssl
/ip service set www-ssl address=192.168.88.10/32 port=443
```

Then sign in to the panel, open **Routers → Add router**, save the connection and click **Test**. For a self-signed development certificate, certificate verification can be switched off temporarily; use a trusted certificate in production.

The panel targets the RouterOS 7 REST endpoints under `/rest`. RouterOS 6 must be upgraded or integrated through the older binary API separately.

## API

Send the token configured in `ISP_API_TOKEN`:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  http://127.0.0.1:8000/api/v1/dashboard
```

Available endpoints:

```text
GET    /api/v1/dashboard
GET    /api/v1/customers
POST   /api/v1/customers
PATCH  /api/v1/customers/{id}
POST   /api/v1/customers/{id}/sync
POST   /api/v1/customers/{id}/toggle
GET    /api/v1/packages
GET    /api/v1/routers
POST   /api/v1/routers/{id}/test
```

API requests are limited to 60 requests per minute per source IP.

## Automatic expiry

Run Laravel's scheduler continuously in production:

```bash
php artisan schedule:work
```

Or add the normal `php artisan schedule:run` cron entry. Manual execution:

```bash
php artisan isp:suspend-expired
```

The application schedules this command daily at `00:00` in `Asia/Kolkata`. The system cron must still invoke `schedule:run` every minute.

## Verification

```bash
php artisan test
php artisan route:list
```
# zostream_isp_thar
