<?php
/*
Plugin Name: افزونه پرداخت پی‌پینگ برای WHMCS
Version: 1.0
Description:  افزونه درگاه پرداخت پی‌پینگ برای WHMCS
Plugin URI: https://www.payping.ir/
Author: Erfan Ebrahimi
Author URI: http://erfanebrahimi.ir/

*/
function payping_config() {
    $configarray = array(
     "FriendlyName" => array("Type" => "System", "FriendlyName" => "عنوان درگاه", "Value"=>"پی‌پینگ"),
     "tokenCode" => array("FriendlyName" => "کد توکن اختصاصی", "Type" => "text", "Size" => "80", ),
     "Currencies" => array("FriendlyName" => "واحد مالی", "Type" => "dropdown", "Options" => "ریال,تومان", ),
     );
	return $configarray;
}

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

function payping_link($params) {

	# Gateway Specific Variables
	$tokenCode = $params['tokenCode'];
    $currencies = $params['Currencies'];
    
	# Invoice Variables
	$invoiceid = $params['invoiceid'];
	$description = $params["description"];
    $amount = $params['amount']; # Format: ##.##
    $currency = $params['currency']; # Currency Code

	# Client Variables
	$firstname = $params['clientdetails']['firstname'];
	$lastname = $params['clientdetails']['lastname'];
	$email = $params['clientdetails']['email'];
	$address1 = $params['clientdetails']['address1'];
	$address2 = $params['clientdetails']['address2'];
	$city = $params['clientdetails']['city'];
	$state = $params['clientdetails']['state'];
	$postcode = $params['clientdetails']['postcode'];
	$country = $params['clientdetails']['country'];
	$phone = $params['clientdetails']['phonenumber'];

	# System Variables
	$companyname = $params['companyname'];
	$systemurl = $params['systemurl'];
	$currency = $params['currency'];


	$amount = intval($amount);
	if($currencies == 'ریال'){
		$amount = round($amount/10);
	}

	$CallbackURL = $systemurl . '/modules/gateways/callback/payping.php?Amount='. $amount;
	$data = array('payerName'=>$firstname .' '.$lastname, 'Amount' => $amount,'payerIdentity'=> $email , 'returnUrl' => $CallbackURL, 'Description' => 'Invoice ID: '. $invoiceid , 'clientRefId' => $invoiceid  );


	try {
		$curl = curl_init();

		curl_setopt_array($curl, array(CURLOPT_URL => "https://api.payping.ir/v2/pay",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => json_encode($data),
			CURLOPT_HTTPHEADER => array(
				"accept: application/json",
				"authorization: Bearer " . $tokenCode,
				"cache-control: no-cache",
				"content-type: application/json"),
			)
		);

		$response = curl_exec($curl);
		$header = curl_getinfo($curl);
		$err = curl_error($curl);
		curl_close($curl);

		if ($err) {
			$return =  "cURL Error #:" . $err;
		} else {
			if ($header['http_code'] == 200) {
				$response = json_decode($response, true);
				if (isset($response["code"]) and $response["code"] != '') {
					$link = sprintf('https://api.payping.ir/v2/pay/gotoipg/%s', $response["code"]);
					$return = '<form method="get" action="'.$link.'"><input type="submit" value=" پرداخت " /></form>';
				} else {
					$return = ' تراکنش ناموفق بود- شرح خطا : عدم وجود کد ارجاع ';
				}
			} elseif ($header['http_code'] == 400) {
				$return = ' تراکنش ناموفق بود- شرح خطا : ' . implode('. ',array_values (json_decode($response,true)));
			} else {
				$return = ' تراکنش ناموفق بود- شرح خطا : ' . payping_status_message($header['http_code']) . '(' . $header['http_code'] . ')';
			}
		}
	} catch (Exception $e){
		$return = ' تراکنش ناموفق بود- شرح خطا سمت برنامه شما : ' . $e->getMessage();
	}

	return $return;
}