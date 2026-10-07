<div id="shcdn">

{* ------------------------------------------------------------------ Header *}
<div class="shcdn-card shcdn-hero">
    <div class="shcdn-hero-main">
        <div class="shcdn-eyebrow">CDN &amp; DNS</div>
        <div class="shcdn-domain">{$shcdnDomain|escape:'html'}</div>
        <div class="shcdn-sub">CDN hostname <span class="shcdn-mono">{$shcdnCdnHost|escape:'html'}</span></div>
    </div>
    <div class="shcdn-hero-side">
        {if $shcdnSuspended}
            <span class="shcdn-pill shcdn-pill-red"><i class="shcdn-dot"></i>Suspended</span>
        {elseif $shcdnDetected}
            <span class="shcdn-pill shcdn-pill-green"><i class="shcdn-dot"></i>CDN live</span>
        {else}
            <span class="shcdn-pill shcdn-pill-amber"><i class="shcdn-dot"></i>Waiting for nameservers</span>
        {/if}
        {if !$shcdnSuspended}
            <div class="shcdn-actions">
                <button type="button" class="shcdn-btn" id="shcdn-ssl">Check SSL</button>
                <button type="button" class="shcdn-btn" id="shcdn-purge">Purge cache</button>
            </div>
        {/if}
    </div>
</div>

{if $shcdnSuspended}
    <div class="shcdn-note shcdn-note-red">This service is not active, so CDN and DNS changes are disabled. Please contact support or pay any outstanding invoice.</div>
{/if}

{* ------------------------------------------------------------- Nameservers *}
<div class="shcdn-card">
    <div class="shcdn-card-head">
        <h3>Nameservers</h3>
        {if $shcdnDetected}<span class="shcdn-pill shcdn-pill-green">Connected</span>{/if}
    </div>
    {if !$shcdnDetected}
        <ol class="shcdn-steps">
            <li><strong>Check your DNS records below.</strong> We copied your existing records (website, email, etc.). Add anything that is missing.</li>
            <li><strong>Change the nameservers at your domain registrar</strong> to the two values below.</li>
            <li><strong>That's it.</strong> The CDN and free SSL activate automatically once the change is detected (usually within a few hours, up to 48 hours).</li>
        </ol>
    {else}
        <p class="shcdn-muted">Your domain is using our nameservers. Keep these at your registrar.</p>
    {/if}
    <div class="shcdn-ns">
        {foreach from=[$shcdnNs1, $shcdnNs2] item=ns key=i}
            <div class="shcdn-ns-item">
                <span class="shcdn-ns-label">Nameserver {$i+1}</span>
                <span class="shcdn-mono shcdn-ns-value">{$ns|escape:'html'}</span>
                <button type="button" class="shcdn-btn shcdn-btn-sm" data-copy="{$ns|escape:'html'}">Copy</button>
            </div>
        {/foreach}
    </div>
    <div class="shcdn-ssl">
        <span class="shcdn-ssl-title">SSL certificates</span>
        {foreach from=$shcdnHostnames item=h}
            <span class="shcdn-pill {if $h.ssl}shcdn-pill-green{else}shcdn-pill-grey{/if}">{$h.host|escape:'html'} &middot; {if $h.ssl}Secure{else}Pending{/if}</span>
        {/foreach}
    </div>
</div>

{* ------------------------------------------------------------------- Usage *}
<div class="shcdn-card">
    <div class="shcdn-card-head">
        <h3>Usage</h3>
        <select id="shcdn-range" class="form-control shcdn-select">
            <option value="month">This month</option>
            <option value="last_month">Last month</option>
            <option value="7d">Last 7 days</option>
            <option value="30d">Last 30 days</option>
        </select>
    </div>
    <div class="shcdn-stats">
        <div class="shcdn-stat"><span class="shcdn-stat-label">Bandwidth</span><span class="shcdn-stat-value" id="shcdn-bw">&ndash;</span></div>
        <div class="shcdn-stat"><span class="shcdn-stat-label">Requests</span><span class="shcdn-stat-value" id="shcdn-req">&ndash;</span></div>
        <div class="shcdn-stat"><span class="shcdn-stat-label">Cache hit rate</span><span class="shcdn-stat-value" id="shcdn-hit">&ndash;</span></div>
        <div class="shcdn-stat shcdn-stat-accent"><span class="shcdn-stat-label">Estimated charge</span><span class="shcdn-stat-value" id="shcdn-charge">&ndash;</span></div>
    </div>
    <div id="shcdn-limit-wrap" class="shcdn-limit" style="display:none">
        <div class="shcdn-limit-text"><span>Monthly bandwidth limit</span><span id="shcdn-limit-text"></span></div>
        <div class="shcdn-bar"><div id="shcdn-limit-bar"></div></div>
    </div>
    <div class="shcdn-chart-wrap">
        <div id="shcdn-chart" class="shcdn-chart"></div>
        <div class="shcdn-chart-axis"><span id="shcdn-axis-from"></span><span id="shcdn-axis-to"></span></div>
    </div>
    <p class="shcdn-muted shcdn-small">
        {$shcdnPricePerGb|escape:'html'} per GB &middot; Limit {$shcdnLimit|escape:'html'} per month &middot;
        Bandwidth is invoiced at the start of each month for the previous month.
    </p>
