<?php
/**
 * @author  DigiWallet.nl
 * @copyright Copyright (C) 2020 e-plugins.nl
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @url     http://www.e-plugins.nl
 */

use Digiwallet\Packages\Transaction\Client\Client;
use Digiwallet\Packages\Transaction\Client\Request\CreateTransaction;

class DigiwalletPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $transactionId = $bankUrl = $message = $result = null;
        $digiwallet = $this->module;
        $option = Tools::getValue('option');
        $method = Tools::getValue('method');
        $rtlo = Configuration::get('DIGIWALLET_RTLO');
        $token = Configuration::get('DIGIWALLET_TOKEN');
        $cart = $this->context->cart;
        $cartId = $cart->id;
        $customer = new Customer((int) ($cart->id_customer));
        $amount = $cart->getOrderTotal();
        $description = 'Cart id: '.$cartId;
        $returnUrl = Context::getContext()->link->getModuleLink('digiwallet', 'returnUrl', ['method' => $method]);
        $reportUrl = Context::getContext()->link->getModuleLink('digiwallet', 'notifyUrl', ['method' => $method]);

        if (in_array($method, ['EPS', 'GIP'])) {
            $digiwalletApi = new Client($digiwallet::DIGIWALLET_API);
            $formParams = [
                'outletId' => $rtlo,
                'currencyCode' => $digiwallet::DIGIWALLET_CURRENCY,
                'consumerEmail' => @$customer->email,
                'description' => $description,
                'returnUrl' => $returnUrl,
                'reportUrl' => $reportUrl,
                'consumerIp' => $digiwallet->getCustomerIP(),
                'suggestedLanguage' => 'NLD',
                'amountChangeable' => false,
                'inputAmount' => $amount * 100,
                'paymentMethods' => [
                    $method,
                ],
                'app_id' => DigiwalletCore::APP_ID,
            ];

            $request = new CreateTransaction($digiwalletApi, $formParams);
            $request->withBearer($token);
            /** @var \Digiwallet\Packages\Transaction\Client\Response\CreateTransaction $apiResult */
            $apiResult = $request->send();
            $result = 0 == $apiResult->status() ? true : false;
            $message = $apiResult->message();
            $transactionId = $apiResult->transactionId();
            $bankUrl = $apiResult->launchUrl();
        } else {
            $digiwalletObj = new DigiwalletCore($method, $rtlo, 'nl');
            if ($option) {
                $digiwalletObj->setBankId($option);
                $digiwalletObj->setCountryId($option);
            }
            $digiwalletObj->setAmount($amount * 100);
            $digiwalletObj->setDescription($description);
            $digiwalletObj->setReturnUrl($returnUrl);
            $digiwalletObj->setReportUrl($reportUrl);
            $digiwalletObj->bindParam('email', @$customer->email);
            if ('AFP' == $digiwalletObj->getPayMethod()) {
                $this->additionalParametersAFP($cart, $digiwalletObj);
            }
            if ('BW' == $digiwalletObj->getPayMethod()) {
                $this->additionalParametersBW($cart, $digiwalletObj);
            }

            $result = $digiwalletObj->startPayment();

            $transactionId = $digiwalletObj->getTransactionId();
            $bankUrl = $digiwalletObj->getBankUrl();
            $message = $digiwalletObj->getErrorMessage();
        }

        if (false !== $result) {
            $state = $digiwallet->getDigiwalletStatusID($digiwallet::DIGIWALLET_PENDING);
            $listMethods = $digiwallet->getListMethods();
            $digiwallet->validateOrder(
                $cartId,
                $state,
                $amount,
                $listMethods[$method]['name'],
                null,
                [
                    'transaction_id' => $transactionId,
                ],
                false,
                false,
                $cart->secure_key
            );
            if ((int) $digiwallet->currentOrder > 0) {
                $sql = sprintf(
                    'INSERT INTO `'._DB_PREFIX_."digiwallet`
                    (`order_id`, `cart_id`, `paymethod`, `rtlo`, `transaction_id`, `description`, `amount`)
                    VALUES (%d, %d, '%s', %d, '%s', '%s', '%s')",
                    $digiwallet->currentOrder,
                    $cartId,
                    $method,
                    $rtlo,
                    $transactionId,
                    $description,
                    $amount
                );
                Db::getInstance()->Execute($sql);
            }

            if ('BW' == $method) { // open an instruction page
                $bw_info = explode('|', $digiwalletObj->getMoreInformation());
                list($trxid, $accountNumber, $iban, $bic, $beneficiary, $bank) = $bw_info;
                $this->context->cookie->bw_info_trxid = $trxid;
                $this->context->cookie->bw_info_accountNumber = $accountNumber;
                $this->context->cookie->bw_info_iban = $iban;
                $this->context->cookie->bw_info_bic = $bic;
                $this->context->cookie->bw_info_beneficiary = $beneficiary;
                $this->context->cookie->bw_info_bank = $bank;
                $this->context->cookie->bw_info_order_total = $amount;
                $this->context->cookie->bw_info_email = (new Customer((int) ($cart->id_customer)))->email;
                Tools::redirectLink('index.php?fc=module&module=digiwallet&controller=bwIntro');
            } else {
                // rebuild cart
                $digiwallet->rebuildCart($digiwallet->currentOrder);
            }

            Tools::redirectLink($bankUrl);
        } else {
            $this->errors[] = $message;
            $this->redirectWithNotifications(
                $this->context->link->getPageLink(
                    'order',
                    true,
                    null,
                    [
                        'step' => '3',
                    ]
                )
            );
        }
    }

    /**
     * @param unknown $country
     * @param unknown $phone
     *
     * @return unknown
     */
    private static function formatPhone($country, $phone)
    {
        $function = 'formatPhone'.Tools::ucfirst($country);
        if (method_exists('DigiwalletPaymentModuleFrontController', $function)) {
            return self::$function($phone);
        } else {
            echo 'unknown phone formatter for country: '.$function;
            exit();
        }

        return $phone;
    }

    /**
     * @param unknown $phone
     *
     * @return string|mixed
     */
    private static function formatPhoneNld($phone)
    {
        // note: making sure we have something
        if (!isset($phone[3])) {
            return '';
        }
        // note: strip out everything but numbers
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $length = Tools::strlen($phone);
        switch ($length) {
            case 9:
                $return = '+31'.$phone;
                break;
            case 10:
                $return = '+31'.Tools::substr($phone, 1);
                break;
            case 11:
            case 12:
                $return = '+'.$phone;
                break;
            default:
                $return = $phone;
                break;
        }

        return $return;
    }

    /**
     * @param unknown $phone
     *
     * @return string|mixed
     */
    private static function formatPhoneBel($phone)
    {
        // note: making sure we have something
        if (!isset($phone[3])) {
            return '';
        }
        // note: strip out everything but numbers
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $length = Tools::strlen($phone);
        switch ($length) {
            case 9:
                $return = '+32'.$phone;
                break;
            case 10:
                $return = '+32'.Tools::substr($phone, 1);
                break;
            case 11:
            case 12:
                $return = '+'.$phone;
                break;
            default:
                $return = $phone;
                break;
        }

        return $return;
    }

    /**
     * @param unknown $street
     *
     * @return null[]|string[]|unknown[]
     */
    private static function breakDownStreet($street)
    {
        $out = [
            'street' => null,
            'houseNumber' => null,
            'houseNumberAdd' => null,
        ];
        $addressResult = null;
        preg_match("/(?P<address>\D+) (?P<number>\d+) (?P<numberAdd>.*)/", $street, $addressResult);
        if (!$addressResult) {
            preg_match("/(?P<address>\D+) (?P<number>\d+)/", $street, $addressResult);
        }
        if (empty($addressResult)) {
            $out['street'] = $street;

            return $out;
        }

        $out['street'] = array_key_exists('address', $addressResult) ? $addressResult['address'] : null;
        $out['houseNumber'] = array_key_exists('number', $addressResult) ? $addressResult['number'] : null;
        $out['houseNumberAdd'] = array_key_exists('numberAdd', $addressResult) ? trim(
            Tools::strtoupper($addressResult['numberAdd'])
        ) : null;

        return $out;
    }

    /**
     * @param unknown $order
     */
    public function additionalParametersAFP($cart, DigiwalletCore $digiwallet)
    {
        $addr_delivery = new Address((int) ($cart->id_address_delivery));
        $addr_invoice = new Address((int) ($cart->id_address_invoice));
        $customer = new Customer((int) ($cart->id_customer));

        // Supported countries are: Netherlands (NLD) and in Belgium (BEL). Belgium = 3 | Netherlands = 13
        $invoiceCountry = 3 == ($addr_invoice->id_country) ? 'BEL' : 'NLD';
        $deliveryCountry = 3 == ($addr_delivery->id_country) ? 'BEL' : 'NLD';

        $streetParts = self::breakDownStreet($addr_invoice->address1);
        $houseNo = empty($streetParts['houseNumber'].$streetParts['houseNumberAdd']) ? $addr_invoice->address1 :
            $streetParts['houseNumber'].' '.$streetParts['houseNumberAdd'];
        $digiwallet->bindParam('billingstreet', $streetParts['street']);
        $digiwallet->bindParam('billinghousenumber', $houseNo);
        $digiwallet->bindParam('billingpostalcode', $addr_invoice->postcode);
        $digiwallet->bindParam('billingcity', $addr_invoice->city);
        $digiwallet->bindParam('billingpersonemail', $customer->email);
        $digiwallet->bindParam('billingpersoninitials', '');
        $digiwallet->bindParam('billingpersongender', '');
        $digiwallet->bindParam('billingpersonfirstname', $addr_invoice->firstname);
        $digiwallet->bindParam('billingpersonsurname', $addr_invoice->lastname);
        $digiwallet->bindParam('billingcountrycode', $invoiceCountry);
        $digiwallet->bindParam('billingpersonlanguagecode', $invoiceCountry);
        $digiwallet->bindParam('billingpersonbirthdate', '');
        $digiwallet->bindParam('billingpersonphonenumber', self::formatPhone($invoiceCountry, $addr_invoice->phone));

        $streetParts = self::breakDownStreet($addr_delivery->address1);
        $houseNo = empty($streetParts['houseNumber'].$streetParts['houseNumberAdd']) ? $addr_delivery->address1 :
            $streetParts['houseNumber'].' '.$streetParts['houseNumberAdd'];
        $digiwallet->bindParam('shippingstreet', $streetParts['street']);
        $digiwallet->bindParam('shippinghousenumber', $houseNo);
        $digiwallet->bindParam('shippingpostalcode', $addr_delivery->postcode);
        $digiwallet->bindParam('shippingcity', $addr_delivery->city);
        $digiwallet->bindParam('shippingpersonemail', $customer->email);
        $digiwallet->bindParam('shippingpersoninitials', '');
        $digiwallet->bindParam('shippingpersongender', '');
        $digiwallet->bindParam('shippingpersonfirstname', $addr_delivery->firstname);
        $digiwallet->bindParam('shippingpersonsurname', $addr_delivery->lastname);
        $digiwallet->bindParam('shippingcountrycode', $deliveryCountry);
        $digiwallet->bindParam('shippingpersonlanguagecode', $deliveryCountry);
        $digiwallet->bindParam('shippingpersonbirthdate', '');
        $digiwallet->bindParam('shippingpersonphonenumber', self::formatPhone($deliveryCountry, $addr_delivery->phone));

        // Getting the items in the order
        $invoicelines = [];
        $total_amount_by_products = 0;

        // Iterating through each item in the order
        foreach ($cart->getProducts() as $product) {
            $total_amount_by_products += $product['total'];
            $invoicelines[] = [
                'productCode' => $product['id_product'],
                'productDescription' => $product['description_short'],
                'quantity' => $product['quantity'],
                'price' => $product['total'], // Price without tax
                'taxCategory' => $digiwallet->getTax($product['rate']),
            ];
        }
        // Remain invoice line to balance against grand total
        $invoicelines[] = [
            'productCode' => '000000',
            'productDescription' => 'Other fees (shipping, additional fees)',
            'quantity' => 1,
            'price' => $cart->getOrderTotal() - $total_amount_by_products,
            'taxCategory' => 3,
        ];

        $digiwallet->bindParam('invoicelines', json_encode($invoicelines));
        $digiwallet->bindParam('userip', $_SERVER['REMOTE_ADDR']);
    }

    /**
     * @param unknown $order
     */
    public function additionalParametersBW($cart, DigiwalletCore $digiwallet)
    {
        $digiwallet->bindParam('salt', $digiwallet->bwSalt);
        $digiwallet->bindParam('email', (new Customer((int) ($cart->id_customer)))->email);
        $digiwallet->bindParam('userip', $_SERVER['REMOTE_ADDR']);
    }
}
