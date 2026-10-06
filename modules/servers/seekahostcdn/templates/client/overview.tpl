<div id="shcdn">

{if $shcdnSuspended}
    <div class="alert alert-warning">This CDN service is not active. DNS and CDN changes are disabled.</div>
{/if}

<div id="shcdn-alert"></div>

{* ---------------------------------------------------------------- Nameservers *}
<div class="shcdn-box">
    <h3>Nameservers for {$shcdnDomain|escape:'html'}</h3>
    {if $shcdnDetected}
        <div class="alert alert-success">Your domain is using our nameservers. The CDN is live.</div>
    {else}
        <div class="alert alert-info">
            To activate the CDN, change the nameservers at your domain registrar to the values below.
            We have already copied your existing DNS records. Please check them in the DNS table below before switching.
            Changes can take up to 24-48 hours to propagate.
        </div>
    {/if}
    <table class="table shcdn-table">
        <tr>
            <td><strong>Nameserver 1</strong></td>
            <td><code>{$shcdnNs1|escape:'html'}</code></td>
            <td class="text-right"><button type="button" class="btn btn-default btn-sm" data-copy="{$shcdnNs1|escape:'html'}">Copy</button></td>
        </tr>
        <tr>
            <td><strong>Nameserver 2</strong></td>
            <td><code>{$shcdnNs2|escape:'html'}</code></td>
            <td class="text-right"><button type="button" class="btn btn-default btn-sm" data-copy="{$shcdnNs2|escape:'html'}">Copy</button></td>
        </tr>
    </table>
    <p class="text-muted small">CDN hostname: <code>{$shcdnCdnHost|escape:'html'}</code></p>

    <h4>SSL</h4>
    <ul class="list-unstyled">
        {foreach from=$shcdnHostnames item=h}
            <li>{$h.host|escape:'html'}
                {if $h.ssl}<span class="label label-success">SSL active</span>
                {else}<span class="label label-default">Pending</span>{/if}
            </li>
        {/foreach}
    </ul>
    {if !$shcdnSuspended}
        <button type="button" class="btn btn-default btn-sm" id="shcdn-ssl">Issue / Check SSL</button>
        <button type="button" class="btn btn-warning btn-sm" id="shcdn-purge">Purge CDN Cache</button>
    {/if}
</div>

{* ---------------------------------------------------------------------- Usage *}
<div class="shcdn-box">
    <div class="shcdn-head">
        <h3>Usage</h3>
        <select id="shcdn-range" class="form-control input-sm">
            <option value="month">This month</option>
            <option value="last_month">Last month</option>
            <option value="7d">Last 7 days</option>
            <option value="30d">Last 30 days</option>
        </select>
    </div>
    <div class="shcdn-stats">
        <div><span id="shcdn-bw">--</span><small>Bandwidth</small></div>
        <div><span id="shcdn-req">--</span><small>Requests</small></div>
        <div><span id="shcdn-hit">--</span><small>Cache hit rate</small></div>
        <div><span id="shcdn-charge">--</span><small>Estimated charge</small></div>
    </div>
    <div id="shcdn-limit-wrap" style="display:none">
        <small>Monthly limit: <span id="shcdn-limit-text"></span></small>
        <div class="progress"><div class="progress-bar" id="shcdn-limit-bar" style="width:0%"></div></div>
    </div>
    <div id="shcdn-chart" class="shcdn-chart"></div>
    <p class="text-muted small">
        Price: {$shcdnPricePerGb|escape:'html'} per GB. Monthly limit: {$shcdnLimit|escape:'html'}.
        Bandwidth is invoiced monthly for the previous month. Figures are updated every few minutes.
    </p>
</div>

