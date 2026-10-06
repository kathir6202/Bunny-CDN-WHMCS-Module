{if $status == 'Pending'}
    <div class="alert alert-info">
        <strong>Your CDN is being set up.</strong>
        It will be ready as soon as your order is activated. This page refreshes automatically.
    </div>
    <script>setTimeout(function () { location.reload(); }, 60000);</script>
{elseif $status == 'Active'}
    <div class="alert alert-warning">
        <strong>Your CDN setup has not completed yet.</strong>
        Our team has been notified. Please contact support if this does not change soon.
    </div>
{else}
    <div class="alert alert-warning">This CDN service is {$status|escape:'html'}.</div>
{/if}
