<?php
/**
 * WHMCS Casfree Payment Gateway Module
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
/**
 * Define module related meta data.
 * @return array
 */
function cashfree_MetaData()
{
    return array(
        'DisplayName' => 'Cashfree',
        'APIVersion' => '1.0.0',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    );
}
/**
 * Define Cashfree gateway configuration options.
 * @return array
 */
function cashfree_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Cashfree',
        ),
        'appId' => array(
            'FriendlyName' => 'App Id',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Cashfree "App Id". Available <a href="https://www.cashfree.com/" target="_blank" style="bottom-border:1px dotted;">HERE</a>',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Cashfree "Secret Key" shared during activation API Key',
        ),
        'themeLogo' => array(
            'FriendlyName' => 'Logo URL',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'ONLY "http<strong>s</strong>://"; else leave blank.<br/><small>Size: 128px X 128px (or higher) | File Type: png/jpg/gif/ico</small>',
        ),
        'themeColor' => array(
            'FriendlyName' => 'Theme Color',
            'Type' => 'text',
            'Size' => '15',
            'Default' => '#15A4D3',
            'Description' => 'The colour of checkout form elements',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
    );
}
/**
 * Payment link.
 * Required by third party payment gateway modules only.
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function cashfree_link($params)
{
    // Gateway Configuration Parameters
    $appId          = $params['appId'];
    $secretKey      = $params['secretKey'];
    $testMode       = $params['testMode'];
    
    // Invoice Parameters
    $invoiceId      = $params['invoiceid'];
    $amount         = $params['amount'];
    $currencyCode   = $params['currency'];
    
    // Client Parameters
    $firstname      = $params['clientdetails']['firstname'];
    $lastname       = $params['clientdetails']['lastname'];
    $email          = $params['clientdetails']['email'];
    $phone          = $params['clientdetails']['phonenumber'];

    // System Parameters
    $systemUrl      = $params['systemurl'];
    $returnUrl      = $params['returnurl'];
    $moduleName     = $params['paymentmethod'];

    //Cashfree request parameters
    $cf_request                     = array();
    $cf_request['appId']            = $appId;
    $cf_request['secretKey']        = $secretKey;
    $cf_request['orderId']          = 'cashfreeWhmcs_'.$invoiceId;
    $cf_request['orderAmount']      = $amount;
    $cf_request['orderCurrency']    = $currencyCode;
    $cf_request['orderNote']        = $invoiceId;
    $cf_request['customerName']     = $firstname.' '.$lastname;
    $cf_request['customerEmail']    = $email;
    $cf_request['customerPhone']    = $phone;
    $cf_request['callback_url']     = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php';
    $cf_request['returnUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php';
    $cf_request['notifyUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '_notify.php';
    $cf_request['source']           = "whmcs";

    //Request to cashfree pg api for payment
    try
    {
        $apiEndpoint = ($params['testMode'] == 'on') ? 'https://test.cashfree.com' : 'https://api.cashfree.com';  				
		
        $opUrl = $apiEndpoint."/api/v1/order/create";
        $timeout = 10;
    
        $request_string = "";
        foreach($cf_request as $key=>$value) {
            $request_string .= $key.'='.rawurlencode($value).'&';
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"$opUrl?");
        curl_setopt($ch,CURLOPT_POST, count($cf_request));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $request_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $curl_result=curl_exec ($ch);
        curl_close ($ch);
        $jsonResponse = json_decode($curl_result);
        header("Location: ". $jsonResponse->paymentLink);
        exit;
    }
    catch (Exception $e)
    {
        return $e;
    }
}