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

// Detect module name from filename.
$gatewayModuleName = 'cashfree';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$appId      = $gatewayParams["appId"];
$secretKey  = $gatewayParams["secretKey"];

//Gateway response parameters
$cashfreeOrderId    = $_REQUEST["order_id"];
$invoiceId          = substr($cashfreeOrderId, strpos($cashfreeOrderId, "_") + 1);

// Validate Callback Invoice ID.
$invoiceId  = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
$error      = "";

try {
    $apiEndpoint = ($gatewayParams["testMode"] == 'on') ? 'https://sandbox.cashfree.com/pg/orders' : 'https://api.cashfree.com/pg/orders'; 
    $getPaymentUrl = $apiEndpoint."/".$cashfreeOrderId."/payments";

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL             => $getPaymentUrl,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => "",
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST   => "GET",
        CURLOPT_HTTPHEADER      => [
            "Accept:            application/json",
            "Content-Type:      application/json",
            "x-api-version:     2021-05-21",
            "x-client-id:       ".$appId,
            "x-client-secret:   ".$secretKey
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    $cfOrder = json_decode($response);
} catch (Exception $e) {
    $success = false;
    $error ="WHMCS_ERROR:Request to Cashfree Failed";
}

if (null !== $cfOrder && !empty($cfOrder[0]->payment_status))
{
    if($cfOrder[0]->payment_status == 'SUCCESS')
    {
        $transactionId = $cfOrder[0]->cf_payment_id;
        $paymentAmount = $cfOrder[0]->order_amount;
        $success = true;
    }
    else {
        $success = false;
        $error = $cfOrder[0]->payment_message;
    }

} else {
    if(!empty($cfOrder->message)) {
        $error = $cfOrder->message;
    } else {
        $error = "Unable to process your order. Please contact support.";
    }
    $success = false;

}
//Check if payment successfully paid

if ($success === true)
{
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($invoiceId, $transactionId, $paymentAmount, 0, $gatewayParams["name"]);
    # Successful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $cfOrder[0], "Successful");

    header("Location: ".$gatewayParams['systemurl']."viewinvoice.php?id=" . $invoiceId."&paymentsuccess=true");
}
else 
{
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_REQUEST["order_id"], "Unsuccessful-".$error . ". Please check cashfree dashboard for order id: ".$cashfreeOrderId);

    header("Location: ".$gatewayParams['systemurl']."viewinvoice.php?id=" . $invoiceId."&paymentfailed=true");
}