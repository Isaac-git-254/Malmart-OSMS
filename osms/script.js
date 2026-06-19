let cart = JSON.parse(localStorage.getItem('shop_cart')) || [];

document.addEventListener('DOMContentLoaded', () => {
    updateCartUI();
    const buttons = document.querySelectorAll('.add-to-cart-btn');
    buttons.forEach(button => {
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const price = parseFloat(button.getAttribute('data-price'));
            addToCart(id, name, price);
        });
    });
});

function toggleCart() {
    document.getElementById('cart-sidebar').classList.toggle('open');
}

function addToCart(id, name, price) {
    const existingItem = cart.find(item => item.id === id);
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.push({ id, name, price, quantity: 1 });
    }
    saveCart();
    updateCartUI();
}

// NEW FUNCTION: Removes an item tracking reference entirely from local arrays
function removeFromCart(id) {
    // Filter out the item matching the unique database ID string
    cart = cart.filter(item => item.id !== id);
    saveCart();
    updateCartUI();
}

function saveCart() {
    localStorage.setItem('shop_cart', JSON.stringify(cart));
}

function updateCartUI() {
    const countElement = document.getElementById('cart-count');
    if (!countElement) return; 

    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    countElement.innerText = totalItems;

    const cartContainer = document.getElementById('cart-items');
    let totalPrice = 0;
    cartContainer.innerHTML = '';

    if (cart.length === 0) {
        cartContainer.innerHTML = '<p style="color:#888; text-align:center; margin-top:2rem;">Your cart is empty.</p>';
    }

    cart.forEach(item => {
        const itemTotal = item.price * item.quantity;
        totalPrice += itemTotal;
        const itemElement = document.createElement('div');
        itemElement.classList.add('cart-item');
        
        // Added a styled delete button inline next to the pricing metadata
        itemElement.innerHTML = `
            <div>
                <strong>${item.name}</strong>
                <br><small>$${item.price.toFixed(2)} x ${item.quantity}</small>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span>$${itemTotal.toFixed(2)}</span>
                <button class="remove-item-btn" onclick="removeFromCart('${item.id}')" title="Remove item">×</button>
            </div>
        `;
        cartContainer.appendChild(itemElement);
    });
    document.getElementById('cart-total').innerText = totalPrice.toFixed(2);
}

function checkout() {
    if (cart.length === 0) {
        alert("Your cart is empty!");
        return;
    }
    window.location.href = 'checkout.php';
}
