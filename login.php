<?php
// login.php
require_once 'db.php';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'email' => $user['email'] ?? '',
            'can_create_surveys' => (bool)$user['can_create_surveys'],
            'can_view_all_results' => (bool)$user['can_view_all_results'],
            'can_export_results' => (bool)($user['can_export_results'] ?? false),
            'can_view_respondent_identities' => (bool)($user['can_view_respondent_identities'] ?? false),
            'can_manage_users' => (bool)($user['can_manage_users'] ?? false)
        ];
        header("Location: index.php");
        exit;
    } else {
        $msg = 'Invalid username or password.';
    }
}
include 'header.php';
?>
<h2>Sign In</h2>
<?php if ($msg): ?><div class="alert alert-error"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="POST">
    <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
    </div>
    <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
    </div>
    <button type="submit" class="btn">Log In</button>
</form>

<p style="margin-top: 20px; font-size: 0.85em; color: #64748b;">
    Default superadmin credentials: <strong>admin</strong> / <strong>admin123</strong>
</p>
<?php include 'footer.php'; ?>