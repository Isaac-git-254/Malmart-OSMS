<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$orderNumber = $_GET['order'] ?? '';
if (!is_string($orderNumber) || preg_match('/\AMM\d{6}[A-F0-9]{16}\z/', $orderNumber) !== 1) {
    http_response_code(400);
    exit('Invalid order.');
}

$statement = db()->prepare(
    'SELECT id, order_number, user_id, customer_name, customer_email, customer_phone,
            shipping_address, total, status, payment_method, payment_status,
            mpesa_receipt_number, created_at
     FROM orders
     WHERE order_number = ?'
);
$statement->execute([$orderNumber]);
$order = $statement->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$user = current_user();
$isOwner = $user !== null && $order['user_id'] !== null && (int) $order['user_id'] === (int) $user['id'];
$recentOrder = $_SESSION['recent_order'] ?? null;
$isRecentOrder = is_array($recentOrder)
    && (int) ($recentOrder['id'] ?? 0) === (int) $order['id']
    && ($recentOrder['number'] ?? null) === $order['order_number'];
if (!$isOwner && !$isRecentOrder) {
    http_response_code(404);
    exit('Order not found.');
}
if ($order['payment_status'] !== 'paid') {
    http_response_code(409);
    exit('A receipt is available after payment is confirmed.');
}

$itemsStatement = db()->prepare(
    'SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id'
);
$itemsStatement->execute([$order['id']]);
$items = $itemsStatement->fetchAll();
$itemsSubtotal = 0;
foreach ($items as $item) {
    $itemsSubtotal += product_price_cents($item['unit_price']) * (int) $item['quantity'];
}
$paidCents = product_price_cents($order['total']);
$roundingAdjustment = $paidCents - $itemsSubtotal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Receipt <?= h($order['order_number']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="receipt-controls">
    <nav class="navbar">
        <a class="brand" href="index.php">Mallmart</a>
        <div class="nav-links">
            <?php if ($isOwner): ?><a href="orders.php">My orders</a><?php else: ?><a href="index.php">Continue shopping</a><?php endif; ?>
            <button type="button" class="small-button" id="print-receipt">Print / Save PDF</button>
        </div>
    </nav>
</header>
<main class="receipt-container">
    <section class="receipt">
        <div class="receipt-heading">
            <div>
                <p class="eyebrow">Payment receipt</p>
                <h1>Mallmart</h1>
                <p class="muted">Thank you for shopping with us.</p>
            </div>
            <div class="receipt-number">
                <strong>Receipt</strong>
                <span><?= h($order['order_number']) ?></span>
                <span><?= h($order['created_at']) ?></span>
            </div>
        </div>

        <?php if ($order['status'] === 'cancelled'): ?>
            <p class="error-msg">This order was cancelled. A payment was confirmed after cancellation; please contact Mallmart to arrange a refund.</p>
        <?php endif; ?>

        <div class="receipt-customer">
            <h2>Customer</h2>
            <p><?= h($order['customer_name']) ?></p>
            <p><?= h($order['customer_email']) ?> · <?= h($order['customer_phone']) ?></p>
            <p><?= h($order['shipping_address']) ?></p>
        </div>

        <table class="receipt-items">
            <thead>
                <tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $unitPrice = product_price_cents($item['unit_price']); ?>
                    <tr>
                        <td><?= h($item['product_name']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td>Ksh. <?= format_money($unitPrice) ?></td>
                        <td>Ksh. <?= format_money($unitPrice * (int) $item['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="3">Items subtotal</th><th>Ksh. <?= format_money($itemsSubtotal) ?></th></tr>
                <?php if ($roundingAdjustment !== 0): ?>
                    <tr><th colspan="3">M-Pesa rounding adjustment</th><th><?= $roundingAdjustment > 0 ? '+' : '-' ?>Ksh. <?= format_money(abs($roundingAdjustment)) ?></th></tr>
                <?php endif; ?>
                <tr><th colspan="3">Total paid</th><th>Ksh. <?= format_money($paidCents) ?></th></tr>
            </tfoot>
        </table>

        <div class="receipt-payment">
            <p><strong>Payment method:</strong> <?= h($order['payment_method'] === 'mpesa' ? 'M-Pesa' : ucfirst(str_replace('_', ' ', $order['payment_method']))) ?></p>
            <?php if ($order['mpesa_receipt_number'] !== null): ?>
                <p><strong>M-Pesa transaction:</strong> <?= h($order['mpesa_receipt_number']) ?></p>
            <?php endif; ?>
            <p><strong>Order status:</strong> <?= h(ucfirst($order['status'])) ?></p>
        </div>
        <p class="receipt-footer">This receipt confirms payment recorded by Mallmart.</p>
    </section>
</main>
<script src="receipt.js" defer></script>
</body>
</html>
