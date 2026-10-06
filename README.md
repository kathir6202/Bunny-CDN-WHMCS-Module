# SeekaHost CDN for WHMCS (bunny.net)

Resell bunny.net CDN + DNS from WHMCS. Clients get a bunny-style DNS manager, usage
stats and your per-GB price. Admins see bunny.net cost vs your price and margin.

## Files

```
modules/servers/seekahostcdn/   provisioning module (upload to WHMCS modules/servers/)
modules/addons/seekahostcdn/    admin addon: pricing, billing, reports (upload to modules/addons/)
```

## Install / upgrade

1. **Upgrading from the old version:** delete these from `modules/servers/seekahostcdn/`
   on the server: `ajax_handler.php*`, `test_zone.php`, `test_new_key.php`, every `*.log`,
   `error_log`, `templates/client/overview.tpl-*`, `lib/BunnyCDN.php*`, `lib/SeekahostAPI.php`,
   `lib/bunny_api_debug.log`; and `admin_debug.log`, `seekahostcdn.php-last-modified` from
   `modules/addons/seekahostcdn/`. Existing services and their zones are kept.
2. Upload both folders.
3. **System Settings > Addon Modules**: activate *SeekaHost CDN* (creates/updates tables),
   then Configure: price per GB, enable monthly usage invoices, billing day, minimum invoice.
4. **System Settings > Servers**: add a server, module *SeekaHost CDN (bunny.net)*, put the
   bunny.net account API key in **Access Hash**, click Test Connection.
5. **Products**: create/edit the CDN product, Module Settings tab:
   - *Origin URL*: optional. Blank = use the domain's current A record.
   - *Monthly Bandwidth Limit (GB)*: 0 = unlimited; enforced by bunny.net.
   - *Pull Zone Tier*: Standard or Volume.
   - Optional: add a product custom field named **Origin Server** (shown on order form)
     so clients can give their server IP/URL.
   - Product price can be Free / 0 if you only charge per GB; usage is invoiced separately.
6. Make sure the WHMCS cron runs daily (it syncs usage, issues SSL and creates invoices).

## How it works

- **Create**: reads the domain's current public DNS (apex + common subdomains, wildcard
  aware, merged with bunny.net's own DNS scan), creates the bunny.net DNS zone and pull zone
  (origin = current site IP, Host header forwarded), copies the records, and points `@` and
  `www` to the CDN. Nameservers are saved to the service (`{$service_ns1}`/`{$service_ns2}`
  for the welcome email). On failure everything created at bunny.net is rolled back.
- **Client area**: nameservers + status, SSL status, purge cache, usage with your price,
  full DNS editor (A, AAAA, CNAME, MX, TXT, SRV, CAA, and "CDN" records that serve any
  subdomain through the CDN). All requests are POST with the WHMCS CSRF token and run
  inside WHMCS's own ownership checks.
- **SSL**: free certificates are requested automatically once bunny.net detects the
  nameservers (daily cron, or *Issue / Retry SSL* button).
- **Suspend**: CDN stops serving (pull zone limit set to 1 byte); DNS keeps working so email
  is not affected. Unsuspend restores the product limit.
- **Terminate**: invoices any unbilled usage, then deletes the pull zone and DNS zone.
- **Billing**: on the billing day the cron invoices every service for the previous calendar
  month (`GB x price per GB`, decimal GB like bunny.net), converted to the client's
  currency. Each service is billed at most once per month; missed cron days catch up.
  Billing can also be run manually from the addon's *Billing History* tab.
- **Admin reports**: addon dashboard shows per-zone bandwidth, bunny.net cost, your price
  and margin; billing history shows the same per month. The service page in admin shows the
  live figures. Clients never see bunny.net costs.

Debugging: enable *System Logs > Module Log* to see every bunny.net API call (API key masked).
