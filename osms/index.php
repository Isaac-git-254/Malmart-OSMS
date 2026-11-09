<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$searchQuery = $_GET['q'] ?? '';
$searchQuery = is_string($searchQuery) ? mb_substr(trim($searchQuery), 0, 100, 'UTF-8') : '';
$sql = 'SELECT id, name, description, category, price, image_url, stock
        FROM products
        WHERE is_active = 1';
$statement = db()->prepare(
    $searchQuery === ''
        ? $sql . ' ORDER BY created_at DESC, id DESC'
        : $sql . " AND (name LIKE ? ESCAPE '!' OR category LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!')
           ORDER BY created_at DESC, id DESC"
);
if ($searchQuery !== '') {
    $escapedQuery = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $searchQuery);
    $searchPattern = '%' . $escapedQuery . '%';
    $statement->execute([$searchPattern, $searchPattern, $searchPattern]);
} else {
    $statement->execute();
}
$products = $statement->fetchAll();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Online Store</title>
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<header>
    <nav class="navbar" aria-label="Main navigation">
        <a class="brand" href="index.php">Online Shopping Management System</a>
        <div class="nav-links">
            <?php if ($user !== null): ?>
                <span class="welcome-message">Welcome, <strong><?= h($user['username']) ?></strong></span>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a href="admin.php">Admin</a>
                <?php endif; ?>
                <a href="orders.php">My orders</a>
                <form method="post" action="logout.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="nav-button">Logout</button>
                </form>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
            <button type="button" class="cart-icon" id="cart-toggle" aria-controls="cart-sidebar" aria-expanded="false">
                Cart (<span id="cart-count">0</span>)
            </button>
        </div>
    </nav>
</header>

<main class="store-container">
    <section class="store-intro">
        <p class="eyebrow">Welcome to Mallmart</p>
        <h1>Find something you love.</h1>
        <p>Browse our collection and pay securely with a convenient M-Pesa prompt at checkout.</p>
    </section>

    <section aria-labelledby="products-title">
        <div class="section-heading">
            <h2 id="products-title">Our Products</h2>
            <span><?= count($products) ?> <?= count($products) === 1 ? 'item' : 'items' ?></span>
        </div>
        <form class="product-search" id="product-search-form" method="get" action="index.php" role="search">
            <label for="product-search">Search products</label>
            <div class="product-search-controls">
                <input id="product-search" name="q" type="search" value="<?= h($searchQuery) ?>" placeholder="Search products or categories" autocomplete="off">
                <button class="search-button" type="submit">Search</button>
            </div>
        </form>
        <?php if ($products === [] && $searchQuery !== ''): ?>
            <p class="no-products-message" role="status">No products match “<?= h($searchQuery) ?>”. Try a different search.</p>
        <?php elseif ($products === []): ?>
            <p class="notice">Our catalog is being updated. Please check back soon.</p>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="product-card" data-search="<?= h($product['name'] . ' ' . $product['category'] . ' ' . $product['description']) ?>">
                        <?php if (image_path_is_safe($product['image_url']) && is_file(__DIR__ . '/' . $product['image_url'])): ?>
                            <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="image-placeholder" role="img" aria-label="Image not available">Image coming soon</div>
                        <?php endif; ?>
                        <p class="product-category"><?= h($product['category']) ?></p>
                        <h3><?= h($product['name']) ?></h3>
                        <p class="product-description"><?= h($product['description'] ?: 'A quality product selected for you.') ?></p>
                        <p class="price">Ksh. <?= number_format((float) $product['price'], 2) ?></p>
                        <p class="stock-status">
                            <?= (int) $product['stock'] > 0 ? h($product['stock']) . ' in stock' : 'Currently unavailable' ?>
                        </p>
                        <button
                            class="add-to-cart-btn"
                            type="button"
                            data-id="<?= (int) $product['id'] ?>"
                            data-name="<?= h($product['name']) ?>"
                            data-price="<?= h($product['price']) ?>"
                            data-stock="<?= (int) $product['stock'] ?>"
                            <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>
                            <?= (int) $product['stock'] > 0 ? 'Add to Cart' : 'Out of Stock' ?>
                        </button>
                    </article>
                <?php endforeach; ?>
            </div>
            <p id="no-products-message" class="no-products-message" role="status" hidden>No products match your search.</p>
        <?php endif; ?>
    </section>
</main>

<aside id="cart-sidebar" class="cart-sidebar" aria-label="Shopping cart" aria-hidden="true" inert>
    <div class="cart-header">
        <h2>Your Cart</h2>
        <button type="button" class="close-btn" id="cart-close" aria-label="Close cart">&times;</button>
    </div>
    <div id="cart-items"></div>
    <div class="cart-footer">
        <h3>Total: Ksh. <span id="cart-total">0.00</span></h3>
        <form id="checkout-start-form" method="post" action="checkout.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="cart_json" id="cart-json">
            <button type="submit" class="checkout-btn">Continue to Checkout</button>
        </form>
    </div>
</aside>
<div id="cart-backdrop" class="cart-backdrop" hidden></div>
<script src="script.js?v=<?= (int) filemtime(__DIR__ . '/script.js') ?>" defer></script>
</body>
</html>
