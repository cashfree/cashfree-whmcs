<?php
/**
 * WHMCS Cashfree Webhook Notification Handler
 * 
 * This file handles webhook notifications from Cashfree payment gateway.
 * It verifies the webhook signature and processes payment status updates.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

define('API_VERSION', '2022-09-01');

$gateway_module_name = 'cashfree';

// Fetch gateway configuration parameters.
$gateway_params = getGatewayVariables($gateway_module_name);

// Die if module is not active.
if (!$gateway_params['type']) {
    http_response_code(400);
    die("Module Not Activated");
}

$secret_key = $gateway_params["secretKey"];
$app_id = $gateway_params["appId"];

// Get raw POST body for signature verification
$raw_post_data = file_get_contents('php://input');
$webhook_data = json_decode($raw_post_data, true);

// Log incoming webhook
logTransaction($gateway_params["name"], [
    'raw_data' => $raw_post_data,
    'headers' => getallheaders()
], "Webhook Received");

// Verify webhook signature (Cashfree new API format)
$received_signature = isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) ? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';

if (empty($received_signature)) {
    // Try alternate header names
    $headers = getallheaders();
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-webhook-signature') {
            $received_signature = $value;
            break;
        }
    }
}

// Verify signature
$computed_signature = base64_encode(hash_hmac('sha256', $raw_post_data, $secret_key, true));

if ($received_signature !== $computed_signature) {
    logTransaction($gateway_params["name"], [
        'error' => 'Invalid signature',
        'received' => $received_signature,
        'computed' => $computed_signature
    ], "Webhook Signature Verification Failed");
    http_response_code(400);
    die("Invalid Signature");
}

// Parse webhook data
if (empty($webhook_data) || !isset($webhook_data['data'])) {
    logTransaction($gateway_params["name"], $webhook_data, "Invalid Webhook Data");
    http_response_code(400);
    die("Invalid webhook data");
}

$data = $webhook_data['data'];
$event_type = isset($webhook_data['type']) ? $webhook_data['type'] : '';

// Extract order and payment details
$order_data = isset($data['order']) ? $data['order'] : $data;
$payment_data = isset($data['payment']) ? $data['payment'] : [];

$cashfree_order_id = isset($order_data['order_id']) ? $order_data['order_id'] : '';

if (empty($cashfree_order_id)) {
    logTransaction($gateway_params["name"], $webhook_data, "Missing order_id in webhook");
    http_response_code(400);
    die("Missing order_id");
}

// Extract invoice ID from order ID (format: cf{timestamp}_{invoice_id})
$invoice_id = substr($cashfree_order_id, strpos($cashfree_order_id, "_") + 1);

if (empty($invoice_id) || !is_numeric($invoice_id)) {
    logTransaction($gateway_params["name"], [
        'order_id' => $cashfree_order_id,
        'error' => 'Invalid invoice ID'
    ], "Invalid Invoice ID");
    http_response_code(400);
    die("Invalid invoice ID");
}

// Get invoice details
$invoice_details = Capsule::table('tblinvoices')
    ->where('id', $invoice_id)
    ->first();

if (!$invoice_details) {
    logTransaction($gateway_params["name"], [
        'invoice_id' => $invoice_id,
        'error' => 'Invoice not found'
    ], "Invoice Not Found");
    http_response_code(404);
    die("Invoice not found");
}

// Check if invoice is already paid
if ($invoice_details->status === 'Paid') {
    logTransaction($gateway_params["name"], [
        'invoice_id' => $invoice_id,
        'message' => 'Invoice already paid'
    ], "Invoice Already Paid");
    http_response_code(200);
    echo "OK - Invoice already paid";
    exit;
}

$success = false;
$error = "";
$transaction_id = '';
$payment_amount = 0;

// Determine payment status based on event type or payment data
$payment_status = '';

if ($event_type === 'PAYMENT_SUCCESS_WEBHOOK' || $event_type === 'ORDER_PAID_WEBHOOK') {
    $payment_status = 'SUCCESS';
} elseif (isset($payment_data['payment_status'])) {
    $payment_status = $payment_data['payment_status'];
} elseif (isset($order_data['order_status'])) {
    $payment_status = ($order_data['order_status'] === 'PAID') ? 'SUCCESS' : $order_data['order_status'];
}

if ($payment_status === 'SUCCESS') {
    $transaction_id = isset($payment_data['cf_payment_id']) ? $payment_data['cf_payment_id'] : '';
    if (empty($transaction_id)) {
        $transaction_id = isset($payment_data['payment_id']) ? $payment_data['payment_id'] : $cashfree_order_id;
    }
    
    $payment_amount = isset($payment_data['payment_amount']) ? $payment_data['payment_amount'] : 
                     (isset($order_data['order_amount']) ? $order_data['order_amount'] : $invoice_details->total);
    
    // Verify amount (with tolerance for rounding)
    if (abs(round($invoice_details->total, 2) - round($payment_amount, 2)) <= 0.01) {
        $success = true;
    } else {
        $success = false;
        $error = "Amount mismatch: Invoice=" . $invoice_details->total . ", Paid=" . $payment_amount;
    }
} else {
    $success = false;
    $error = "Payment status: " . $payment_status;
    if (isset($payment_data['payment_message'])) {
        $error .= " - " . $payment_data['payment_message'];
    }
}

// Process payment
if ($success === true && !empty($transaction_id)) {
    // Check if transaction already exists
    $existing_transaction = Capsule::table('tblaccounts')
        ->where('transid', $transaction_id)
        ->count();
    
    if ($existing_transaction === 0) {
        // Add payment to invoice
        addInvoicePayment($invoice_id, $transaction_id, $invoice_details->total, 0, $gateway_params["name"]);
        
        logTransaction($gateway_params["name"], [
            'order_id' => $cashfree_order_id,
            'transaction_id' => $transaction_id,
            'amount' => $invoice_details->total,
            'invoice_id' => $invoice_id,
            'event_type' => $event_type
        ], "Payment Successful via Webhook");
    } else {
        logTransaction($gateway_params["name"], [
            'transaction_id' => $transaction_id,
            'message' => 'Transaction already processed'
        ], "Duplicate Webhook Transaction");
    }
    
    http_response_code(200);
    echo "OK";
} else {
    // Log failed payment (DO NOT change invoice status to Unpaid - it should remain as is)
    logTransaction($gateway_params["name"], [
        'order_id' => $cashfree_order_id,
        'invoice_id' => $invoice_id,
        'error' => $error,
        'payment_status' => $payment_status,
        'event_type' => $event_type
    ], "Payment Failed via Webhook - " . $error);
    
    http_response_code(200);
    echo "OK - Logged";
}

exit;