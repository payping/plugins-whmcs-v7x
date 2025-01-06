<?php
/**
 * WHMCS PayPing Payment Gateway Module
 *
 * Payment Gateway modules allow you to integrate payment solutions with the
 * WHMCS platform.
 *
 * This PayPing file demonstrates how a payment gateway module for WHMCS should
 * be structured and all supported functionality it can contain.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For this
 * example file, the filename is "gatewaymodule" and therefore all functions
 * begin "gatewaymodule_".
 *
 * If your module or third party API does not support a given function, you
 * should not define that function within your module. Only the _config
 * function is required.
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 *
 * @copyright Copyright (c) WHMCS Limited 2025
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
function payping_config() {
    return [
        "FriendlyName" => [
            "Type" => "System", 
            "FriendlyName" => "عنوان درگاه", 
            "Value" => "پی‌پینگ"
        ],
        "tokenCode" => [
            "FriendlyName" => "کد توکن اختصاصی", 
            "Type" => "text", 
            "Size" => "80"
        ],
        "Currencies" => [
            "FriendlyName" => "واحد مالی", 
            "Type" => "dropdown", 
            "Options" => "ریال,تومان"
        ],
        "serverio" => [
            "FriendlyName" => "سرور خارج", 
            "Type" => "yesno", 
            "Description" => "برای اتصال به سرور خارج فعال شود", 
            "Default" => "no"
        ],
    ];
}

function payping_status_message($errorCode) {
    $messages = [
        200 => 'عملیات با موفقیت انجام شد',
        400 => 'مشکلی در ارسال درخواست وجود دارد',
        500 => 'مشکلی در سرور رخ داده است',
        503 => 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد',
        401 => 'عدم دسترسی',
        403 => 'دسترسی غیر مجاز',
        404 => 'آیتم درخواستی مورد نظر موجود نمی‌باشد'
    ];

    return $messages[$errorCode] ?? 'خطای ناشناخته';
}

function payping_link($params) {
    $tokenCode = $params['tokenCode'];
    $currencies = $params['Currencies'];
    $baseurl = $params['serverio'] === 'yes' ? "api.payping.io" : "api.payping.ir";

    $invoiceid = strval($params['invoiceid']);
    $amount = intval($params['amount']);
    $systemurl = $params['systemurl'];

    if ($currencies === 'ریال') {
        $amount = round($amount / 10);
    }

    $CallbackURL = "{$systemurl}modules/gateways/callback/payping.php?Amount={$amount}";
    $data = [
        'Amount' => $amount,
        'ReturnUrl' => $CallbackURL,
        'PayerIdentity' => $params['clientdetails']['email'],
        'PayerName' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'Description' => "Invoice ID: {$invoiceid}",
        'ClientRefId' => $invoiceid
    ];

    try {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://{$baseurl}/v3/pay",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Bearer {$tokenCode}",
                "content-type: application/json"
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return "خطای cURL: {$err}";
        }

        if ($httpCode === 200) {
            $response = json_decode($response, true);
            if (!empty($response["paymentCode"])) {
                $link = "https://{$baseurl}/v3/pay/start/{$response['paymentCode']}";
                return '<form method="get" action="' . $link . '"><input type="submit" value=" پرداخت " /></form>';
            } else {
                return 'تراکنش ناموفق بود - شرح خطا: عدم وجود کد ارجاع';
            }
        } 

        $errorMessage = payping_status_message($httpCode);
        return "تراکنش ناموفق بود - شرح خطا: {$errorMessage} ({$httpCode})";

    } catch (Exception $e) {
        return 'تراکنش ناموفق بود - شرح خطا سمت برنامه شما: ' . $e->getMessage();
    }
}