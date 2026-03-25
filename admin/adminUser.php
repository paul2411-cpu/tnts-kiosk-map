<?php
require_once __DIR__ . "/inc/auth.php";
require_superadmin("Only superadmins can manage admin accounts.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/admin_users.php";
require_once __DIR__ . "/inc/layout.php";

admin_users_ensure_schema($conn);

function admin_users_page_redirect(array $query = []): void {
  $url = "adminUser.php";
  if ($query) $url .= "?" . http_build_query($query);
  header("Location: " . $url);
  exit;
}

function admin_users_alert_type(array $flash): string {
  $type = trim((string)($flash["type"] ?? "info"));
  return in_array($type, ["success", "error"], true) ? $type : "info";
}

function admin_users_blank_form(): array {
  return [
    "admin_id" => 0,
    "full_name" => "",
    "username" => "",
    "role" => "staff",
    "account_type" => "staff",
  ];
}

function admin_users_form_from_row(array $row): array {
  $role = admin_users_normalize_role((string)($row["role"] ?? "staff"));
  return [
    "admin_id" => (int)($row["admin_id"] ?? 0),
    "full_name" => (string)($row["full_name"] ?? ""),
    "username" => (string)($row["username"] ?? ""),
    "role" => $role,
    "account_type" => $role === "superadmin"
      ? "staff"
      : admin_users_normalize_account_type((string)($row["account_type"] ?? "staff")),
  ];
}

function admin_users_format_datetime(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === "" || $raw === "0000-00-00 00:00:00") return "Never";
  $ts = strtotime($raw);
  if ($ts === false) return $raw;
  return date("M j, Y g:i A", $ts);
}

