<?php
session_start();
$host = 'localhost:3307';
 // Update to 'localhost:3307' if you changed your port earlier
$user = 'root';
$pass = '';
$dbname = 'shop_db';
$error = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        // Pull the user details matching the typed username
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user_record = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify the typed password matches the encrypted database hash
        if ($user_record && password_verify($password, $user_record['password_hash'])) {
            $_SESSION['user'] = $user_record['username'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickShop | Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header><div class="navbar"><h1 onclick="window.location.href='index.php'" style="cursor:pointer;">Mallmart</h1></div></header>

    <div class="auth-container">
        <form class="auth-form" method="POST" action="login.php">
            <h2>Account Login</h2>
            <?php if ($error): ?><p class="error-msg"><?php echo $error; ?></p><?php endif; ?>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Enter username">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Enter password">
            </div>
            <button type="submit" class="checkout-btn">Login</button>
            <p style="margin-top:1rem; font-size:0.9rem; text-align:center;">New customer? <a href="register.php">Register here</a></p>
        </form>
    </div>
</body>
</html>