</div>

{* ------------------------------------------------------------- DNS records *}
<div class="shcdn-card">
    <div class="shcdn-card-head">
        <h3>DNS records</h3>
        <div class="shcdn-actions">
            <input type="search" id="shcdn-filter" class="form-control shcdn-search" placeholder="Search records">
            {if !$shcdnSuspended}
                <button type="button" class="shcdn-btn" id="shcdn-import" title="Look up your domain's public DNS and add missing records">Re-import</button>
                <button type="button" class="shcdn-btn shcdn-btn-primary" id="shcdn-new">+ Add record</button>
            {/if}
        </div>
    </div>
    {if $shcdnLoadError}<div class="shcdn-note shcdn-note-amber">{$shcdnLoadError|escape:'html'}</div>{/if}

    {if !$shcdnSuspended}
    <form id="shcdn-form" class="shcdn-form" autocomplete="off" style="display:none">
        <div class="shcdn-form-title" id="shcdn-form-title">Add record</div>
        <input type="hidden" name="record_id" value="">
        <div class="shcdn-grid">
            <label class="shcdn-field shcdn-w-type"><span>Type</span>
                <select name="type" class="form-control">
                    <option value="7">CDN (serve through CDN)</option>
                    <option value="0" selected>A (IPv4 address)</option>
                    <option value="1">AAAA (IPv6 address)</option>
                    <option value="2">CNAME (alias)</option>
                    <option value="4">MX (mail)</option>
                    <option value="3">TXT (text)</option>
                    <option value="8">SRV (service)</option>
                    <option value="9">CAA (certificate authority)</option>
                </select>
            </label>
            <label class="shcdn-field shcdn-w-name"><span>Name</span>
                <input type="text" name="name" class="form-control" placeholder="@ for root, or e.g. blog" maxlength="253">
            </label>
            <label class="shcdn-field shcdn-w-value shcdn-f-value"><span>Value</span>
                <input type="text" name="value" class="form-control" maxlength="2048">
            </label>
            <label class="shcdn-field shcdn-w-ttl"><span>TTL</span>
                <select name="ttl" class="form-control">
                    <option value="300">5 minutes</option>
                    <option value="3600" selected>1 hour</option>
                    <option value="14400">4 hours</option>
                    <option value="86400">1 day</option>
                </select>
            </label>
            <label class="shcdn-field shcdn-w-sm shcdn-f-priority" style="display:none"><span>Priority</span><input type="number" name="priority" class="form-control" value="10" min="0" max="65535"></label>
            <label class="shcdn-field shcdn-w-sm shcdn-f-srv" style="display:none"><span>Weight</span><input type="number" name="weight" class="form-control" value="0" min="0" max="65535"></label>
            <label class="shcdn-field shcdn-w-sm shcdn-f-srv" style="display:none"><span>Port</span><input type="number" name="port" class="form-control" value="443" min="1" max="65535"></label>
            <label class="shcdn-field shcdn-w-sm shcdn-f-caa" style="display:none"><span>Flags</span><input type="number" name="flags" class="form-control" value="0" min="0" max="255"></label>
            <label class="shcdn-field shcdn-w-sm shcdn-f-caa" style="display:none"><span>Tag</span>
                <select name="tag" class="form-control"><option>issue</option><option>issuewild</option><option>iodef</option></select>
            </label>
        </div>
        <p class="shcdn-hint" id="shcdn-hint"></p>
        <div class="shcdn-actions">
            <button type="submit" class="shcdn-btn shcdn-btn-primary" id="shcdn-save">Add record</button>
            <button type="button" class="shcdn-btn" id="shcdn-cancel">Cancel</button>
        </div>
    </form>
    {/if}

    <div class="shcdn-table-wrap">
        <table class="shcdn-table">
            <thead><tr><th style="width:90px">Type</th><th>Name</th><th>Value</th><th style="width:90px">TTL</th><th style="width:150px"></th></tr></thead>
            <tbody id="shcdn-records"></tbody>
        </table>
    </div>
