<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mpesa.php';

function mpesa_callback_response(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ResultCode' => $status === 200 ? 0 : 1, 'ResultDesc' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mpesa_callback_response(405, 'Method not allowed.');
}

try {
    $callbackToken = mpesa_callback_token();
} catch (RuntimeException $exception) {
    error_log('Mallmart M-Pesa callback configuration error: ' . $exception->getMessage());
    mpesa_callback_response(503, 'Payment callback is not configured.');
}

$token = $_GET['token'] ?? '';
if (!is_string($token) || !hash_equals($callbackToken, $token)) {
    mpesa_callback_response(403, 'Invalid callback token.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    mpesa_callback_response(413, 'Callback payload is too large.');
}

$rawBody = file_get_contents('php://input');
try {
    $payload = json_decode(is_string($rawBody) ? $rawBody : '', true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    mpesa_callback_response(400, 'Invalid callback payload.');
}

$callback = is_array($payload) ? ($payload['Body']['stkCallback'] ?? null) : null;
$checkoutRequestId = is_array($callback) ? ($callback['CheckoutRequestID'] ?? null) : null;
$merchantRequestId = is_array($callback) ? ($callback['MerchantRequestID'] ?? null) : null;
$resultCode = is_array($callback) ? ($callback['ResultCode'] ?? null) : null;
if (!is_string($checkoutRequestId) || !is_string($merchantRequestId) || !is_numeric($resultCode)) {
    mpesa_callback_response(400, 'Required callback fields are missing.');
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id, customer_phone, total, payment_status, mpesa_merchant_request_id
         FROM orders
         WHERE mpesa_checkout_request_id = ?
         FOR UPDATE'
    );
    $statement->execute([$checkoutRequestId]);
    $order = $statement->fetch();
    if (!$order) {
        $pdo->rollBack();
        mpesa_callback_response(404, 'Order not found.');
    }
    if (
        !is_string($order['mpesa_merchant_request_id'])
        || !hash_equals($order['mpesa_merchant_request_id'], $merchantRequestId)
    ) {
        $pdo->rollBack();
        mpesa_callback_response(400, 'Callback request did not match the order.');
    }

    if ((int) $resultCode === 0) {
        $metadata = $callback['CallbackMetadata']['Item'] ?? null;
        $items = [];
        if (is_array($metadata)) {
            foreach ($metadata as $item) {
                if (is_array($item) && is_string($item['Name'] ?? null)) {
                    $items[$item['Name']] = $item['Value'] ?? null;
                }
            }
        }
        $amount = $items['Amount'] ?? null;
        $receipt = $items['MpesaReceiptNumber'] ?? null;
        $phone = $items['PhoneNumber'] ?? null;
        if (
            !is_numeric($amount)
            || (float) $amount !== (float) (int) $amount
            || (int) $amount !== (int) round((float) $order['total'])
            || !is_string($receipt)
            || $receipt === ''
            || !is_numeric($phone)
            || (string) $phone !== mpesa_phone_number($order['customer_phone'])
        ) {
            $pdo->rollBack();
            error_log('Mallmart M-Pesa callback amount, receipt, or phone did not match order ' . (int) $order['id'] . '.');
            mpesa_callback_response(400, 'Payment details did not match the order.');
        }
        $update = $pdo->prepare(
            'UPDATE orders
             SET payment_status = ?, mpesa_receipt_number = ?
             WHERE id = ? AND payment_status <> ?'
        );
        $update->execute(['paid', substr($receipt, 0, 50), $order['id'], 'paid']);
    } elseif ($order['payment_status'] === 'pending') {
        $itemsStatement = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $itemsStatement->execute([$order['id']]);
        $restoreStock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        foreach ($itemsStatement->fetchAll() as $item) {
            if ($item['product_id'] !== null) {
                $restoreStock->execute([$item['quantity'], $item['product_id']]);
            }
        }
        $update = $pdo->prepare(
            'UPDATE orders SET status = ?, payment_status = ? WHERE id = ?'
        );
        $update->execute(['cancelled', 'failed', $order['id']]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mallmart M-Pesa callback processing failed: ' . $exception->getMessage());
    mpesa_callback_response(500, 'Callback processing failed.');
}

mpesa_callback_response(200, 'Callback received successfully.');
