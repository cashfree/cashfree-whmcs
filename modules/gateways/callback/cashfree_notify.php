<?php
// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'cashfree';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

$secretKey = $gatewayParams["secretKey"];

$cashfreeOrderId = $_POST["orderId"];
$invoiceId = substr($cashfreeOrderId, strpos($cashfreeOrderId, "_") + 1);

$command = 'GetOrders';

$invoiceData = array(
    'id' => $invoiceId,
);
$order = localAPI($command, $invoiceData);

if($order['totalresults'] === 0 or $$result['orders']['order'][0]['status'] === 'Paid')
{
    return;
}

$success = false;
$error = "";
$errorMessage = 'The payment has failed.';

$amount = $order['orders']['order'][0]['amount'];

if($_POST["orderAmount"] === $amount)
{
    $success = true;
}
else
{
    $error = 'WHMCS_ERROR: Payment to Cashfree Failed. Amount mismatch.';
}

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

if ($success === true)
{
    # Successful
    # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    addInvoicePayment($invoiceId, $cashfreeOrderId, $amount, 0, $gatewayParams["name"]);

    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Successful");
}
else
{
    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check cashfree dashboard for order id: ".$cashfreeOrderId);
}
exit;