</div>

<div id="shcdn-toast" class="shcdn-toast" role="status" aria-live="polite"></div>
</div>

{literal}
<style>
#shcdn { --c-border:#e5e7eb; --c-muted:#6b7280; --c-text:#111827; --c-soft:#f9fafb; --c-primary:#2563eb; --c-primary-d:#1d4ed8;
         --c-green:#047857; --c-green-bg:#ecfdf5; --c-amber:#b45309; --c-amber-bg:#fffbeb; --c-red:#b91c1c; --c-red-bg:#fef2f2;
         color:var(--c-text); font-size:14px; line-height:1.5; }
#shcdn *, #shcdn *::before, #shcdn *::after { box-sizing:border-box; }
#shcdn h3 { font-size:16px; font-weight:600; margin:0; }
#shcdn .shcdn-card { background:#fff; border:1px solid var(--c-border); border-radius:12px; padding:20px; margin-bottom:16px; box-shadow:0 1px 2px rgba(16,24,40,.04); }
#shcdn .shcdn-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
#shcdn .shcdn-hero { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
#shcdn .shcdn-eyebrow { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--c-muted); font-weight:600; }
#shcdn .shcdn-domain { font-size:22px; font-weight:700; word-break:break-all; }
#shcdn .shcdn-sub, #shcdn .shcdn-muted { color:var(--c-muted); }
#shcdn .shcdn-small { font-size:12.5px; margin:12px 0 0; }
#shcdn .shcdn-hero-side { display:flex; flex-direction:column; align-items:flex-end; gap:10px; }
#shcdn .shcdn-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
#shcdn .shcdn-mono { font-family:SFMono-Regular,Menlo,Consolas,monospace; font-size:13px; }

#shcdn .shcdn-btn { display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px; border-radius:8px; border:1px solid var(--c-border);
                    background:#fff; color:var(--c-text); font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; transition:background .15s,border-color .15s; }
#shcdn .shcdn-btn:hover { background:var(--c-soft); border-color:#d1d5db; }
#shcdn .shcdn-btn:disabled { opacity:.6; cursor:default; }
#shcdn .shcdn-btn-sm { height:28px; padding:0 10px; font-size:12px; }
#shcdn .shcdn-btn-primary { background:var(--c-primary); border-color:var(--c-primary); color:#fff; }
#shcdn .shcdn-btn-primary:hover { background:var(--c-primary-d); border-color:var(--c-primary-d); }
#shcdn .shcdn-btn-danger { color:var(--c-red); }
#shcdn .shcdn-btn-danger:hover { background:var(--c-red-bg); border-color:#fecaca; }

#shcdn .shcdn-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
#shcdn .shcdn-dot { width:7px; height:7px; border-radius:50%; background:currentColor; display:inline-block; }
#shcdn .shcdn-pill-green { background:var(--c-green-bg); color:var(--c-green); }
#shcdn .shcdn-pill-amber { background:var(--c-amber-bg); color:var(--c-amber); }
#shcdn .shcdn-pill-red { background:var(--c-red-bg); color:var(--c-red); }
#shcdn .shcdn-pill-grey { background:#f3f4f6; color:#4b5563; }

#shcdn .shcdn-note { border-radius:10px; padding:12px 14px; margin-bottom:16px; }
#shcdn .shcdn-note-red { background:var(--c-red-bg); color:var(--c-red); }
#shcdn .shcdn-note-amber { background:var(--c-amber-bg); color:var(--c-amber); }

