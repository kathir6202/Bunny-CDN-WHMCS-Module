# SeekaHost CDN for WHMCS (bunny.net)

Resell bunny.net CDN, DNS and storage from WHMCS. Clients get a bunny-style control panel
with your per-GB price; admins see bunny.net cost vs your price and margin.

## Features

| Area | What you get |
|---|---|
| Setup modes | **Nameservers** (we host DNS, existing records copied automatically) or **CNAME** (client keeps their DNS and adds one CNAME), per product |
| Selling modes | Standalone product, **paid product addon** on hosting, or **free CDN included** with chosen hosting products |
| Storage | Optional bunny.net storage zone as origin with optional replication regions; clients get FTP/API details, a browser file manager (upload, download, delete, folders, copy CDN URL) and can reset the password |
| SSL | Free certificates issued automatically once the nameservers or CNAME are detected; Force HTTPS |
| Client panel | Status, setup steps, usage chart with top countries, response codes (2xx–5xx) and origin traffic/response time, access-log download (last 3 days), DNSSEC with DS record, BIND zone file export/import, DNS editor (A, AAAA, CNAME, MX, TXT, SRV, CAA, CDN), purge everything or specific URLs, CDN settings: origin, Force HTTPS, cache times, query strings, country/IP blocking, hotlink protection, connection/request/speed limits per IP, CORS headers, token authentication (signed URLs, key reset), Origin Shield, stale cache, vary cache by WebP/country/cookies, and custom SSL certificate upload |
| Bandwidth | Monthly limit per product or per **"Bandwidth" configurable option** (packages/upgrades); warning email at X%, at 100% email + admin To-Do + optional WHMCS suspension; automatic unsuspend next month |
| Billing | Monthly invoice per service: bandwidth GB × your price (+ storage GB × storage price), converted to the client's currency, never billed twice, final invoice on cancellation. CDN is **suspended as soon as a usage invoice is past its due date** and restored when it is paid |
| Admin | Margin dashboard (bunny.net USD cost converted to your default currency), billing history with retry, **import existing bunny.net pull zones**, add/remove hostnames and run actions from the service page |

## Requirements

- WHMCS 8.0 or later (uses only documented WHMCS functions, hooks and module APIs)
- PHP 7.4 – 8.4 (checked with PHPCompatibility), with curl
- MySQL / MariaDB, including strict SQL mode
- A bunny.net account API key

## Files

```
modules/servers/seekahostcdn/   provisioning module
modules/addons/seekahostcdn/    admin addon: pricing, billing, alerts, reports, hooks
```

Extract the release zip in the WHMCS root folder; both folders land in place.

## Install / upgrade

1. **Upgrading from the very first version:** delete the old `modules/servers/seekahostcdn/`
   and `modules/addons/seekahostcdn/` folders first (they contained test scripts and logs).
   From 2.x, just overwrite. Existing services and zones are kept.
2. Extract the zip in the WHMCS root.
3. **System Settings > Addon Modules**: activate (or re-activate) *SeekaHost CDN* to create/upgrade
   tables and the two bandwidth email templates. Click Configure and set prices, billing and
   bandwidth-alert options.
4. **System Settings > Servers**: add a server with module *SeekaHost CDN (bunny.net)*, put the
   bunny.net account API key in **Access Hash**, and click Test Connection.
5. Make sure the WHMCS cron runs daily.

## Selling the CDN

- **Standalone product:** product with module *SeekaHost CDN (bunny.net)*. Module Settings:
  origin (blank = the site's current IP), bandwidth limit, tier, **Setup Mode**, **Storage Zone**,
  storage region. Optional custom field **Origin Server** lets the client give their server.
- **Paid addon on hosting:** Product Addon with Module = *SeekaHost CDN (bunny.net)*, applied to
  hosting products. The CDN panel appears on the hosting service page.
- **Free with hosting:** addon setting *Include Free CDN with Products* = hosting product IDs.
  New services get a CDN (origin = hosting server IP); it is suspended/terminated with the hosting.
  Use *Setup > Add included CDN to existing hosting services* for existing customers.
- **Bandwidth packages:** configurable option named **Bandwidth** with values in GB, e.g.
  `100|100 GB`, `1000|1 TB`, `0|Unlimited`. Upgrades apply the new limit immediately.

## How it works

- **Nameserver mode:** copies the domain's DNS (apex, common subdomains, wildcards, bunny.net scan)
  into bunny.net DNS, creates the pull zone and points `@` and `www` at it. The client changes
  nameservers at their registrar.
- **CNAME mode:** creates the pull zone and hostnames only. The client adds a CNAME (ALIAS/ANAME
  for a root domain) to the CDN hostname shown in their panel.
- **Daily cron:** syncs usage and bunny.net cost, issues SSL, sends bandwidth alerts, lifts last
  month's bandwidth suspensions and, from the billing day, invoices the previous month.
- **Overdue usage invoices:** checked on every cron run. The day after the due date the CDN is
  suspended (a CDN product is suspended in WHMCS with reason "Overdue CDN usage invoice #N"; an
  addon or included CDN stops only the CDN, never the hosting). It is restored as soon as the
  invoice is paid or cancelled. If an admin unsuspends manually, that invoice is not enforced again.
  Can be turned off with *Suspend on Overdue Usage Invoice*.
- **Margins:** bunny.net bills in USD; costs are converted with the USD exchange rate in
  System Settings > Currencies (add a USD currency if your default is not USD).
- **Included CDN settings:** a changed *Included CDN Bandwidth* applies to existing included CDNs on
  the next cron run. A changed setup mode only applies to new ones (switching a live site would break it).
- **Suspend:** the CDN stops serving; DNS keeps working so email is unaffected.
- **Terminate:** invoices unbilled usage, then deletes the pull zone, DNS zone and storage zone.
- Clients never see bunny.net costs. Email texts are editable under Email Templates (Product):
  *SeekaHost CDN Bandwidth Warning* and *SeekaHost CDN Bandwidth Limit Reached*.

Continuous checks: GitHub Actions (`.github/workflows/ci.yml`) runs a PHP 7.4–8.4 syntax check, the
PHP compatibility scan, PHPStan (level 5) and the test suite on SQLite and MariaDB for every push.

Testing: `tests/run.sh` runs the end-to-end test suite against a local fake bunny.net API
(PHP 8 with pdo_sqlite, composer and node required). It is not part of the release zip.

Debugging: enable *System Logs > Module Log* to see every bunny.net API call (API key masked).
