<?php
/**
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2020 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url     http://www.e-plugins.nl
 */

class DigiwalletNotifyUrlModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $digiwallet = $this->module;
        $method = Tools::getValue('method');
        switch ($method) {
            case 'PYP':
                $trxid = Tools::getValue('acquirerID');
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
        if ($transactionInfoArr) {
            $digiwallet->rebuildCart($transactionInfoArr['order_id']);
            $return = $digiwallet->updateOrderAfterCheck($transactionInfoArr, true);
            echo $return.'<br />';
            die('Done version 1.7.xx');
        }
        die('Transaction is not found');
    }
}