#shcdn .shcdn-steps { margin:0 0 16px; padding-left:20px; color:#374151; }
#shcdn .shcdn-steps li { margin-bottom:6px; }
#shcdn .shcdn-ns { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
#shcdn .shcdn-ns-item { display:flex; align-items:center; gap:10px; border:1px solid var(--c-border); border-radius:10px; padding:10px 12px; background:var(--c-soft); }
#shcdn .shcdn-ns-label { color:var(--c-muted); font-size:12px; white-space:nowrap; }
#shcdn .shcdn-ns-value { flex:1; font-weight:600; }
#shcdn .shcdn-ssl { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:16px; }
#shcdn .shcdn-ssl-title { font-size:12px; color:var(--c-muted); margin-right:4px; }

#shcdn .shcdn-select { width:auto; height:34px; padding:4px 10px; font-size:13px; }
#shcdn .shcdn-search { width:200px; height:34px; font-size:13px; }
#shcdn .shcdn-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
#shcdn .shcdn-stat { border:1px solid var(--c-border); border-radius:10px; padding:14px; }
#shcdn .shcdn-stat-accent { background:#eff6ff; border-color:#dbeafe; }
#shcdn .shcdn-stat-label { display:block; font-size:12px; color:var(--c-muted); }
#shcdn .shcdn-stat-value { display:block; font-size:22px; font-weight:700; margin-top:2px; }
#shcdn .shcdn-limit { margin-top:16px; }
#shcdn .shcdn-limit-text { display:flex; justify-content:space-between; font-size:12.5px; color:var(--c-muted); margin-bottom:6px; }
#shcdn .shcdn-bar { height:8px; background:#f3f4f6; border-radius:999px; overflow:hidden; }
#shcdn .shcdn-bar > div { height:100%; width:0; background:var(--c-primary); border-radius:999px; transition:width .4s; }
#shcdn .shcdn-chart-wrap { margin-top:18px; }
#shcdn .shcdn-chart { display:flex; align-items:flex-end; gap:3px; height:110px; border-bottom:1px solid var(--c-border); }
#shcdn .shcdn-chart > div { flex:1 1 0; background:#93c5fd; border-radius:4px 4px 0 0; min-height:2px; }
#shcdn .shcdn-chart > div:hover { background:var(--c-primary); }
#shcdn .shcdn-chart > div.shcdn-future { background:#f3f4f6; }
#shcdn .shcdn-chart-empty { align-self:center; color:var(--c-muted); font-size:13px; }
#shcdn .shcdn-chart-axis { display:flex; justify-content:space-between; font-size:11.5px; color:var(--c-muted); margin-top:4px; }

#shcdn .shcdn-form { background:var(--c-soft); border:1px solid var(--c-border); border-radius:10px; padding:16px; margin-bottom:16px; }
#shcdn .shcdn-form-title { font-weight:600; margin-bottom:10px; }
#shcdn .shcdn-grid { display:flex; flex-wrap:wrap; gap:12px; }
#shcdn .shcdn-field { display:flex; flex-direction:column; gap:4px; margin:0; font-weight:normal; }
#shcdn .shcdn-field > span { font-size:12px; font-weight:600; color:#374151; }
#shcdn .shcdn-w-type { flex:0 0 210px; } #shcdn .shcdn-w-name { flex:1 1 160px; } #shcdn .shcdn-w-value { flex:2 1 240px; }
#shcdn .shcdn-w-ttl { flex:0 0 130px; } #shcdn .shcdn-w-sm { flex:0 0 110px; }
#shcdn .shcdn-hint { font-size:12.5px; color:var(--c-muted); margin:8px 0 12px; min-height:1px; }

#shcdn .shcdn-table-wrap { border:1px solid var(--c-border); border-radius:10px; overflow:hidden; }
#shcdn .shcdn-table { width:100%; border-collapse:collapse; margin:0; }
#shcdn .shcdn-table th { background:var(--c-soft); font-size:12px; font-weight:600; color:var(--c-muted); text-transform:uppercase; letter-spacing:.04em; text-align:left; padding:10px 14px; border-bottom:1px solid var(--c-border); }
#shcdn .shcdn-table td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
#shcdn .shcdn-table tr:last-child td { border-bottom:0; }
#shcdn .shcdn-table tbody tr:hover { background:#fafafa; }
#shcdn .shcdn-val { font-family:SFMono-Regular,Menlo,Consolas,monospace; font-size:13px; color:#374151; word-break:break-all; display:block; max-height:3.2em; overflow:hidden; }
#shcdn .shcdn-row-actions { display:flex; gap:6px; justify-content:flex-end; }
#shcdn .shcdn-type { display:inline-block; min-width:54px; text-align:center; padding:2px 8px; border-radius:6px; font-size:11.5px; font-weight:700; background:#f3f4f6; color:#374151; }
#shcdn .shcdn-type-7 { background:var(--c-green-bg); color:var(--c-green); }
#shcdn .shcdn-type-0, #shcdn .shcdn-type-1 { background:#eff6ff; color:#1d4ed8; }
#shcdn .shcdn-type-4 { background:#fdf4ff; color:#a21caf; }
#shcdn .shcdn-empty { text-align:center; color:var(--c-muted); padding:24px !important; }

