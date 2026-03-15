<?php
// /admin/login.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/inc/db.php";

$error = "";

if (!empty($_SESSION["admin_logged_in"])) {
  header("Location: dashboard.php");
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = $_POST["password"] ?? "";

  if ($username === "" || $password === "") {
    $error = "Please enter username and password.";
  } else {
    $stmt = $conn->prepare("SELECT admin_id, username, password_hash, full_name, role FROM admin_users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user["password_hash"])) {
      session_regenerate_id(true);
      $_SESSION["admin_logged_in"] = true;
      $_SESSION["admin_id"] = $user["admin_id"];
      $_SESSION["admin_username"] = $user["username"];
      $_SESSION["admin_full_name"] = $user["full_name"] ?: $user["username"];
      $_SESSION["admin_role"] = $user["role"];

      // Update last login
      $stmt2 = $conn->prepare("UPDATE admin_users SET last_login=NOW() WHERE admin_id=?");
      $stmt2->bind_param("i", $user["admin_id"]);
      $stmt2->execute();
      $stmt2->close();

      header("Location: dashboard.php");
      exit;
    } else {
      $error = "Invalid login. Try again.";
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin Login</title>
  <link rel="stylesheet" href="assets/admin.css"/>
  <style>
    body{ background:#f6f7fb; }
    .login-shell{
      min-height:100vh;
      display:grid;
      place-items:center;
      padding:24px;
    }
    .login-card{
      width:min(520px, 96vw);
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow: 0 18px 50px rgba(16,24,40,.12);
      padding:22px;
    }
    .login-title{
      font-weight:950;
      font-size:20px;
      letter-spacing:.4px;
      text-align:center;
      margin: 4px 0 12px;
    }
    .login-logo{
      display:flex;
      justify-content:center;
      margin: 10px 0 18px;
    }
    .login-logo img{
      width: 120px;
      height: 120px;
      object-fit: contain;
      border-radius: 16px;
      border: 1px solid #eee;
      padding: 10px;
      background:#fff;
    }
    .error{
      background:#fee4e2;
      border:1px solid #fecdca;
      color:#7a271a;
      padding:10px 12px;
      border-radius:12px;
      font-weight:800;
      font-size:13px;
      margin-bottom: 12px;
    }
    .login-actions{ display:flex; gap:10px; }
    .login-actions .btn{ flex:1; }
  </style>
</head>
<body>
  <div class="login-shell">
    <div class="login-card">
      <div class="login-title">ADMIN LOGIN</div>

      <div class="login-logo">
        <img src="../assets/logo.jpg" alt="TNTS Logo">
      </div>

      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" class="form" autocomplete="off">
        <div class="row">
          <div class="label">Username</div>
          <input class="input" name="username" placeholder="Enter username" />
        </div>
        <div class="row">
          <div class="label">Password</div>
          <input class="input" type="password" name="password" placeholder="Enter password" />
        </div>

        <div class="login-actions">
          <button class="btn primary" type="submit">Login</button>
          <a class="btn gray" href="../pages/map.php">Back to Kiosk</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
