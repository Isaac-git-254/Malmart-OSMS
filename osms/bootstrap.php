<?php
declare(strict_types=1);

$appEnvironment = getenv('APP_ENV') ?: 'production';
$isProduction = $appEnvironment === 'production';

if ($isProduction) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isProduction || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
if ($isProduction) {
    header('Strict-Transport-Security: max-age=31536000');
}

set_exception_handler(static function (Throwable $exception) use ($isProduction): void {
    error_log('Mallmart application error: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $isProduction
        ? 'The store is temporarily unavailable. Please try again later.'
        : 'The request could not be completed. Check the PHP error log for details.';
});

if (!in_array($appEnvironment, ['local', 'production'], true)) {
    throw new RuntimeException('APP_ENV must be set to local or production.');
}
define('APP_ENVIRONMENT', $appEnvironment);

function db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $environment = APP_ENVIRONMENT;
    $configuredHost = getenv('DB_HOST');
    $configuredDatabase = getenv('DB_NAME');
    $configuredUsername = getenv('DB_USER');
    $configuredPassword = getenv('DB_PASSWORD');

    if (
        $environment === 'production'
        && ($configuredHost === false || $configuredHost === ''
            || $configuredDatabase === false || $configuredDatabase === ''
            || $configuredUsername === false || $configuredUsername === ''
            || $configuredPassword === false || $configuredPassword === '')
    ) {
        throw new RuntimeException('Production database configuration is incomplete.');
    }

    $host = $configuredHost ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: ($environment === 'production' ? '3306' : '3307');
    $database = $configuredDatabase ?: 'shop_db';
    $username = $configuredUsername ?: 'root';
    $password = $configuredPassword ?: '';
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('The form expired. Please go back, refresh the page, and try again.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        ? $_SESSION['user']
        : null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    $statement = db()->prepare('SELECT role FROM users WHERE id = ?');
    $statement->execute([$user['id']]);
    $role = $statement->fetchColumn();

    if ($role !== 'admin') {
        http_response_code(403);
        exit('You are not authorized to view this page.');
    }

    $_SESSION['user']['role'] = 'admin';
    return $_SESSION['user'];
}

function format_money(int $cents): string
{
    return number_format($cents / 100, 2, '.', ',');
}

function product_price_cents(string|int|float $price): int
{
    return (int) round((float) $price * 100);
}

function image_path_is_safe(string $imagePath): bool
{
    return preg_match('~\Aimages/[a-zA-Z0-9_./ -]+\.(?:jpg|jpeg|png|webp|gif)\z~i', $imagePath) === 1
        && !str_contains($imagePath, '..')
        && !str_contains($imagePath, '//');
}
