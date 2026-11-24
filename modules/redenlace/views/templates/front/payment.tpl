{extends file='page.tpl'}

{block name='page_content'}
  <section class="redenlace-payment" id="redenlace-payment">
    <h1>{l s='Paga mediante código QR' mod='redenlace'}</h1>
    {if isset($transaction)}
      <p>{l s='Escanea el código QR desde la aplicación de tu banco para pagar tu pedido.' mod='redenlace'}</p>
      <p id="redenlace-qr-image"><img src="data:image/png;base64,{$transaction.qr_base64|escape:'htmlall':'UTF-8'}" alt="{l s='Código QR de pago' mod='redenlace'}"></p>
      <p class="redenlace-payment__expiry" id="redenlace-qr-expiry">
        {l s='Tiempo restante para pagar:' mod='redenlace'}
        <strong id="redenlace-qr-countdown" data-expires-at="{$transaction.expires_at_unix|intval}">{$transaction.expiry_countdown|escape:'htmlall':'UTF-8'}</strong>
      </p>
      <p id="redenlace-qr-expired" hidden>
        {l s='Este código QR venció. No realices el pago con este código.' mod='redenlace'}
      </p>
      <p id="redenlace-qr-waiting">{l s='La confirmación del pago se realizará de forma segura. No cierres esta página hasta completar el pago.' mod='redenlace'}</p>
      <div id="redenlace-payment-status" class="redenlace-payment__status" aria-live="polite" hidden
        data-status-url="{$transaction.status_url|escape:'htmlall':'UTF-8'}"
        data-confirmation-url="{$transaction.confirmation_url|escape:'htmlall':'UTF-8'}">
      </div>
    {else}
      <p>{l s='No se pudo generar un QR para este pago.' mod='redenlace'}</p>
    {/if}
  </section>
{/block}
