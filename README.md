# SeekaHost CDN for WHMCS (bunny.net)

Resell bunny.net CDN, DNS and storage from WHMCS. Clients get a bunny-style control panel
with your per-GB price; admins see bunny.net cost vs your price and margin.

## Features

| Area | What you get |
|---|---|
| Setup modes | **Nameservers** (we host DNS, existing records copied automatically) or **CNAME** (client keeps their DNS and adds one CNAME), per product |
| Selling modes | Standalone product, **paid product addon** on hosting, or **free CDN included** with chosen hosting products |
| Storage | Optional bunny.net storage zone as origin; clients get FTP/API details and can reset the password |
| SSL | Free certificates issued automatically once the nameservers or CNAME are detected; Force HTTPS |
| Client panel | Status, setup steps, usage chart, DNS editor (A, AAAA, CNAME, MX, TXT, SRV, CAA, CDN), CDN settings (origin, cache times, query strings, country blocking, hotlink protection, IP blocking), purge everything or specific URLs |
| Bandwidth | Monthly limit per product or per **"Bandwidth" configurable option** (packages/upgrades); warning email at X%, at 100% email + admin To-Do + optional WHMCS suspension; automatic unsuspend next month |
| Billing | Monthly invoice per service: bandwidth GB × your price (+ storage GB × storage price), converted to the client's currency, never billed twice, final invoice on cancellation |
| Admin | Margin dashboard, billing history with retry, **import existing bunny.net pull zones**, add/remove hostnames and run actions from the service page |

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
- **Suspend:** the CDN stops serving; DNS keeps working so email is unaffected.
- **Terminate:** invoices unbilled usage, then deletes the pull zone, DNS zone and storage zone.
- Clients never see bunny.net costs. Email texts are editable under Email Templates (Product):
  *SeekaHost CDN Bandwidth Warning* and *SeekaHost CDN Bandwidth Limit Reached*.

Debugging: enable *System Logs > Module Log* to see every bunny.net API call (API key masked).
