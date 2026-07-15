# ZoStream ISP production setup: Laravel + FreeRADIUS + MikroTik

This is the canonical production guide for the current ZoStream ISP application.
It replaces the older design that created one local MikroTik PPP Secret for every
subscriber.

## 1. Current architecture

```text
Admin panel / API
        |
        v
Laravel application
        |
        v
MySQL database: zostream_isp
  - customers, packages, routers, payments
  - radcheck, radreply, nas
  - radacct, radpostauth
  - radusergroup, radgroupcheck, radgroupreply
        ^
        |
FreeRADIUS on the same VPS
        ^
        | UDP 1812/1813 over WireGuard
        |
MikroTik 10.77.0.2, 10.77.0.3, ...
        ^
        | PPPoE access network / OLT / VLAN
        |
Customer ONU/ONT/CPE
```

There is one physical MySQL database. Laravel owns the subscriber and billing
data, while FreeRADIUS reads and writes the standard RADIUS tables in that same
database. MikroTik never connects directly to MySQL.

RouterOS REST remains enabled only over WireGuard for router health, live PPP
session discovery and immediate disconnection after suspend/delete. Subscriber
authentication is handled by FreeRADIUS.

## 2. What the application synchronizes

Laravel automatically maintains these rows:

| Table | Purpose |
|---|---|
| `radcheck` | `Cleartext-Password := password` for active users, or `Auth-Type := Reject` for suspended/expired users |
| `radreply` | `Mikrotik-Rate-Limit` from the selected package and `Acct-Interim-Interval` |
| `nas` | One RADIUS client definition per enabled router |
| `radacct` | Session start/update/stop records written by FreeRADIUS |
| `radpostauth` | `Access-Accept` and `Access-Reject` history |
| `radusergroup`, `radgroupcheck`, `radgroupreply` | Standard FreeRADIUS group-query tables; they may remain empty in the current direct-user design |

Creating, editing, importing, suspending, renewing or deleting a customer updates
the RADIUS SQL rows automatically. Package speed changes update every assigned
customer's `Mikrotik-Rate-Limit` automatically. The customer **Sync** button and
`isp:radius-sync` command are recovery/backfill tools, not part of normal daily
operation.

`Synced` in the admin panel means the Laravel-to-RADIUS database write completed.
It does not mean that the subscriber is currently connected. A subscriber appears
under MikroTik **PPP → Active Connections** only after its CPE performs a successful
PPPoE login.

## 3. Values used in this installation

| Item | Value/example |
|---|---|
| VPS public IP | `153.92.223.7` |
| VPS WireGuard IP | `10.77.0.1/24` |
| Router 1 WireGuard IP | `10.77.0.2/24` |
| Router 2 / Ngopa WireGuard IP | `10.77.0.3/24` |
| WireGuard public endpoint | `153.92.223.7:51820/udp` |
| RADIUS authentication | `10.77.0.1:1812/udp` |
| RADIUS accounting | `10.77.0.1:1813/udp` |
| RouterOS REST | `http://ROUTER_WG_IP:80/rest/...` over WireGuard only |
| Application database | `zostream_isp` |

Use a unique WireGuard tunnel IP, key pair, REST password and RADIUS shared secret
for every router.

## 4. Back up before changing authentication

Do all of the following before a cutover:

1. Back up MySQL.
2. Export the MikroTik configuration.
3. Back up `/etc/wireguard/wg0.conf`, the Laravel `.env` and `APP_KEY` securely.
4. Keep legacy PPP Secrets until one active, one suspended and one expired test
   account have passed end-to-end verification.
5. Prepare a rollback window.

Example database backup:

```bash
sudo mysqldump --no-defaults -uroot --single-transaction zostream_isp \
  > /root/zostream_isp-before-radius.sql
sudo chmod 600 /root/zostream_isp-before-radius.sql
```

Test restoring backups on a separate database before relying on them.

## 5. Deploy and migrate Laravel

From the deployed application directory:

```bash
cd /var/www/isptest.zostream.in
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

The migrations must create all of these tables:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e "SHOW TABLES LIKE 'rad%';"
```

Required results include:

```text
radcheck
radreply
radacct
radpostauth
radusergroup
radgroupcheck
radgroupreply
```

