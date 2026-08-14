<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    refreshUserSession((int) currentUser()['id']);
    header('Location: ' . homePage());
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
if ($redirect !== '' && (!preg_match('/^[a-z0-9_\-\/\.?=&%]+$/i', $redirect) || str_contains($redirect, '//'))) {
    $redirect = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (attemptLogin($username, $password)) {
        $target = $redirect !== '' ? $redirect : homePage();
        $targetPage = basename(parse_url($target, PHP_URL_PATH) ?: $target, '.php');
        $needed = pagePermissionMap()[$targetPage] ?? null;
        if ($needed !== null) {
            $ok = is_array($needed) ? canAny($needed) : can($needed);
            if (!$ok) {
                $target = homePage();
            }
        }
        header('Location: ' . $target);
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

$pharmacyName = getSetting('pharmacy_name', 'TADE PHARMACY');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= htmlspecialchars($pharmacyName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/style.css">
  <?php renderPharmacyFavicon(); ?>
</head>
<body class="auth-body">
  <div class="auth-bg"></div>
  <div class="login-page">
    <a href="welcome.php" class="login-back">
      <i data-lucide="arrow-left"></i> Back to welcome
    </a>

    <div class="login-card">
      <div class="login-brand">
        <?php renderPharmacyLogo('logo-icon--md'); ?>
        <div class="logo-text">
          <span class="logo-main"><?= htmlspecialchars($pharmacyName) ?></span>
          <span class="logo-sub">Manager · Pharmacist · Cashier · Admin</span>
        </div>
      </div>

      <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;">
        <i data-lucide="alert-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" class="login-form" autocomplete="on">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <div class="form-group">
          <label for="username">Username</label>
          <div class="input-icon">
            <i data-lucide="user"></i>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Enter username" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-icon">
            <i data-lucide="lock"></i>
            <input type="password" id="password" name="password" placeholder="Enter password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary login-submit">
          <i data-lucide="log-in"></i> Sign In
        </button>
      </form>

      <p class="login-hint">Default admin: <strong>admin</strong> / <strong>admin123</strong></p>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>
</html>
