<?php
/**
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2018 e-plugins.nl
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url     http://www.e-plugins.nl
 */

class DigiwalletReturnUrlModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     *
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $retMsg = null;
        $digiwallet = $this->module;
        $trxid = Tools::getValue('trxid');
        if (empty($trxid)) { //paypal use paypalid instead of trxid
            $trxid = Tools::getValue('paypalid');
        }
        if (empty($trxid)) { //afterpay use invoiceID instead of trxid
            $trxid = Tools::getValue('invoiceID');
        }
        $transactionInfoArr = $digiwallet->selectTransaction($trxid);
        if ($transactionInfoArr === false) {
            Tools::redirect(_PS_BASE_URL_);
            exit();
        }
        
        if ($transactionInfoArr) {
            $retMsg = $digiwallet->updateOrderAfterCheck($transactionInfoArr);
        }
        
        $order = new Order((int) $transactionInfoArr['order_id']);
        if (in_array($order->current_state, array(Configuration::get('PS_OS_ERROR'), Configuration::get('PS_OS_CANCELED')))) {
            $this->errors[] = $retMsg;
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, array()));
        } else {
            //clear cart
            $digiwallet->removeCart();
            // redirect to confirm page to show the result
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $order->id_cart .
                '&id_module=' . $digiwallet->id . '&id_order=' . $order->id . '&key=' . $order->secure_key);
        }
    }
}
