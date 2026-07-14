# MikroTik leh Laravel Admin Panel Setup

> Tunah production design chu router tinah customer PPP Secret provision lovin central FreeRADIUS a hmang. VPS, Winbox leh staged cutover atan [RADIUS_CUTOVER_SETUP.md](RADIUS_CUTOVER_SETUP.md) zawm rawh. A hnuaia REST setup chu router health check leh active session disconnect nan a la ngai.

He document hi MikroTik RouterOS 7 router chu ZoStream ISP Laravel admin panel nen REST API hmanga connect dan step-by-step a ni.

## Tested configuration

| Setting | Value |
|---|---|
| MikroTik model | RB5009UG+S+ |
| RouterOS | 7.19.6 stable |
| MikroTik REST IP | `192.168.1.45` |
| Laravel server/Mac IP | `192.168.1.47` |
| REST port | `80` HTTP |
| API username | `zostream-api` |
| API user group | `isp-panel` |

> Password chu document-ah dah loh tur. Mahni strong password hmang rawh.

## 1. RouterOS version leh IP verify

Winbox-a **New Terminal** open la:

```routeros
/system resource print
/ip address print
```

RouterOS version `7.x` a nih a ngai. He setup-a router REST IP chu `192.168.1.45` a ni.

Laravel server/Mac Terminal-ah source IP verify:

```bash
ifconfig | grep "inet 192.168.1"
```

Expected IP:

```text
192.168.1.47
```

## 2. Restricted API group siam

Winbox Terminal-ah:

```routeros
/user group add name=isp-panel policy=read,write,web,api,rest-api
```

Group a awm tawh chuan `add` command-in error a pe ang. Chutiang chuan update rawh:

```routeros
/user group set [find where name="isp-panel"] policy=read,write,web,api,rest-api
```

Verify:

```routeros
/user group print detail where name="isp-panel"
```

## 3. REST API user siam

`REPLACE_WITH_STRONG_PASSWORD` chu mahni password nen thlak rawh:

```routeros
/user add name=zostream-api group=isp-panel address=192.168.1.47/32 password="REPLACE_WITH_STRONG_PASSWORD"
```

User a awm tawh chuan update rawh:

```routeros
/user set [find where name="zostream-api"] disabled=no group=isp-panel address=192.168.1.47/32 password="REPLACE_WITH_STRONG_PASSWORD"
```

Verify:

```routeros
/user print detail where name="zostream-api"
```

Expected values:

```text
name="zostream-api"
group=isp-panel
address=192.168.1.47/32
disabled=no
```

API user chu `full` group-ah dah reng loh tur.

## 4. RouterOS HTTP REST service enable

Winbox Terminal-ah:

```routeros
/ip service set [find where name="www"] disabled=no port=80 address=192.168.1.47/32
```

Verify:

```routeros
/ip service print detail where name="www"
```

Expected values:

```text
name="www"
port=80
address=192.168.1.47/32
```

## 5. Firewall access allow

Laravel server IP `192.168.1.47` atanga TCP port `80` chauh allow rawh:

```routeros
/ip firewall filter add chain=input action=accept protocol=tcp src-address=192.168.1.47/32 dst-port=80 comment="Allow ZoStream REST"
```

Rule thar chu default WAN drop rule hmaah a awm ngei tur a ni. Firewall list print rawh:

```routeros
/ip firewall filter print stats without-paging
```

`Allow ZoStream REST` rule chu `defconf: drop all not coming from LAN` rule hnuah a awm chuan, current default list-ah top static position `1`-ah move rawh:

```routeros
/ip firewall filter move [find where comment="Allow ZoStream REST"] 1
```

Print leh la, allow rule chu WAN drop rule hmaah a awm tih verify rawh:

```routeros
/ip firewall filter print stats without-paging
```

> Firewall rule chu Laravel server IP exact chauh a allow. Port `80` chu internet zawng zawng tan open loh tur.

## 6. Mac atangin REST API test

Mac Terminal-ah:

```bash
curl --basic --user 'zostream-api:REPLACE_WITH_STRONG_PASSWORD' \
  http://192.168.1.45/rest/system/resource
```

Connection fel chuan RouterOS JSON data a chhuak ang:

```json
{
  "board-name": "RB5009UG+S+",
  "platform": "MikroTik",
  "version": "7.19.6 (stable)"
}
```

Error awm theite:

- `Timeout`: firewall rule order, router IP, port, source IP check rawh.
- `401 Unauthorized`: username, password, user group policies leh allowed address check rawh.
- Browser-ah `http://192.168.1.45` login page a lang chuan network leh `www` service chu reachable a ni.

## 7. Laravel project initial setup

Project first setup chauh atan:

```bash
cd "/Volumes/buannel/Zo Stream ISP(thar ber)"
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

Existing configured workspace-ah heng initial commands hi run nawn a ngai lo.

## 8. Laravel server run

Admin panel open turin:

```bash
cd "/Volumes/buannel/Zo Stream ISP(thar ber)"
php artisan serve
```

Terminal chu close loh tur. Browser-ah open rawh:

```text
http://127.0.0.1:8000
```

## 9. Laravel admin login

Development seed login:

```text
Email: admin@example.com
Password: change-me-now
```

Production hmain default admin password thlak ngei tur.

## 10. Laravel panel-ah MikroTik add

Login hnuah **Routers → Add router** ah kal la:

```text
Display name: Main MikroTik
Router IP / hostname: 192.168.1.45
REST API port: 80
API username: zostream-api
API password: REPLACE_WITH_STRONG_PASSWORD
Use HTTPS: OFF
Verify TLS certificate: OFF
Router is active: ON
```

**Save router** click la, router list-a **Test** click rawh.

Connection fel chuan message:

```text
Connected to RB5009UG+S+ (RouterOS 7.19.6).
```

## 11. Daily admin login flow

Computer restart hnuah admin panel login turin:

```bash
cd "/Volumes/buannel/Zo Stream ISP(thar ber)"
php artisan serve
```

Browser-ah:

```text
http://127.0.0.1:8000
```

Admin email/password hmangin login rawh. MikroTik connection configuration chu database-ah encrypted-in a awm tawh avangin router setup run nawn a ngai lo.

## Security note

He tested setup hi local network-a HTTP connection a ni. Production deployment-ah HTTPS certificate leh `www-ssl` port `443` hman tur. `APP_KEY` chu router credentials encrypt nan hman a nih avangin production data save hnuah thlak loh tur.