Also verify `nas`:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT id,nasname,shortname,type,LENGTH(secret) AS secret_length FROM nas;"
```

## 6. Install FreeRADIUS MySQL support

```bash
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils
```

Confirm the service and SQL module package:

```bash
freeradius -v
dpkg -l | grep -E 'freeradius|freeradius-mysql'
```

Create a dedicated MySQL login for FreeRADIUS. The database is shared; the MySQL
login may still be separate and restricted.

```sql
CREATE USER 'freeradius'@'localhost' IDENTIFIED BY 'LONG_RANDOM_DB_PASSWORD';
GRANT SELECT ON zostream_isp.radcheck TO 'freeradius'@'localhost';
GRANT SELECT ON zostream_isp.radreply TO 'freeradius'@'localhost';
GRANT SELECT ON zostream_isp.radusergroup TO 'freeradius'@'localhost';
GRANT SELECT ON zostream_isp.radgroupcheck TO 'freeradius'@'localhost';
GRANT SELECT ON zostream_isp.radgroupreply TO 'freeradius'@'localhost';
GRANT SELECT ON zostream_isp.nas TO 'freeradius'@'localhost';
GRANT SELECT, INSERT, UPDATE ON zostream_isp.radacct TO 'freeradius'@'localhost';
GRANT SELECT, INSERT ON zostream_isp.radpostauth TO 'freeradius'@'localhost';
FLUSH PRIVILEGES;
```

Do not paste the database password into chat, screenshots, shell history or source
control.

## 7. Enable and configure the FreeRADIUS SQL module

Check that the module file exists:

```bash
sudo test -f /etc/freeradius/3.0/mods-available/sql && echo "SQL module present"
```

Enable it:

```bash
sudo ln -s ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
```

If the link already exists, leave it unchanged. Edit the enabled module:

```bash
sudo nano /etc/freeradius/3.0/mods-enabled/sql
```

Set:

```text
dialect = "mysql"
driver = "rlm_sql_${dialect}"
server = "127.0.0.1"
port = 3306
login = "freeradius"
password = "LONG_RANDOM_DB_PASSWORD"
database = "zostream_isp"
read_clients = yes
client_table = "nas"
```

Some packaged example configurations contain placeholder TLS paths such as:

```text
/etc/ssl/certs/my_ca.crt
```

When MySQL is local on `127.0.0.1` and database TLS is not configured, comment the
entire example `tls { ... }` block, including its opening and closing braces. Do
not comment only `ca_file`, because the next placeholder certificate will fail.

If database TLS is required, replace every placeholder with real CA/client
certificate files instead of disabling the block.

## 8. Enable SQL in the FreeRADIUS virtual server

Edit:

```bash
sudo nano /etc/freeradius/3.0/sites-enabled/default
```

Ensure the following module calls exist once in their respective sections:

```text
authorize {
    ...
    sql
    ...
    pap
}

authenticate {
    Auth-Type PAP {
        pap
    }
}

accounting {
    ...
    sql
}

post-auth {
    ...
    sql
}
```

`sql` must occur before `pap` in `authorize`. A leading `-sql` still runs the
module but treats a module error as optional. The central authentication path
should use `sql` in `authorize` so SQL failures are visible. Do not add both
`sql` and `-sql` to the same section.

PPP PAP/CHAP/MSCHAP authentication uses the `default` virtual server. SQL in
`inner-tunnel` is only required for protocols that actually use that virtual
server; it is not required for ordinary PPPoE PAP.

Validate and start:

```bash
sudo freeradius -CX
sudo systemctl enable --now freeradius
sudo systemctl restart freeradius
sudo systemctl status freeradius --no-pager
```

Expected validation result:

```text
Configuration appears to be OK
```

## 9. Restrict RADIUS to WireGuard

RADIUS must not be reachable from the public internet. Allow only the WireGuard
router subnet:

```bash
sudo ufw allow in on wg0 from 10.77.0.0/24 to any port 1812 proto udp
sudo ufw allow in on wg0 from 10.77.0.0/24 to any port 1813 proto udp
sudo ufw reload
sudo ufw status
```

Verify listeners:

```bash
sudo ss -lunp | grep -E ':1812|:1813'
```

Keep MySQL bound to localhost/private interfaces. Do not open TCP 3306 publicly.

## 10. Register each router in the admin panel

Open **Routers → Edit**:

1. Set the router host to its WireGuard IP, for example `10.77.0.3` for Ngopa.
2. Enter a unique random RADIUS shared secret of 16–60 characters.
3. Enable RADIUS for that router.
4. Save.

The application writes the router into `nas`. Confirm without displaying the
secret:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT id,nasname,shortname,type,LENGTH(secret) AS secret_length FROM nas;"
```

