<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mpesa.php';

function parse_cart(string $json): array
{
    try {
        $submittedItems = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('Your cart could not be read. Return to the shop and try again.');
    }

    if (!is_array($submittedItems) || $submittedItems === [] || count($submittedItems) > 50) {
        throw new DomainException('Your cart is empty or contains too many products.');
    }

    $cart = [];
    foreach ($submittedItems as $item) {
        if (!is_array($item) || !isset($item['id'], $item['quantity'])) {
            throw new DomainException('Your cart contains an invalid item.');
        }

        $id = filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($id === false || $quantity === false) {
            throw new DomainException('Your cart contains an invalid product or quantity.');
        }

        $cart[$id] = ($cart[$id] ?? 0) + $quantity;
        if ($cart[$id] > 99) {
            throw new DomainException('The maximum quantity per product is 99.');
        }
    }

    return $cart;
}

function load_cart_products(PDO $pdo, array $cart, bool $lock = false): array
{
    if ($cart === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cart), '?'));
    $statement = $pdo->prepare(
        'SELECT id, name, description, price, image_url, stock
         FROM products
         WHERE is_active = 1 AND id IN (' . $placeholders . ')' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(array_keys($cart));
    $products = [];
    foreach ($statement->fetchAll() as $product) {
        $products[(int) $product['id']] = $product;
    }

    return $products;
}