if (empty($_SESSION["admin_users_csrf"])) {
  try {
    $_SESSION["admin_users_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["admin_users_csrf"] = sha1(uniqid((string)mt_rand(), true));
  }
}
$adminUsersCsrf = (string)$_SESSION["admin_users_csrf"];

$flash = $_SESSION["admin_users_flash"] ?? null;
unset($_SESSION["admin_users_flash"]);

$roleDefinitions = admin_users_role_definitions();
$accountTypeDefinitions = admin_users_account_type_definitions();
$permissionDefinitions = admin_users_permission_definitions();
$currentAdminId = (int)($_SESSION["admin_id"] ?? 0);

$form = admin_users_blank_form();
$formPermissions = admin_users_default_permissions($form["role"], $form["account_type"]);
$formErrors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postedCsrf = (string)($_POST["admin_users_csrf"] ?? "");
  if ($postedCsrf === "" || !hash_equals($adminUsersCsrf, $postedCsrf)) {
    $_SESSION["admin_users_flash"] = [
      "type" => "error",
      "message" => "The admin-user action could not be verified. Please refresh and try again.",
    ];
    admin_users_page_redirect();
  }

  $action = trim((string)($_POST["action"] ?? "save"));
  $targetAdminId = (int)($_POST["admin_id"] ?? 0);

  if ($action === "delete") {
    try {
      $row = $targetAdminId > 0 ? admin_users_fetch_by_id($conn, $targetAdminId) : null;
      if (!$row) {
        $_SESSION["admin_users_flash"] = [
          "type" => "error",
          "message" => "The selected admin account could not be found.",
        ];
        admin_users_page_redirect();
      }

      if ($targetAdminId === $currentAdminId) {
        $_SESSION["admin_users_flash"] = [
          "type" => "error",
          "message" => "You cannot delete the account you are currently using.",
        ];
        admin_users_page_redirect(["edit" => $targetAdminId]);
      }

      if (($row["role"] ?? "") === "superadmin" && admin_users_count_superadmins($conn, $targetAdminId) <= 0) {
        $_SESSION["admin_users_flash"] = [
          "type" => "error",
          "message" => "You must keep at least one superadmin account.",
        ];
        admin_users_page_redirect(["edit" => $targetAdminId]);
      }

      $conn->begin_transaction();
      admin_users_delete_permissions($conn, $targetAdminId);

      $stmt = $conn->prepare("DELETE FROM admin_users WHERE admin_id = ? LIMIT 1");
      if (!$stmt) {
        throw new RuntimeException($conn->error);
      }
      $stmt->bind_param("i", $targetAdminId);
      $ok = $stmt->execute();
      $stmt->close();

      if (!$ok) {
        throw new RuntimeException("The admin account could not be deleted.");
      }

      $conn->commit();
      $_SESSION["admin_users_flash"] = [
        "type" => "success",
        "message" => "Admin account #" . $targetAdminId . " was deleted.",
      ];
    } catch (Throwable $e) {
      @$conn->rollback();
      $_SESSION["admin_users_flash"] = [
        "type" => "error",
        "message" => "The admin account could not be deleted right now.",
      ];
    }
    admin_users_page_redirect();
  }

  $form = [
    "admin_id" => $targetAdminId,
    "full_name" => trim((string)($_POST["full_name"] ?? "")),
    "username" => trim((string)($_POST["username"] ?? "")),
    "role" => admin_users_normalize_role((string)($_POST["role"] ?? "staff")),
    "account_type" => admin_users_normalize_account_type((string)($_POST["account_type"] ?? "staff")),
  ];
  if ($form["role"] === "superadmin") {
    $form["account_type"] = "staff";
  }
  $formPermissions = admin_users_permissions_from_submission($_POST, $form["role"]);
  $password = (string)($_POST["password"] ?? "");
  $confirmPassword = (string)($_POST["confirm_password"] ?? "");

  if ($form["full_name"] === "") {
    $formErrors[] = "Full name is required.";
  }
  if ($form["username"] === "") {
    $formErrors[] = "Username is required.";
  } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $form["username"])) {
    $formErrors[] = "Username must be 3 to 50 characters and use only letters, numbers, dot, underscore, or dash.";
  }

  $passwordRequired = $targetAdminId <= 0;
  if ($passwordRequired && $password === "") {
    $formErrors[] = "Password is required for a new admin account.";
  }
  if ($password !== "" && strlen($password) < 8) {
    $formErrors[] = "Password must be at least 8 characters.";
  }
  if (($password !== "" || $confirmPassword !== "" || $passwordRequired) && $password !== $confirmPassword) {
    $formErrors[] = "Password confirmation does not match.";
  }

  try {
    $existingUser = admin_users_fetch_by_username($conn, $form["username"]);
  } catch (Throwable $_) {
    $existingUser = null;
  }
  if ($existingUser && (int)($existingUser["admin_id"] ?? 0) !== $targetAdminId) {
    $formErrors[] = "That username is already in use.";
  }

  if ($targetAdminId === $currentAdminId && $form["role"] !== "superadmin") {
    $formErrors[] = "You cannot remove your own superadmin access while signed in.";
  }

  if ($targetAdminId > 0) {
    try {
      $currentRow = admin_users_fetch_by_id($conn, $targetAdminId);
    } catch (Throwable $_) {
      $currentRow = null;
    }
    if (!$currentRow) {
      $formErrors[] = "The selected admin account could not be found.";
    } elseif (($currentRow["role"] ?? "") === "superadmin" && $form["role"] !== "superadmin" && admin_users_count_superadmins($conn, $targetAdminId) <= 0) {
      $formErrors[] = "You must keep at least one superadmin account.";
    }
  }

  if (!$formErrors) {
    try {
      $conn->begin_transaction();

      if ($targetAdminId > 0) {
        if ($password !== "") {
          $passwordHash = password_hash($password, PASSWORD_DEFAULT);
          $stmt = $conn->prepare("UPDATE admin_users SET full_name = ?, username = ?, role = ?, password_hash = ? WHERE admin_id = ? LIMIT 1");
          if (!$stmt) throw new RuntimeException($conn->error);
          $stmt->bind_param("ssssi", $form["full_name"], $form["username"], $form["role"], $passwordHash, $targetAdminId);
        } else {
          $stmt = $conn->prepare("UPDATE admin_users SET full_name = ?, username = ?, role = ? WHERE admin_id = ? LIMIT 1");
          if (!$stmt) throw new RuntimeException($conn->error);
          $stmt->bind_param("sssi", $form["full_name"], $form["username"], $form["role"], $targetAdminId);
        }
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) throw new RuntimeException("Failed to update admin account.");
      } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param("ssss", $form["username"], $passwordHash, $form["full_name"], $form["role"]);
        $ok = $stmt->execute();
        $targetAdminId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if (!$ok || $targetAdminId <= 0) throw new RuntimeException("Failed to create admin account.");
      }

      if (!admin_users_upsert_permissions($conn, $targetAdminId, $form["account_type"], $formPermissions)) {
        throw new RuntimeException("Failed to save admin permissions.");
      }

      $conn->commit();

      if ($targetAdminId === $currentAdminId) {
        $freshRow = admin_users_fetch_by_id($conn, $targetAdminId);
        if ($freshRow) {
          admin_users_apply_session($freshRow);
        }
      }

      $_SESSION["admin_users_flash"] = [
        "type" => "success",
        "message" => $form["admin_id"] > 0
          ? "Admin account #" . $targetAdminId . " was updated."
          : "Admin account #" . $targetAdminId . " was created.",
      ];
      admin_users_page_redirect(["edit" => $targetAdminId]);
    } catch (Throwable $e) {
      @$conn->rollback();
      $formErrors[] = "The admin account could not be saved right now.";
    }
  }
}

