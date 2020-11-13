<?php
// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'cashfree';

// Fetch gateway configuration parameters.
$gatewayParams      = getGatewayVariables($gatewayModuleName);
$secretKey          = $gatewayParams["secretKey"];

//Gateway response parameters
$cashfreeOrderId    = $_POST["orderId"];
$invoiceId          = substr($cashfreeOrderId, strpos($cashfreeOrderId, "_") + 1);
$transactionId      = $_POST['referenceId'];

$command = 'GetOrders';

$invoiceData = array(
    'id' => $invoiceId,
);
$order = localAPI($command, $invoiceData);

//Execute notify url after 10 sec of execution return url
sleep(10);
if($order['totalresults'] === 0 or $result['orders']['order'][0]['status'] === 'Paid')
{
    return;
}

$success = false;
$error = "";
$errorMessage = 'The payment has failed.';

try {
    $data = "{$_POST['orderId']}{$_POST['orderAmount']}{$_POST['referenceId']}{$_POST['txStatus']}{$_POST['paymentMode']}{$_POST['txMsg']}{$_POST['txTime']}";
    $hash_hmac = hash_hmac('sha256', $data, $secretKey, true) ;
    $computedSignature = base64_encode($hash_hmac);

    if ($_POST["signature"] != $computedSignature)
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

# Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
addInvoicePayment($invoiceId, $transactionId, $paymentAmount, 0, $gatewayParams["name"]);
if ($success === true)
{
    # Successful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Webhook successfully executed.");
}
else
{
     # Unsuccessful
     Capsule::table('tblinvoices')
     ->where('id', $invoiceId)
     ->update(array(
         'status' => 'Unpaid'
     ));
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Webhook successfully execute with Error - ".$error . ". Please check cashfree dashboard for order id: ".$cashfreeOrderId);
}
exit;