FreeRADIUS `read_clients = yes` reads NAS clients during module initialization.
After adding a new router or changing its host/shared secret, restart FreeRADIUS:

```bash
sudo systemctl restart freeradius
```

Initial backfill/recovery:

```bash
cd /var/www/isptest.zostream.in
php artisan isp:radius-sync
sudo systemctl restart freeradius
```

## 11. Configure MikroTik RADIUS in Winbox

For each MikroTik, open **RADIUS → New**:

| Field | Value |
|---|---|
| Enabled | Yes |
| Service | `ppp` |
| Address | `10.77.0.1` |
| Authentication Port | `1812` |
| Accounting Port | `1813` |
| Secret | Exact secret saved for this router in the admin panel |
| Src. Address | This router's WireGuard IP, for example `10.77.0.3` |
| Timeout | `1000ms`–`3000ms` |

The `Src. Address` and `nas.nasname` must match. Packet capture may reveal the
actual source address:

```bash
sudo tcpdump -ni any udp port 1812
```

Example:

```text
wg0 In IP 10.77.0.3.xxxxx > 10.77.0.1.1812: RADIUS, Access-Request
```

Then open **PPP → Configuration → PPP Authentication** (called **PPP → AAA** in
some Winbox versions):

- Use RADIUS: Yes
- Accounting: Yes
- Interim Update: `00:05:00`

Enable PAP, CHAP, MSCHAP1 and MSCHAP2 on the PPPoE server unless there is an
intentional policy to restrict methods.

## 12. PPP default profile versus admin package

These settings have different jobs:

```text
PPPoE Server default profile (for example profile1)
  -> local address / PPP gateway
  -> remote address pool
  -> DNS
  -> common network settings

Admin package (ROOKIE, ELITE, PRO, ...)
  -> radreply.Mikrotik-Rate-Limit
  -> subscriber-specific bandwidth
```

Keep a network-ready default PPP profile on the PPPoE server. Example:

```text
Name: profile1
Local Address: router PPP gateway address
Remote Address: pool1
DNS Server: 8.8.8.8, 8.8.4.4
Change TCP MSS: yes/default
```

Do not set `zostream_rookie` as the PPPoE server default profile: that would make
every subscriber inherit the same default package. The current application sends
the per-user speed directly as `Mikrotik-Rate-Limit`.

The package profiles synchronized to **PPP → Profiles** are retained for REST/manual
compatibility, but are not required for RADIUS speed enforcement. If future
package-specific bridge, address-list or filter settings are required, add an
explicit `Mikrotik-Group` design; it is not part of the current implementation.

Package updates apply to the next authentication. Disconnect/reconnect an existing
session to apply a newly changed rate immediately.

## 13. OLT, VLAN and customer ONU/ONT/CPE

RADIUS is authentication; VLAN is Layer-2 transport. They are not directly bound
to each other.

Typical path:

```text
Customer F670L ONU/ONT
  -> PPPoE username/password
  -> customer/service VLAN
OLT service port
  -> tagged or translated uplink
MikroTik physical/bridge/VLAN interface
  -> PPPoE server
  -> FreeRADIUS
```

F670L routed PPPoE example:

```text
Type: Routing
Service List: INTERNET
PPP Transfer Type: PPPoE
Authentication: Auto
Connection Mode: Always On
NAT: On
VLAN: On only when the ONU/OLT design requires it
VLAN ID: 1337 when assigned by the OLT design
802.1p: 0
```

`VLAN ID 1337` is only a tag number. Using the same number on two devices does not
create a tunnel. The OLT must map that ONU/service port to the MikroTik-facing
uplink.

Use **Interfaces → physical port → Torch** while reconnecting the CPE:

- EtherType `0x8863`: PPPoE discovery.
- EtherType `0x8864`: established PPPoE session traffic.
- VLAN ID blank: traffic reaches MikroTik untagged.
- VLAN ID `1337`: bind PPPoE to the correct VLAN interface/bridge VLAN.
- Service tag `0x88a8`: use the RouterOS service-tag/QinQ design intentionally.

If OLT translation removes VLAN 1337 before the MikroTik uplink, the PPPoE server
must bind to the resulting untagged physical/bridge interface, not a VLAN interface.
Do not run competing PPPoE servers on the same broadcast domain.

## 14. Local PPP Secrets and RADIUS priority