#shcdn .shcdn-toast { position:fixed; right:20px; bottom:20px; z-index:2000; max-width:360px; padding:12px 16px; border-radius:10px; color:#fff;
                      font-size:14px; box-shadow:0 10px 25px rgba(0,0,0,.15); opacity:0; transform:translateY(10px); transition:all .2s; pointer-events:none; }
#shcdn .shcdn-toast.show { opacity:1; transform:none; }
#shcdn .shcdn-toast.ok { background:#065f46; } #shcdn .shcdn-toast.err { background:#991b1b; }

@media (max-width: 767px) {
    #shcdn .shcdn-card { padding:16px; }
    #shcdn .shcdn-hero-side { align-items:flex-start; }
    #shcdn .shcdn-ns { grid-template-columns:1fr; }
    #shcdn .shcdn-stats { grid-template-columns:1fr 1fr; }
    #shcdn .shcdn-stat-value { font-size:18px; }
    #shcdn .shcdn-search { width:100%; }
    #shcdn .shcdn-w-type, #shcdn .shcdn-w-ttl, #shcdn .shcdn-w-name, #shcdn .shcdn-w-value { flex:1 1 100%; }
    #shcdn .shcdn-table thead { display:none; }
    #shcdn .shcdn-table tr { display:grid; grid-template-columns:auto 1fr; gap:4px 10px; padding:12px 14px; border-bottom:1px solid #f3f4f6; }
    #shcdn .shcdn-table td { padding:0; border:0; }
    #shcdn .shcdn-table td.shcdn-c-value, #shcdn .shcdn-table td.shcdn-c-actions { grid-column:1 / -1; }
    #shcdn .shcdn-table td.shcdn-c-ttl { display:none; }
    #shcdn .shcdn-row-actions { justify-content:flex-start; }
}
</style>
{/literal}

