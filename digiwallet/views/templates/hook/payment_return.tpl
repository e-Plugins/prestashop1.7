{*
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2020 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url      http://www.e-plugins.nl
*}

{if $status == 'ok'}
    <p class="text-success">{l s='Your order on %s is complete.' sprintf=[$shop_name] mod='digiwallet'}</p>
    <p>{l s='Your order information:' mod='digiwallet'}</p>
    <dl>
        <dt>{l s='Order number' mod='digiwallet'}</dt>
        <dd>{$order->id|escape:'htmlall':'UTF-8'}</dd>
        <dt>{l s='Amount' mod='digiwallet'}</dt>
        <dd>{$total|escape:'htmlall':'UTF-8'}</dd>
    </dl>
    <strong>{l s='Your order will be sent as soon as we receive payment.' mod='digiwallet'}</strong>
    <p>
    {l s='Thank you for shopping. While logged in, you may continue shopping or view your current order status and [1]order history[/1].' mod='digiwallet' tags=["<a class='link-button' href=\"{$urls.pages.history}\">"]}
    </p>
{else if $status == 'processing'}
    <p class="text-info">{l s='Your order on %s is processing.' sprintf=[$shop_name] mod='digiwallet'}</p>
    <p>{l s='Your order information:' mod='digiwallet'}</p>
    <dl>
        <dt>{l s='Order number' mod='digiwallet'}</dt>
        <dd>{$order->id|escape:'htmlall':'UTF-8'}</dd>
        <dt>{l s='Amount' mod='digiwallet'}</dt>
        <dd>{$total|escape:'htmlall':'UTF-8'}</dd>
    </dl>
    <strong>{l s='Payment is under processing. Your order will be sent as soon as we receive payment.' mod='digiwallet'}</strong>
    <p>
      {l s='Thank you for shopping. While logged in, you may continue shopping or view your current order status and [1]order history[/1].' mod='digiwallet' tags=["<a class='link-button' href=\"{$urls.pages.history}\">"]}
    </p>
{else}
    <p class="text-warning">{l s='Your order on %s is failed.' sprintf=[$shop_name] mod='digiwallet'}</p>
    <p>{l s='Your order information:' mod='digiwallet'}</p>
    <dl>
        <dt>{l s='Order number' mod='digiwallet'}</dt>
        <dd>{$order->id|escape:'htmlall':'UTF-8'}</dd>
        <dt>{l s='Amount' mod='digiwallet'}</dt>
        <dd>{$total|escape:'htmlall':'UTF-8'}</dd>
    </dl>
    <strong>{l s='We noticed a problem with your order.' mod='digiwallet'}</strong>
    <p>
        {l s='If you want to reorder ' mod='digiwallet'}
        <a class="link-button" href="{$reorderUrl|escape:'htmlall':'UTF-8'}" title="{l s='Reorder' mod='digiwallet'}">{l s='click here' mod='digiwallet'}</a>.
    </p>
{/if}