RouterOS checks a matching local PPP Secret before querying RADIUS. A legacy local
secret can therefore bypass central suspend/expiry policy.

After one end-to-end RADIUS test succeeds:

1. Export/backup MikroTik configuration.
2. Open **PPP → Secrets**.
3. Disable/remove only migrated subscriber secrets in small batches.
4. Keep infrastructure, emergency and VPN accounts that are intentionally local.
5. Test active, suspended and expired users after every batch.

Never delete all local secrets before a rollback-capable test account works.

## 15. End-to-end test procedure

Use a username that does not exist in MikroTik PPP Secrets.

1. Create `testuser1` in the admin panel.
2. Set status Active, a future expiry date and a package such as ROOKIE.
3. Save; manual Sync is not required.
4. Confirm database rows:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT * FROM radcheck WHERE username='testuser1'; \
   SELECT * FROM radreply WHERE username='testuser1';"
```

Expected active rows:

```text
Cleartext-Password := password
Mikrotik-Rate-Limit := 30M/30M
Acct-Interim-Interval := 300
```

5. Configure the CPE with the exact case-sensitive username and password.
6. Reset MikroTik RADIUS Status and reconnect once.
7. Expect Requests and Accepts to increase, with Timeouts and Bad Replies at zero.
8. Confirm **PPP → Active Connections** contains the username.
9. Confirm the CPE receives an address from the PPP pool and can reach the internet.
10. Suspend the user: the current session should disconnect and the next attempt
    should become `Access-Reject`.
11. Activate/renew: the next login should become `Access-Accept`.
12. Change the package and confirm `radreply.Mikrotik-Rate-Limit` changes. Reconnect
    to apply the new rate to an existing session.

## 16. Accounting behavior

`Acct-Interim-Interval := 300` means MikroTik sends an accounting update every
five minutes while a session remains connected:

```text
connect    -> Accounting-Start
every 5m   -> Accounting-Interim-Update
disconnect -> Accounting-Stop
```

This updates session time, framed IP and traffic counters in `radacct`. It does not
control speed or expiry. Normal Start and Stop packets are immediate; an abrupt
disconnect may remain stale until the interim timeout/cleanup logic detects it.

For a smaller deployment, `60` seconds is a reasonable more-responsive interval.
Changing the application value requires syncing/re-authenticating subscribers.
Avoid very small intervals that create unnecessary router, RADIUS and MySQL load.

Active-customer counts in the current dashboard are also obtained from live
MikroTik REST data, so they are not solely dependent on `radacct` timing.

Useful accounting query:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT username,nasipaddress,framedipaddress,acctstarttime,acctupdatetime,acctstoptime \
   FROM radacct ORDER BY radacctid DESC LIMIT 20;"
```

## 17. Troubleshooting decision tree

### RADIUS Status: Requests = 0

MikroTik is not attempting RADIUS authentication.

Check:

- CPE is actually attempting PPPoE.
- PPPoE server is enabled on the interface where EtherType `0x8863` arrives.
- **Use RADIUS** is enabled.
- No matching local PPP Secret intercepts the username.
- Service name and authentication methods match.
- OLT/VLAN/bridge mapping is correct.

### Requests increase, Timeouts increase, Accepts/Rejects remain zero

MikroTik sends requests but receives no FreeRADIUS reply.

Check packet arrival:

```bash
sudo tcpdump -ni any udp port 1812
```

If packets arrive, confirm:

- Source IP exists in `nas`.
- Shared secret matches exactly.
- `read_clients = yes`.
- SQL module is enabled.
- FreeRADIUS was restarted after NAS creation/change.
- UFW allows 1812/1813 over `wg0`.

### Access-Reject with correct password

Inspect:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT * FROM radcheck WHERE username='testuser1'; \
   SELECT * FROM radpostauth WHERE username='testuser1' ORDER BY id DESC LIMIT 10;"
