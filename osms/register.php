<?php
session_start();
$host = 'localhost:3307';
 // Update to 'localhost:3307' if you changed your port earlier
$user = 'root';
$pass = '';
$dbname = 'shop_db';
$error = '';
$success = '';

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
        // Encrypt the password securely before saving
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->execute([$username, $hashed_password]);
            $success = "Account created successfully! You can now log in.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Unique constraint violation error
                $error = "That username is already taken!";
            } else {
                $error = "An error occurred. Please try again.";
            }
        }
    } else {
        $error = "Please fill in all fields.";
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
    <header><div class="navbar"><h1 onclick="window.location.href='index.php'" style="cursor:pointer;">Mallmart</h1></div></header>

    <div class="auth-container">
        <form class="auth-form" method="POST" action="register.php">
            <h2>Create Account</h2>
            <?php if ($error): ?><p class="error-msg"><?php echo $error; ?></p><?php endif; ?>
            <?php if ($success): ?><p style="color: green; margin-bottom: 1rem;"><?php echo $success; ?></p><?php endif; ?>
            
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Choose a username">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Choose a password">
            </div>
            <button type="submit" class="checkout-btn">Sign Up</button>
            <p style="margin-top:1rem; font-size:0.9rem; text-align:center;">Already have an account? <a href="login.php">Login here</a></p>
        </form>
    </div>
</body>
</html>
