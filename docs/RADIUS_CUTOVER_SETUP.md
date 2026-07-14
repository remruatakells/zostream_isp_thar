# ZoStream ISP: FreeRADIUS + MikroTik cutover

This design uses one Laravel/MySQL database as the subscriber source of truth. FreeRADIUS reads subscriber credentials, status and speed from that database. Every MikroTik connects to the same VPS over WireGuard.

Laravel writes:

- `radcheck`: `Cleartext-Password` for an active account, or `Auth-Type := Reject` for suspended/expired accounts.
- `radreply`: `Mikrotik-Rate-Limit` from the selected package and `Acct-Interim-Interval = 300`.
- `nas`: one RADIUS client entry for each enabled router.
- `radacct`: FreeRADIUS accounting sessions and traffic totals.
- `radpostauth`: authentication results.

REST remains enabled over WireGuard for router health checks and immediate disconnection of a suspended active PPP session. Laravel no longer creates or updates customer entries in MikroTik PPP Secrets.

## 1. Back up before changing authentication

Back up the application database and export the MikroTik configuration. Do not remove existing PPP Secrets yet. Complete one test customer from end to end first.

## 2. Run the Laravel migration on the VPS

From the deployed Laravel directory:

```bash
cd /var/www/isptest.zostream.in
php artisan migrate --force
php artisan optimize:clear
```

The migration creates the standard SQL tables used by this application and adds RADIUS settings to each router.

## 3. Install FreeRADIUS with MySQL support

```bash
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils
```

Laravel and FreeRADIUS should use the same MySQL/MariaDB database. Create a separate database login for FreeRADIUS with only the permissions it needs. Replace the placeholders below:

```sql
CREATE USER 'freeradius'@'localhost' IDENTIFIED BY 'LONG_RANDOM_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON YOUR_LARAVEL_DATABASE.radcheck TO 'freeradius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON YOUR_LARAVEL_DATABASE.radreply TO 'freeradius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON YOUR_LARAVEL_DATABASE.radacct TO 'freeradius'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON YOUR_LARAVEL_DATABASE.radpostauth TO 'freeradius'@'localhost';
GRANT SELECT ON YOUR_LARAVEL_DATABASE.nas TO 'freeradius'@'localhost';
FLUSH PRIVILEGES;
```

## 4. Configure the FreeRADIUS SQL module

Edit `/etc/freeradius/3.0/mods-available/sql`. On distributions using another FreeRADIUS version, use its matching configuration directory.

Set the important values:

```text
dialect = "mysql"
driver = "rlm_sql_${dialect}"
server = "localhost"
port = 3306
login = "freeradius"
password = "LONG_RANDOM_DB_PASSWORD"
radius_db = "YOUR_LARAVEL_DATABASE"
read_clients = yes
client_table = "nas"
```

Enable the module:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
```

If that link already exists, leave it as it is. In both `/etc/freeradius/3.0/sites-enabled/default` and `inner-tunnel`, ensure `sql` is enabled in `authorize`. In `default`, also enable `sql` in `accounting`, `session` and `post-auth`.

Check the configuration before restarting:

```bash
sudo freeradius -XC
sudo systemctl enable --now freeradius
sudo systemctl restart freeradius
sudo systemctl status freeradius --no-pager
```

## 5. Restrict RADIUS to WireGuard

RADIUS must not be exposed to the public internet. Allow authentication and accounting only on the VPS WireGuard interface:

```bash
sudo ufw allow in on wg0 to any port 1812 proto udp
sudo ufw allow in on wg0 to any port 1813 proto udp
sudo ufw status
```

The VPS WireGuard IP in this installation is `10.77.0.1`. Each MikroTik needs a unique tunnel IP such as `10.77.0.2`, `10.77.0.3`, and so on.

## 6. Register each router in the admin panel

Open **Routers → Edit** in the Laravel admin panel:

1. Set **Router IP / hostname** to the router's WireGuard IP, for example `10.77.0.2`.
2. Enter a unique random **RADIUS shared secret** of at least 16 characters.
3. Turn on **Enable RADIUS for this router**.
4. Save.

The admin panel writes that router to the `nas` table. Use a different shared secret for every router.

After deploying the code for the first time, backfill all router and customer RADIUS rows:

```bash
php artisan isp:radius-sync
sudo systemctl restart freeradius
```

## 7. Configure MikroTik in Winbox without Terminal

On each MikroTik:

### RADIUS entry

Open **RADIUS → New** and set:

- Service: `ppp`
- Address: `10.77.0.1`
- Secret: the exact shared secret saved for this router in the admin panel
- Authentication Port: `1812`
- Accounting Port: `1813`
- Timeout: `1000 ms` to `3000 ms`
- Src. Address: this router's WireGuard IP, for example `10.77.0.2`

### PPP AAA

Open **PPP → AAA** and enable:

- Use RADIUS: Yes
- Accounting: Yes
- Interim Update: `00:05:00`

Choose a default PPP profile that already has the correct local address, remote IP pool, DNS and other network settings. RADIUS supplies the subscriber speed through `Mikrotik-Rate-Limit`; the default profile still supplies settings not returned by RADIUS.

`RADIUS → Incoming` is not required for this application because immediate session disconnection uses the existing REST connection.

## 8. Test before removing PPP Secrets

1. Create a new test customer in the admin panel with a username that does not exist in MikroTik PPP Secrets.
2. Select a package and click **Sync**.
3. Confirm the row exists in `radcheck` and the speed exists in `radreply`.
4. Log in from the test customer's PPPoE router/CPE.
5. Confirm the session appears under **PPP → Active Connections**.
6. Suspend the customer in the admin panel. The current session should disconnect and the next login should be rejected.
7. Activate or renew the customer and verify login works again.

For troubleshooting, temporarily run FreeRADIUS in debug mode during a controlled maintenance window:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

Press `Ctrl+C` after testing, then start the service again:

```bash
sudo systemctl start freeradius
```

## 9. Staged removal of legacy PPP Secrets

RouterOS checks a matching local PPP Secret before asking RADIUS. Therefore an old local secret can bypass a suspended RADIUS account.

After the RADIUS test succeeds:

1. Export/backup the MikroTik configuration.
2. In Winbox open **PPP → Secrets**.
3. Remove only migrated customer PPPoE secrets in small batches. Do not remove VPN, infrastructure or emergency accounts.
4. Verify several active, suspended and expired customers after each batch.
5. Repeat on each router.

Do not remove all local secrets before the test account works. The admin database contains the imported PPPoE passwords, so the RADIUS rows can be rebuilt with `php artisan isp:radius-sync`.

## 10. Multi-router rules

- Every router has a unique WireGuard IP and unique RADIUS shared secret.
- PPPoE usernames must be globally unique across all routers.
- All routers point to the same VPS RADIUS address `10.77.0.1`.
- Package speed is centrally controlled by `packages.rate_limit`.
- Customer status, expiry, password and speed changes take effect through RADIUS Sync.
- The hourly `isp:suspend-expired` task changes expired customers to `Reject` and disconnects active sessions through REST.

## Security note

FreeRADIUS commonly needs the recoverable PPP password for PAP/CHAP/MSCHAP processing. The application therefore decrypts the Laravel customer password and writes it as `Cleartext-Password` in `radcheck`. Protect MySQL, backups and administrator access carefully; never expose RADIUS tables through the public API. RADIUS UDP and RouterOS REST must remain private to WireGuard.
