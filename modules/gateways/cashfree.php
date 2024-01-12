<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/cashfree/sentry-php/vendor/autoload.php';

define('CASHFREE_PLUGIN_VERSION', '2.2.0', true);

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
        'DisplayName'               => 'Cashfree',
        'APIVersion'                => CASHFREE_PLUGIN_VERSION,
        'DisableLocalCredtCardInput'=> true,
        'TokenisedStorage'          => false,
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
            'Type'  => 'System',
            'Value' => 'Cashfree',
        ),
        'appId' => array(
            'FriendlyName'  => 'App Id',
            'Type'          => 'text',
            'Size'          => '50',
            'Description'   => 'Cashfree "App Id". Available <a href="https://www.cashfree.com/" target="_blank" style="bottom-border:1px dotted;">HERE</a>',
        ),
        'secretKey' => array(
            'FriendlyName'  => 'Secret Key',
            'Type'          => 'password',
            'Size'          => '50',
            'Description'   => 'Cashfree "Secret Key" shared during activation API Key',
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
    $invoice_id      = $params['invoiceid'];

    // System Parameters
    $system_url      = $params['systemurl'];
    $module_name     = $params['paymentmethod'];
    $invoice_details = WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoice_id)
        ->first();

    #check whether order is already paid or not, if paid then redirect to complete page
    if($invoice_details->status === 'Paid')
    {
        header("Location: ".$system_url."/viewinvoice.php?id=" . $invoice_id);
        
        exit;
    } 

    //Cashfree request parameters
    $cf_request                 = array();
    $cf_request['orderId']      = 'cf'.time().'_'.$invoice_id;
    $cf_request['returnUrl']    = $system_url . 'modules/gateways/cashfree/' . $module_name . '.php?order_id={order_id}';
    $cf_request['notifyUrl']    = "$system_url . 'modules/gateways/cashfree/' . $module_name . '_notify.php'";
    $mode = $cf_request['mode'] = ($params['testMode'] == 'on') ? 'sandbox' : 'production';
    $payment_session_id         = generatePaymentSession($cf_request,$params);

    $html_output = <<<EOT
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
        </head>
        <body>
            <button type="button" id="renderBtn">
            Pay Now
            </button>
        </body>
        <script>
            const cashfree = Cashfree({
                mode: "$mode"
            });
            document.getElementById("renderBtn").addEventListener("click", () => {
                cashfree.checkout({
                paymentSessionId: "$payment_session_id",
                platformName: "wh"
                });
            });
        </script>
        </html>
        EOT;
    return $html_output;
}

function generatePaymentSession($cf_request, $params)
{
    $api_endpoint = ($params['testMode'] == 'on') ? 'https://sandbox.cashfree.com/pg/orders' : 'https://api.cashfree.com/pg/orders';
    
    $get_cashfree_order_url = $api_endpoint."/".$cf_request['orderId'];
    
    $get_order = getCfOrder($params, $get_cashfree_order_url);

    if ($get_order && $get_order->order_status == "ACTIVE" &&
        $get_order->order_amount == $params['amount'] && $get_order->order_currency == $params['currency']) {
            return $get_order->payment_session_id;
    }

    $payment_session_id = createCashfreeOrder($cf_request, $params, $api_endpoint);

    if ($payment_session_id) {
        return $payment_session_id;
    } else {
        die("Unable to create your order. Please contact support.");
    }
}

function getCfOrder($params, $curl_url) {
    try {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL             => $curl_url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_ENCODING        => "",
            CURLOPT_MAXREDIRS       => 10,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST   => "GET",
            CURLOPT_HTTPHEADER      => [
                "Accept:            application/json",
                "Content-Type:      application/json",
                "x-api-version:     2022-09-01",
                "x-client-id:       ".$params['appId'],
                "x-client-secret:   ".$params['secretKey']
            ],
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($curl));
        }

        curl_close($curl);
        
        $cf_order = json_decode($response);

        if ($cf_order === null) {
            throw new Exception("Failed to decode JSON response");
        }
        return $cf_order;
    } catch (Exception $e) {
        \Sentry\init([
            'dsn' => 'https://d8571a24c9b11025a1471d5e6aee55ce@o330525.ingest.sentry.io/4506552108187648',
            'release' => CASHFREE_PLUGIN_VERSION . '@whmcs',
            'environment' => $cf_request['mode'],
        ]);
        \Sentry\captureException($e);
    }
    
}

function createCashfreeOrder($cf_request, $params, $api_endpoint) {
    try {
        $customer_details = array(
            "customer_id"       => "WhmcsCustomer",
            "customer_email"    => $params['clientdetails']['email'],
            "customer_name"     => $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'],
            "customer_phone"    => $params['clientdetails']['phonenumber']
        );
        $order_meta = array(
            "return_url"        => $cf_request['returnUrl'],
            "notify_url"        => $cf_request['notifyUrl']
        );
        $request = array(
            "customer_details"  => $customer_details,
            "order_id"          => $cf_request['orderId'],
            "order_amount"      => $params['amount'],
            "order_currency"    => $params['currency'],
            "order_note"        => "WHMCS Order",
            "order_meta"        => $order_meta
        );

        $curl_postfield = json_encode($request);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL             => $api_endpoint,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_ENCODING        => "",
            CURLOPT_MAXREDIRS       => 10,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST   => "POST",
            CURLOPT_POSTFIELDS      => $curl_postfield,
            CURLOPT_HTTPHEADER      => [
                "Accept:            application/json",
                "Content-Type:      application/json",
                "x-api-version:     2022-09-01",
                "x-client-id:       ".$params['appId'],
                "x-client-secret:   ".$params['secretKey']
            ],
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($curl));
        }

        curl_close($curl);
        
        $cf_order = json_decode($response);

        if ($cf_order && !empty($cf_order->payment_session_id)) {
            return $cf_order->payment_session_id;
        } else {
            return null;
        }
    } catch (Exception $e) {

        \Sentry\init([
            'dsn' => 'https://d8571a24c9b11025a1471d5e6aee55ce@o330525.ingest.sentry.io/4506552108187648',
            'release' => CASHFREE_PLUGIN_VERSION . '@whmcs',
            'environment' => $cf_request['mode'],
        ]);
        Sentry\captureException($e);
    }

}
