<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;text-align:center;margin-bottom:16px">
{if $status == 'Pending'}
    <div style="font-size:18px;font-weight:600;margin-bottom:6px">Your CDN is being set up</div>
    <div style="color:#6b7280">It will be ready as soon as your order is activated. This page refreshes automatically.</div>
    <script>setTimeout(function () { location.reload(); }, 60000);</script>
{elseif $status == 'Active'}
    <div style="font-size:18px;font-weight:600;margin-bottom:6px">Setup has not completed yet</div>
    <div style="color:#6b7280">Our team has been notified. Please contact support if this does not change soon.</div>
{else}
    <div style="font-size:18px;font-weight:600;margin-bottom:6px">This CDN service is {$status|escape:'html'}</div>
    <div style="color:#6b7280">Please contact support if you have any questions.</div>
{/if}
</div>