```

Expected active check item:

```text
Cleartext-Password := password
```

`Auth-Type := Reject` means the account is suspended/expired.

If debug shows:

```text
Table 'zostream_isp.radusergroup' doesn't exist
[sql] = fail
```

deploy the latest migration and run:

```bash
php artisan migrate --force
```

The group tables may be empty, but the default FreeRADIUS group query expects them
to exist.

### Access-Accept but no Active Connection

Authentication succeeded but PPP negotiation/session creation failed. Check:

- PPP profile local address.
- Remote address pool exists and has free addresses.
- PPP logs for `no free addresses`, `peer disconnected` or negotiation errors.
- CPE reconnect behavior and authentication methods.

### Active Connection exists but no internet

Check:

- Customer received an IP from the expected pool.
- Default route is active.
- NAT/masquerade exists when using private customer addresses.
- Routed public pools are routed correctly when NAT is not used.
- DNS is supplied by the PPP profile.

Ping an IP first, then test DNS.

## 18. Focused FreeRADIUS debugging

Normal service log:

```bash
sudo journalctl -fu freeradius
```

Full request debug requires stopping the service because the running process owns
UDP port 1812:

```bash
sudo systemctl stop freeradius
sudo freeradius -X > /tmp/freeradius-debug.log 2>&1
```

Reconnect the test CPE once, wait a few seconds, then press `Ctrl+C` and restart:

```bash
sudo systemctl start freeradius
```

Find the request number:

```bash
grep 'User-Name = "testuser1"' /tmp/freeradius-debug.log | tail -1
```

If the result begins with `(118)`, extract that request:

```bash
grep '^(118)' /tmp/freeradius-debug.log
```

`suffix: No '@'` is normal for usernames without a realm. It is not an error.

## 19. Package-change verification

Before changing a test customer's package:

```bash
sudo mysql --no-defaults -uroot zostream_isp -e \
  "SELECT username,attribute,op,value FROM radreply \
   WHERE username='testuser1' ORDER BY attribute;"
```

Change the package in the admin panel and run the same query. A verified example:

```text
ROOKIE:  30M/30M
VETERAN: 250M/250M
```

The database update is immediate. Reconnect an existing PPP session to apply the
new limit on MikroTik.

## 20. Multi-router rules

- Every router has a unique WireGuard `/32` peer address.
- Every router has a unique WireGuard key pair.
- Every router has a unique REST password and RADIUS shared secret.
- MikroTik RADIUS `Src. Address` must equal that router's `nas.nasname`.
- PPPoE usernames are globally unique across all routers.
- All routers authenticate against `10.77.0.1` over WireGuard.
- Customers remain assigned to a router for health/session/disconnect operations.
- Packages remain centrally shared and produce per-user rate-limit rows.
- Restart FreeRADIUS after adding/changing a NAS row when using `read_clients`.

## 21. Expiry automation

The application schedules `isp:suspend-expired` daily at midnight in
`Asia/Kolkata`. The system cron must invoke Laravel's scheduler every minute:

```cron
* * * * * cd /var/www/isptest.zostream.in && php artisan schedule:run >> /dev/null 2>&1
```

Verify:

```bash
cd /var/www/isptest.zostream.in
php artisan schedule:list
sudo crontab -l
php artisan isp:suspend-expired
```

The command changes expired active customers to suspended/Reject and attempts to
disconnect their current PPP session through the private REST connection.

## 22. Production checklist

Application:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
```

Deploy:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan test --compact
```

Required operational checks:

- Public Laravel admin uses HTTPS.
- Admin password and API token are strong and unique.
- Login rate limiting is enabled before broad public exposure.
- WireGuard, DB, RADIUS, REST and admin credentials exposed during setup are
  rotated before production.
- Do not rotate `APP_KEY` without a planned re-encryption migration; it encrypts
  saved customer and router passwords.
- MySQL is not public.
- RADIUS 1812/1813 and RouterOS REST are private to WireGuard.
- FreeRADIUS, MySQL, Nginx and PHP-FPM are monitored.
- Database and configuration backups run automatically and restore tests pass.
- Disk-space, authentication-failure and service-down alerts exist.
- One active, one suspended, one expired and one package-change test pass.
- Start with a small pilot group before removing all legacy secrets.

FreeRADIUS needs recoverable subscriber credentials for PAP/CHAP/MSCHAP. Laravel
stores customer passwords encrypted, but `radcheck.Cleartext-Password` is readable
by the restricted FreeRADIUS database account. Protect MySQL, backups and server
administrator access accordingly.

## 23. Go-live definition

The system is production-ready only after all of these are true:

1. Laravel migrations and tests pass on the deployed release.
2. MikroTik RADIUS shows Accept/Reject replies with zero Timeouts and Bad Replies.
3. A test user receives Access-Accept, appears in Active Connections and reaches
   the internet at the selected package speed.
4. Suspend disconnects/rejects; renew re-enables; expiry automation works.
5. Accounting rows update.
6. Secrets have been rotated, network services are private and backups restore.
7. A staged pilot remains stable before full subscriber cutover.
