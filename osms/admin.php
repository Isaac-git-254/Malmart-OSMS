<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin();

$pdo = db();
$allowedTransitions = [
    'pending' => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped' => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $idInput = $_POST['id'] ?? '';
        $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $category = trim(is_string($_POST['category'] ?? null) ? $_POST['category'] : '');
        $price = is_string($_POST['price'] ?? null) ? trim($_POST['price']) : '';
        $imageUrl = trim(is_string($_POST['image_url'] ?? null) ? $_POST['image_url'] : '');
        $stockInput = $_POST['stock'] ?? '';
        $stock = is_string($stockInput) ? filter_var($stockInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) : false;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $id = $idInput === '' ? null : filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (
            ($idInput !== '' && $id === false)
            || $name === '' || strlen($name) > 255
            || strlen($description) > 2000
            || $category === '' || strlen($category) > 100
            || !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $price)
            || (float) $price <= 0
            || $stock === false
            || !image_path_is_safe($imageUrl)
            || !is_file(__DIR__ . '/' . $imageUrl)
        ) {
            set_flash('error', 'Enter a valid product name, description, category, price, stock, and existing image path.');
            header('Location: admin.php');
            exit;
        }

        try {
            if ($id === null) {
                $statement = $pdo->prepare(
                    'INSERT INTO products (name, description, category, price, image_url, stock, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$name, $description, $category, $price, $imageUrl, $stock, $active]);
            } else {
                $statement = $pdo->prepare(
                    'UPDATE products
                     SET name = ?, description = ?, category = ?, price = ?, image_url = ?, stock = ?, is_active = ?
                     WHERE id = ?'
                );
                $statement->execute([$name, $description, $category, $price, $imageUrl, $stock, $active, $id]);
                if ($statement->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) {
                        set_flash('error', 'The product no longer exists.');
                        header('Location: admin.php');
                        exit;
                    }
                }
            }
            set_flash('success', 'Product saved.');
        } catch (PDOException $exception) {
            error_log('Mallmart product update failed: ' . $exception->getMessage());
            set_flash('error', 'The product could not be saved. Please try again.');
        }

        header('Location: admin.php');
        exit;
    }

    if ($action === 'update_order') {
        $orderIdInput = $_POST['order_id'] ?? '';
        $orderId = is_string($orderIdInput)
            ? filter_var($orderIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

        if ($orderId === false) {
            set_flash('error', 'Invalid order.');
            header('Location: admin.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT status, payment_status, payment_method FROM orders WHERE id = ? FOR UPDATE');
            $statement->execute([$orderId]);
            $order = $statement->fetch();
            $currentStatus = $order['status'] ?? false;

            $canCompletePaidPendingOrder = $currentStatus === 'pending'
                && $newStatus === 'delivered'
                && $order['payment_status'] === 'paid';
            if (
                $currentStatus === false
                || (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true) && !$canCompletePaidPendingOrder)
            ) {
                throw new DomainException('That order status change is not allowed.');
            }
            if (
                in_array($newStatus, ['processing', 'delivered'], true)
                && $order['payment_method'] === 'mpesa'
                && $order['payment_status'] !== 'paid'
            ) {
                throw new DomainException('An order cannot be completed until its M-Pesa payment is confirmed.');
            }
            if ($newStatus === 'cancelled' && $order['payment_method'] === 'mpesa' && $order['payment_status'] === 'paid') {
                throw new DomainException('This M-Pesa payment is already complete. Process any refund separately before cancelling the order.');
            }

            if ($newStatus === 'cancelled') {
                $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
                $items->execute([$orderId]);
                $restoreStock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                foreach ($items->fetchAll() as $item) {
                    if ($item['product_id'] !== null) {
                        $restoreStock->execute([$item['quantity'], $item['product_id']]);
                    }
                }
            }

            $update = $pdo->prepare(
                "UPDATE orders
                 SET status = ?,
                     payment_status = CASE
                         WHEN ? = 'cancelled' AND payment_method = 'mpesa' THEN 'failed'
                         WHEN ? = 'delivered' AND payment_method = 'cash_on_delivery' THEN 'paid'
                         ELSE payment_status
                     END
                 WHERE id = ?"
            );
            $update->execute([$newStatus, $newStatus, $newStatus, $orderId]);
            $pdo->commit();
            set_flash('success', 'Order status updated.');
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', $exception->getMessage());
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Mallmart order update failed: ' . $exception->getMessage());
            set_flash('error', 'The order could not be updated. Please try again.');
        }

        header('Location: admin.php');
        exit;
    }

    http_response_code(400);
    exit('Invalid admin action.');
}