<script>window.SHCDN_CONFIG = {$shcdnConfig nofilter};</script>
{literal}
<script>
(function () {
    var cfg = window.SHCDN_CONFIG;
    var root = document.getElementById('shcdn');
    var form = document.getElementById('shcdn-form');
    var $ = function (id) { return document.getElementById(id); };

    var hints = {
        '0': 'Points the name to an IPv4 address. Not served through the CDN.',
        '1': 'Points the name to an IPv6 address. Not served through the CDN.',
        '2': 'Makes the name an alias of another hostname. Cannot be used on the root (@).',
        '3': 'Text records are used for SPF, DKIM, site verification, etc.',
        '4': 'Mail server for the name. Lower priority is tried first.',
        '7': 'Serves this name through the CDN from your origin server. SSL is issued automatically.',
        '8': 'Service record. Name is usually like _sip._tls.',
        '9': 'Limits which certificate authorities may issue certificates. Keep letsencrypt.org allowed for free SSL.'
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var toastTimer;
    function notify(type, text) {
        var t = $('shcdn-toast');
        t.textContent = text;
        t.className = 'shcdn-toast show ' + (type === 'success' ? 'ok' : 'err');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.className = 'shcdn-toast'; }, 5000);
    }

    function call(action, data) {
        var body = new URLSearchParams(data || {});
        body.append('shcdn_action', action);
        body.append('token', cfg.token);
        return fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) { return r.json(); })
          .catch(function () { return { success: false, error: 'Network error. Please try again.' }; });
    }

    function busy(btn, on, label) {
        if (!btn) return;
        btn.disabled = on;
        if (on) { btn.dataset.label = btn.textContent; btn.textContent = label || 'Working...'; }
        else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
    }

    function ttlText(s) {
        if (s >= 86400) return Math.round(s / 86400) + (Math.round(s / 86400) === 1 ? ' day' : ' days');
        if (s >= 3540) return Math.round(s / 3600) + (Math.round(s / 3600) === 1 ? ' hour' : ' hours');
        if (s >= 60) return Math.round(s / 60) + ' min';
        return s + 's';
    }

    // ------------------------------------------------------------- records
    function displayValue(r) {
        if (r.type === 7) return 'via CDN → ' + r.value;
        if (r.type === 4) return r.priority + '  ' + r.value;
        if (r.type === 8) return r.priority + ' ' + r.weight + ' ' + r.port + '  ' + r.value;
        if (r.type === 9) return r.flags + ' ' + r.tag + ' "' + r.value + '"';
        return r.value;
    }

    function renderRecords(records) {
        if (records) cfg.records = records;
        var q = ($('shcdn-filter').value || '').toLowerCase();
        var list = cfg.records.filter(function (r) {
            return !q || (r.typeName + ' ' + (r.name || '@') + ' ' + r.value).toLowerCase().indexOf(q) >= 0;
        });
        var tbody = $('shcdn-records');
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="shcdn-empty">' + (q ? 'No records match your search.' : 'No DNS records yet.') + '</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(function (r) {
            var host = r.name ? r.name + '.' + cfg.domain : cfg.domain;
            var actions = (cfg.active && r.editable)
                ? '<div class="shcdn-row-actions"><button type="button" class="shcdn-btn shcdn-btn-sm" data-edit="' + r.id + '">Edit</button>' +
                  '<button type="button" class="shcdn-btn shcdn-btn-sm shcdn-btn-danger" data-del="' + r.id + '">Delete</button></div>'
                : '';
            return '<tr><td><span class="shcdn-type shcdn-type-' + r.type + '">' + esc(r.typeName) + '</span></td>' +
                '<td title="' + esc(host) + '"><strong>' + esc(r.name || '@') + '</strong></td>' +
                '<td class="shcdn-c-value"><span class="shcdn-val" title="' + esc(displayValue(r)) + '">' + esc(displayValue(r)) + '</span></td>' +
                '<td class="shcdn-c-ttl">' + esc(ttlText(r.ttl)) + '</td><td class="shcdn-c-actions">' + actions + '</td></tr>';
        }).join('');
    }

    function toggleFields() {
        if (!form) return;
        var t = form.type.value;
        var show = function (sel, on) {
            root.querySelectorAll(sel).forEach(function (el) { el.style.display = on ? '' : 'none'; });
        };
        show('.shcdn-f-priority', t === '4' || t === '8');
        show('.shcdn-f-srv', t === '8');
        show('.shcdn-f-caa', t === '9');
        show('.shcdn-f-value', t !== '7');
        $('shcdn-hint').textContent = hints[t] || '';
        form.value.placeholder = { '0': '192.0.2.10', '1': '2001:db8::1', '2': 'target.example.com', '4': 'mail.example.com',
            '3': 'v=spf1 include:example.com ~all', '8': 'sip.example.com', '9': 'letsencrypt.org' }[t] || '';
    }

    function openForm(title, saveLabel) {
        form.style.display = '';
        $('shcdn-form-title').textContent = title;
        $('shcdn-save').textContent = saveLabel;
        toggleFields();
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeForm() {
        if (!form) return;
        form.reset();
        form.record_id.value = '';
        form.style.display = 'none';
    }

    function editRecord(id) {
        var r = cfg.records.filter(function (x) { return x.id === id; })[0];
        if (!r || !form) return;
        form.reset();
        form.record_id.value = r.id;
        form.type.value = String(r.type);
        form.name.value = r.name || '@';
        form.value.value = r.type === 7 ? '' : r.value;
        form.ttl.value = [300, 3600, 14400, 86400].indexOf(r.ttl) >= 0 ? String(r.ttl) : '3600';
        form.priority.value = r.priority;
        form.weight.value = r.weight;
        form.port.value = r.port || 443;
        form.flags.value = r.flags;
        if (r.tag) form.tag.value = r.tag;
        openForm('Edit ' + r.typeName + ' record', 'Save changes');
    }

    if (form) {
        form.type.addEventListener('change', toggleFields);
        $('shcdn-cancel').addEventListener('click', closeForm);
        $('shcdn-new').addEventListener('click', function () { closeForm(); openForm('Add record', 'Add record'); form.name.focus(); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = $('shcdn-save');
            var data = {};
            new FormData(form).forEach(function (v, k) { data[k] = v; });
            busy(btn, true, 'Saving...');
            call(data.record_id ? 'update' : 'add', data).then(function (res) {
                busy(btn, false);
                if (res.success) {
                    renderRecords(res.records);
                    notify('success', data.record_id ? 'Record updated.' : 'Record added.');
                    closeForm();
                } else {
                    notify('error', res.error || 'Could not save the record.');
                }
            });
        });
    }

    $('shcdn-filter').addEventListener('input', function () { renderRecords(); });

    $('shcdn-records').addEventListener('click', function (e) {
        var edit = e.target.closest('[data-edit]');
        var del = e.target.closest('[data-del]');
        if (edit) editRecord(parseInt(edit.getAttribute('data-edit'), 10));
        if (del) {
            if (!confirm('Delete this DNS record? This takes effect immediately.')) return;
            busy(del, true, '...');
            call('delete', { record_id: del.getAttribute('data-del') }).then(function (res) {
                if (res.success) { renderRecords(res.records); notify('success', 'Record deleted.'); }
                else { busy(del, false); notify('error', res.error || 'Could not delete the record.'); }
            });
        }
    });

    function simpleAction(id, action, confirmText) {
        var btn = $(id);
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (confirmText && !confirm(confirmText)) return;
            busy(btn, true);
            call(action).then(function (res) {
                busy(btn, false);
                if (res.records) renderRecords(res.records);
                notify(res.success ? 'success' : 'error', res.success ? (res.message || 'Done.') : (res.error || 'Request failed.'));
                if (action === 'ssl' && res.success) setTimeout(function () { location.reload(); }, 1500);
            });
        });
    }
    simpleAction('shcdn-purge', 'purge', 'Purge the entire CDN cache? Visitors will get fresh content from your server.');
    simpleAction('shcdn-import', 'import', 'Look up your domain\'s public DNS and add any missing records?');
    simpleAction('shcdn-ssl', 'ssl');

    root.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-copy');
            if (navigator.clipboard) navigator.clipboard.writeText(text);
            b.textContent = 'Copied';
            setTimeout(function () { b.textContent = 'Copy'; }, 1500);
        });
    });

    // --------------------------------------------------------------- stats
    function fmtDate(d) {
        var p = String(d).split('-');
        var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(p[1], 10) - 1];
        return m ? parseInt(p[2], 10) + ' ' + m : d;
    }

    function loadStats() {
        var range = $('shcdn-range').value;
        $('shcdn-chart').style.opacity = '.4';
        call('stats', { range: range }).then(function (s) {
            $('shcdn-chart').style.opacity = '1';
            if (!s.success) {
                $('shcdn-chart').innerHTML = '<span class="shcdn-chart-empty">' + esc(s.error || 'Statistics are not available yet.') + '</span>';
                return;
            }
            $('shcdn-bw').textContent = s.bandwidth;
            $('shcdn-req').textContent = s.requests;
            $('shcdn-hit').textContent = s.cacheHitRate + '%';
            $('shcdn-charge').textContent = s.charge;
            if (s.limitPercent !== null) {
                $('shcdn-limit-wrap').style.display = '';
                $('shcdn-limit-text').textContent = s.limitPercent + '% of ' + s.limit + ' used';
                var bar = $('shcdn-limit-bar');
                bar.style.width = s.limitPercent + '%';
                bar.style.background = s.limitPercent >= 90 ? '#dc2626' : s.limitPercent >= 75 ? '#d97706' : '';
            }
            var max = Math.max.apply(null, s.chart.map(function (p) { return p.bytes || 0; }).concat([1]));
            $('shcdn-chart').innerHTML = s.chart.length ? s.chart.map(function (p) {
                if (p.bytes === null) return '<div class="shcdn-future" style="height:2px"></div>';
                return '<div style="height:' + Math.max(2, Math.round(p.bytes / max * 100)) + '%" title="' + esc(fmtDate(p.date)) + ': ' + (p.bytes / 1e9).toFixed(2) + ' GB"></div>';
            }).join('') : '<span class="shcdn-chart-empty">No traffic in this period yet.</span>';
            $('shcdn-axis-from').textContent = fmtDate(s.from);
            $('shcdn-axis-to').textContent = fmtDate(s.to);
        });
    }
    $('shcdn-range').addEventListener('change', loadStats);

    renderRecords(cfg.records);
    loadStats();
})();
</script>
{/literal}
