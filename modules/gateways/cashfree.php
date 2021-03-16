<?php
/**
 * WHMCS Cashfree Payment Gateway Module
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
    $cf_request['orderId']          = 'cashfreeWhmcs_'.$invoiceId;
    $cf_request['orderAmount']      = $amount;
    $cf_request['orderCurrency']    = $currencyCode;
    $cf_request['orderNote']        = $invoiceId;
    $cf_request['customerName']     = $firstname.' '.$lastname;
    $cf_request['customerEmail']    = $email;
    $cf_request['customerPhone']    = $phone;
    $cf_request['returnUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php';
    $cf_request['notifyUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '_notify.php';
    $cf_request['source']           = "whmcs";
    $cf_request['signature']        = generateCashfreeSignature($cf_request,$params);

    $langPayNow = $params['langpaynow'];
    $apiEndpoint = ($params['testMode'] == 'on') ? 'https://test.cashfree.com/billpay' : 'https://www.cashfree.com';  
    $url = $apiEndpoint."/checkout/post/submit";
    $htmlOutput = '<form method="post" action="' . $url . '">';
    foreach ($cf_request as $k => $v) {
        $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . ($v) . '" />';
    }
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

function generateCashfreeSignature($cf_request, $params)
{
    // get secret key from your config
    $secretKey      = $params['secretKey'];
    ksort($cf_request);
    $signatureData = "";
    foreach ($cf_request as $key => $value){
        $signatureData .= $key.$value;
    }
    
    $signature = hash_hmac('sha256', $signatureData, $secretKey,true);
    $signature = base64_encode($signature);
    return $signature;
}