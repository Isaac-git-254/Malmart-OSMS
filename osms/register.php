<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';
$success = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $usernameInput = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmation = $_POST['password_confirmation'] ?? '';

    if (!is_string($usernameInput) || !is_string($password) || !is_string($confirmation)) {
        $error = 'Enter valid account details.';
    } else {
        $username = trim($usernameInput);

        if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $username)) {
            $error = 'Username must be 3–50 characters using letters, numbers, dots, dashes, or underscores.';
        } elseif (strlen($password) < 12 || strlen($password) > 255) {
            $error = 'Choose a password between 12 and 255 characters.';
        } elseif ($password !== $confirmation) {
            $error = 'The passwords do not match.';
        } else {
            try {
                $statement = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $success = 'Account created. You can now log in.';
                $username = '';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'That username is already taken.';
                } else {
                    error_log('Mallmart registration failed: ' . $exception->getMessage());
                    $error = 'We could not create your account. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Register</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a><a href="index.php">Back to shop</a></nav></header>
<main class="auth-container">
    <form class="auth-form" method="post" action="register.php">
        <h1>Create Account</h1>
        <?php if ($error !== ''): ?><p class="error-msg" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if ($success !== ''): ?><p class="success-msg" role="status"><?= h($success) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-group">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="username" value="<?= h($username) ?>" placeholder="Choose a username">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required minlength="12" maxlength="255" autocomplete="new-password" placeholder="At least 12 characters">
        </div>
        <div class="form-group">
            <label for="password-confirmation">Confirm password</label>
            <input id="password-confirmation" type="password" name="password_confirmation" required minlength="12" maxlength="255" autocomplete="new-password">
        </div>
        <button type="submit" class="checkout-btn">Create account</button>
        <p class="form-footnote">Already registered? <a href="login.php">Login</a></p>
    </form>
</main>
</body>
</html>
