-- Run once against the shop_db database to create/upgrade the store schema.
-- Existing users and product rows are retained; existing stock starts at zero.

CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'customer';

CREATE TABLE IF NOT EXISTS products (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS description TEXT NULL,
    ADD COLUMN IF NOT EXISTS category VARCHAR(100) NOT NULL DEFAULT 'General',
    ADD COLUMN IF NOT EXISTS stock INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(24) NOT NULL,
    user_id INT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    shipping_address VARCHAR(500) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'mpesa',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    mpesa_checkout_request_id VARCHAR(100) NULL,
    mpesa_merchant_request_id VARCHAR(100) NULL,
    mpesa_receipt_number VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY order_number (order_number),
    KEY orders_user_created (user_id, created_at),
    KEY orders_status_created (status, created_at),
    KEY orders_mpesa_checkout (mpesa_checkout_request_id),
    CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS mpesa_checkout_request_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS mpesa_merchant_request_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS mpesa_receipt_number VARCHAR(50) NULL;
ALTER TABLE orders
    ALTER COLUMN payment_method SET DEFAULT 'mpesa',
    ALTER COLUMN payment_status SET DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(255) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY order_items_order (order_id),
    CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add new catalog items here; copy each image into images/ and set its relative path.
INSERT INTO products (name, description, category, price, image_url, stock)
SELECT 'Wireless Earbuds', 'Compact wireless earbuds for everyday listening.', 'Electronics', 49.99, 'images/headphones.jpg', 10
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Wireless Earbuds');

INSERT INTO products (name, description, category, price, image_url, stock)
SELECT 'Gaming Keyboard', 'Responsive keyboard for work and play.', 'Electronics', 79.99, 'images/keyboard.jpg', 10
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Gaming Keyboard');

INSERT INTO products (name, description, category, price, image_url, stock)
SELECT 'Canvas Sneakers', 'Comfortable everyday sneakers with a classic look.', 'Fashion', 59.99, 'images/nike sneakers.jpg', 10
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Canvas Sneakers');

INSERT INTO products (name, description, category, price, image_url, stock)
SELECT 'Leather Wallet', 'A timeless leather accessory for daily essentials.', 'Accessories', 34.99, 'images/leather watch.jpg', 10
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Leather Wallet');
