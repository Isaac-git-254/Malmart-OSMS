<?php
session_start(); // <-- Add this line at the very top

$host = 'localhost:3307';

$user = 'root';
$pass = '';
$dbname = 'shop_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$stmt = $pdo->query("SELECT * FROM products");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickShop | Online Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

   <header>
    <div class="navbar">
        <h1 onclick="window.location.href='index.php'" style="cursor:pointer;">Mallmart</h1>
        
        <div style="display: flex; gap: 1.5rem; align-items: center;">
            <?php if (isset($_SESSION['user'])): ?>
                <!-- Displays the username persistently even upon manual browser refreshes -->
                <span style="color: white;">Welcome, <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong></span>
                <!-- Navigates directly to the session destruction template -->
                <a href="logout.php" style="color: #ff5722; text-decoration: none; font-weight: bold;">Logout</a>
            <?php else: ?>
                <a href="login.php" style="color: white; text-decoration: none; font-weight: bold;">Login</a>
            <?php endif; ?>

            <div class="cart-icon" onclick="toggleCart()">
                🛒 Cart (<span id="cart-count">0</span>)
            </div>
        </div>
    </div>
</header>


    <main class="store-container">
        <h2>Our Products</h2>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
                <div class="product-card">
                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <p class="price">$<?php echo number_format($product['price'], 2); ?></p>
                    <button class="add-to-cart-btn" 
                            data-id="<?php echo $product['id']; ?>" 
                            data-name="<?php echo htmlspecialchars($product['name']); ?>" 
                            data-price="<?php echo $product['price']; ?>">
                        Add to Cart
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <div id="cart-sidebar" class="cart-sidebar">
        <div class="cart-header">
            <h3>Your Shopping Cart</h3>
            <button class="close-btn" onclick="toggleCart()">×</button>
        </div>
        <div id="cart-items"></div>
        <div class="cart-footer">
            <h4>Total: $<span id="cart-total">0.00</span></h4>
            <button class="checkout-btn" onclick="checkout()">Proceed to Checkout</button>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>
