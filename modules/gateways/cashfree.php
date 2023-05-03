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
        'APIVersion' => '1.0.2',
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
    // Invoice Parameters
    $invoiceId      = $params['invoiceid'];

    // System Parameters
    $systemUrl      = $params['systemurl'];
    $moduleName     = $params['paymentmethod'];

    //Cashfree request parameters
    $cf_request                     = array();
    $cf_request['orderId']          = 'cashfreeWhmcs_'.$invoiceId;
    $cf_request['returnUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php?order_id={order_id}&order_token={order_token}';
    $cf_request['notifyUrl']        = $systemUrl . 'modules/gateways/callback/' . $moduleName . '_notify.php';
    $payment_link                   = generatePaymentLink($cf_request,$params);

    $langPayNow = $params['langpaynow'];
    $htmlOutput = '<form method="post" action="' . $payment_link . '">';
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

function generatePaymentLink($cf_request, $params)
{
    $apiEndpoint = ($params['testMode'] == 'on') ? 'https://sandbox.cashfree.com/pg/orders' : 'https://api.cashfree.com/pg/orders';
    $getCashfreeOrderUrl = $apiEndpoint."/".$cf_request['orderId'];
    
    $getOrder = getCfOrder($params, $getCashfreeOrderUrl);

    if (null !== $getOrder && $getOrder->order_status == "ACTIVE" &&
        $getOrder->order_amount == $params['amount'] && $getOrder->order_currency == $params['currency']) {
            return $getOrder->payment_link;
    }

    $request = array(
        "customer_details"      => array(
            "customer_id"       => "WhmcsCustomer",
            "customer_email"    => $params['clientdetails']['email'],
            "customer_name"     => $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'],
            "customer_phone"    => $params['clientdetails']['phonenumber']
        ),
        "order_id"              => $cf_request['orderId'],
        "order_amount"          => $params['amount'],
        "order_currency"        => $params['currency'],
        "order_note"            => "WHMCS Order",
        "order_meta"            => array(
            "return_url"        => $cf_request['returnUrl'],
            "notify_url"        => $cf_request['notifyUrl']
        )
    );

    $curlPostfield = json_encode($request);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL             => $apiEndpoint,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => "",
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST   => "POST",
        CURLOPT_POSTFIELDS      => $curlPostfield,
        CURLOPT_HTTPHEADER      => [
            "Accept:            application/json",
            "Content-Type:      application/json",
            "x-api-version:     2022-01-01",
            "x-client-id:       ".$params['appId'],
            "x-client-secret:   ".$params['secretKey'],
            "x-idempotency-key: ".$cf_request['orderId']
        ],
    ]);

    $response = curl_exec($curl);
    
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        die("Unable to create your order. Please contact support.");
    }
    
    $cfOrder = json_decode($response);

    if (null !== $cfOrder && !empty($cfOrder->order_token))
    {        
        return $cfOrder->payment_link;
    } else {
        if(!empty($cfOrder->message)) {
            die($cfOrder->message);
        } else {
            die("Unable to create your order. Please contact support.");
        }
    }
}

function getCfOrder($params, $curlUrl) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL             => $curlUrl,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => "",
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST   => "GET",
        CURLOPT_HTTPHEADER      => [
            "Accept:            application/json",
            "Content-Type:      application/json",
            "x-api-version:     2022-01-01",
            "x-client-id:       ".$params['appId'],
            "x-client-secret:   ".$params['secretKey']
        ],
    ]);

    $getOrderResponse = curl_exec($curl);
    
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        die("Unable to create your order. Please contact support.");
    }
    
    return json_decode($getOrderResponse);
}