{* ---------------------------------------------------------------- DNS records *}
<div class="shcdn-box">
    <div class="shcdn-head">
        <h3>DNS Records</h3>
        {if !$shcdnSuspended}
            <button type="button" class="btn btn-default btn-sm" id="shcdn-import">Re-import existing DNS</button>
        {/if}
    </div>
    {if $shcdnLoadError}<div class="alert alert-warning">{$shcdnLoadError|escape:'html'}</div>{/if}

    <div class="table-responsive">
        <table class="table table-striped shcdn-table">
            <thead><tr><th>Type</th><th>Name</th><th>Value</th><th>TTL</th><th></th></tr></thead>
            <tbody id="shcdn-records"></tbody>
        </table>
    </div>

    {if !$shcdnSuspended}
    <form id="shcdn-form" autocomplete="off">
        <h4 id="shcdn-form-title">Add Record</h4>
        <input type="hidden" name="record_id" value="">
        <div class="row">
            <div class="col-sm-3 form-group">
                <label>Type</label>
                <select name="type" class="form-control">
                    <option value="0">A</option>
                    <option value="1">AAAA</option>
                    <option value="2">CNAME</option>
                    <option value="7">CDN (serve via CDN)</option>
                    <option value="4">MX</option>
                    <option value="3">TXT</option>
                    <option value="8">SRV</option>
                    <option value="9">CAA</option>
                </select>
            </div>
            <div class="col-sm-3 form-group">
                <label>Name</label>
                <input type="text" name="name" class="form-control" placeholder="@ or www" maxlength="253">
            </div>
            <div class="col-sm-4 form-group shcdn-f-value">
                <label>Value</label>
                <input type="text" name="value" class="form-control" maxlength="2048">
            </div>
            <div class="col-sm-2 form-group">
                <label>TTL</label>
                <select name="ttl" class="form-control">
                    <option value="300">5 min</option>
                    <option value="3600" selected>1 hour</option>
                    <option value="14400">4 hours</option>
                    <option value="86400">1 day</option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-2 form-group shcdn-f-priority" style="display:none"><label>Priority</label><input type="number" name="priority" class="form-control" value="10" min="0" max="65535"></div>
            <div class="col-sm-2 form-group shcdn-f-srv" style="display:none"><label>Weight</label><input type="number" name="weight" class="form-control" value="0" min="0" max="65535"></div>
            <div class="col-sm-2 form-group shcdn-f-srv" style="display:none"><label>Port</label><input type="number" name="port" class="form-control" value="443" min="1" max="65535"></div>
            <div class="col-sm-2 form-group shcdn-f-caa" style="display:none"><label>Flags</label><input type="number" name="flags" class="form-control" value="0" min="0" max="255"></div>
            <div class="col-sm-3 form-group shcdn-f-caa" style="display:none"><label>Tag</label>
                <select name="tag" class="form-control"><option>issue</option><option>issuewild</option><option>iodef</option></select>
            </div>
        </div>
        <p class="help-block shcdn-f-cdnhelp" style="display:none">The name will be served through the CDN from your origin server. SSL is issued automatically.</p>
        <button type="submit" class="btn btn-primary" id="shcdn-save">Add Record</button>
        <button type="button" class="btn btn-default" id="shcdn-cancel" style="display:none">Cancel</button>
    </form>
    {/if}
</div>

</div>

{literal}
<style>
#shcdn .shcdn-box { border:1px solid #e3e3e3; border-radius:6px; padding:16px 18px; margin-bottom:20px; background:#fff; }
#shcdn h3 { font-size:18px; margin:0 0 12px; }
#shcdn h4 { font-size:15px; margin:16px 0 8px; }
#shcdn .shcdn-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px; }
#shcdn .shcdn-head h3 { margin:0; }
#shcdn .shcdn-head select { width:auto; }
#shcdn .shcdn-table td { vertical-align:middle; word-break:break-all; }
#shcdn .shcdn-stats { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:12px; }
#shcdn .shcdn-stats > div { flex:1 1 140px; background:#f7f9fb; border-radius:6px; padding:12px; text-align:center; }
#shcdn .shcdn-stats span { display:block; font-size:20px; font-weight:600; }
#shcdn .shcdn-stats small { color:#777; }
#shcdn .shcdn-chart { display:flex; align-items:flex-end; gap:2px; height:90px; margin:10px 0; }
#shcdn .shcdn-chart div { flex:1; background:#3c8dbc; min-height:1px; border-radius:2px 2px 0 0; }
#shcdn .progress { height:8px; margin:4px 0 10px; }
#shcdn .shcdn-type { display:inline-block; min-width:52px; text-align:center; padding:2px 6px; border-radius:3px; background:#eef1f4; font-size:12px; font-weight:600; }
#shcdn .shcdn-type-7 { background:#dff0d8; color:#3c763d; }
</style>
{/literal}

