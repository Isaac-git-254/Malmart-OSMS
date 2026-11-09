# Mallmart

Mallmart is a PHP/MySQL storefront with account registration, a persistent product catalog and inventory, M-Pesa STK Push payments, customer order cancellation, printable payment receipts, customer order history, and an administrator dashboard.

## Local setup (XAMPP)

1. Create a MySQL database named `shop_db`.
2. Import `database.sql` into that database. It adds the store tables/columns and sample products without removing existing users or products. Existing products start with zero stock; set their stock in the admin dashboard before selling them. Re-import the file on an existing installation to add the M-Pesa order columns.
3. Set `APP_ENV=local` in the XAMPP Apache environment (for example, with `SetEnv APP_ENV local` in the local virtual host). The local defaults are host `127.0.0.1`, port `3307`, database `shop_db`, and user `root` with no password. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` if your local database differs.
4. Open `http://localhost/Malmart-OSMS/osms/`.
5. Register an account, then promote it to administrator in the database:

   ```sql
   UPDATE users SET role = 'admin' WHERE username = 'your-username';
   ```

   Sign out and back in, then use the **Admin** link in the store header.

## Production configuration

Set these environment variables in the web server/PHP-FPM environment; do not put credentials in source control. Without an explicit `APP_ENV=local`, the app defaults to production-safe configuration and requires database credentials.

| Variable | Required in production | Description |
| --- | --- | --- |
| `APP_ENV` | Yes | Set to `production` |
| `DB_HOST` | Yes | Database host |
| `DB_PORT` | No | Defaults to `3306` |
| `DB_NAME` | Yes | Database name |
| `DB_USER` | Yes | Dedicated application database user |
| `DB_PASSWORD` | Yes | Database password |

Use HTTPS, a dedicated database account with only the required privileges, regular database backups, and a supported PHP/MySQL release. Keep PHP error display disabled in production and route error logs to a protected location. The web server must be configured to serve `osms/` as the application root and prevent access to SQL files and other deployment-only files.

## M-Pesa STK Push setup

Checkout requires a configured Safaricom Daraja account. When a customer submits a Kenyan mobile number, Mallmart requests an STK Push and does not mark the order paid until the authenticated callback confirms the amount and phone number.

Set these variables in the Apache/PHP environment; never commit Daraja credentials:

| Variable | Required | Description |
| --- | --- | --- |
| `MPESA_ENVIRONMENT` | No | `sandbox` or `production`; defaults to sandbox locally and production when `APP_ENV=production` |
| `MPESA_TRANSACTION_TYPE` | No | `CustomerPayBillOnline` (default) or `CustomerBuyGoodsOnline` for a till |
| `MPESA_CONSUMER_KEY` | Yes | Daraja app consumer key |
| `MPESA_CONSUMER_SECRET` | Yes | Daraja app consumer secret |
| `MPESA_SHORTCODE` | Yes | Paybill or till shortcode configured for STK Push |
| `MPESA_PASSKEY` | Yes | Daraja Lipa Na M-Pesa Online passkey |
| `MPESA_CALLBACK_URL` | Yes | Public HTTPS URL to `mpesa_callback.php` (for example, `https://shop.example.com/mpesa_callback.php`) |
| `MPESA_CALLBACK_TOKEN` | Yes | Random secret of at least 32 characters, shared by the app and its callback URL |

Generate the callback token with `php -r "echo bin2hex(random_bytes(32));"`. The app adds this token as a query parameter to the callback URL and verifies it before processing callbacks. Configure the exact callback URL in the Daraja app if your account requires callback URL registration. The callback endpoint must be publicly reachable over HTTPS; `localhost` is not reachable by Safaricom. Use a public HTTPS development URL when testing in sandbox.

Enable the PHP cURL extension. Sandbox and production each need their own Daraja credentials and shortcode. M-Pesa accepts whole-shilling amounts, so the final cart total is rounded to the nearest shilling and limited to Ksh. 150,000 per checkout. Stock is reserved while payment is pending and restored when Safaricom reports a failed payment. Fulfillment cannot start until the callback marks payment as paid. Orders that remain pending after a timeout may need review in the admin dashboard.

Customers can cancel unpaid orders before fulfillment begins from the order confirmation or **My Orders** page. Receipts are available after payment is confirmed; use **Print / Save PDF** on the receipt page to print it or save a PDF from the browser.

For existing database installations, back up the database and run `database.sql` to add the callback fields and update defaults. Existing historical cash-on-delivery orders are retained; new checkout orders can only use M-Pesa. The application does not collect or store card numbers.

## Products and images

Place product images under `images/` and enter a relative path such as `images/new-product.jpg` in the admin product form. Only local image paths with supported image extensions are accepted.
