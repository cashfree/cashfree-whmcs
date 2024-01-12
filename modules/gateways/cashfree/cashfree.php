<?php
die;
/**
 * WHMCS Cashfree Payment Callback File
 *
 * Verifying that the payment gateway module is active,
 * Validating an Invoice ID, Checking for the existence of a Transaction ID,
 * Logging the Transaction for debugging and Adding Payment to an Invoice.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/sentry-php/vendor/autoload.php';

use WHMCS\Database\Capsule;
function checkTransIdExist($transaction_id) {
    $transaction_details = Capsule::table('tblaccounts')
        ->where('transid', $transaction_id)
        ->first();
    
    return ($transaction_details) ? true : false;
}

// Detect module name from filename.
$gateway_module_name = 'cashfree';

// Fetch gateway configuration parameters.
$gateway_params = getGatewayVariables($gateway_module_name);
$mode = ($params['testMode'] == 'on') ? 'sandbox' : 'production';

// Die if module is not active.
if (!$gateway_params['type']) {
    die("Module Not Activated");
}

$app_id      = $gateway_params["appId"];
$secret_key  = $gateway_params["secretKey"];

//Gateway response parameters
$cashfree_order_id    = $_REQUEST["order_id"];
$invoice_id          = substr($cashfree_order_id, strpos($cashfree_order_id, "_") + 1);

// Validate Callback Invoice ID.
$invoice_id = checkCbInvoiceID($invoice_id, $gateway_params['name']);

$error = "";

try {
    $api_endpoint = ($gateway_params["testMode"] == 'on') ? 'https://sandbox.cashfree.com/pg/orders' : 'https://api.cashfree.com/pg/orders'; 
    
    $get_payment_url = $api_endpoint . "/" . $cashfree_order_id . "/payments";

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL             => $get_payment_url,
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
            "x-client-id:       " . $app_id,
            "x-client-secret:   " . $secret_key
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
} catch (Exception $e) {
    $success = false;
    $error = "WHMCS_ERROR: " . $e->getMessage(); // Get the specific error message
    \Sentry\init([
        'dsn' => 'https://d8571a24c9b11025a1471d5e6aee55ce@o330525.ingest.sentry.io/4506552108187648',
        'release' => CASHFREE_PLUGIN_VERSION . '@whmcs',
        'environment' => $mode,
    ]);
    Sentry\captureException($e);
    $cf_order = null;
}

if (null !== $cf_order && !empty($cf_order[0]->payment_status))
{
    if($cf_order[0]->payment_status == 'SUCCESS')
    {
        $transaction_id = $cf_order[0]->cf_payment_id;
        $paymentAmount = $cf_order[0]->order_amount;
        $success = true;
    }
    else {
        $success = false;
        $error = $cf_order[0]->payment_message;
    }

} else {
    if(!empty($cf_order->message)) {
        $error = $cf_order->message;
    } else {
        $error = "Unable to process your order. Please contact support.";
    }
    $success = false;

}


//Check if payment successfully paid

if ($success === true)
{
    /**
     * Check Callback Transaction ID.
     *
     * Performs a check for any existing transactions with the same given
     * transaction number.
     *
     * Performs a die upon encountering a duplicate.

    * @param string $transaction_id
    */
    # Apply Payment to Invoice: invoiceid, transaction_id, amount paid, fees, modulename
    if (!checkTransIdExist($transaction_id)){
        $invoice_details = Capsule::table('tblinvoices')
            ->where('id', $invoice_id)
            ->first();

        $amount = $invoice_details->total;
        
        addInvoicePayment($invoice_id, $transaction_id, $amount, 0, $gateway_params["name"]);
        
        # Successful
        # Save to Gateway Log: name, data array, status
        logTransaction($gateway_params["name"], $cf_order[0], "Successful");
    }

    header("Location: ".$gateway_params['systemurl']."viewinvoice.php?id=" . $invoice_id."&paymentsuccess=true");
}
else 
{
    # Save to Gateway Log: name, data array, status
    logTransaction($gateway_params["name"], $_REQUEST["order_id"], "Unsuccessful-".$error . ". Please check cashfree dashboard for order id: ".$cashfree_order_id);

    header("Location: ".$gateway_params['systemurl']."viewinvoice.php?id=" . $invoice_id."&paymentfailed=true");
}