<script>window.SHCDN_CONFIG = {$shcdnConfig nofilter};</script>
{literal}
<script>
(function () {
    var cfg = window.SHCDN_CONFIG;
    var root = document.getElementById('shcdn');
    var form = document.getElementById('shcdn-form');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function notify(type, text) {
        var box = document.getElementById('shcdn-alert');
        box.innerHTML = '<div class="alert alert-' + (type === 'success' ? 'success' : 'danger') + '">' + esc(text) + '</div>';
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function () { box.innerHTML = ''; }, 8000);
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

    function busy(btn, on) {
        if (!btn) return;
        btn.disabled = on;
        if (on) { btn.dataset.label = btn.textContent; btn.textContent = 'Please wait...'; }
        else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
    }

    // ------------------------------------------------------------- records
    function displayValue(r) {
        if (r.type === 7) return 'CDN → ' + r.value;
        if (r.type === 4) return r.priority + ' ' + r.value;
        if (r.type === 8) return r.priority + ' ' + r.weight + ' ' + r.port + ' ' + r.value;
        if (r.type === 9) return r.flags + ' ' + r.tag + ' "' + r.value + '"';
        return r.value;
    }

    function renderRecords(records) {
        cfg.records = records || [];
        var tbody = document.getElementById('shcdn-records');
        if (!cfg.records.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted">No DNS records yet.</td></tr>';
            return;
        }
        tbody.innerHTML = cfg.records.map(function (r) {
            var host = r.name ? r.name + '.' + cfg.domain : cfg.domain;
            var actions = (cfg.active && r.editable)
                ? '<button type="button" class="btn btn-xs btn-default" data-edit="' + r.id + '">Edit</button> ' +
                  '<button type="button" class="btn btn-xs btn-danger" data-del="' + r.id + '">Delete</button>'
                : '';
            return '<tr><td><span class="shcdn-type shcdn-type-' + r.type + '">' + esc(r.typeName) + '</span></td>' +
                '<td title="' + esc(host) + '"><code>' + esc(r.name || '@') + '</code></td>' +
                '<td><code>' + esc(displayValue(r)) + '</code></td>' +
                '<td>' + esc(r.ttl) + 's</td><td class="text-right" style="white-space:nowrap">' + actions + '</td></tr>';
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
        show('.shcdn-f-cdnhelp', t === '7');
    }

    function resetForm() {
        if (!form) return;
        form.reset();
        form.record_id.value = '';
        document.getElementById('shcdn-form-title').textContent = 'Add Record';
        document.getElementById('shcdn-save').textContent = 'Add Record';
        document.getElementById('shcdn-cancel').style.display = 'none';
        toggleFields();
    }

    function editRecord(id) {
        var r = cfg.records.filter(function (x) { return x.id === id; })[0];
        if (!r || !form) return;
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
        document.getElementById('shcdn-form-title').textContent = 'Edit Record';
        document.getElementById('shcdn-save').textContent = 'Save Changes';
        document.getElementById('shcdn-cancel').style.display = '';
        toggleFields();
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (form) {
        form.type.addEventListener('change', toggleFields);
        document.getElementById('shcdn-cancel').addEventListener('click', resetForm);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('shcdn-save');
            var data = {};
            new FormData(form).forEach(function (v, k) { data[k] = v; });
            busy(btn, true);
            call(data.record_id ? 'update' : 'add', data).then(function (res) {
                busy(btn, false);
                if (res.success) {
                    renderRecords(res.records);
                    notify('success', data.record_id ? 'Record updated.' : 'Record added.');
                    resetForm();
                } else {
                    notify('error', res.error || 'Could not save the record.');
                }
            });
        });
    }

    document.getElementById('shcdn-records').addEventListener('click', function (e) {
        var edit = e.target.closest('[data-edit]');
        var del = e.target.closest('[data-del]');
        if (edit) editRecord(parseInt(edit.getAttribute('data-edit'), 10));
        if (del) {
            if (!confirm('Delete this DNS record?')) return;
            busy(del, true);
            call('delete', { record_id: del.getAttribute('data-del') }).then(function (res) {
                if (res.success) { renderRecords(res.records); notify('success', 'Record deleted.'); }
                else { busy(del, false); notify('error', res.error || 'Could not delete the record.'); }
            });
        }
    });

    function simpleAction(id, action, confirmText) {
        var btn = document.getElementById(id);
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
    simpleAction('shcdn-purge', 'purge', 'Purge the entire CDN cache?');
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
    function loadStats() {
        var range = document.getElementById('shcdn-range').value;
        call('stats', { range: range }).then(function (s) {
            if (!s.success) {
                document.getElementById('shcdn-chart').innerHTML = '<small class="text-muted">' + esc(s.error || 'Statistics are not available yet.') + '</small>';
                return;
            }
            document.getElementById('shcdn-bw').textContent = s.bandwidth;
            document.getElementById('shcdn-req').textContent = s.requests;
            document.getElementById('shcdn-hit').textContent = s.cacheHitRate + '%';
            document.getElementById('shcdn-charge').textContent = s.charge;
            var wrap = document.getElementById('shcdn-limit-wrap');
            if (s.limitPercent !== null) {
                wrap.style.display = '';
                document.getElementById('shcdn-limit-text').textContent = s.limitPercent + '% of ' + s.limit + ' used this month';
                var bar = document.getElementById('shcdn-limit-bar');
                bar.style.width = s.limitPercent + '%';
                bar.className = 'progress-bar' + (s.limitPercent >= 90 ? ' progress-bar-danger bg-danger' : s.limitPercent >= 75 ? ' progress-bar-warning bg-warning' : '');
            }
            var max = Math.max.apply(null, s.chart.map(function (p) { return p.bytes; }).concat([1]));
            document.getElementById('shcdn-chart').innerHTML = s.chart.map(function (p) {
                var gb = (p.bytes / 1e9).toFixed(3);
                return '<div style="height:' + Math.max(1, Math.round(p.bytes / max * 100)) + '%" title="' + esc(p.date) + ': ' + gb + ' GB"></div>';
            }).join('');
        });
    }
    document.getElementById('shcdn-range').addEventListener('change', loadStats);

    renderRecords(cfg.records);
    toggleFields();
    loadStats();
})();
</script>
{/literal}
