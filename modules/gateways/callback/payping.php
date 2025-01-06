<?php
/**
 * WHMCS PayPing Payment Gateway Callback File
 *
 * This file handles the callback requests from PayPing and processes them 
 * according to WHMCS standards and PayPing API v3 documentation.
 *
 * For more information, refer to WHMCS and PayPing documentation.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

/**
 * Redirect the user to the invoice page with an optional error message
 *
 * @param string $invoiceId The ID of the invoice
 * @param string|null $errorCode Optional error code to display
 * @param string|null $errorMessage Optional error message to display
 */
function redirectWithError($invoiceId, $errorCode = null, $errorMessage = null) {
    global $CONFIG;

    // Prepare error URL parameters
    $url = "{$CONFIG['SystemURL']}/viewinvoice.php?id={$invoiceId}";

    // Add error details to the URL if provided
    if ($errorCode) {
        $url .= "&error={$errorCode}";
    }
    if ($errorMessage) {
        $url .= "&error_message=" . urlencode($errorMessage);
    }

    // Log the error (you can also log other error details here)
    logTransaction("PayPing", ['InvoiceID' => $invoiceId, 'ErrorCode' => $errorCode, 'ErrorMessage' => $errorMessage], 'Payment Error');

    // Redirect to the invoice page with the error message
    header("Location: $url");
    exit();
}   

function payping_error_message_verify( $error ){
    switch ($error){
        case 200 :
        return 'عملیات با موفقیت انجام شد';
        break ;
        case 400 :
        return 'مشکلی در ارسال درخواست وجود دارد';
        break ;
        case 500 :
        return 'مشکلی در سرور رخ داده است';
        break;
        case 503 :
        return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
        break;
        case 401 :
        return 'عدم دسترسی';
        break;
        case 403 :
        return 'دسترسی غیر مجاز';
        break;
        case 404 :
        return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
        break;
    }
}

// Decode the raw input for potential HTML entities
$input = file_get_contents('php://input');
$input = html_entity_decode($input); // Decode potential HTML entities

// Decoding URL encoded data
parse_str($input, $data);

// Decode the JSON encoded string within the 'data' parameter
$data_array = json_decode(urldecode($data['data']), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logTransaction($gatewayParams['name'], ['Input' => $input, 'Error' => json_last_error_msg()], 'Invalid Callback');
    die('Invalid callback data');
}

// Extract necessary fields from the data
$invoiceId = $data_array['clientRefId'] ?? null;
if (!$invoiceId) {
    redirectWithError($invoiceId, $data['errorCode'], 'Missing Required Data');
}

if($data['status'] == 0){
     redirectWithError($invoiceId, $data['errorCode'], payping_error_message_verify($data['errorCode']));
}

$paymentRefId = $data_array['paymentRefId'] ?? null;
$amount = $data_array['amount'] ?? null;

// Validate Invoice ID.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Construct API endpoint based on environment.
$baseUrl = $gatewayParams['serverio'] === 'yes' ? "https://api.payping.io" : "https://api.payping.ir";
$verifyUrl = "$baseUrl/v3/pay/verify";

// Prepare verification data
$verificationData = [
    'PaymentRefId' => intval($paymentRefId),
    'amount' => $amount,
];

// Initialize cURL for verification request
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $verifyUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($verificationData),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer {$gatewayParams['tokenCode']}",
    ],
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($curlError) {
    redirectWithError($invoiceId, 'payment_failed', "Connection error: $curlError");
}

$responseData = json_decode($response, true);

if ($httpCode === 200) {
    checkCbTransID($paymentRefId); // Ensure the transaction ID is unique.
    addInvoicePayment($invoiceId, $paymentRefId, $amount, 0, $gatewayModuleName); // Apply payment to the invoice.
    logTransaction($gatewayParams['name'], $responseData, 'Successful');
} elseif ($httpCode === 409) {
    logTransaction($gatewayParams['name'], $responseData, 'Duplicate Transaction');
} else {
    logTransaction($gatewayParams['name'], $responseData, 'Unsuccessful');
    redirectWithError($invoiceId, $httpCode, payping_error_message_verify($httpCode));
}

// Redirect user to the specific invoice page after successful payment.
header("Location: {$CONFIG['SystemURL']}/clientarea.php?action=viewinvoice&id={$invoiceId}");
exit;