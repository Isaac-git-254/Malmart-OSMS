<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

$flash = take_flash();
$statement = db()->prepare(
    'SELECT id, order_number, total, status, payment_method, payment_status, created_at
     FROM orders
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$statement->execute([$user['id']]);
$orders = [];
foreach ($statement->fetchAll() as $row) {
    $id = (int) $row['id'];
    $orders[$id] = [
        'number' => $row['order_number'],
        'total' => product_price_cents($row['total']),
        'status' => $row['status'],
        'payment_method' => $row['payment_method'],
        'payment_status' => $row['payment_status'],
        'created_at' => $row['created_at'],
        'items' => [],
    ];
}
if ($orders !== []) {
    $orderIds = array_keys($orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStatement = db()->prepare(
        'SELECT order_id, product_name, unit_price, quantity
         FROM order_items WHERE order_id IN (' . $placeholders . ') ORDER BY order_id DESC, id'
    );
    $itemsStatement->execute($orderIds);
    foreach ($itemsStatement->fetchAll() as $item) {
        $orders[(int) $item['order_id']]['items'][] = [
            'name' => $item['product_name'],
            'price' => product_price_cents($item['unit_price']),
            'quantity' => (int) $item['quantity'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | My Orders</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a><a href="index.php">Back to shop</a></nav></header>
<main class="store-container">
    <h1>My Orders</h1>
    <?php if ($flash !== null): ?>
        <p class="<?= $flash['type'] === 'success' ? 'success-msg' : 'error-msg' ?>" role="status"><?= h($flash['message']) ?></p>
    <?php endif; ?>
    <?php if ($orders === []): ?>
        <p class="notice">You have not placed any orders while signed in.</p>
        <a class="button-link" href="index.php">Browse products</a>
    <?php else: ?>
        <div class="orders-list">
            <?php foreach ($orders as $order): ?>
                <article class="order-card">
                    <div class="order-heading">
                        <div>
                            <h2>Order <?= h($order['number']) ?></h2>
                            <p class="muted"><?= h($order['created_at']) ?></p>
                        </div>
                        <p><strong><?= h(ucfirst($order['status'])) ?></strong><br><?= h($order['payment_method'] === 'mpesa' ? 'M-Pesa' : ucfirst(str_replace('_', ' ', $order['payment_method']))) ?> · <?= h($order['payment_status']) ?></p>
                    </div>
                    <ul class="order-items">
                        <?php foreach ($order['items'] as $item): ?>
                            <li><span><?= h($item['name']) ?> × <?= (int) $item['quantity'] ?></span><span>Ksh. <?= format_money($item['price'] * $item['quantity']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="order-total">Total: Ksh. <?= format_money($order['total']) ?></p>
                    <div class="order-actions">
                        <?php if ($order['payment_status'] === 'paid'): ?>
                            <a class="button-link" href="receipt.php?order=<?= rawurlencode($order['number']) ?>">View receipt</a>
                        <?php endif; ?>
                        <?php if ($order['status'] === 'pending' && $order['payment_status'] !== 'paid'): ?>
                            <form method="post" action="cancel_order.php" data-confirm-cancel>
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $id ?>">
                                <input type="hidden" name="order_number" value="<?= h($order['number']) ?>">
                                <button id="cancel-order-button" class="cancel-order-button" type="submit">Cancel order</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script src="cancel_order.js" defer></script>
</body>
</html>