$error = '';
$formValues = [
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'shipping_address' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    if ($action === 'review') {
        $cartJson = $_POST['cart_json'] ?? '';
        if (!is_string($cartJson)) {
            $error = 'Your cart could not be read. Return to the shop and try again.';
        } else {
            try {
                $_SESSION['cart'] = parse_cart($cartJson);
                header('Location: checkout.php');
                exit;
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'place_order') {
        foreach ($formValues as $field => $_) {
            $value = $_POST[$field] ?? '';
            $formValues[$field] = is_string($value) ? trim($value) : '';
        }

        $cart = $_SESSION['cart'] ?? [];
        if (!is_array($cart) || $cart === []) {
            $error = 'Your cart is empty. Add items before checking out.';
        } elseif (
            $formValues['customer_name'] === '' || strlen($formValues['customer_name']) > 120
            || filter_var($formValues['customer_email'], FILTER_VALIDATE_EMAIL) === false
            || strlen($formValues['customer_email']) > 254
            || $formValues['customer_phone'] === '' || strlen($formValues['customer_phone']) > 40
            || $formValues['shipping_address'] === '' || strlen($formValues['shipping_address']) > 500
        ) {
            $error = 'Enter a valid name, email, phone number, and shipping address.';
        } else {
            try {
                $mpesaConfig = mpesa_config();
                $phoneNumber = mpesa_phone_number($formValues['customer_phone']);
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                error_log('Mallmart M-Pesa configuration error: ' . $exception->getMessage());
                $error = 'M-Pesa payments are not configured. Please contact the store and try again later.';
            }

            if ($error === '') {
                $pdo = db();
                $orderId = null;
                $orderNumber = '';
                $mpesaAmount = 0;
                try {
                    $pdo->beginTransaction();
                    $products = load_cart_products($pdo, $cart, true);
                    if (count($products) !== count($cart)) {
                        throw new DomainException('One or more products are no longer available. Update your cart and try again.');
                    }

                    $totalCents = 0;
                    foreach ($cart as $id => $quantity) {
                        if ($products[$id]['stock'] < $quantity) {
                            throw new DomainException('There is not enough stock for ' . $products[$id]['name'] . '. Update your cart and try again.');
                        }
                        $totalCents += product_price_cents($products[$id]['price']) * $quantity;
                    }
                    $mpesaAmount = (int) round($totalCents / 100);
                    if ($mpesaAmount < 1 || $mpesaAmount > 150000) {
                        throw new DomainException('M-Pesa orders must total between Ksh. 1 and Ksh. 150,000.');
                    }

                    $orderNumber = 'MM' . date('ymd') . strtoupper(bin2hex(random_bytes(8)));
                    $user = current_user();
                    $insertOrder = $pdo->prepare(
                        'INSERT INTO orders
                            (order_number, user_id, customer_name, customer_email, customer_phone,
                             shipping_address, total, payment_method, payment_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertOrder->execute([
                        $orderNumber,
                        $user['id'] ?? null,
                        $formValues['customer_name'],
                        $formValues['customer_email'],
                        $phoneNumber,
                        $formValues['shipping_address'],
                        number_format($mpesaAmount, 2, '.', ''),
                        'mpesa',
                        'pending',
                    ]);
                    $orderId = (int) $pdo->lastInsertId();
                    $insertItem = $pdo->prepare(
                        'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $decrementStock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

                    foreach ($cart as $id => $quantity) {
                        $product = $products[$id];
                        $insertItem->execute([$orderId, $id, $product['name'], $product['price'], $quantity]);
                        $decrementStock->execute([$quantity, $id, $quantity]);
                        if ($decrementStock->rowCount() !== 1) {
                            throw new DomainException('Stock changed while your order was being placed. Please try again.');
                        }
                    }
                    $pdo->commit();
                } catch (DomainException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Mallmart order placement failed: ' . $exception->getMessage());
                    $error = 'We could not place your order. Please try again.';
                }

                if ($error === '' && $orderId !== null) {
                    try {
                        $push = mpesa_start_stk_push($mpesaConfig, $phoneNumber, $mpesaAmount, $orderNumber);
                        $savePush = $pdo->prepare(
                            'UPDATE orders
                             SET mpesa_checkout_request_id = ?, mpesa_merchant_request_id = ?
                             WHERE id = ? AND payment_status = ?'
                        );
                        $savePush->execute([
                            $push['checkout_request_id'],
                            $push['merchant_request_id'],
                            $orderId,
                            'pending',
                        ]);
                        if ($savePush->rowCount() !== 1) {
                            throw new PDOException('The STK request was accepted but could not be linked to the order.');
                        }
                        $_SESSION['recent_order'] = ['id' => $orderId, 'number' => $orderNumber];
                        $_SESSION['cart_after_mpesa'] = $_SESSION['cart'];
                        unset($_SESSION['cart']);
                        header('Location: order_success.php');
                        exit;
                    } catch (PDOException $exception) {
                        error_log('Mallmart could not save M-Pesa request for order ' . $orderNumber . ': ' . $exception->getMessage());
                        $error = 'Your order ' . $orderNumber . ' may still receive an M-Pesa prompt. Please contact the store before trying again.';
                    } catch (MpesaRejectedException $exception) {
                        error_log('Mallmart M-Pesa STK initiation failed for order ' . $orderNumber . ': ' . $exception->getMessage());
                        try {
                            $pdo->beginTransaction();
                            $lockOrder = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ? FOR UPDATE');
                            $lockOrder->execute([$orderId]);
                            if ($lockOrder->fetchColumn() === 'pending') {
                                $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
                                $items->execute([$orderId]);
                                $restoreStock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                                foreach ($items->fetchAll() as $item) {
                                    if ($item['product_id'] !== null) {
                                        $restoreStock->execute([$item['quantity'], $item['product_id']]);
                                    }
                                }
                                $cancel = $pdo->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?');
                                $cancel->execute(['cancelled', 'failed', $orderId]);
                            }
                            $pdo->commit();
                            $error = 'We could not start the M-Pesa payment. Please try again.';
                        } catch (PDOException $databaseException) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            error_log('Mallmart failed to release stock after STK initiation error: ' . $databaseException->getMessage());
                            $error = 'Your order ' . $orderNumber . ' was created but payment could not be confirmed. Please contact the store before trying again.';
                        }
                    } catch (RuntimeException $exception) {
                        error_log('Mallmart M-Pesa request outcome is unknown for order ' . $orderNumber . ': ' . $exception->getMessage());
                        $error = 'We could not confirm whether the M-Pesa prompt was sent for order ' . $orderNumber . '. Check your phone and contact the store before trying again.';
                    }
                }
            }
        }
    } else {
        http_response_code(400);
        exit('Invalid checkout action.');
    }
}

$cart = $_SESSION['cart'] ?? [];
$products = [];
$totalCents = 0;
$cartNeedsUpdate = false;
if (is_array($cart) && $cart !== []) {
    $products = load_cart_products(db(), $cart);
    foreach ($cart as $id => $quantity) {
        if (isset($products[$id])) {
            $totalCents += product_price_cents($products[$id]['price']) * $quantity;
            if ((int) $products[$id]['stock'] < $quantity) {
                $cartNeedsUpdate = true;
            }
        } else {
            $cartNeedsUpdate = true;
        }
    }
    if (count($products) !== count($cart)) {
        $cartNeedsUpdate = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Checkout</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a><a href="index.php">Back to shop</a></nav></header>
<main class="store-container">
    <h1>Complete Your Order</h1>
    <?php if ($error !== ''): ?><p class="error-msg" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if ($cart === [] || $products === []): ?>
        <p class="notice">Your cart is empty or its products are no longer available.</p>
        <a class="button-link" href="index.php">Return to shop</a>
    <?php elseif ($cartNeedsUpdate): ?>
        <p class="error-msg" role="alert">Some items are unavailable or the requested quantity is out of stock. Update your cart before continuing.</p>
        <a class="button-link" href="index.php">Update cart in store</a>
    <?php else: ?>
        <div class="checkout-layout">
            <section class="checkout-form-section">
                <h2>Shipping Details</h2>
                <p class="notice">Pay securely with M-Pesa. An STK prompt will be sent to the Kenyan mobile number you enter.</p>
                <form id="order-form" method="post" action="checkout.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="place_order">
                    <div class="form-group">
                        <label for="customer-name">Full name</label>
                        <input id="customer-name" name="customer_name" type="text" maxlength="120" autocomplete="name" required value="<?= h($formValues['customer_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="customer-email">Email</label>
                        <input id="customer-email" name="customer_email" type="email" maxlength="254" autocomplete="email" required value="<?= h($formValues['customer_email']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="customer-phone">Phone</label>
                        <input id="customer-phone" name="customer_phone" type="tel" maxlength="20" autocomplete="tel" placeholder="0712345678" required value="<?= h($formValues['customer_phone']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="shipping-address">Shipping address</label>
                        <textarea id="shipping-address" name="shipping_address" maxlength="500" autocomplete="street-address" required><?= h($formValues['shipping_address']) ?></textarea>
                    </div>
                    <button type="submit" class="checkout-btn">Pay with M-Pesa</button>
                </form>
            </section>
            <section class="order-summary-section">
                <h2>Order Summary</h2>
                <?php foreach ($cart as $id => $quantity): ?>
                    <?php if (isset($products[$id])): ?>
                        <div class="summary-item">
                            <span><?= h($products[$id]['name']) ?> × <?= (int) $quantity ?></span>
                            <span>Ksh. <?= format_money(product_price_cents($products[$id]['price']) * $quantity) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <hr>
                <h3>Estimated total: Ksh. <?= format_money($totalCents) ?></h3>
                <p class="muted">M-Pesa accepts whole shillings; the amount charged will be rounded to the nearest Ksh. Shipping fees, if any, are confirmed before delivery.</p>
            </section>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
