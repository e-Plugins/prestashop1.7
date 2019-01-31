{*
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2018 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url      http://www.e-plugins.nl
*}

<section>
  <!-- iDEAL -->
<div class="row">
    <div class="col-xs-12 col-md-12">
        {foreach from=$optionListArr key=k item=v}
            <div class="digi-checkbox"><label class="digi-label"><input type="radio" name="payment_option_{$method|escape:'htmlall':'UTF-8'}" {if $selected == $k} checked {/if} value="{$k|escape:'htmlall':'UTF-8'}" class="digi-radio"/>{$v|escape:'htmlall':'UTF-8'}</label></div>
        {/foreach}
    </div>
</div>
</section>