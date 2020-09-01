<?php
/**
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2020 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url     http://www.e-plugins.nl
 */

class DigiwalletReturnUrlModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $retMsg = null;
        $digiwallet = $this->module;
        $method = Tools::getValue('method');
        switch ($method) {
            case 'PYP':
                $trxid = Tools::getValue('paypalid');
                break;
            case 'AFP':
                $trxid = Tools::getValue('invoiceID');
                break;
            case 'EPS':
            case 'GIP':
                $trxid = Tools::getValue('transactionID');
                break;
            case 'IDE':
            case 'MRC':
            case 'DEB':
            case 'CC':
            case 'WAL':
            case 'BW':
            default:
                $trxid = Tools::getValue('trxid');
        }
        $transactionInfoArr = $digiwallet->selectTransaction($trxid, $method);
        if (false === $transactionInfoArr) {
            Tools::redirect(_PS_BASE_URL_);
            exit();
        }

        if ($transactionInfoArr) {
            $retMsg = $digiwallet->updateOrderAfterCheck($transactionInfoArr);
        }

        $order = new Order((int) $transactionInfoArr['order_id']);
        if (in_array(
            $order->current_state,
            [
                Configuration::get('PS_OS_ERROR'),
                Configuration::get('PS_OS_CANCELED'),
            ]
        )) {
            $this->errors[] = $retMsg;
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, []));
        } else {
            // clear cart
            $digiwallet->removeCart();
            // redirect to confirm page to show the result
            Tools::redirect(
                'index.php?controller=order-confirmation&id_cart='.$order->id_cart.'&id_module='.$digiwallet->id.
                     '&id_order='.$order->id.'&key='.$order->secure_key
            );
        }
    }
}
