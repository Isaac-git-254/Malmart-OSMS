<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!is_string($username) || !is_string($password) || trim($username) === '' || $password === '') {
        $error = 'Enter a valid username and password.';
    } else {
        $statement = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $statement->execute([trim($username)]);
        $record = $statement->fetch();

        if ($record && password_verify($password, $record['password_hash'])) {
            if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
                $update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $record['id']]);
            }

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $record['id'],
                'username' => $record['username'],
                'role' => $record['role'],
            ];
            header('Location: index.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mallmart | Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header><nav class="navbar"><a class="brand" href="index.php">Mallmart</a><a href="index.php">Back to shop</a></nav></header>
<main class="auth-container">
    <form class="auth-form" method="post" action="login.php">
        <h1>Account Login</h1>
        <?php if ($error !== ''): ?><p class="error-msg" role="alert"><?= h($error) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-group">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" required maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Enter username">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required maxlength="255" autocomplete="current-password" placeholder="Enter password">
        </div>
        <button type="submit" class="checkout-btn">Login</button>
        <p class="form-footnote">New customer? <a href="register.php">Create an account</a></p>
    </form>
</main>
</body>
</html>