$flash = take_flash();
$products = $pdo->query(
    'SELECT id, name, description, category, price, image_url, stock, is_active
     FROM products ORDER BY id DESC'
)->fetchAll();
$orders = $pdo->query(
    'SELECT id, order_number, customer_name, customer_email, customer_phone,
            shipping_address, total, status, payment_method, payment_status, created_at
     FROM orders ORDER BY created_at DESC, id DESC LIMIT 100'
)->fetchAll();
$itemsByOrder = [];
if ($orders !== []) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStatement = $pdo->prepare(
        'SELECT order_id, product_name, quantity
         FROM order_items WHERE order_id IN (' . $placeholders . ')
         ORDER BY order_id DESC, id'
    );
    $itemStatement->execute($orderIds);
    foreach ($itemStatement->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Admin</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a><div class="nav-links"><a href="orders.php">My orders</a><a href="index.php">View store</a></div></nav></header>
<main class="store-container admin-container">
    <h1>Store Administration</h1>
    <p class="muted">Manage catalog visibility, inventory, pricing, and order fulfillment.</p>
    <?php if ($flash !== null): ?>
        <p class="<?= $flash['type'] === 'success' ? 'success-msg' : 'error-msg' ?>" role="status"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <section aria-labelledby="add-product-title">
        <h2 id="add-product-title">Add a product</h2>
        <form class="admin-product-form" method="post" action="admin.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_product">
            <div class="form-group"><label for="new-name">Name</label><input id="new-name" name="name" maxlength="255" required></div>
            <div class="form-group"><label for="new-description">Description</label><textarea id="new-description" name="description" maxlength="2000"></textarea></div>
            <div class="form-group"><label for="new-category">Category</label><input id="new-category" name="category" maxlength="100" required></div>
            <div class="form-group"><label for="new-price">Price (Ksh.)</label><input id="new-price" name="price" type="number" min="0.01" max="99999999.99" step="0.01" required></div>
            <div class="form-group"><label for="new-stock">Stock</label><input id="new-stock" name="stock" type="number" min="0" max="1000000" step="1" value="0" required></div>
            <div class="form-group"><label for="new-image">Image path</label><input id="new-image" name="image_url" placeholder="images/new-product.jpg" required><small>Add the image to the images/ folder first.</small></div>
            <label class="checkbox-label"><input type="checkbox" name="is_active" checked> Visible in store</label>
            <button class="checkout-btn" type="submit">Add product</button>
        </form>
    </section>

    <section aria-labelledby="products-management-title">
        <h2 id="products-management-title">Products (<?= count($products) ?>)</h2>
        <?php foreach ($products as $product): ?>
            <details class="admin-product" id="product-<?= (int) $product['id'] ?>">
                <summary>
                    <?= h($product['name']) ?> · Ksh. <?= number_format((float) $product['price'], 2) ?>
                    · <?= (int) $product['stock'] ?> in stock
                    · <?= (int) $product['is_active'] === 1 ? 'Active' : 'Hidden' ?>
                </summary>
                <form class="admin-product-form" method="post" action="admin.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <div class="form-group"><label for="name-<?= (int) $product['id'] ?>">Name</label><input id="name-<?= (int) $product['id'] ?>" name="name" value="<?= h($product['name']) ?>" maxlength="255" required></div>
                    <div class="form-group"><label for="description-<?= (int) $product['id'] ?>">Description</label><textarea id="description-<?= (int) $product['id'] ?>" name="description" maxlength="2000"><?= h($product['description']) ?></textarea></div>
                    <div class="form-group"><label for="category-<?= (int) $product['id'] ?>">Category</label><input id="category-<?= (int) $product['id'] ?>" name="category" value="<?= h($product['category']) ?>" maxlength="100" required></div>
                    <div class="form-group"><label for="price-<?= (int) $product['id'] ?>">Price (Ksh.)</label><input id="price-<?= (int) $product['id'] ?>" name="price" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= h($product['price']) ?>" required></div>
                    <div class="form-group"><label for="stock-<?= (int) $product['id'] ?>">Stock</label><input id="stock-<?= (int) $product['id'] ?>" name="stock" type="number" min="0" max="1000000" step="1" value="<?= (int) $product['stock'] ?>" required></div>
                    <div class="form-group"><label for="image-<?= (int) $product['id'] ?>">Image path</label><input id="image-<?= (int) $product['id'] ?>" name="image_url" value="<?= h($product['image_url']) ?>" required></div>
                    <label class="checkbox-label"><input type="checkbox" name="is_active" <?= (int) $product['is_active'] === 1 ? 'checked' : '' ?>> Visible in store</label>
                    <button class="checkout-btn" type="submit">Save product</button>
                </form>
            </details>
        <?php endforeach; ?>
    </section>

    <section aria-labelledby="orders-management-title">
        <h2 id="orders-management-title">Recent Orders (<?= count($orders) ?>)</h2>
        <?php if ($orders === []): ?>
            <p class="notice">No orders have been placed yet.</p>
        <?php else: ?>
            <div class="admin-orders">
                <?php foreach ($orders as $order): ?>
                    <article class="admin-order">
                        <div class="order-heading">
                            <div>
                                <h3><?= h($order['order_number']) ?></h3>
                                <p><?= h($order['customer_name']) ?> · <?= h($order['customer_email']) ?> · <?= h($order['customer_phone']) ?></p>
                                <p class="muted"><?= h($order['shipping_address']) ?> · <?= h($order['created_at']) ?></p>
                                <p>
                                    <?php foreach ($itemsByOrder[(int) $order['id']] ?? [] as $item): ?>
                                        <?= h($item['product_name']) ?> × <?= (int) $item['quantity'] ?><br>
                                    <?php endforeach; ?>
                                </p>
                                <strong>Ksh. <?= number_format((float) $order['total'], 2) ?> · <?= h($order['payment_method'] === 'mpesa' ? 'M-Pesa' : ucfirst(str_replace('_', ' ', $order['payment_method']))) ?> <?= h($order['payment_status']) ?></strong>
                            </div>
                            <form class="order-status-form" method="post" action="admin.php">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_order">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <label for="status-<?= (int) $order['id'] ?>">Status: <?= h($order['status']) ?></label>
                                <?php
                                $orderTransitions = $allowedTransitions[$order['status']] ?? [];
                                if ($order['status'] === 'pending' && $order['payment_status'] === 'paid') {
                                    $orderTransitions[] = 'delivered';
                                }
                                ?>
                                <?php if ($orderTransitions !== []): ?>
                                    <select id="status-<?= (int) $order['id'] ?>" name="status" required>
                                        <?php foreach ($orderTransitions as $status): ?>
                                            <option value="<?= h($status) ?>"><?= $status === 'delivered' ? 'Completed / successful' : h(ucfirst($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="small-button" type="submit">Update</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
