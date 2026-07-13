<?php
/**
 * ipn.php
 *
 * Copyright (c) 2026 LinuxISP (Pty) Ltd
 * You (being anyone who is not LinuxISP (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 * 
 * @author     Ruben Venter (ruben@linuxweb.co.za)
 * @version    1.0.0
 * @date       13/07/2026
 *
 * @link       https://github.com/Linuxweb/Payfast-ThirtyBees/
 */

//Make sure the module is installed on thirtybees
if (!defined('_TB_VERSION_') && !defined('_PS_VERSION_')) {
    http_response_code(400);
    exit;
}

//Main class
class PayfastIpnModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;
    public $ssl = true;

    public function postProcess()
    {
        // Ensure we always return 200 to PayFast quickly
        header('HTTP/1.1 200 OK');

        // Read POST safely
        $pfData = $_POST;

        if (empty($pfData)) {
            exit;
        }

        // Security check flags
        $bSigPassed    = false;
        $bDomainPassed = false;
        $bComparePassed = false;
        $bServerPassed = false;

        // ----------------------------------------------------------------
        // 1. Signature check
        // ----------------------------------------------------------------
        $pfPassphrase = Configuration::get('PAYFAST_PASSPHRASE', null);
        if ($pfPassphrase === false) {
            $pfPassphrase = null;
        }

        $pfParamString = '';
        foreach ($pfData as $key => $val) {
            if ($key === 'signature') {
                continue;
            }
            $pfParamString .= $key . '=' . urlencode(trim($val)) . '&';
        }
        $pfParamString = rtrim($pfParamString, '&');

        $tempParamString = $pfParamString;
        if (!empty($pfPassphrase)) {
            $tempParamString .= '&passphrase=' . urlencode($pfPassphrase);
        }

        $calculatedSignature = md5($tempParamString);
        $receivedSignature   = isset($pfData['signature']) ? $pfData['signature'] : '';
        $bSigPassed = ($receivedSignature === $calculatedSignature);

        // ----------------------------------------------------------------
        // 2. Domain / IP check
        // ----------------------------------------------------------------
        $pfMode = Configuration::get('PAYFAST_MODE', 'sandbox');
        $pfHost = ($pfMode === 'live') ? 'www.payfast.co.za' : 'sandbox.payfast.co.za';

        $validHosts = ['www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za'];
        $validIps   = [];
        foreach ($validHosts as $host) {
            $ips = @gethostbynamel($host);
            if ($ips !== false) {
                $validIps = array_merge($validIps, $ips);
            }
        }
        $validIps = array_unique($validIps);

        $remoteIp     = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $bDomainPassed = in_array($remoteIp, $validIps, true);

        // ----------------------------------------------------------------
        // 3. Amount comparison
        // getOrderTotal() returns the total in the cart's own currency (e.g.
        // USD), not always ZAR. PayFast's amount_gross is always ZAR (it's
        // the only currency PayFast accepts), so convert the cart total to
        // ZAR the same way payment.php did before comparing.
        // ----------------------------------------------------------------
        $m_payment_id = isset($pfData['m_payment_id']) ? $pfData['m_payment_id'] : null;
        $postedAmount = isset($pfData['amount_gross']) ? (float)$pfData['amount_gross'] : null;

        $cartTotalOwnCurrency = null;

        if ($m_payment_id !== null && $postedAmount !== null) {
            try {
                $cart = new Cart((int)$m_payment_id);
                if (Validate::isLoadedObject($cart)) {
                    $cartCurrency = new Currency((int)$cart->id_currency);

                    // getOrderTotal() prices the cart using the active Context
                    // currency, not $cart->id_currency. This IPN request comes
                    // straight from PayFast's server with no customer session,
                    // so Context defaults to the shop's default currency (ZAR)
                    // unless we point it at the cart's own currency here. Without
                    // this, the cart re-prices at conversion rate 1 (i.e. the
                    // raw, unconverted default-currency price).
                    $this->context->currency = $cartCurrency;

                    $cartTotalOwnCurrency = (float)$cart->getOrderTotal(true, Cart::BOTH);

                    $zarCurrency  = new Currency((int)Currency::getIdByIsoCode('ZAR'));
                    $cartTotalZAR = (float)number_format(
                        Tools::convertPriceFull($cartTotalOwnCurrency, $cartCurrency, $zarCurrency),
                        2, '.', ''
                    );

                    $bComparePassed = (abs($cartTotalZAR - $postedAmount) <= 0.50);
                }
            } catch (Exception $e) {
                // Comparison failed; $bComparePassed stays false and the IPN is rejected below.
            }
        }

        // ----------------------------------------------------------------
        // 4. Server-side validation with PayFast
        // ----------------------------------------------------------------
        if (in_array('curl', get_loaded_extensions(), true)) {
            $url = 'https://' . $pfHost . '/eng/query/validate';
            $ch  = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $pfParamString);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response !== false && trim($response) === 'VALID') {
                $bServerPassed = true;
            }
        }

        // ----------------------------------------------------------------
        // 5. Create order if all checks pass
        // ----------------------------------------------------------------
        if ($bSigPassed && $bDomainPassed && $bComparePassed && $bServerPassed) {

            $cart     = new Cart((int)$m_payment_id);
            $customer = new Customer($cart->id_customer);
            $order_id = Order::getOrderByCartId($cart->id);

            if (!$order_id) {
                // Record the order in the cart's own currency (e.g. USD), not
                // in ZAR. ZAR is only what PayFast, as a gateway, requires -
                // it must never become the order's currency of record, or
                // ThirtyBees will re-price the order's products in ZAR at a
                // 1:1 rate (the unconverted base price) instead of showing
                // what the customer actually agreed to pay.
                $orderCurrencyId = (int)$cart->id_currency;
                $orderAmount = (float)number_format($cartTotalOwnCurrency, 2, '.', '');

                try {
                    $this->module->validateOrder(
                        $cart->id,
                        Configuration::get('PS_OS_PAYMENT'),
                        $orderAmount,
                        $this->module->displayName,
                        'PayFast payment successful',
                        [],
                        $orderCurrencyId,
                        false,
                        $customer->secure_key
                    );
                } catch (Exception $e) {
                    // Order creation failed; $order_id stays unset/false below.
                }

                $order_id = Order::getOrderByCartId($cart->id);
            }
        }
        exit;
    }
}
