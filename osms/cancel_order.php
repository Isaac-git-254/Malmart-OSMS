<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

verify_csrf_token();
$orderIdInput = $_POST['order_id'] ?? '';
$orderNumberInput = $_POST['order_number'] ?? '';
$orderId = is_string($orderIdInput)
    ? filter_var($orderIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$orderNumber = is_string($orderNumberInput) ? $orderNumberInput : '';
if ($orderId === false || preg_match('/\AMM\d{6}[A-F0-9]{16}\z/', $orderNumber) !== 1) {
    http_response_code(400);
    exit('Invalid order.');
}

$pdo = db();
$redirectToOrders = current_user() !== null;
try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id, order_number, user_id, status, payment_method, payment_status
         FROM orders
         WHERE id = ? AND order_number = ?
         FOR UPDATE'
    );
    $statement->execute([$orderId, $orderNumber]);
    $order = $statement->fetch();
    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Order not found.');
    }

    $user = current_user();
    $isOwner = $user !== null && $order['user_id'] !== null && (int) $order['user_id'] === (int) $user['id'];
    $redirectToOrders = $isOwner;
    $recentOrder = $_SESSION['recent_order'] ?? null;
    $isRecentGuestOrder = is_array($recentOrder)
        && (int) ($recentOrder['id'] ?? 0) === (int) $order['id']
        && ($recentOrder['number'] ?? null) === $order['order_number'];
    if (!$isOwner && !$isRecentGuestOrder) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Order not found.');
    }
    if ($order['status'] !== 'pending' || $order['payment_status'] === 'paid') {
        $pdo->rollBack();
        set_flash('error', 'Only unpaid orders that have not started processing can be cancelled.');
        header('Location: ' . ($isOwner ? 'orders.php' : 'order_success.php'));
        exit;
    }

    $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$order['id']]);
    $restoreStock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    foreach ($items->fetchAll() as $item) {
        if ($item['product_id'] !== null) {
            $restoreStock->execute([$item['quantity'], $item['product_id']]);
        }
    }

    $update = $pdo->prepare(
        "UPDATE orders
         SET status = 'cancelled',
             payment_status = CASE WHEN payment_method = 'mpesa' THEN 'failed' ELSE payment_status END
         WHERE id = ?"
    );
    $update->execute([$order['id']]);
    $pdo->commit();
    set_flash('success', 'Your order has been cancelled and the items returned to stock.');
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mallmart customer order cancellation failed: ' . $exception->getMessage());
    set_flash('error', 'We could not cancel your order. Please try again.');
}

header('Location: ' . ($redirectToOrders ? 'orders.php' : 'order_success.php'));
exit;