$editId = isset($_GET["edit"]) ? (int)$_GET["edit"] : 0;
if ($_SERVER["REQUEST_METHOD"] !== "POST" && $editId > 0) {
  try {
    $editingRow = admin_users_fetch_by_id($conn, $editId);
  } catch (Throwable $_) {
    $editingRow = null;
  }
  if ($editingRow) {
    $form = admin_users_form_from_row($editingRow);
    $formPermissions = admin_users_permissions_from_row($editingRow);
  } else {
    $flash = [
      "type" => "error",
      "message" => "The selected admin account could not be found.",
    ];
  }
}

try {
  $rows = admin_users_fetch_all($conn);
} catch (Throwable $_) {
  $rows = [];
  if (!$flash) {
    $flash = [
      "type" => "error",
      "message" => "Admin accounts could not be loaded right now.",
    ];
  }
}

$stats = [
  "total" => count($rows),
  "superadmins" => 0,
  "staff_accounts" => 0,
  "student_leaders" => 0,
];
foreach ($rows as $row) {
  $role = admin_users_normalize_role((string)($row["role"] ?? "staff"));
  $type = admin_users_normalize_account_type((string)($row["account_type"] ?? "staff"));
  if ($role === "superadmin") {
    $stats["superadmins"]++;
  } else {
    $stats["staff_accounts"]++;
    if ($type === "student_leader") {
      $stats["student_leaders"]++;
    }
  }
}

