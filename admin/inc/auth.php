<?php
// /admin/inc/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/admin_users.php";

function require_admin() {
  if (empty($_SESSION["admin_logged_in"])) {
    header("Location: login.php");
    exit;
  }
}

function admin_is_superadmin(): bool {
  return (($_SESSION["admin_role"] ?? "") === "superadmin");
}

function admin_name() {
  return $_SESSION["admin_full_name"] ?? $_SESSION["admin_username"] ?? "Admin";
}

function admin_role() {
  return $_SESSION["admin_role"] ?? "staff";
}

function admin_account_type(): string {
  return admin_users_normalize_account_type((string)($_SESSION["admin_account_type"] ?? "staff"));
}

function admin_role_label(): string {
  if (admin_is_superadmin()) {
    return admin_users_role_label("superadmin");
  }
  return admin_users_account_type_label(admin_account_type());
}

function admin_permissions(): array {
  $role = admin_role();
  $accountType = admin_account_type();
  $defaults = admin_users_default_permissions($role, $accountType);
  $stored = $_SESSION["admin_permissions"] ?? null;

  if (!is_array($stored)) {
    return $defaults;
  }

  foreach (admin_users_permission_keys() as $key) {
    if (array_key_exists($key, $stored)) {
      $defaults[$key] = !empty($stored[$key]);
    }
  }
  return $defaults;
}

function admin_has_permission(string $permission): bool {
  if (admin_is_superadmin()) {
    return true;
  }
  $permissions = admin_permissions();
  return !empty($permissions[$permission]);
}

function admin_is_json_request(): bool {
  if (isset($_GET["action"])) return true;

  $requestedWith = strtolower(trim((string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")));
  if ($requestedWith === "xmlhttprequest") return true;

  $accept = strtolower(trim((string)($_SERVER["HTTP_ACCEPT"] ?? "")));
  if ($accept !== "" && strpos($accept, "application/json") !== false) return true;

  $contentType = strtolower(trim((string)($_SERVER["CONTENT_TYPE"] ?? "")));
  if ($contentType !== "" && strpos($contentType, "application/json") !== false) return true;

  return false;
}

function admin_deny_access(string $message): void {
  http_response_code(403);

  if (admin_is_json_request()) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
      "ok" => false,
      "error" => $message,
    ], JSON_PRETTY_PRINT);
    exit;
  }

  $safeMessage = htmlspecialchars($message, ENT_QUOTES, "UTF-8");
  $safeName = htmlspecialchars((string)admin_name(), ENT_QUOTES, "UTF-8");
  echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Access Denied</title>
  <link rel="stylesheet" href="assets/admin.css">
  <style>
    body { background: #f6f7fb; }
    .access-shell {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
    }
    .access-card {
      width: min(520px, 96vw);
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 18px;
      box-shadow: 0 18px 50px rgba(16,24,40,.12);
      padding: 24px;
      display: grid;
      gap: 14px;
    }
    .access-kicker {
      color: #175cd3;
      font-size: 12px;
      font-weight: 900;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .access-title {
      font-size: 24px;
      font-weight: 900;
      color: #101828;
    }
    .access-copy {
      color: #475467;
      line-height: 1.6;
    }
    .access-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
  </style>
</head>
<body>
  <div class="access-shell">
    <div class="access-card">
      <div class="access-kicker">Restricted Area</div>
      <div class="access-title">Access denied</div>
      <div class="access-copy">{$safeMessage}</div>
      <div class="access-copy">Signed in as <strong>{$safeName}</strong>.</div>
      <div class="access-actions">
        <a class="btn primary" href="dashboard.php">Go to Dashboard</a>
        <a class="btn gray" href="logout.php">Log Out</a>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
  exit;
}

function require_superadmin(string $message = "Only superadmins can access this page."): void {
  require_admin();
  if (admin_is_superadmin()) return;
  admin_deny_access($message);
}

function require_admin_permission(string $permission, string $message = "You do not have permission to access this page."): void {
  require_admin();
  if (admin_has_permission($permission)) return;
  admin_deny_access($message);
}
