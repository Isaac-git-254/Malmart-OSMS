<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$recentOrder = $_SESSION['recent_order'] ?? null;
if (!is_array($recentOrder) || !isset($recentOrder['id'], $recentOrder['number'])) {
    header('Location: index.php');
    exit;
}

$statement = db()->prepare(
    'SELECT id, order_number, customer_name, total, status, payment_method, payment_status, mpesa_receipt_number, created_at
     FROM orders WHERE id = ? AND order_number = ?'
);
$statement->execute([$recentOrder['id'], $recentOrder['number']]);
$order = $statement->fetch();
if (!$order) {
    unset($_SESSION['recent_order']);
    header('Location: index.php');
    exit;
}
if ($order['payment_status'] === 'paid') {
    unset($_SESSION['cart_after_mpesa']);
} elseif (
    $order['payment_status'] === 'failed'
    && (!isset($_SESSION['cart']) || !is_array($_SESSION['cart']) || $_SESSION['cart'] === [])
    && is_array($_SESSION['cart_after_mpesa'] ?? null)
) {
    $_SESSION['cart'] = $_SESSION['cart_after_mpesa'];
    unset($_SESSION['cart_after_mpesa']);
}

$itemsStatement = db()->prepare(
    'SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id'
);
$itemsStatement->execute([$order['id']]);
$items = $itemsStatement->fetchAll();
$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Order Confirmed</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a></nav></header>
<main class="auth-container order-confirmation">
    <?php if ($flash !== null): ?>
        <p class="<?= $flash['type'] === 'success' ? 'success-msg' : 'error-msg' ?>" role="status"><?= h($flash['message']) ?></p>
    <?php endif; ?>
    <p class="eyebrow">Order received</p>
    <h1>Thank you, <?= h($order['customer_name']) ?>.</h1>
    <p>
        <?php if ($order['payment_method'] !== 'mpesa'): ?>
            Order <strong><?= h($order['order_number']) ?></strong> was placed using the original <?= h(str_replace('_', ' ', $order['payment_method'])) ?> payment method.
        <?php elseif ($order['status'] === 'cancelled' && $order['payment_status'] === 'paid'): ?>
            Order <strong><?= h($order['order_number']) ?></strong> was cancelled, but its M-Pesa payment was confirmed afterward. Please contact the store to arrange a refund.
        <?php elseif ($order['payment_status'] === 'paid'): ?>
            Payment for order <strong><?= h($order['order_number']) ?></strong> is confirmed with M-Pesa.
        <?php elseif ($order['payment_status'] === 'failed'): ?>
            The M-Pesa payment for order <strong><?= h($order['order_number']) ?></strong> was not completed. Please return to the shop and place your order again.
        <?php else: ?>
            An M-Pesa prompt was sent to your phone for order <strong><?= h($order['order_number']) ?></strong>. Enter your M-Pesa PIN to pay Ksh. <?= format_money(product_price_cents($order['total'])) ?>, then refresh this page to see the payment result.
        <?php endif; ?>
    </p>
    <?php if ($order['mpesa_receipt_number'] !== null): ?><p class="muted">M-Pesa receipt: <?= h($order['mpesa_receipt_number']) ?></p><?php endif; ?>
    <p class="muted">Status: <?= h(ucfirst($order['status'])) ?> · <?= h($order['created_at']) ?></p>
    <ul class="order-items">
        <?php foreach ($items as $item): ?>
            <li><span><?= h($item['product_name']) ?> × <?= (int) $item['quantity'] ?></span><span>Ksh. <?= format_money(product_price_cents($item['unit_price']) * (int) $item['quantity']) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <p class="order-total">Total: Ksh. <?= format_money(product_price_cents($order['total'])) ?></p>
    <?php if ($order['payment_status'] === 'paid'): ?>
        <a class="button-link" href="receipt.php?order=<?= rawurlencode($order['order_number']) ?>">View receipt</a>
    <?php elseif ($order['status'] === 'pending'): ?>
        <form class="order-cancel-form" method="post" action="cancel_order.php" data-confirm-cancel>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <input type="hidden" name="order_number" value="<?= h($order['order_number']) ?>">
            <button id="cancel-order-button" class="cancel-order-button" type="submit">Cancel order</button>
        </form>
        <p class="muted">You can cancel while payment is unpaid and fulfillment has not started. If you approve an M-Pesa prompt after cancelling, contact the store.</p>
    <?php endif; ?>
    <a class="button-link" href="index.php">Continue shopping</a>
</main>
<script src="cancel_order.js" defer></script>
</body>
</html>