$permissionPresets = [
  "staff" => admin_users_default_permissions("staff", "staff"),
  "student_leader" => admin_users_default_permissions("staff", "student_leader"),
];
$permissionPresetsJson = htmlspecialchars(json_encode($permissionPresets, JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8");

$GLOBALS["admin_extra_head"] = <<<HTML
<style>
  .admin-users-alert {
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid #d0d5dd;
    margin-bottom: 14px;
    font-size: 13px;
    font-weight: 800;
  }
  .admin-users-alert.success { background: #ecfdf3; border-color: #abefc6; color: #067647; }
  .admin-users-alert.error { background: #fef3f2; border-color: #fecdca; color: #b42318; }
  .admin-users-alert.info { background: #eff8ff; border-color: #b2ddff; color: #175cd3; }
  .admin-users-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
    align-items: start;
  }
  .admin-users-kpis {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 16px;
  }
  .admin-users-form-grid {
    display: grid;
    gap: 12px;
  }
  .admin-users-form-grid .row {
    grid-template-columns: 180px minmax(0, 1fr);
  }
  .admin-users-note {
    color: #475467;
    font-size: 12px;
    line-height: 1.6;
  }
  .admin-users-password-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .admin-users-permissions {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .admin-users-permission {
    border: 1px solid #d0d5dd;
    border-radius: 14px;
    padding: 12px;
    background: #fff;
    display: grid;
    gap: 6px;
  }
  .admin-users-permission label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 900;
    color: #101828;
  }
  .admin-users-permission input[type="checkbox"] {
    width: 18px;
    height: 18px;
  }
  .admin-users-permission-copy {
    color: #475467;
    font-size: 12px;
    line-height: 1.55;
  }
  .admin-users-sidebar-card {
    display: grid;
    gap: 12px;
  }
  .admin-users-profile {
    border: 1px solid #e4e7ec;
    border-radius: 14px;
    padding: 14px;
    background: #fcfcfd;
  }
  .admin-users-profile strong {
    display: block;
    margin-bottom: 6px;
    color: #101828;
  }
  .admin-users-errors {
    margin: 0 0 14px;
    padding-left: 20px;
    color: #b42318;
    font-size: 13px;
    font-weight: 800;
  }
  .admin-users-role-chip,
  .admin-users-type-chip,
  .admin-users-access-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .02em;
    white-space: nowrap;
  }
  .admin-users-role-chip.superadmin { background: #fef3f2; color: #b42318; }
  .admin-users-role-chip.staff { background: #eff8ff; color: #175cd3; }
  .admin-users-type-chip { background: #f2f4f7; color: #344054; }
  .admin-users-access-chip { background: #ecfdf3; color: #067647; margin-top: 6px; }
  .admin-users-table-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .admin-users-inline-form {
    display: inline-flex;
    margin: 0;
  }
  .admin-users-muted {
    color: #667085;
    font-size: 12px;
  }
  .admin-users-locked {
    color: #b42318;
    font-size: 12px;
    font-weight: 900;
  }
  @media (max-width: 1180px) {
    .admin-users-grid,
    .admin-users-kpis,
    .admin-users-password-grid,
    .admin-users-permissions {
      grid-template-columns: 1fr;
    }
  }
</style>
HTML;

admin_layout_start("Admin Users", "admins");
?>

<?php if ($flash): ?>
  <div class="admin-users-alert <?= htmlspecialchars(admin_users_alert_type($flash), ENT_QUOTES, "UTF-8") ?>">
    <?= htmlspecialchars((string)($flash["message"] ?? ""), ENT_QUOTES, "UTF-8") ?>
  </div>
<?php endif; ?>

<?php if ($formErrors): ?>
  <ul class="admin-users-errors">
    <?php foreach ($formErrors as $error): ?>
      <li><?= htmlspecialchars((string)$error, ENT_QUOTES, "UTF-8") ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="admin-users-kpis">
  <div class="card">
    <div class="kpi-title">TOTAL ADMINS</div>
    <div class="kpi-value"><?= number_format($stats["total"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">SUPERADMINS</div>
    <div class="kpi-value"><?= number_format($stats["superadmins"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">SCOPED ADMINS</div>
    <div class="kpi-value"><?= number_format($stats["staff_accounts"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">STUDENT LEADERS</div>
    <div class="kpi-value"><?= number_format($stats["student_leaders"]) ?></div>
  </div>
</div>

<div class="admin-users-grid">
  <div class="card">
    <div class="section-title"><?= $form["admin_id"] > 0 ? "Edit Admin Account" : "Add Admin Account" ?></div>
    <div class="admin-users-note" style="margin-bottom: 12px;">
      Superadmins always have full access. Scoped admins can be configured as school staff or student org/council leaders, then you can fine-tune exactly which modules they can reach.
    </div>

    <form method="post" class="form admin-users-form-grid" novalidate>
      <input type="hidden" name="admin_users_csrf" value="<?= htmlspecialchars($adminUsersCsrf, ENT_QUOTES, "UTF-8") ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="admin_id" value="<?= (int)$form["admin_id"] ?>">

      <div class="row">
        <div class="label">Full Name</div>
        <input class="input" name="full_name" value="<?= htmlspecialchars($form["full_name"], ENT_QUOTES, "UTF-8") ?>" placeholder="Enter full name" />
      </div>

      <div class="row">
        <div class="label">Username</div>
        <input class="input" name="username" value="<?= htmlspecialchars($form["username"], ENT_QUOTES, "UTF-8") ?>" placeholder="Enter username" />
      </div>

      <div class="row">
        <div class="label">Admin Role</div>
        <div>
          <select class="select" name="role" data-admin-role>
            <?php foreach ($roleDefinitions as $roleValue => $roleMeta): ?>
              <option value="<?= htmlspecialchars($roleValue, ENT_QUOTES, "UTF-8") ?>" <?= $form["role"] === $roleValue ? "selected" : "" ?>>
                <?= htmlspecialchars((string)$roleMeta["label"], ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="admin-users-note" data-role-note style="margin-top: 8px;">
            <?= htmlspecialchars((string)($roleDefinitions[$form["role"]]["description"] ?? ""), ENT_QUOTES, "UTF-8") ?>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="label">Account Type</div>
        <div>
          <select class="select" name="account_type" data-account-type>
            <?php foreach ($accountTypeDefinitions as $typeValue => $typeMeta): ?>
              <option value="<?= htmlspecialchars($typeValue, ENT_QUOTES, "UTF-8") ?>" <?= $form["account_type"] === $typeValue ? "selected" : "" ?>>
                <?= htmlspecialchars((string)$typeMeta["label"], ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="admin-users-note" data-account-type-note style="margin-top: 8px;">
            <?= htmlspecialchars((string)($accountTypeDefinitions[$form["account_type"]]["description"] ?? ""), ENT_QUOTES, "UTF-8") ?>
          </div>
        </div>
      </div>

      <div class="admin-users-password-grid">
        <div>
          <div class="label" style="margin-bottom: 6px;">Password<?= $form["admin_id"] > 0 ? " (Optional)" : "" ?></div>
          <input class="input" type="password" name="password" placeholder="<?= $form["admin_id"] > 0 ? "Leave blank to keep current password" : "Enter password" ?>" />
        </div>
        <div>
          <div class="label" style="margin-bottom: 6px;">Confirm Password</div>
          <input class="input" type="password" name="confirm_password" placeholder="Confirm password" />
        </div>
      </div>

      <div>
        <div class="label" style="margin-bottom: 8px;">Module Access</div>
        <div class="admin-users-note" data-permission-note style="margin-bottom: 10px;">
          Choose what this admin can open. Use the recommended preset if you want the standard staff or student-leader access set.
        </div>
        <div class="actions" style="margin-bottom: 10px;">
          <button type="button" class="btn gray" data-apply-permission-preset>Apply Recommended Access</button>
        </div>
        <div class="admin-users-permissions">
          <?php foreach ($permissionDefinitions as $permissionKey => $permissionMeta): ?>
            <div class="admin-users-permission">
              <label>
                <input
                  type="checkbox"
                  name="<?= htmlspecialchars($permissionKey, ENT_QUOTES, "UTF-8") ?>"
                  value="1"
                  data-permission-box
                  <?= !empty($formPermissions[$permissionKey]) ? "checked" : "" ?>
                />
                <span><?= htmlspecialchars((string)$permissionMeta["label"], ENT_QUOTES, "UTF-8") ?></span>
              </label>
              <div class="admin-users-permission-copy">
                <?= htmlspecialchars((string)$permissionMeta["description"], ENT_QUOTES, "UTF-8") ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="actions">
        <button class="btn primary" type="submit"><?= $form["admin_id"] > 0 ? "Save Admin" : "Create Admin" ?></button>
        <a class="btn gray" href="adminUser.php">Reset Form</a>
      </div>
    </form>
  </div>

  <div class="admin-users-sidebar-card">
    <div class="card">
      <div class="section-title">Recommended Profiles</div>
      <?php foreach ($accountTypeDefinitions as $typeValue => $typeMeta): ?>
        <div class="admin-users-profile">
          <strong><?= htmlspecialchars((string)$typeMeta["label"], ENT_QUOTES, "UTF-8") ?></strong>
          <div class="admin-users-note"><?= htmlspecialchars((string)$typeMeta["description"], ENT_QUOTES, "UTF-8") ?></div>
          <div class="admin-users-access-chip">
            <?= htmlspecialchars(admin_users_permission_summary($permissionPresets[$typeValue]), ENT_QUOTES, "UTF-8") ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="section-title">Safety Rules</div>
      <div class="admin-users-note">Only superadmins can open this page.</div>
      <div class="admin-users-note">The currently signed-in superadmin cannot delete their own account.</div>
      <div class="admin-users-note">The system also prevents removing the last remaining superadmin.</div>
    </div>
  </div>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Admin</th>
        <th>Role</th>
        <th>Account Type</th>
        <th>Access</th>
        <th>Last Login</th>
        <th>Created</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="8">No admin accounts found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <?php
            $rowId = (int)($row["admin_id"] ?? 0);
            $rowRole = admin_users_normalize_role((string)($row["role"] ?? "staff"));
            $rowType = $rowRole === "superadmin"
              ? "staff"
              : admin_users_normalize_account_type((string)($row["account_type"] ?? "staff"));
            $rowPermissions = admin_users_permissions_from_row($row);
            $rowAccessLabel = $rowRole === "superadmin"
              ? "Full access including Admin Users"
              : admin_users_permission_summary($rowPermissions);
          ?>
          <tr>
            <td><?= $rowId ?></td>
            <td>
              <strong><?= htmlspecialchars((string)($row["full_name"] ?: $row["username"]), ENT_QUOTES, "UTF-8") ?></strong><br>
              <span class="admin-users-muted">@<?= htmlspecialchars((string)($row["username"] ?? ""), ENT_QUOTES, "UTF-8") ?></span>
            </td>
            <td>
              <span class="admin-users-role-chip <?= htmlspecialchars($rowRole, ENT_QUOTES, "UTF-8") ?>">
                <?= htmlspecialchars(admin_users_role_label($rowRole), ENT_QUOTES, "UTF-8") ?>
              </span>
            </td>
            <td>
              <span class="admin-users-type-chip">
                <?= htmlspecialchars(admin_users_account_type_label($rowType), ENT_QUOTES, "UTF-8") ?>
              </span>
            </td>
            <td>
              <div><?= htmlspecialchars($rowAccessLabel, ENT_QUOTES, "UTF-8") ?></div>
            </td>
            <td><?= htmlspecialchars(admin_users_format_datetime((string)($row["last_login"] ?? "")), ENT_QUOTES, "UTF-8") ?></td>
            <td><?= htmlspecialchars(admin_users_format_datetime((string)($row["date_created"] ?? "")), ENT_QUOTES, "UTF-8") ?></td>
            <td>
              <div class="admin-users-table-actions">
                <a class="btn blue" href="adminUser.php?edit=<?= $rowId ?>">Edit</a>
                <?php if ($rowId === $currentAdminId): ?>
                  <span class="admin-users-locked">Current account</span>
                <?php else: ?>
                  <form method="post" class="admin-users-inline-form" onsubmit="return confirm('Delete this admin account?');">
                    <input type="hidden" name="admin_users_csrf" value="<?= htmlspecialchars($adminUsersCsrf, ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="admin_id" value="<?= $rowId ?>">
                    <button class="btn danger" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
(() => {
  const roleSelect = document.querySelector("[data-admin-role]");
  const accountTypeSelect = document.querySelector("[data-account-type]");
  const permissionBoxes = Array.from(document.querySelectorAll("[data-permission-box]"));
  const applyPresetButton = document.querySelector("[data-apply-permission-preset]");
  const roleNote = document.querySelector("[data-role-note]");
  const accountTypeNote = document.querySelector("[data-account-type-note]");
  const permissionNote = document.querySelector("[data-permission-note]");
  const roleDescriptions = <?= json_encode(array_map(static fn($meta) => (string)($meta["description"] ?? ""), $roleDefinitions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const accountDescriptions = <?= json_encode(array_map(static fn($meta) => (string)($meta["description"] ?? ""), $accountTypeDefinitions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const permissionPresets = JSON.parse("<?= $permissionPresetsJson ?>");
  let scopedSnapshot = Object.fromEntries(permissionBoxes.map((box) => [box.name, box.checked]));

  function collectScopedSnapshot() {
    scopedSnapshot = Object.fromEntries(permissionBoxes.map((box) => [box.name, box.checked]));
  }

  function applySnapshot(snapshot) {
    permissionBoxes.forEach((box) => {
      box.checked = !!snapshot[box.name];
    });
  }

  function applyRecommendedPreset() {
    const preset = permissionPresets[accountTypeSelect.value] || permissionPresets.staff || {};
    applySnapshot(preset);
    collectScopedSnapshot();
  }

  function syncMode(fromRoleChange = false) {
    const isSuperadmin = roleSelect.value === "superadmin";
    roleNote.textContent = roleDescriptions[roleSelect.value] || "";
    accountTypeNote.textContent = accountDescriptions[accountTypeSelect.value] || "";

    if (isSuperadmin) {
      collectScopedSnapshot();
      permissionBoxes.forEach((box) => {
        box.checked = true;
        box.disabled = true;
      });
      accountTypeSelect.disabled = true;
      applyPresetButton.disabled = true;
      permissionNote.textContent = "Superadmins always have full access, including this Admin Users page.";
      return;
    }

    accountTypeSelect.disabled = false;
    applyPresetButton.disabled = false;
    permissionBoxes.forEach((box) => {
      box.disabled = false;
    });

    if (fromRoleChange) {
      const hasSnapshot = Object.keys(scopedSnapshot).length > 0;
      if (hasSnapshot) applySnapshot(scopedSnapshot);
      else applyRecommendedPreset();
    }

    permissionNote.textContent = "Use the recommended preset for a quick start, then tick or untick modules to match this admin's limits.";
  }

  permissionBoxes.forEach((box) => {
    box.addEventListener("change", collectScopedSnapshot);
  });
  roleSelect.addEventListener("change", () => syncMode(true));
  accountTypeSelect.addEventListener("change", () => {
    accountTypeNote.textContent = accountDescriptions[accountTypeSelect.value] || "";
  });
  applyPresetButton.addEventListener("click", applyRecommendedPreset);
  syncMode(false);
})();
</script>

<?php admin_layout_end(); ?>
