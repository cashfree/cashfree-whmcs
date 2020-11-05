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

$appId = $gatewayParams["appId"];
$secretKey = $gatewayParams["secretKey"];

//Gateway response parameters
$invoiceId = substr($_POST["orderId"], strpos($_POST["orderId"], "_") + 1);
$cashfreeOrderId = $_POST["orderId"];
$paymentAmount = $_POST["orderAmount"];

// Validate Callback Invoice ID.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Check Callback Transaction ID.
checkCbTransID($cashfreeOrderId);

$error = "";

if($_POST['txStatus'] == 'SUCCESS') {

    try {
        $data = "{$_POST['orderId']}{$_POST['orderAmount']}{$_POST['referenceId']}{$_POST['txStatus']}{$_POST['paymentMode']}{$_POST['txMsg']}{$_POST['txTime']}";
        $hash_hmac = hash_hmac('sha256', $data, $secretKey, true) ;
        $computedSignature = base64_encode($hash_hmac);
        if ($_POST["signature"] != $computedSignature) {
            $success = false;
            $error = 'CASHFREE_ERROR:Invalid Signature';
        } else {
            $success = true;
        }
    } catch (Exception $e) {
        $success = false;
        $error ="WHMCS_ERROR:Request to Cashfree Failed";
    }
}
else {
    $success = false;
    $error = $_POST['txMsg'];
}
if ($success === true) {
    # Successful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Successful");

    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($invoiceId, $cashfreeOrderId, $paymentAmount, 0, $gatewayParams["name"]);

    header("Location: ".$gatewayParams['systemurl']."viewinvoice.php?id=" . $invoiceId."&paymentsuccess=true");
} else {
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check cashfree dashboard for order id: ".$cashfreeOrderId);

    header("Location: ".$gatewayParams['systemurl']."viewinvoice.php?id=" . $invoiceId."&paymentfailed=true");
}
