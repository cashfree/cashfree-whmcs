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

use WHMCS\Database\Capsule;

define('API_VERSION', '2022-09-01');

/**
 * Check if transaction ID already exists in database
 * @param string $transaction_id
 * @return bool
 */
function checkTransIdExist($transaction_id) {
    if (empty($transaction_id)) {
        return false;
    }
    $count = Capsule::table('tblaccounts')
        ->where('transid', $transaction_id)
        ->count();
    return $count > 0;
}

// Detect module name from filename.
$gateway_module_name = 'cashfree';

// Fetch gateway configuration parameters.
$gateway_params = getGatewayVariables($gateway_module_name);

// Die if module is not active.
if (!$gateway_params['type']) {
    die("Module Not Activated");
}

// Get mode from gateway params (FIXED: was using undefined $params)
$mode = ($gateway_params['testMode'] == 'on') ? 'sandbox' : 'production';

$app_id      = $gateway_params['appId'];
$secret_key  = $gateway_params['secretKey'];

// Gateway response parameters
$cashfree_order_id = isset($_REQUEST['order_id']) ? trim($_REQUEST['order_id']) : '';

if (empty($cashfree_order_id)) {
    logTransaction($gateway_params['name'], $_REQUEST, "Error: Missing order_id");
    die("Invalid Request: Missing order_id");
}

// Extract invoice ID from order ID (format: cf{timestamp}_{invoice_id})
$invoice_id = substr($cashfree_order_id, strpos($cashfree_order_id, "_") + 1);

if (empty($invoice_id) || !is_numeric($invoice_id)) {
    logTransaction($gateway_params['name'], $_REQUEST, "Error: Invalid invoice ID extracted from order_id");
    die("Invalid Request: Invalid invoice ID");
}

// Validate Callback Invoice ID.
$invoice_id = checkCbInvoiceID($invoice_id, $gateway_params['name']);

// Get invoice details using Capsule (FIXED: replaced deprecated mysql_fetch_assoc)
$invoice_details = Capsule::table('tblinvoices')
    ->where('id', $invoice_id)
    ->first();

if (!$invoice_details) {
    logTransaction($gateway_params['name'], $_REQUEST, "Error: Invoice not found");
    die("Invalid Invoice");
}

// Check if invoice is already paid
if ($invoice_details->status === 'Paid') {
    header("Location: " . $gateway_params['systemurl'] . "viewinvoice.php?id=" . $invoice_id . "&paymentsuccess=true");
    exit;
}

$invoice_amount = $invoice_details->total;
$error = "";
$success = false;
$transaction_id = '';

// Build API endpoint
$api_endpoint = ($gateway_params['testMode'] == 'on') 
    ? 'https://sandbox.cashfree.com/pg/orders' 
    : 'https://api.cashfree.com/pg/orders';

$get_payment_url = $api_endpoint . "/" . $cashfree_order_id . "/payments";

// Make API call to get payment details
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
        "Accept: application/json",
        "Content-Type: application/json",
        "x-api-version: " . API_VERSION,
        "x-client-id: " . $app_id,
        "x-client-secret: " . $secret_key
    ],
]);

$response = curl_exec($curl);
$curl_error = curl_error($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

// Log raw response for debugging
logTransaction($gateway_params['name'], [
    'order_id' => $cashfree_order_id,
    'http_code' => $http_code,
    'response' => $response
], "API Response");

if ($curl_error) {
    $error = "CURL Error: " . $curl_error;
    $success = false;
} else {
    $cf_order = json_decode($response);
    
    // Handle error response from Cashfree
    if (is_object($cf_order) && isset($cf_order->message)) {
        $success = false;
        $error = $cf_order->message;
    } 
    // Handle successful payment array response
    elseif (is_array($cf_order) && !empty($cf_order) && isset($cf_order[0])) {
        $payment = $cf_order[0];
        
        if (isset($payment->payment_status)) {
            if ($payment->payment_status === 'SUCCESS') {
                $transaction_id = isset($payment->cf_payment_id) ? $payment->cf_payment_id : '';
                $cf_order_amount = isset($payment->payment_amount) ? $payment->payment_amount : (isset($payment->order_amount) ? $payment->order_amount : 0);
                
                // Verify amount matches (with 0.01 tolerance for rounding)
                if (abs(round($invoice_amount, 2) - round($cf_order_amount, 2)) <= 0.01) {
                    $success = true;
                } else {
                    $error = 'Amount Mismatch: Invoice=' . $invoice_amount . ', Paid=' . $cf_order_amount;
                    $success = false;
                }
            } else {
                $success = false;
                $error = isset($payment->payment_message) ? $payment->payment_message : 'Payment status: ' . $payment->payment_status;
            }
        } else {
            $error = "Invalid payment response structure";
            $success = false;
        }
    }
    // Handle empty response
    elseif (is_array($cf_order) && empty($cf_order)) {
        $error = "No payment found for this order. Payment may be pending or not initiated.";
        $success = false;
    }
    else {
        $error = "Unable to process payment response. Please contact support.";
        $success = false;
    }
}

// Process payment result
if ($success === true && !empty($transaction_id)) {
    // Check if transaction already processed (prevent duplicate payments)
    if (!checkTransIdExist($transaction_id)) {
        // Add payment to invoice
        addInvoicePayment($invoice_id, $transaction_id, $invoice_amount, 0, $gateway_module_name);
        
        // Log successful transaction
        logTransaction($gateway_params['name'], [
            'order_id' => $cashfree_order_id,
            'transaction_id' => $transaction_id,
            'amount' => $invoice_amount,
            'invoice_id' => $invoice_id
        ], "Payment Successful");
    } else {
        // Transaction already exists, just log it
        logTransaction($gateway_params['name'], [
            'order_id' => $cashfree_order_id,
            'transaction_id' => $transaction_id,
            'message' => 'Transaction already processed'
        ], "Duplicate Transaction");
    }
    
    // Redirect to invoice with success message
    header("Location: " . $gateway_params['systemurl'] . "viewinvoice.php?id=" . $invoice_id . "&paymentsuccess=true");
    exit;
} else {
    // Log unsuccessful transaction
    logTransaction($gateway_params['name'], [
        'order_id' => $cashfree_order_id,
        'invoice_id' => $invoice_id,
        'error' => $error
    ], "Payment Failed - " . $error);
    
    // Redirect to invoice with error
    header("Location: " . $gateway_params['systemurl'] . "viewinvoice.php?id=" . $invoice_id . "&paymentfailed=true");
    exit;
}