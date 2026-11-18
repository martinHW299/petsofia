{extends file='page.tpl'}

{block name='page_content'}
  <section class="redenlace-payment">
    <h1>{l s='Paga mediante código QR' mod='redenlace'}</h1>
    {if isset($transaction)}
      <p>{l s='Escanea el código QR desde la aplicación de tu banco para pagar tu pedido.' mod='redenlace'}</p>
      <p><img src="data:image/png;base64,{$transaction.qr_base64|escape:'htmlall':'UTF-8'}" alt="{l s='Código QR de pago' mod='redenlace'}"></p>
      <p>{l s='La confirmación del pago se realizará de forma segura. No cierres esta página hasta completar el pago.' mod='redenlace'}</p>
    {else}
      <p>{l s='No se pudo generar un QR para este pago.' mod='redenlace'}</p>
    {/if}
  </section>
{/block}
