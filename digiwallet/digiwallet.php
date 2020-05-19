<?php
/**
 * Activates iDEAL, Bancontact, Sofort Banking, Visa / Mastercard Credit cards, PaysafeCard, AfterPay, BankWire, PayPal and Refunds in Prestashop
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2018 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url      http://www.e-plugins.nl
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\Order\OrderPresenter;

if (! defined('_PS_VERSION_')) {
    exit();
}

require_once('core/digiwallet.class.php');

class Digiwallet extends PaymentModule
{
    const DEFAULT_RTLO = 93929;
    // you can obtain your api key in your organization dashboard on https://digiwallet.nl
    const DEFAULT_TOKEN = '';
    const DIGIWALLET_BANKWIRE_PARTIAL = 'digiwallet_bankwire_partial';
    const DIGIWALLET_PENDING = 'digiwallet_pending';
    const DEFAULT_ENABLE_METHOD = 1;
    
    public $appId = 'e16cc084dc5a1341f373e016d40ae1b2';
    
    public function __construct()
    {
        $this->name = 'digiwallet';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.7';
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        );
        $this->author = 'DigiWallet.nl';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->limited_currencies = array(
            'EUR'
        );
        $this->bootstrap = true;
        $this->module_key = '87b9b7383334aff69cedb34d8e1e996e';
        parent::__construct();
        $this->displayName = $this->l('Digiwallet for Prestashop');
        $this->description = $this->l('Activates iDEAL, Bancontact, Sofort Banking, Visa / Mastercard Credit cards, PaysafeCard, AfterPay, BankWire, PayPal and Refunds in Prestashop');
        if (! count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }
    
    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }
        
        Configuration::updateValue('DIGIWALLET_RTLO', self::DEFAULT_RTLO); // Default Digiwallet
        Configuration::updateValue('DIGIWALLET_TOKEN', self::DEFAULT_TOKEN); // Default Digiwallet
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            Configuration::updateValue('DW_ENABLE_METHOD_' . $id, $method['enabled']);
            Configuration::updateValue('DW_ORDER_METHOD_' . $id, $method['order']);
        }
        if (! parent::install()
            || ! $this->createDigiwalletTable()
            || ! $this->updateDigiwalletTable()
            || ! $this->createDigiwalletStatus()
            || ! $this->registerHook('displayHeader')
            || ! $this->registerHook('displayBackOfficeHeader')
            || ! $this->registerHook('paymentOptions')
            || ! $this->registerHook('paymentReturn')
            || ! $this->registerHook('actionAdminControllerSetMedia')
            || ! $this->registerHook('actionOrderSlipAdd')  // for refund
            || Currency::refreshCurrencies()) {
            return false;
        }
            
        return true;
    }
    
    /**
     * Delete config when uninstall
     *
     * @return unknown
     */
    public function uninstall()
    {
        Configuration::deleteByName('DIGIWALLET_RTLO');
        Configuration::deleteByName('BANK_LIST_MODE');
        Configuration::deleteByName('COUNTRY_LIST_MODE');
        $listMethods = array_keys($this->getListMethods());
        foreach ($listMethods as $id) {
            Configuration::deleteByName('DW_ENABLE_METHOD_' . $id);
            Configuration::deleteByName('DW_ORDER_METHOD_' . $id);
        }
        
        return parent::uninstall();
    }
    
    /**
     * Function called by install
     * Column Descriptions:
     * id_payment the primary key.
     * order_id : Stores the order number associated with iDEAL
     * paymethod: Stores the paymethod
     * transaction_id: The transaction_id which is retrieved from the API
     * bank_id: The bank identifier
     * description: Description of the payment
     * amount: Decimal of the amount. 1 euro and 10 cents is "1.10"
     * status: init:0, success:1, fail:2
     * via
     */
    public function createDigiwalletTable()
    {
        $db = Db::getInstance();
        $query = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "digiwallet` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
            `order_id` int(11) NULL DEFAULT '0',
            `cart_id` int(11) NOT NULL DEFAULT '0',
            `rtlo` int(11) NOT NULL,
            `paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
            `transaction_id` varchar(255) NOT NULL,
            `description` varchar(64) NOT NULL,
            `amount` decimal(11,2) NOT NULL,
            INDEX `IX_tp_transaction_id` (`transaction_id`)
            ) ENGINE = InnoDB ";
        
        $db->Execute($query);
        
        return true;
    }
    
    /**
     * add field
     * @return boolean
     */
    public function updateDigiwalletTable()
    {
        $db = Db::getInstance();
        $sql = "SHOW COLUMNS FROM `"._DB_PREFIX_."digiwallet` LIKE 'paid_amount'";
        $results = $db->ExecuteS($sql);
        if (empty($results)) {
            $db->Execute("ALTER TABLE `" . _DB_PREFIX_ . "digiwallet` ADD `paid_amount` decimal(11,2) NOT NULL DEFAULT '0' AFTER `paymethod`;");
        }
        return true;
    }

    /**
     * @return bool
     */
    public function createDigiwalletStatus()
    {
        $statuses = array(
            array(
                'module_name' => self::DIGIWALLET_BANKWIRE_PARTIAL,
                'invoice' => 1,
                'send_email' => 0,
                'color' => 'blue',
                'unremovable' => 1,
                'logable' => 1,
                'paid' => 1,
                'title' => 'Digiwallet Partial Payment Received',
                'template' => 'bankwire'
            ),
            array(
                'module_name' => self::DIGIWALLET_PENDING,
                'invoice' => 0,
                'send_email' => 0,
                'color' => '#4169E1',
                'unremovable' => 1,
                'logable' => 0,
                'paid' => 0,
                'title' => 'Digiwallet Pending',
                'template' => ''
            ),
        );
        foreach ($statuses as $status) {
            $db = Db::getInstance();
            $query = '
                SELECT count(*)
                FROM `' . _DB_PREFIX_ . 'order_state`
                WHERE `module_name` = "' . $status['module_name'] . '"
            ';
            $result = Db::getInstance()->getValue($query);
            if (!$result) {
                $query = '
                INSERT INTO `' . _DB_PREFIX_ . 'order_state`
                SET
                    `invoice` = "' . $status['invoice'] . '",
                    `send_email` = "' . $status['send_email'] . '",
                    `module_name` = "' . $status['module_name'] . '",
                    `color` = "' . $status['color'] . '",
                    `unremovable` = "' . $status['unremovable'] . '",
                    `logable` = "' . $status['logable'] . '",
                    `paid` = "' . $status['paid'] . '"
                ';
                $db->Execute($query);
                $statusID = $db->Insert_ID();
                foreach (Language::getLanguages() as $language) {
                    $query = sprintf(
                        '
                    INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang`
                    SET
                        `id_order_state` = %d,
                        `id_lang` = %d,
                        `name` = "' . $status['title'] . '",
                        `template` = "' . $status['template'] . '"
                    ',
                        $statusID,
                        $language['id_lang']
                    );
                    $db->Execute($query);
                }
                $digiwalletIcon = dirname(__FILE__).'/logo.gif';
                $newStateIcon = dirname(__FILE__).'/../../img/os/'.(int)$statusID.'.gif';
                copy($digiwalletIcon, $newStateIcon);
            }
        }
        
        return true;
    }
    
    /* admin configuration settings */
    /**
     * Admin configuration settings
     *
     * @return string
     */
    public function getContent()
    {
        $output = null;
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $RTLO = (string)(Tools::getValue('DIGIWALLET_RTLO'));
            $Token = (string)(Tools::getValue('DIGIWALLET_TOKEN'));
            if (! $RTLO || empty($RTLO) || ! Validate::isGenericName($RTLO) || ! Validate::isUnsignedInt($RTLO)) {
                $output .= $this->displayError($this->l('Invalid RTLO. Only numbers allowed.'));
            } else {
                Configuration::updateValue('DIGIWALLET_RTLO', $RTLO);
                Configuration::updateValue('DIGIWALLET_TOKEN', $Token);
                $listMethods = array_keys($this->getListMethods());
                foreach ($listMethods as $id) {
                    $enabled = (string)(Tools::getValue('DW_ENABLE_METHOD_' . $id));
                    Configuration::updateValue('DW_ENABLE_METHOD_' . $id, $enabled ? 'yes' : 'no');
                    Configuration::updateValue('DW_ORDER_METHOD_' . $id, (string)(Tools::getValue('DW_ORDER_METHOD_' . $id)));
                }
                $bankListMode = (string)(Tools::getValue('BANK_LIST_MODE'));
                $countryListMode = (string)(Tools::getValue('COUNTRY_LIST_MODE'));
                Configuration::updateValue('BANK_LIST_MODE', ($bankListMode == 1) ? '1' : '0');
                Configuration::updateValue('COUNTRY_LIST_MODE', ($countryListMode == 1) ? '1' : '0');
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        
        return $output . $this->displayForm();
    }
    
    /**
     * Build config form
     *
     * @return string
     */
    private function displayForm()
    {
        // Get default Language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        
        $helper = new HelperForm();
        
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        
        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true; // false -> remove toolbar
        $helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );
        
        // Load current value
        $helper->fields_value['DIGIWALLET_RTLO'] = Configuration::get('DIGIWALLET_RTLO');
        $helper->fields_value['DIGIWALLET_TOKEN'] = Configuration::get('DIGIWALLET_TOKEN');
        $helper->fields_value['BANK_LIST_MODE'] = Configuration::get('BANK_LIST_MODE');
        $helper->fields_value['COUNTRY_LIST_MODE'] = Configuration::get('COUNTRY_LIST_MODE');
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            $helper->fields_value['DW_ENABLE_METHOD_' . $id] = $method['enabled'] == 'yes' ? 1 : 0;
            $helper->fields_value['DW_ORDER_METHOD_' . $id] = $method['order'];
        }
        return $helper->generateForm(array(
            $this->getConfigForm()
        ));
    }
    
    /**
     * Set config element to array
     *
     * @return array
     */
    private function getConfigForm()
    {
        $arrInputs = array(
            array(
                'name' => '',
                'type' => 'html',
                'html_content' => '<div class="inline description"><p><strong>You can enable test-mode for your outlet from your DigiWallet Organization Dashboard to test your payments through the DigiWallet Test Panel.</strong></p></div>'
            ),
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter a valid RTLO'),
                'name' => 'DIGIWALLET_RTLO',
                'required' => true,
                'label' => $this->l('RTLO')
            ),
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter Digiwallet token, register one at digiwallet.nl'),
                'name' => 'DIGIWALLET_TOKEN',
                'required' => false,
                'label' => $this->l('Digiwallet Token')
            ),
        );
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            $arrInputs[] = array(
                'type' => 'switch',
                'label' => $method['name'],
                'name' => 'DW_ENABLE_METHOD_' . $id,
                'is_bool' => true,
                'desc' => $this->l($method['extra_text']),
                'values' => array(
                    array(
                        'id' => 'active_on_' . $id,
                        'value' => true,
                        'label' => $this->l('Enabled')
                    ),
                    array(
                        'id' => 'active_off_' . $id,
                        'value' => false,
                        'label' => $this->l('Disabled')
                    )
                )
            );
            $arrInputs[] = array(
                'name' => '',
                'required' => true,
                'desc' => 'Order ' . $method['name'],
                'type' => 'html',
                'html_content' => '<input class="tp-sort-order" min="1"  type="number" value="' . $method['order'] . '" name="' . 'DW_ORDER_METHOD_' . $id . '">'
            );
        }
        $arrInputs[] = array(
            'type' => 'select',
            'label' => $this->l('iDEAL bank list mode'),
            'name' => 'BANK_LIST_MODE',
            'required' => true,
            'options' => array(
                'query' => array(
                    array(
                        'id_option' => 0,
                        'name' => $this->l('Show in checkout process'),
                        'default' => true
                    ),
                    array(
                        'id_option' => 1,
                        'name' => $this->l('Hide from checkout process, show after confirmation')
                    ),
                ),
                'id' => 'id_option',
                'name' => 'name',
            )
        );
        
        $arrInputs[] = array(
            'type' => 'select',
            'label' => $this->l('Sofort country list mode'),
            'name' => 'COUNTRY_LIST_MODE',
            'required' => true,
            'options' => array(
                'query' => array(
                    array(
                        'id_option' => 0,
                        'name' => $this->l('Show in checkout process')
                    ),
                    array(
                        'id_option' => 1,
                        'name' => $this->l('Hide from checkout process, show after confirmation')
                    ),
                ),
                'id' => 'id_option',
                'name' => 'name'
            )
        );
        
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => $arrInputs,
                'submit' => array(
                    'title' => $this->l('Save')
                )
            )
        );
    }
    
    /**
     * Check currency of cart.
     * Digiwallet is only accept EUR right now
     *
     * @param unknown $cart
     * @return boolean
     */
    private function checkCurrency($cart)
    {
        $currencyOrder = new Currency($cart->id_currency);
        if (in_array($currencyOrder->iso_code, $this->limited_currencies) == true) {
            return true;
        }
        return false;
    }
    
    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookDisplayHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }
    
    public function hookDisplayBackOfficeHeader()
    {
        $this->context->controller->addCSS($this->_path . '/views/css/admin.css');
    }
    /**
     * hookPaymentOptions
     * Called in Front Office at Payment Screen - displays user this module as payment option
     *
     * @param unknown $params
     * @return string
     */
    public function hookPaymentOptions($params)
    {
        $payment_options = array();
        
        if (!$this->active) {
            return;
        }
        
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }
        
        $rtlo = Configuration::get('DIGIWALLET_RTLO');
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            $setInputs = array(
                'bankID' => array(
                    'name' => 'method',
                    'type' => 'hidden',
                    'value' => $id,
                ));
            $addInfo = '';
            if (($id == 'IDE' && Configuration::get('BANK_LIST_MODE') != 1) || ($id == 'DEB' && Configuration::get('COUNTRY_LIST_MODE') != 1)) {
                $templateVars = $this->getTemplateVars($id, $rtlo);
                $this->smarty->assign(
                    $templateVars
                );
                $addInfo = $this->fetch('module:digiwallet/views/templates/front/payment_infos.tpl');
                $setInputs['option'] = array(
                    'name' => 'option',
                    'type' => 'hidden',
                    'value' => $templateVars['selected']
                );
            }
            
            if ($method['enabled'] == 'yes') {
                $newOption = new PaymentOption();
                $newOption->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
                ->setAdditionalInformation($addInfo)
                ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/views/img/'. $id .'_50.png'))
                ->setInputs($setInputs)
                ;
                $payment_options[] = $newOption;
            }
        }
        return $payment_options;
    }
    
    /**
     *
     * @param unknown $method
     * @param unknown $rtlo
     * @return [][]
     */
    public function getTemplateVars($method, $rtlo)
    {
        $digiwallet = new DigiwalletCore($method, $rtlo);
        if ($method == 'IDE') {
            $list = $digiwallet->getBankList();
        } else {
            $list = $digiwallet->getCountryList();
        }
    
        return array(
            'optionListArr' => $list,
            'selected' => key($list),
            'method' => $method
        );
    }
    
    /**
     * This hook is used to display the order confirmation page.
     *
     * @param unknown $params
     * @return void|unknown
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false) {
            return;
        }
        
        $order = $params['order'];
        if ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_PAYMENT')) {
            $this->smarty->assign('status', 'ok');
        } elseif ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_CHEQUE')) {
            $this->smarty->assign('status', 'processing');
        } else {
            $this->smarty->assign('status', 'error');
        }
        $order_presenter = new OrderPresenter();
        $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'order' => $order,
                'reorderUrl' => $order_presenter->present($order)['details']['reorder_url'],
                'total' => Tools::displayPrice($params['order']->getOrdersTotalPaid(), new Currency($params['order']->id_currency), false)
            ));
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
    
    /**
     * Get transaction info in digiwallet table
     *
     * @param string $trxid
     * @return boolean|object|NULL
     */
    public function selectTransaction($trxid)
    {
        $sql = sprintf("SELECT `id`, `cart_id`, `rtlo`,`order_id`, `paymethod`, `transaction_id`, `description`, `amount`
            FROM `" . _DB_PREFIX_ . "digiwallet`
            WHERE `transaction_id`= '%s'
            ORDER BY `id` DESC", $trxid); // Choose most recent to minimize collision risk because we lack a paymethod field here!
        $result = Db::getInstance()->getRow($sql);
        return $result;
    }

    /**
     * @param $statusName
     * @return mixed
     */
    public function getDigiwalletStatusID($statusName)
    {
        $query = '
            SELECT `id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_state`
            WHERE `module_name` = "' . $statusName . '"
        ';
        $result = Db::getInstance()->getRow($query);
        
        return $result['id_order_state'];
    }
    
    /**
     * Update order, order history, transaction info after payment
     *
     * @param array $transactionInfoArr
     * @param boolean $isReport
     */
    public function updateOrderAfterCheck($transactionInfoArr, $isReport = false)
    {
        $orderId = (int) $transactionInfoArr['order_id'];
        $order = new Order($orderId);
        if (! $order) {
            return ("Order is not found");
        }
        
        if ($order->current_state == Configuration::get('PS_OS_PAYMENT')) {
            return ("order $orderId had been done");
        }
        try {
            list($payment) = $order->getOrderPaymentCollection();
            if (!empty($payment) && $payment->transaction_id == $transactionInfoArr['transaction_id']) {
                return ("transaction {$transactionInfoArr['transaction_id']} had been done");
            }
        } catch (Exception $e) {
        }
        $listMethods = $this->getListMethods();
        $digiwallet = new DigiwalletCore($transactionInfoArr["paymethod"], $transactionInfoArr["rtlo"], "nl");
        $digiwallet->checkPayment($transactionInfoArr['transaction_id']);
        $updateArr = array();
        $paymentIsPartial = false;
        $amountPaid = null;
        if ($digiwallet->getPaidStatus()) {
            $amountPaid = $transactionInfoArr['amount'];
            if ($transactionInfoArr["paymethod"] == 'BW') {
                $consumber_info = $digiwallet->getConsumerInfo();
                if (!empty($consumber_info) && $consumber_info['bw_paid_amount'] > 0) {
                    $amountPaid = number_format($consumber_info['bw_paid_amount'] / 100, 5);
                    if ($consumber_info['bw_paid_amount'] < $consumber_info['bw_due_amount']) {
                        $paymentIsPartial = true;
                    }
                }
                if ($paymentIsPartial) {
                    $state = $this->getDigiwalletStatusID(self::DIGIWALLET_BANKWIRE_PARTIAL);
                    $retMsg = $updateArr["description"] = 'Paid partial';
                    $updateArr['paid_amount'] = $amountPaid;
                } else {
                    $state = Configuration::get('PS_OS_PAYMENT');
                    $retMsg = $updateArr["description"] = 'Paid';
                    $updateArr['paid_amount'] = $amountPaid;
                }
            } else {
                $state = Configuration::get('PS_OS_PAYMENT');
                $retMsg = $updateArr["description"] = 'Paid';
                $updateArr['paid_amount'] = $amountPaid;
            }
        } else {
            $errorMessage = $digiwallet->getErrorMessage();
            if (strpos($errorMessage, 'DW_SE_0021') !== false) {
                $state = Configuration::get('PS_OS_CANCELED');
            } else {
                $state = Configuration::get('PS_OS_ERROR');
            }
            
            $retMsg = $updateArr["description"] = 'Error:' . $errorMessage;
        }
        
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($state, $orderId);
        $history->save();
        $this->updateTransaction($updateArr, $transactionInfoArr['transaction_id']);
        if ($digiwallet->getPaidStatus()) {
            list($payment) = $order->getOrderPaymentCollection(); // Should be one single payment
            $payment->payment_method = $listMethods[$transactionInfoArr["paymethod"]]['name'];
            $payment->transaction_id = $transactionInfoArr['transaction_id'];
            if ($paymentIsPartial) {
                $payment->amount = $amountPaid;
            }
            $payment->save();
        }
        //send email confirm;
        $order = new Order($orderId);
        $this->sendEmailConfirm($order, $isReport);
        return $retMsg;
    }
    
    /**
     * Update transaction info in digiwallet table
     *
     * @param array $updateArr
     * @param string $trxid
     */
    public function updateTransaction($updateArr, $trxid)
    {
        $fields = '';
        foreach ($updateArr as $key => $value) {
            $fields .= "`" . $key . "` = '" . $value . "',";
        }
        $fields = rtrim($fields, ", ");
        
        $sql = sprintf("UPDATE `" . _DB_PREFIX_ . "digiwallet` SET
            " . $fields . "
            WHERE `transaction_id`= '%s'", $trxid);
        return Db::getInstance()->execute($sql);
    }
    
    public function getListMethods()
    {
        $listMethods = array(
            'AFP' => array(
                'name' => 'Afterpay',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_AFP') ? Configuration::get('DW_ENABLE_METHOD_AFP'): 'no',
                'extra_text' => $this->l('Enable Afterpay method'),
                'order' => Configuration::get('DW_ORDER_METHOD_AFP') ? Configuration::get('DW_ORDER_METHOD_AFP'): 1
            ),
            "MRC" => array(
                'name' => 'Bancontact',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_MRC') ? Configuration::get('DW_ENABLE_METHOD_MRC'): 'yes',
                'extra_text' => $this->l('Enable Bancontact method'),
                'order' => Configuration::get('DW_ORDER_METHOD_MRC') ? Configuration::get('DW_ORDER_METHOD_MRC'): 1
            ),
            'BW' => array(
                'name' => 'Bankwire',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_BW') ? Configuration::get('DW_ENABLE_METHOD_BW'): 'no',
                'extra_text' => $this->l('Enable Bankwire method'),
                'order' => Configuration::get('DW_ORDER_METHOD_BW') ? Configuration::get('DW_ORDER_METHOD_BW'): 1
            ),
            'CC' => array(
                'name' => 'Creditcard',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_CC') ? Configuration::get('DW_ENABLE_METHOD_CC'): 'no',
                'extra_text' => $this->l('Enable Creditcard method (only possible when creditcard is activated on your digiwallet account)'),
                'order' => Configuration::get('DW_ORDER_METHOD_CC') ? Configuration::get('DW_ORDER_METHOD_CC'): 1
            ),
            "IDE" => array(
                'name' => 'iDEAL',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_IDE') ? Configuration::get('DW_ENABLE_METHOD_IDE'): 'yes',
                'extra_text' => $this->l('Enable iDEAL method'),
                'order' => Configuration::get('DW_ORDER_METHOD_IDE') ? Configuration::get('DW_ORDER_METHOD_IDE'): 1
            ),
            'PYP' => array(
                'name' => 'Paypal',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_PYP') ? Configuration::get('DW_ENABLE_METHOD_PYP'): 'no',
                'extra_text' => $this->l('Enable Paypal method'),
                'order' => Configuration::get('DW_ORDER_METHOD_PYP') ? Configuration::get('DW_ORDER_METHOD_PYP'): 1
            ),
            'WAL' => array(
                'name' => 'Paysafecard',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_WAL') ? Configuration::get('DW_ENABLE_METHOD_WAL'): 'yes',
                'extra_text' => $this->l('Enable Paysafecard method'),
                'order' => Configuration::get('DW_ORDER_METHOD_WAL') ? Configuration::get('DW_ORDER_METHOD_WAL'): 1
            ),
            'DEB' => array(
                'name' => 'Sofort Banking',
                'enabled' => Configuration::get('DW_ENABLE_METHOD_DEB') ? Configuration::get('DW_ENABLE_METHOD_DEB'): 'yes',
                'extra_text' => $this->l('Enable Sofort Banking method'),
                'order' => Configuration::get('DW_ORDER_METHOD_DEB') ? Configuration::get('DW_ORDER_METHOD_DEB'): 1
            )
        );
        uasort($listMethods, function ($a, $b) {
            $retval = $a['order'] - $b['order'];
            if ($retval == 0) {
                $retval = strcmp($a['name'], $b['name']);
            }
            return $retval;
        });
            
        return $listMethods;
    }

    public function hookActionAdminControllerSetMedia()
    {
        if (Tools::getValue('controller') == "AdminOrders" && $id_order = Tools::getValue('id_order')) {
            $sql = sprintf("SELECT `transaction_id` FROM `" . _DB_PREFIX_ . "digiwallet` WHERE `order_id`= '%s' AND paid_amount > 0", $id_order);
            $trxid = Db::getInstance()->getValue($sql);
            if ($trxid) {
                Media::addJsDefL('chb_digiwallet_refund', $this->l('Refund on Digiwallet'));
                $this->context->controller->addJS($this->_path . '/views/js/bo_order.js');
            }
        }
    }

    /**
     *
     * @param unknown $params
     */
    public function hookActionOrderSlipAdd($params)
    {
        if (Tools::isSubmit('doPartialRefundDigiwallet')) {
            return $this->refund($params);
        }
    }
    
    /**
     *
     * @param unknown $params
     */
    public function refund($params)
    {
        $order = $params['order'];
        $refundAmount = 0;
        foreach ($params['productList'] as $product) {
            $refundAmount += $product['amount'];
        }
        if (Tools::getValue('partialRefundShippingCost')) {
            $refundAmount += Tools::getValue('partialRefundShippingCost');
        }
        
        if ($refundAmount == 0) {
            return false;
        }
        
        $this->refundProcess($order, $refundAmount);
    }
    
    /**
     *
     * @param unknown $order
     * @param unknown $refundAmount
     */
    public function refundProcess($order, $refundAmount)
    {
        $customer = new Customer($order->id_customer);
        $sql = sprintf("SELECT `rtlo`,`paymethod`, `transaction_id`
            FROM `" . _DB_PREFIX_ . "digiwallet`
            WHERE `order_id`= '%s'", $order->id);
        $result = Db::getInstance()->getRow($sql);
        $dataRefund = array(
            'paymethodID' => $result['paymethod'],
            'transactionID' => $result['transaction_id'],
            'amount' => (int)((float)($refundAmount) * 100),
            'description' => 'OrderId: ' . $order->id . ', Amount: ' . $refundAmount,
            'internalNote' => 'Internal note - OrderId: ' . $order->id . ', Amount: ' . $refundAmount . ', Customer Email: ' . $customer->email,
            'consumerName' => $customer->firstname . ' ' . $customer->lastname
        );
        
        $digiwallet = new DigiwalletCore($result['paymethod'], $result['rtlo']);
        
        if (! $digiwallet->refund(Configuration::get('DIGIWALLET_TOKEN'), $dataRefund)) {
            PrestaShopLogger::addLog($digiwallet->getErrorMessage(), 3, null, 'Order', $order->id, true);
            $this->context->controller->errors[] = ($digiwallet->getErrorMessage());
        } else {
            $history = new OrderHistory();
            $history->id_order = $order->id;
            $history->changeIdOrderState(Configuration::get('PS_OS_REFUND'), $order->id);
            $history->save();
            $history->sendEmail($order);
        }
    }
    
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        if (self::DEBUG_MODE) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Function called', 1, null, 'Cart', (int)$id_cart, true);
        }
        
        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }
        $this->context->cart = new Cart((int)$id_cart);
        $this->context->customer = new Customer((int)$this->context->cart->id_customer);
        // The tax cart is loaded before the customer so re-cache the tax calculation method
        $this->context->cart->setTaxCalculationMethod();
        
        $this->context->language = new Language((int)$this->context->cart->id_lang);
        $this->context->shop = ($shop ? $shop : new Shop((int)$this->context->cart->id_shop));
        ShopUrl::resetMainDomainCache();
        $id_currency = $currency_special ? (int)$currency_special : (int)$this->context->cart->id_currency;
        $this->context->currency = new Currency((int)$id_currency, null, (int)$this->context->shop->id);
        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
            $context_country = $this->context->country;
        }
        
        $order_status = new OrderState((int)$id_order_state, (int)$this->context->language->id);
        if (!Validate::isLoadedObject($order_status)) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status cannot be loaded', 3, null, 'Cart', (int)$id_cart, true);
            throw new PrestaShopException('Can\'t load Order status');
        }
        
        if (!$this->active) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Module is not active', 3, null, 'Cart', (int)$id_cart, true);
            die(Tools::displayError());
        }
        
        // Does order already exists ?
        if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->OrderExists() == false) {
            if ($secure_key !== false && $secure_key != $this->context->cart->secure_key) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Secure key does not match', 3, null, 'Cart', (int)$id_cart, true);
                die(Tools::displayError());
            }
            
            // For each package, generate an order
            $delivery_option_list = $this->context->cart->getDeliveryOptionList();
            $package_list = $this->context->cart->getPackageList();
            $cart_delivery_option = $this->context->cart->getDeliveryOption();
            
            // If some delivery options are not defined, or not valid, use the first valid option
            foreach ($delivery_option_list as $id_address => $package) {
                if (!isset($cart_delivery_option[$id_address]) || !array_key_exists($cart_delivery_option[$id_address], $package)) {
                    $package = array_keys($package);
                    foreach ($package as $key) {
                        $cart_delivery_option[$id_address] = $key;
                        break;
                    }
                }
            }
            
            $order_list = array();
            $order_detail_list = array();
            
            do {
                $reference = Order::generateReference();
            } while (Order::getByReference($reference)->count());
            
            $this->currentOrderReference = $reference;
            
            $cart_total_paid = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(true, Cart::BOTH), 2);
            
            foreach ($cart_delivery_option as $id_address => $key_carriers) {
                foreach ($delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data) {
                    foreach ($data['package_list'] as $id_package) {
                        // Rewrite the id_warehouse
                        $package_list[$id_address][$id_package]['id_warehouse'] = (int)$this->context->cart->getPackageIdWarehouse($package_list[$id_address][$id_package], (int)$id_carrier);
                        $package_list[$id_address][$id_package]['id_carrier'] = $id_carrier;
                    }
                }
            }
            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            $cart_rules = $this->context->cart->getCartRules();
            foreach ($cart_rules as $cart_rule) {
                if (($rule = new CartRule((int)$cart_rule['obj']->id)) && Validate::isLoadedObject($rule)) {
                    if ($error = $rule->checkValidity($this->context, true, true)) {
                        $this->context->cart->removeCartRule((int)$rule->id);
                        if (isset($this->context->cookie) && isset($this->context->cookie->id_customer) && $this->context->cookie->id_customer && !empty($rule->code)) {
                            Tools::redirect('index.php?controller=order&submitAddDiscount=1&discount_name='.urlencode($rule->code));
                        } else {
                            $rule_name = isset($rule->name[(int)$this->context->cart->id_lang]) ? $rule->name[(int)$this->context->cart->id_lang] : $rule->code;
                            $error = $this->trans('The cart rule named "%2s" (ID %1s) used in this cart is not valid and has been withdrawn from cart', array((int)$rule->id, $rule_name), 'Admin.Payment.Notification');
                            PrestaShopLogger::addLog($error, 3, '0000002', 'Cart', (int)$this->context->cart->id);
                        }
                    }
                }
            }
            
            foreach ($package_list as $id_address => $packageByAddress) {
                foreach ($packageByAddress as $id_package => $package) {
                    /** @var Order $order */
                    $order = new Order();
                    $order->product_list = $package['product_list'];
                    
                    if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                        $address = new Address((int)$id_address);
                        $this->context->country = new Country((int)$address->id_country, (int)$this->context->cart->id_lang);
                        if (!$this->context->country->active) {
                            throw new PrestaShopException('The delivery address country is not active.');
                        }
                    }
                    
                    $carrier = null;
                    if (!$this->context->cart->isVirtualCart() && isset($package['id_carrier'])) {
                        $carrier = new Carrier((int)$package['id_carrier'], (int)$this->context->cart->id_lang);
                        $order->id_carrier = (int)$carrier->id;
                        $id_carrier = (int)$carrier->id;
                    } else {
                        $order->id_carrier = 0;
                        $id_carrier = 0;
                    }
                    
                    $order->id_customer = (int)$this->context->cart->id_customer;
                    $order->id_address_invoice = (int)$this->context->cart->id_address_invoice;
                    $order->id_address_delivery = (int)$id_address;
                    $order->id_currency = $this->context->currency->id;
                    $order->id_lang = (int)$this->context->cart->id_lang;
                    $order->id_cart = (int)$this->context->cart->id;
                    $order->reference = $reference;
                    $order->id_shop = (int)$this->context->shop->id;
                    $order->id_shop_group = (int)$this->context->shop->id_shop_group;
                    
                    $order->secure_key = ($secure_key ? pSQL($secure_key) : pSQL($this->context->customer->secure_key));
                    $order->payment = $payment_method;
                    if (isset($this->name)) {
                        $order->module = $this->name;
                    }
                    $order->recyclable = $this->context->cart->recyclable;
                    $order->gift = (int)$this->context->cart->gift;
                    $order->gift_message = $this->context->cart->gift_message;
                    $order->mobile_theme = $this->context->cart->mobile_theme;
                    $order->conversion_rate = $this->context->currency->conversion_rate;
                    $amount_paid = !$dont_touch_amount ? Tools::ps_round((float)$amount_paid, 2) : $amount_paid;
                    $order->total_paid_real = 0;
                    
                    $order->total_products = (float)$this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_products_wt = (float)$this->context->cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_discounts_tax_excl = (float)abs($this->context->cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts_tax_incl = (float)abs($this->context->cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts = $order->total_discounts_tax_incl;
                    
                    $order->total_shipping_tax_excl = (float)$this->context->cart->getPackageShippingCost((int)$id_carrier, false, null, $order->product_list);
                    $order->total_shipping_tax_incl = (float)$this->context->cart->getPackageShippingCost((int)$id_carrier, true, null, $order->product_list);
                    $order->total_shipping = $order->total_shipping_tax_incl;
                    
                    if (!is_null($carrier) && Validate::isLoadedObject($carrier)) {
                        $order->carrier_tax_rate = $carrier->getTaxesRate(new Address((int)$this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));
                    }
                    
                    $order->total_wrapping_tax_excl = (float)abs($this->context->cart->getOrderTotal(false, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping_tax_incl = (float)abs($this->context->cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping = $order->total_wrapping_tax_incl;
                    
                    $order->total_paid_tax_excl = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(false, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid_tax_incl = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(true, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid = $order->total_paid_tax_incl;
                    $order->round_mode = Configuration::get('PS_PRICE_ROUND_MODE');
                    $order->round_type = Configuration::get('PS_ROUND_TYPE');
                    
                    $order->invoice_date = '0000-00-00 00:00:00';
                    $order->delivery_date = '0000-00-00 00:00:00';
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Creating order
                    $result = $order->add();
                    
                    if (!$result) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order cannot be created', 3, null, 'Cart', (int)$id_cart, true);
                        throw new PrestaShopException('Can\'t save Order');
                    }
                    
                    // Amount paid by customer is not the right one -> Status = payment error
                    // We don't use the following condition to avoid the float precision issues : http://www.php.net/manual/en/language.types.float.php
                    // if ($order->total_paid != $order->total_paid_real)
                    // We use number_format in order to compare two string
                    if ($order_status->logable && number_format($cart_total_paid, _PS_PRICE_COMPUTE_PRECISION_) != number_format($amount_paid, _PS_PRICE_COMPUTE_PRECISION_)) {
                        $id_order_state = Configuration::get('PS_OS_ERROR');
                    }
                    
                    $order_list[] = $order;
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderDetail is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Insert new Order detail list using cart for the current order
                    $order_detail = new OrderDetail(null, null, $this->context);
                    $order_detail->createList($order, $this->context->cart, $id_order_state, $order->product_list, 0, true, $package_list[$id_address][$id_package]['id_warehouse']);
                    $order_detail_list[] = $order_detail;
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderCarrier is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Adding an entry in order_carrier table
                    if (!is_null($carrier)) {
                        $order_carrier = new OrderCarrier();
                        $order_carrier->id_order = (int)$order->id;
                        $order_carrier->id_carrier = (int)$id_carrier;
                        $order_carrier->weight = (float)$order->getTotalWeight();
                        $order_carrier->shipping_cost_tax_excl = (float)$order->total_shipping_tax_excl;
                        $order_carrier->shipping_cost_tax_incl = (float)$order->total_shipping_tax_incl;
                        $order_carrier->add();
                    }
                }
            }
            
            // The country can only change if the address used for the calculation is the delivery address, and if multi-shipping is activated
            if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                $this->context->country = $context_country;
            }
            
            if (!$this->context->country->active) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Country is not active', 3, null, 'Cart', (int)$id_cart, true);
                throw new PrestaShopException('The order address country is not active.');
            }
            
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Payment is about to be added', 1, null, 'Cart', (int)$id_cart, true);
            }
            
            // Register Payment only if the order status validate the order
            if ($order_status->logable) {
                // $order is the last order loop in the foreach
                // The method addOrderPayment of the class Order make a create a paymentOrder
                // linked to the order reference and not to the order id
                if (isset($extra_vars['transaction_id'])) {
                    $transaction_id = $extra_vars['transaction_id'];
                } else {
                    $transaction_id = null;
                }
                
                if (!$order->addOrderPayment($amount_paid, null, $transaction_id)) {
                    PrestaShopLogger::addLog('PaymentModule::validateOrder - Cannot save Order Payment', 3, null, 'Cart', (int)$id_cart, true);
                    throw new PrestaShopException('Can\'t save Order Payment');
                }
            }
            
            // Next !
            //$only_one_gift = false;
            $cart_rule_used = array();
            //$products = $this->context->cart->getProducts();
            
            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            foreach ($order_detail_list as $key => $order_detail) {
                /** @var OrderDetail $order_detail */
                
                $order = $order_list[$key];
                if (isset($order->id)) {
                    if (!$secure_key) {
                        $message .= '<br />'.$this->trans('Warning: the secure key is empty, check your payment account before validation', array(), 'Admin.Payment.Notification');
                    }
                    // Optional message to attach to this order
                    if (isset($message) & !empty($message)) {
                        $msg = new Message();
                        $message = strip_tags($message, '<br>');
                        if (Validate::isCleanHtml($message)) {
                            if (self::DEBUG_MODE) {
                                PrestaShopLogger::addLog('PaymentModule::validateOrder - Message is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                            }
                            $msg->message = $message;
                            $msg->id_cart = (int)$id_cart;
                            $msg->id_customer = (int)($order->id_customer);
                            $msg->id_order = (int)$order->id;
                            $msg->private = 1;
                            $msg->add();
                        }
                    }
                    
                    // Insert new Order detail list using cart for the current order
                    //$orderDetail = new OrderDetail(null, null, $this->context);
                    //$orderDetail->createList($order, $this->context->cart, $id_order_state);
                    
                    // Construct order detail table for the email
                    //$products_list = '';
                    $virtual_product = true;
                    $specific_price = null;
                    $product_var_tpl_list = array();
                    foreach ($order->product_list as $product) {
                        $price = Product::getPriceStatic((int)$product['id_product'], false, ($product['id_product_attribute'] ? (int)$product['id_product_attribute'] : null), 6, null, false, true, $product['cart_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
                        $price_wt = Product::getPriceStatic((int)$product['id_product'], true, ($product['id_product_attribute'] ? (int)$product['id_product_attribute'] : null), 2, null, false, true, $product['cart_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
                        
                        $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, 2) : $price_wt;
                        
                        $product_var_tpl = array(
                            'id_product' => $product['id_product'],
                            'reference' => $product['reference'],
                            'name' => $product['name'].(isset($product['attributes']) ? ' - '.$product['attributes'] : ''),
                            'price' => Tools::displayPrice($product_price * $product['quantity'], $this->context->currency, false),
                            'quantity' => $product['quantity'],
                            'customization' => array()
                        );
                        
                        if (isset($product['price']) && $product['price']) {
                            $product_var_tpl['unit_price'] = Tools::displayPrice($product['price'], $this->context->currency, false);
                            $product_var_tpl['unit_price_full'] = Tools::displayPrice($product['price'], $this->context->currency, false)
                            .' '.$product['unity'];
                        } else {
                            $product_var_tpl['unit_price'] = $product_var_tpl['unit_price_full'] = '';
                        }
                        
                        $customized_datas = Product::getAllCustomizedDatas((int)$order->id_cart, null, true, null, (int)$product['id_customization']);
                        if (isset($customized_datas[$product['id_product']][$product['id_product_attribute']])) {
                            $product_var_tpl['customization'] = array();
                            foreach ($customized_datas[$product['id_product']][$product['id_product_attribute']][$order->id_address_delivery] as $customization) {
                                $customization_text = '';
                                if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                                    foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                        $customization_text .= '<strong>'.$text['name'].'</strong>: '.$text['value'].'<br />';
                                    }
                                }
                                
                                if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                                    $customization_text .= $this->trans('%d image(s)', array(count($customization['datas'][Product::CUSTOMIZE_FILE])), 'Admin.Payment.Notification').'<br />';
                                }
                                
                                $customization_quantity = (int)$customization['quantity'];
                                
                                $product_var_tpl['customization'][] = array(
                                    'customization_text' => $customization_text,
                                    'customization_quantity' => $customization_quantity,
                                    'quantity' => Tools::displayPrice($customization_quantity * $product_price, $this->context->currency, false)
                                );
                            }
                        }
                        
                        $product_var_tpl_list[] = $product_var_tpl;
                        // Check if is not a virutal product for the displaying of shipping
                        if (!$product['is_virtual']) {
                            $virtual_product &= false;
                        }
                    } // end foreach ($products)
                    
                    
                    $cart_rules_list = array();
                    $total_reduction_value_ti = 0;
                    $total_reduction_value_tex = 0;
                    foreach ($cart_rules as $cart_rule) {
                        $package = array('id_carrier' => $order->id_carrier, 'id_address' => $order->id_address_delivery, 'products' => $order->product_list);
                        $values = array(
                            'tax_incl' => $cart_rule['obj']->getContextualValue(true, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                            'tax_excl' => $cart_rule['obj']->getContextualValue(false, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package)
                        );
                        
                        // If the reduction is not applicable to this order, then continue with the next one
                        if (!$values['tax_excl']) {
                            continue;
                        }
                        
                        // IF
                        //  This is not multi-shipping
                        //  The value of the voucher is greater than the total of the order
                        //  Partial use is allowed
                        //  This is an "amount" reduction, not a reduction in % or a gift
                        // THEN
                        //  The voucher is cloned with a new value corresponding to the remainder
                        if (count($order_list) == 1 && $values['tax_incl'] > ($order->total_products_wt - $total_reduction_value_ti) && $cart_rule['obj']->partial_use == 1 && $cart_rule['obj']->reduction_amount > 0) {
                            // Create a new voucher from the original
                            $voucher = new CartRule((int)$cart_rule['obj']->id); // We need to instantiate the CartRule without lang parameter to allow saving it
                            unset($voucher->id);
                            
                            // Set a new voucher code
                            $voucher->code = empty($voucher->code) ? Tools::substr(md5($order->id.'-'.$order->id_customer.'-'.$cart_rule['obj']->id), 0, 16) : $voucher->code.'-2';
                            if (preg_match('/\-([0-9]{1,2})\-([0-9]{1,2})$/', $voucher->code, $matches) && $matches[1] == $matches[2]) {
                                $voucher->code = preg_replace('/'.$matches[0].'$/', '-'.((int)($matches[1]) + 1), $voucher->code);
                            }
                            
                            // Set the new voucher value
                            if ($voucher->reduction_tax) {
                                $voucher->reduction_amount = ($total_reduction_value_ti + $values['tax_incl']) - $order->total_products_wt;
                                
                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                                }
                            } else {
                                $voucher->reduction_amount = ($total_reduction_value_tex + $values['tax_excl']) - $order->total_products;
                                
                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                                }
                            }
                            if ($voucher->reduction_amount <= 0) {
                                continue;
                            }
                            
                            if ($this->context->customer->isGuest()) {
                                $voucher->id_customer = 0;
                            } else {
                                $voucher->id_customer = $order->id_customer;
                            }
                            
                            $voucher->quantity = 1;
                            $voucher->reduction_currency = $order->id_currency;
                            $voucher->quantity_per_user = 1;
                            $voucher->free_shipping = 0;
                            if ($voucher->add()) {
                                // If the voucher has conditions, they are now copied to the new voucher
                                CartRule::copyConditions($cart_rule['obj']->id, $voucher->id);
                                $orderLanguage = new Language((int) $order->id_lang);
                                
                                $params = array(
                                    '{voucher_amount}' => Tools::displayPrice($voucher->reduction_amount, $this->context->currency, false),
                                    '{voucher_num}' => $voucher->code,
                                    '{firstname}' => $this->context->customer->firstname,
                                    '{lastname}' => $this->context->customer->lastname,
                                    '{id_order}' => $order->reference,
                                    '{order_name}' => $order->getUniqReference()
                                );
                                Mail::Send(
                                    (int)$order->id_lang,
                                    'voucher',
                                    Context::getContext()->getTranslator()->trans(
                                        'New voucher for your order %s',
                                        array($order->reference),
                                        'Emails.Subject',
                                        $orderLanguage->locale
                                    ),
                                    $params,
                                    $this->context->customer->email,
                                    $this->context->customer->firstname.' '.$this->context->customer->lastname,
                                    null,
                                    null,
                                    null,
                                    null,
                                    _PS_MAIL_DIR_,
                                    false,
                                    (int)$order->id_shop
                                );
                            }
                            
                            $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                            $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                        }
                        $total_reduction_value_ti += $values['tax_incl'];
                        $total_reduction_value_tex += $values['tax_excl'];
                        
                        $order->addCartRule($cart_rule['obj']->id, $cart_rule['obj']->name, $values, 0, $cart_rule['obj']->free_shipping);
                        
                        if ($id_order_state != Configuration::get('PS_OS_ERROR') && $id_order_state != Configuration::get('PS_OS_CANCELED') && !in_array($cart_rule['obj']->id, $cart_rule_used)) {
                            $cart_rule_used[] = $cart_rule['obj']->id;
                            
                            // Create a new instance of Cart Rule without id_lang, in order to update its quantity
                            $cart_rule_to_update = new CartRule((int)$cart_rule['obj']->id);
                            $cart_rule_to_update->quantity = max(0, $cart_rule_to_update->quantity - 1);
                            $cart_rule_to_update->update();
                        }
                        
                        $cart_rules_list[] = array(
                            'voucher_name' => $cart_rule['obj']->name,
                            'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '').Tools::displayPrice($values['tax_incl'], $this->context->currency, false)
                        );
                    }
                    
                    // Specify order id for message
                    $old_message = Message::getMessageByCartId((int)$this->context->cart->id);
                    if ($old_message && !$old_message['private']) {
                        $update_message = new Message((int)$old_message['id_message']);
                        $update_message->id_order = (int)$order->id;
                        $update_message->update();
                        
                        // Add this message in the customer thread
                        $customer_thread = new CustomerThread();
                        $customer_thread->id_contact = 0;
                        $customer_thread->id_customer = (int)$order->id_customer;
                        $customer_thread->id_shop = (int)$this->context->shop->id;
                        $customer_thread->id_order = (int)$order->id;
                        $customer_thread->id_lang = (int)$this->context->language->id;
                        $customer_thread->email = $this->context->customer->email;
                        $customer_thread->status = 'open';
                        $customer_thread->token = Tools::passwdGen(12);
                        $customer_thread->add();
                        
                        $customer_message = new CustomerMessage();
                        $customer_message->id_customer_thread = $customer_thread->id;
                        $customer_message->id_employee = 0;
                        $customer_message->message = $update_message->message;
                        $customer_message->private = 1;
                        
                        if (!$customer_message->add()) {
                            $this->errors[] = $this->trans('An error occurred while saving message', array(), 'Admin.Payment.Notification');
                        }
                    }
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Hook validateOrder is about to be called', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Hook validate order
                    Hook::exec('actionValidateOrder', array(
                        'cart' => $this->context->cart,
                        'order' => $order,
                        'customer' => $this->context->customer,
                        'currency' => $this->context->currency,
                        'orderStatus' => $order_status
                    ));
                    
                    foreach ($this->context->cart->getProducts() as $product) {
                        if ($order_status->logable) {
                            ProductSale::addProductSale((int)$product['id_product'], (int)$product['cart_quantity']);
                        }
                    }
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Set the order status
                    $new_history = new OrderHistory();
                    $new_history->id_order = (int)$order->id;
                    $new_history->changeIdOrderState((int)$id_order_state, $order, true);
                    $new_history->addWithemail(true, $extra_vars);
                    
                    // Switch to back order if needed
                    if (Configuration::get('PS_STOCK_MANAGEMENT') && ($order_detail->getStockState() || $order_detail->product_quantity_in_stock <= 0)) {
                        $history = new OrderHistory();
                        $history->id_order = (int)$order->id;
                        $history->changeIdOrderState(Configuration::get($order->valid ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_OUTOFSTOCK_UNPAID'), $order, true);
                        $history->addWithemail();
                    }
                    
                    unset($order_detail);
                    
                    // Order is reloaded because the status just changed
                    $order = new Order((int)$order->id);
                    
                    // updates stock in shops
                    if (Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
                        $product_list = $order->getProducts();
                        foreach ($product_list as $product) {
                            // if the available quantities depends on the physical stock
                            if (StockAvailable::dependsOnStock($product['product_id'])) {
                                // synchronizes
                                StockAvailable::synchronize($product['product_id'], $order->id_shop);
                            }
                        }
                    }
                    
                    $order->updateOrderDetailTax();
                } else {
                    $error = $this->trans('Order creation failed', array(), 'Admin.Payment.Notification');
                    PrestaShopLogger::addLog($error, 4, '0000002', 'Cart', (int)($order->id_cart));
                    die($error);
                }
            } // End foreach $order_detail_list
            
            // Use the last order as currentOrder
            if (isset($order) && $order->id) {
                $this->currentOrder = (int)$order->id;
            }
            
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - End of validateOrder', 1, null, 'Cart', (int)$id_cart, true);
            }
            
            return true;
        } else {
            $error = $this->trans('Cart cannot be loaded or an order has already been placed using this cart', array(), 'Admin.Payment.Notification');
            PrestaShopLogger::addLog($error, 4, '0000001', 'Cart', (int)($this->context->cart->id));
            die($error);
        }
    }
    
    public function sendEmailConfirm($order, $isReport = false, $extra_vars = null)
    {
        if ($order->current_state != Configuration::get('PS_OS_ERROR') && $order->current_state != Configuration::get('PS_OS_CANCELED') && ($this->context->customer->id || $isReport)) {
            //$products_list = '';
            $virtual_product = true;
            $order_status = new OrderState((int)$order->current_state, (int)$order->id_lang);
            $carrier = new Carrier($order->id_carrier);
            $product_var_tpl_list = array();
            $specific_price = null;
            foreach ($order->getProducts() as $product) {
                $price = Product::getPriceStatic((int)$product['id_product'], false, ($product['product_attribute_id'] ? (int)$product['product_attribute_id'] : null), 6, null, false, true, $product['product_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
                $price_wt = Product::getPriceStatic((int)$product['id_product'], true, ($product['product_attribute_id'] ? (int)$product['product_attribute_id'] : null), 2, null, false, true, $product['product_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
                
                $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, 2) : $price_wt;
                
                $product_var_tpl = array(
                    'id_product' => $product['id_product'],
                    'reference' => $product['reference'],
                    'name' => $product['product_name'].(isset($product['attributes']) ? ' - '.$product['attributes'] : ''),
                    'price' => Tools::displayPrice($product_price * $product['product_quantity'], (int)$order->id_currency, false),
                    'quantity' => $product['product_quantity'],
                    'customization' => array()
                );
                
                if (isset($product['price']) && $product['price']) {
                    $product_var_tpl['unit_price'] = Tools::displayPrice($product['price'], (int)$order->id_currency, false);
                    $product_var_tpl['unit_price_full'] = Tools::displayPrice($product['price'], (int)$order->id_currency, false)
                    .' '.$product['unity'];
                } else {
                    $product_var_tpl['unit_price'] = $product_var_tpl['unit_price_full'] = '';
                }
                
                $customized_datas = Product::getAllCustomizedDatas((int)$order->id_cart, null, true, null, (int)$product['id_customization']);
                if (isset($customized_datas[$product['id_product']][$product['product_attribute_id']])) {
                    $product_var_tpl['customization'] = array();
                    foreach ($customized_datas[$product['id_product']][$product['product_attribute_id']][$order->id_address_delivery] as $customization) {
                        $customization_text = '';
                        if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                            foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                $customization_text .= '<strong>'.$text['name'].'</strong>: '.$text['value'].'<br />';
                            }
                        }
                        
                        if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                            $customization_text .= $this->trans('%d image(s)', array(count($customization['datas'][Product::CUSTOMIZE_FILE])), 'Admin.Payment.Notification').'<br />';
                        }
                        
                        $customization_quantity = (int)$customization['quantity'];
                        
                        $product_var_tpl['customization'][] = array(
                            'customization_text' => $customization_text,
                            'customization_quantity' => $customization_quantity,
                            'quantity' => Tools::displayPrice($customization_quantity * $product_price, (int)$order->id_currency, false)
                        );
                    }
                }
                
                $product_var_tpl_list[] = $product_var_tpl;
                // Check if is not a virutal product for the displaying of shipping
                if (!$product['is_virtual']) {
                    $virtual_product &= false;
                }
            } // end foreach ($products)
            
            $product_list_txt = '';
            $product_list_html = '';
            if (count($product_var_tpl_list) > 0) {
                $product_list_txt = $this->getEmailTemplateContent('order_conf_product_list.txt', Mail::TYPE_TEXT, $product_var_tpl_list);
                $product_list_html = $this->getEmailTemplateContent('order_conf_product_list.tpl', Mail::TYPE_HTML, $product_var_tpl_list);
            }
            
            $cart_rules_list = array();
            $total_reduction_value_ti = 0;
            $total_reduction_value_tex = 0;
            $cart_rules = $order->getCartRules();
            foreach ($cart_rules as $cart_rule) {
                $package = array('id_carrier' => $order->id_carrier, 'id_address' => $order->id_address_delivery, 'products' => $order->product_list);
                $values = array(
                    'tax_incl' => $cart_rule['obj']->getContextualValue(true, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                    'tax_excl' => $cart_rule['obj']->getContextualValue(false, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package)
                );
                
                // If the reduction is not applicable to this order, then continue with the next one
                if (!$values['tax_excl']) {
                    continue;
                }
                
                $total_reduction_value_ti += $values['tax_incl'];
                $total_reduction_value_tex += $values['tax_excl'];
                
                $cart_rules_list[] = array(
                    'voucher_name' => $cart_rule['obj']->name,
                    'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '').Tools::displayPrice($values['tax_incl'], (int)$order->id_currency, false)
                );
            }
            
            $cart_rules_list_txt = '';
            $cart_rules_list_html = '';
            if (count($cart_rules_list) > 0) {
                $cart_rules_list_txt = $this->getEmailTemplateContent('order_conf_cart_rules.txt', Mail::TYPE_TEXT, $cart_rules_list);
                $cart_rules_list_html = $this->getEmailTemplateContent('order_conf_cart_rules.tpl', Mail::TYPE_HTML, $cart_rules_list);
            }
            
            $invoice = new Address((int)$order->id_address_invoice);
            $delivery = new Address((int)$order->id_address_delivery);
            $customer = new Customer((int)($order->id_customer));
            $delivery_state = $delivery->id_state ? new State((int)$delivery->id_state) : false;
            $invoice_state = $invoice->id_state ? new State((int)$invoice->id_state) : false;
            
            $data = array(
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{delivery_block_txt}' => $this->_getFormatedAddress($delivery, "\n"),
                '{invoice_block_txt}' => $this->_getFormatedAddress($invoice, "\n"),
                '{delivery_block_html}' => $this->_getFormatedAddress($delivery, '<br />', array(
                    'firstname'    => '<span style="font-weight:bold;">%s</span>',
                    'lastname'    => '<span style="font-weight:bold;">%s</span>'
                )),
                '{invoice_block_html}' => $this->_getFormatedAddress($invoice, '<br />', array(
                    'firstname'    => '<span style="font-weight:bold;">%s</span>',
                    'lastname'    => '<span style="font-weight:bold;">%s</span>'
                )),
                '{delivery_company}' => $delivery->company,
                '{delivery_firstname}' => $delivery->firstname,
                '{delivery_lastname}' => $delivery->lastname,
                '{delivery_address1}' => $delivery->address1,
                '{delivery_address2}' => $delivery->address2,
                '{delivery_city}' => $delivery->city,
                '{delivery_postal_code}' => $delivery->postcode,
                '{delivery_country}' => $delivery->country,
                '{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
                '{delivery_phone}' => ($delivery->phone) ? $delivery->phone : $delivery->phone_mobile,
                '{delivery_other}' => $delivery->other,
                '{invoice_company}' => $invoice->company,
                '{invoice_vat_number}' => $invoice->vat_number,
                '{invoice_firstname}' => $invoice->firstname,
                '{invoice_lastname}' => $invoice->lastname,
                '{invoice_address2}' => $invoice->address2,
                '{invoice_address1}' => $invoice->address1,
                '{invoice_city}' => $invoice->city,
                '{invoice_postal_code}' => $invoice->postcode,
                '{invoice_country}' => $invoice->country,
                '{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
                '{invoice_phone}' => ($invoice->phone) ? $invoice->phone : $invoice->phone_mobile,
                '{invoice_other}' => $invoice->other,
                '{order_name}' => $order->getUniqReference(),
                '{date}' => Tools::displayDate(date('Y-m-d H:i:s'), null, 1),
                '{carrier}' => ($virtual_product || !isset($carrier->name)) ? $this->trans('No carrier', array(), 'Admin.Payment.Notification') : $carrier->name,
                '{payment}' => Tools::substr($order->payment, 0, 255),
                '{products}' => $product_list_html,
                '{products_txt}' => $product_list_txt,
                '{discounts}' => $cart_rules_list_html,
                '{discounts_txt}' => $cart_rules_list_txt,
                '{total_paid}' => Tools::displayPrice($order->total_paid, (int)$order->id_currency, false),
                '{total_products}' => Tools::displayPrice(Product::getTaxCalculationMethod() == PS_TAX_EXC ? $order->total_products : $order->total_products_wt, (int)$order->id_currency, false),
                '{total_discounts}' => Tools::displayPrice($order->total_discounts, (int)$order->id_currency, false),
                '{total_shipping}' => Tools::displayPrice($order->total_shipping, (int)$order->id_currency, false),
                '{total_wrapping}' => Tools::displayPrice($order->total_wrapping, (int)$order->id_currency, false),
                '{total_tax_paid}' => Tools::displayPrice(($order->total_products_wt - $order->total_products) + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl), (int)$order->id_currency, false));
            
            if (is_array($extra_vars)) {
                $data = array_merge($data, $extra_vars);
            }
            $file_attachement = null;
            // Join PDF invoice
            if ((int)Configuration::get('PS_INVOICE') && $order_status->invoice && $order->invoice_number) {
                $order_invoice_list = $order->getInvoicesCollection();
                Hook::exec('actionPDFInvoiceRender', array('order_invoice_list' => $order_invoice_list));
                $pdf = new PDF($order_invoice_list, PDF::TEMPLATE_INVOICE, $this->context->smarty);
                $file_attachement['content'] = $pdf->render(false);
                $file_attachement['name'] = Configuration::get('PS_INVOICE_PREFIX', (int)$order->id_lang, null, $order->id_shop).sprintf('%06d', $order->invoice_number).'.pdf';
                $file_attachement['mime'] = 'application/pdf';
            }
            
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Mail is about to be sent', 1, null, 'Cart', (int)($order->id_cart), true);
            }
            
            $orderLanguage = new Language((int) $order->id_lang);
            
            if (Validate::isEmail($customer->email)) {
                Mail::Send(
                    (int)$order->id_lang,
                    'order_conf',
                    Context::getContext()->getTranslator()->trans(
                        'Order confirmation',
                        array(),
                        'Emails.Subject',
                        $orderLanguage->locale
                    ),
                    $data,
                    $customer->email,
                    $customer->firstname.' '.$customer->lastname,
                    null,
                    null,
                    $file_attachement,
                    null,
                    _PS_MAIL_DIR_,
                    false,
                    (int)$order->id_shop
                );
            }
        }
    }
    
    public function rebuildCart($id_order)
    {
        $oldCart = new Cart(Order::getCartIdStatic($id_order, $this->context->customer->id));
        $duplication = $oldCart->duplicate();
        if (!$duplication || !Validate::isLoadedObject($duplication['cart'])) {
            $this->errors[] = $this->trans('Sorry. We cannot renew your order.', array(), 'Shop.Notifications.Error');
        } elseif (!$duplication['success']) {
            $this->errors[] = $this->trans(
                'Some items are no longer available, and we are unable to renew your order.',
                array(),
                'Shop.Notifications.Error'
            );
        } else {
            $this->context->cookie->id_cart = $duplication['cart']->id;
            $context = $this->context;
            $context->cart = $duplication['cart'];
            CartRule::autoAddToCart($context);
            $this->context->cookie->write();
        }
        return true;
    }
    
    public function removeCart()
    {
        $products = $this->context->cart->getProducts();
        foreach ($products as $product) {
            $this->context->cart->deleteProduct($product["id_product"], $product["id_product_attribute"]);
        }
        return true;
    }
}
