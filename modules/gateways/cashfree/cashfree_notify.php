<?php
// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$gateway_module_name = 'cashfree';

// Fetch gateway configuration parameters.
$gateway_params  = getGatewayVariables($gateway_module_name);
$secret_key      = $gateway_params["secretKey"];

//Gateway response parameters
$cashfree_order_id  = $_POST["orderId"];
$invoice_id         = substr($cashfree_order_id, strpos($cashfree_order_id, "_") + 1);
$transaction_id     = $_POST['referenceId'];

$invoice_details = Capsule::table('tblinvoices')
            ->where('id', $invoice_id)
            ->first();
//Execute notify url after 30 sec of execution return url
// sleep(30);
if($invoice_details->status === 'Paid')
{
    exit;
}

$success = false;
$error = "";

try {
    $data = "{$_POST['orderId']}{$_POST['orderAmount']}{$_POST['referenceId']}{$_POST['txStatus']}{$_POST['paymentMode']}{$_POST['txMsg']}{$_POST['txTime']}";
    $hash_hmac = hash_hmac('sha256', $data, $secret_key, true) ;
    $computed_signature = base64_encode($hash_hmac);
    
    if ($_POST["signature"] != $computed_signature)
    {
        $success = false;
        $error = 'CASHFREE_ERROR:Invalid Signature';
    }
    else
    {
        $success = true;
    }

} catch (Exception $e) {
    $success = false;
    $error ="WHMCS_ERROR:Request to Cashfree Failed";
}

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.

 * @param string $transaction_id
 */

checkCbTransID($transaction_id);

$amount = $invoice_details->total;

# Apply Payment to Invoice: invoice_id, transaction_id, amount paid, fees, modulename
if ($success === true)
{
    # Successful
    addInvoicePayment($invoice_id, $transaction_id, $amount, 0, $gateway_params["name"]);
    # Save to Gateway Log: name, data array, status
    logTransaction($gateway_params["name"], $_POST, "Webhook successfully executed.");
}
else
{
    # Unsuccessful
    Capsule::table('tblinvoices')
        ->where('id', $invoice_id)
        ->update(array(
            'status' => 'Unpaid'
        ));
    # Save to Gateway Log: name, data array, status
    logTransaction($gateway_params["name"], $_POST, "Webhook successfully execute with Error - ".$error . ". Please check cashfree dashboard for order id: ".$cashfree_order_id);
}
exit;