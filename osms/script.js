let cart = [];

try {
    const savedCart = JSON.parse(localStorage.getItem('shop_cart') || '[]');
    if (Array.isArray(savedCart)) {
        cart = savedCart
            .filter(item => item && /^\d+$/.test(String(item.id)) && Number.isInteger(Number(item.quantity)) && Number(item.quantity) > 0)
            .map(item => ({ id: String(item.id), quantity: Math.min(Number(item.quantity), 99) }));
    }
} catch (error) {
    console.error('Could not read the saved cart:', error);
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('product-search');
    if (searchInput) {
        searchInput.addEventListener('input', filterProducts);
    }

    updateCartUI();

    document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', () => {
            addToCart(button.dataset.id, Number(button.dataset.stock));
        });
    });

    document.getElementById('cart-toggle')?.addEventListener('click', toggleCart);
    document.getElementById('cart-close')?.addEventListener('click', toggleCart);
    document.getElementById('cart-backdrop')?.addEventListener('click', toggleCart);
    document.getElementById('checkout-start-form')?.addEventListener('submit', event => {
        if (cart.length === 0) {
            event.preventDefault();
            return;
        }
        document.getElementById('cart-json').value = JSON.stringify(cart);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && document.getElementById('cart-sidebar')?.classList.contains('open')) {
            toggleCart();
        }
    });
});

function filterProducts(event) {
    const query = event.target.value.trim().toLocaleLowerCase();
    const productCards = document.querySelectorAll('.product-card');
    let visibleProducts = 0;

    productCards.forEach(card => {
        const isMatch = card.dataset.search.toLocaleLowerCase().includes(query);
        card.hidden = !isMatch;
        if (isMatch) visibleProducts += 1;
    });

    const noProductsMessage = document.getElementById('no-products-message');
    if (noProductsMessage) {
        noProductsMessage.hidden = visibleProducts > 0;
    }
}

function productCatalog() {
    return new Map(Array.from(document.querySelectorAll('.product-card')).map(card => {
        const button = card.querySelector('.add-to-cart-btn');
        return [button.dataset.id, {
            name: card.querySelector('h3').textContent,
            price: Number(button.dataset.price),
            stock: Number(button.dataset.stock),
        }];
    }));
}

function toggleCart() {
    const sidebar = document.getElementById('cart-sidebar');
    const backdrop = document.getElementById('cart-backdrop');
    const toggle = document.getElementById('cart-toggle');
    if (!sidebar) return;

    const isOpen = sidebar.classList.toggle('open');
    sidebar.setAttribute('aria-hidden', String(!isOpen));
    sidebar.inert = !isOpen;
    toggle?.setAttribute('aria-expanded', String(isOpen));
    if (backdrop) backdrop.hidden = !isOpen;
}

function addToCart(id, stock) {
    const existingItem = cart.find(item => item.id === id);
    const currentQuantity = existingItem ? existingItem.quantity : 0;
    if (currentQuantity >= stock || currentQuantity >= 99) {
        alert('The available quantity is already in your cart.');
        return;
    }

    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.push({ id, quantity: 1 });
    }
    saveCart();
    updateCartUI();
}

function removeFromCart(id) {
    cart = cart.filter(item => item.id !== id);
    saveCart();
    updateCartUI();
}

function saveCart() {
    try {
        localStorage.setItem('shop_cart', JSON.stringify(cart));
    } catch (error) {
        console.error('Could not save the cart in this browser:', error);
    }
}

function updateCartUI() {
    const countElement = document.getElementById('cart-count');
    const cartContainer = document.getElementById('cart-items');
    const totalElement = document.getElementById('cart-total');
    if (!countElement || !cartContainer || !totalElement) return;

    const catalog = productCatalog();
    cart = cart.filter(item => catalog.has(item.id));
    saveCart();

    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    countElement.textContent = String(totalItems);
    cartContainer.replaceChildren();
    let totalPrice = 0;

    if (cart.length === 0) {
        const emptyMessage = document.createElement('p');
        emptyMessage.className = 'empty-cart-message';
        emptyMessage.textContent = 'Your cart is empty.';
        cartContainer.appendChild(emptyMessage);
    }

    cart.forEach(item => {
        const product = catalog.get(item.id);
        const itemTotal = product.price * item.quantity;
        totalPrice += itemTotal;

        const itemElement = document.createElement('div');
        itemElement.className = 'cart-item';

        const details = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = product.name;
        const pricing = document.createElement('small');
        pricing.textContent = `Ksh. ${product.price.toFixed(2)} × ${item.quantity}`;
        details.append(name, document.createElement('br'), pricing);

        const actions = document.createElement('div');
        actions.className = 'cart-item-actions';
        const subtotal = document.createElement('span');
        subtotal.textContent = `Ksh. ${itemTotal.toFixed(2)}`;
        const removeButton = document.createElement('button');
        removeButton.className = 'remove-item-btn';
        removeButton.type = 'button';
        removeButton.title = 'Remove item';
        removeButton.setAttribute('aria-label', `Remove ${product.name}`);
        removeButton.textContent = '×';
        removeButton.addEventListener('click', () => removeFromCart(item.id));
        actions.append(subtotal, removeButton);
        itemElement.append(details, actions);
        cartContainer.appendChild(itemElement);
    });

    totalElement.textContent = totalPrice.toFixed(2);
    const checkoutButton = document.querySelector('#checkout-start-form button[type="submit"]');
    if (checkoutButton) checkoutButton.disabled = cart.length === 0;
}
