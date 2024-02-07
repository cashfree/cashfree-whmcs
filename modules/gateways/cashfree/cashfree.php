<?php
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

define('API_VERSION', '2022-09-01');

function checkTransIdExist($transaction_id) {
    $result = select_query("tblaccounts", "id", array("transid" => $transaction_id));
    $numRows = mysql_num_rows($result);
    if ($numRows) {
        return true;
    }
    return false;
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

$app_id      = $gateway_params['appId'];
$secret_key  = $gateway_params['secretKey'];

//Gateway response parameters
$cashfree_order_id    = $_REQUEST['order_id'];
$invoice_id          = substr($cashfree_order_id, strpos($cashfree_order_id, "_") + 1);

// Validate Callback Invoice ID.
$invoice_id = checkCbInvoiceID($invoice_id, $gateway_params['name']);

$error = "";


$api_endpoint = ($gateway_params['testMode'] == 'on') ? 'https://sandbox.cashfree.com/pg/orders' : 'https://api.cashfree.com/pg/orders'; 

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
        "x-api-version:     " . API_VERSION,
        "x-client-id:       " . $app_id,
        "x-client-secret:   " . $secret_key
    ],
]);

$response = curl_exec($curl);

curl_close($curl);

$cf_order = json_decode($response);

$result = mysql_fetch_assoc(select_query('tblinvoices', '*', array("id" => $invoice_id)));
$invoice_amount = $result['total'];

if (is_object($cf_order) && isset($cf_order->message)) {
    $success = false;
    $error = $cf_order->message;
} elseif (is_array($cf_order) && isset($cf_order[0]) && isset($cf_order[0]->payment_status)) {
    if($cf_order[0]->payment_status == 'SUCCESS')
    {
        $transaction_id = $cf_order[0]->cf_payment_id;
        $cf_order_amount = $cf_order[0]->order_amount;
        if(round($invoice_amount, 2) == round($cf_order_amount, 2)) {
            $success = true;
        } else {
            $error = 'Amount Mismatched';
            $success = false;
        }
    } else {
        $success = false;
        $error = $cf_order[0]->payment_message;
    }
} else {
    // Handle other cases or errors
    $error = "Unable to process your order. Please contact support.";
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
    // var_dump(checkTransIdExist($transaction_id));
    // die;
    if (!checkTransIdExist($transaction_id)){
        addInvoicePayment($invoice_id, $transaction_id, $invoice_amount, 0, $gateway_params["name"]);
        
        # Successful
        # Save to Gateway Log: name, data array, status
        logTransaction($gateway_params['name'], $cf_order[0], "Successful");
    }
    header("Location: ".$gateway_params['systemurl']."viewinvoice.php?id=" . $invoice_id."&paymentsuccess=true");
}
else 
{
    # Save to Gateway Log: name, data array, status
    logTransaction($gateway_params['name'], $_REQUEST['order_id'], "Unsuccessful-".$error . ". Please check cashfree dashboard for order id: ".$cashfree_order_id);

    header("Location: ".$gateway_params['systemurl']."viewinvoice.php?id=" . $invoice_id."&paymentfailed=true");
}