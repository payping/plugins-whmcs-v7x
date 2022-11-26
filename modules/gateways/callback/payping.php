<?php
    
	# Required File Includes
if(file_exists('../../../init.php'))
{
require( '../../../init.php' );

}else{

require("../../../dbconnect.php");
}
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

	$gatewaymodule = 'payping'; # Enter your gateway module name here replacing template

	$GATEWAY = getGatewayVariables($gatewaymodule);
	if (!$GATEWAY['type']) die('Module Not Activated'); # Checks gateway module is active before accepting callback

	# Get Returned Variables - Adjust for Post Variable Names from your Gateway's Documentation
	$invoiceid  = $_REQUEST['clientrefid'];
	$Amount 	= $_REQUEST['Amount'];
	$refid      = $_REQUEST['refid'];
	$invoiceid  = checkCbInvoiceID($invoiceid, $GATEWAY['name']); # Checks invoice ID is a valid invoice number or ends processing

if ( ! function_exists('payping_status_message')){
	function payping_status_message($code) {
		switch ($code){
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
		return null;
	}
}

$serverio = $GATEWAY['serverio']; if($serverio == 'yes'){ $baseurl = "api.payping.io"; }else{ $baseurl = "api.payping.ir"; }
$data = array('refId' => $_REQUEST['refid'], 'amount' => $Amount);
try {
	$curl = curl_init();
	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://$baseurl/v2/pay/verify",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode($data),
		CURLOPT_HTTPHEADER => array(
			"accept: application/json",
			"authorization: Bearer ".$GATEWAY['tokenCode'],
			"cache-control: no-cache",
			"content-type: application/json",
		),
	));
	$response = curl_exec($curl);
	$err = curl_error($curl);
	$header = curl_getinfo($curl);
	curl_close($curl);

	if ($err) {
		logTransaction($GATEWAY['name'], array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'Curl Error: '.$err), 'Unsuccessful'); # Save to Gateway Log: name, data array, status
	} else {
		if ($header['http_code'] == 200) {
			$response = json_decode($response, true);
			if (isset($_REQUEST["refid"]) and $_REQUEST["refid"] != '') {
				$transid = $_REQUEST["refid"] ;
				if($GATEWAY['Currencies'] == 'ریال'){
					$Amount  *= 10;
				}
				checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does
				addInvoicePayment($invoiceid, $transid, $Amount, 0, $gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
				logTransaction($GATEWAY['name'], array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,), 'Successful'); # Save to Gateway Log: name, data array, status
			} else {
				logTransaction($GATEWAY['name'], array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'refid is empty'), 'Unsuccessful'); # Save to Gateway Log: name, data array, status
			}
		} else {
			logTransaction($GATEWAY['name'], array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => payping_status_message($header['http_code']) . '(' . $header['http_code'] . ')' ), 'Unsuccessful'); # Save to Gateway Log: name, data array, status
		}

	}
} catch (Exception $e){
	logTransaction($GATEWAY['name'], array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'connection Error : '.$e->getMessage()), 'Unsuccessful'); # Save to Gateway Log: name, data array, status
}
	Header('Location: '.$CONFIG['SystemURL'].'/clientarea.php?action=invoices');
  exit;