<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickShop | Checkout</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="navbar">
            <h1 onclick="window.location.href='index.php'" style="cursor:pointer;">QuickShop</h1>
            <a href="index.php" style="color:white; text-decoration:none; font-weight:bold;">← Back to Shop</a>
        </div>
    </header>

    <main class="store-container">
        <h2>Complete Your Order</h2>
        <div class="checkout-layout">
            <div class="checkout-form-section">
                <h3>Shipping Details</h3>
                <form id="order-form" onsubmit="placeOrder(event)">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" required>
                    </div>
                    <div class="form-group">
                        <label>Shipping Address</label>
                        <input type="text" required>
                    </div>
                    <div class="form-group">
                        <label>Credit Card Number</label>
                        <input type="text" placeholder="1111-2222-3333-4444" required>
                    </div>
                    <button type="submit" class="checkout-btn">Pay & Place Order</button>
                </form>
            </div>

            <div class="order-summary-section">
                <h3>Order Summary</h3>
                <div id="checkout-items"></div>
                <hr style="margin: 1rem 0; border: 0; border-top: 1px solid #ddd;">
                <h4>Total: $<span id="checkout-total">0.00</span></h4>
            </div>
        </div>
    </main>

    <script>
        // Load cart items from localStorage on checkout page
        document.addEventListener('DOMContentLoaded', () => {
            const cart = JSON.parse(localStorage.getItem('shop_cart')) || [];
            const container = document.getElementById('checkout-items');
            let total = 0;

            if (cart.length === 0) {
                container.innerHTML = '<p>Your cart is empty.</p>';
                return;
            }

            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                const div = document.createElement('div');
                div.style.display = 'flex';
                div.style.justifyContent = 'space-between';
                div.style.marginBottom = '0.5rem';
                div.innerHTML = `<span>${item.name} (x${item.quantity})</span><span>$${itemTotal.toFixed(2)}</span>`;
                container.appendChild(div);
            });

            document.getElementById('checkout-total').innerText = total.toFixed(2);
        });

        function placeOrder(e) {
            e.preventDefault();
            alert('🎉 Payment successful! Your order has been placed.');
            localStorage.removeItem('shop_cart');
            window.location.href = 'index.php';
        }
    </script>
</body>
</html>
