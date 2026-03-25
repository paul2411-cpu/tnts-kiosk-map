<?php

function admin_users_col(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function admin_users_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException($conn->error);
  }
}

function admin_users_permission_definitions(): array {
  return [
    "manage_announcements" => [
      "column" => "can_manage_announcements",
      "label" => "Announcements",
      "description" => "Create, edit, schedule, and delete announcements.",
    ],
    "manage_events" => [
      "column" => "can_manage_events",
      "label" => "Events",
      "description" => "Create, edit, and remove events.",
    ],
    "manage_buildings" => [
      "column" => "can_manage_buildings",
      "label" => "Buildings",
      "description" => "Manage building records and building imports.",
    ],
    "manage_rooms" => [
      "column" => "can_manage_rooms",
      "label" => "Rooms",
      "description" => "Manage room records and room imports.",
    ],
    "manage_facilities" => [
      "column" => "can_manage_facilities",
      "label" => "Facilities",
      "description" => "Manage facility records and facility map links.",
    ],
    "manage_feedback" => [
      "column" => "can_manage_feedback",
      "label" => "Feedback",
      "description" => "Review and update public feedback status.",
    ],
    "manage_map" => [
      "column" => "can_manage_map",
      "label" => "Map Tools",
      "description" => "Use the map editor and map import tools.",
    ],
    "view_error_logs" => [
      "column" => "can_view_error_logs",
      "label" => "Error Logs",
      "description" => "Open the system error log viewer.",
    ],
  ];
}

function admin_users_permission_keys(): array {
  return array_keys(admin_users_permission_definitions());
}

function admin_users_role_definitions(): array {
  return [
    "superadmin" => [
      "label" => "Superadmin",
      "description" => "Full access, including Admin Users management.",
    ],
    "staff" => [
      "label" => "Scoped Admin",
      "description" => "Access is limited to the modules you enable below.",
    ],
  ];
}

function admin_users_account_type_definitions(): array {
  return [
    "staff" => [
      "label" => "School Staff",
      "description" => "Recommended for office staff and department admins.",
    ],
    "student_leader" => [
      "label" => "Student Org / Council",
      "description" => "Recommended for presidents or council officers who only need content modules.",
    ],
  ];
}

function admin_users_normalize_role(string $role): string {
  return $role === "superadmin" ? "superadmin" : "staff";
}

function admin_users_normalize_account_type(string $accountType): string {
  return $accountType === "student_leader" ? "student_leader" : "staff";
}

function admin_users_role_label(string $role): string {
  $defs = admin_users_role_definitions();
  $role = admin_users_normalize_role($role);
  return $defs[$role]["label"] ?? "Scoped Admin";
}

function admin_users_account_type_label(string $accountType): string {
  $defs = admin_users_account_type_definitions();
  $accountType = admin_users_normalize_account_type($accountType);
  return $defs[$accountType]["label"] ?? "School Staff";
}

function admin_users_all_permissions(bool $enabled = true): array {
  return array_fill_keys(admin_users_permission_keys(), $enabled);
}

function admin_users_default_permissions(string $role, string $accountType): array {
  $role = admin_users_normalize_role($role);
  $accountType = admin_users_normalize_account_type($accountType);

  if ($role === "superadmin") {
    return admin_users_all_permissions(true);
  }

  $defaults = admin_users_all_permissions(false);

  if ($accountType === "student_leader") {
    $defaults["manage_announcements"] = true;
    $defaults["manage_events"] = true;
    return $defaults;
  }

  $defaults["manage_announcements"] = true;
  $defaults["manage_events"] = true;
  $defaults["manage_buildings"] = true;
  $defaults["manage_rooms"] = true;
  $defaults["manage_facilities"] = true;
  $defaults["manage_feedback"] = true;
  return $defaults;
}

function admin_users_permissions_from_submission(array $source, string $role): array {
  $role = admin_users_normalize_role($role);
  if ($role === "superadmin") {
    return admin_users_all_permissions(true);
  }

  $permissions = admin_users_all_permissions(false);
  foreach (admin_users_permission_keys() as $key) {
    $permissions[$key] = !empty($source[$key]);
  }
  return $permissions;
}

function admin_users_permissions_from_row(array $row): array {
  $role = admin_users_normalize_role((string)($row["role"] ?? "staff"));
  $accountType = admin_users_normalize_account_type((string)($row["account_type"] ?? "staff"));

  if ($role === "superadmin") {
    return admin_users_all_permissions(true);
  }

  $hasPermissionRow = !empty($row["permissions_admin_id"]);
  if (!$hasPermissionRow) {
    return admin_users_default_permissions($role, $accountType);
  }

  $permissions = admin_users_all_permissions(false);
  foreach (admin_users_permission_definitions() as $key => $definition) {
    $column = $definition["column"];
    $permissions[$key] = !empty($row[$column]);
  }
  return $permissions;
}

function admin_users_permission_summary(array $permissions): string {
  $enabled = [];
  foreach (admin_users_permission_definitions() as $key => $definition) {
    if (!empty($permissions[$key])) {
      $enabled[] = $definition["label"];
    }
  }
  if (!$enabled) return "No module access";
  if (count($enabled) === count(admin_users_permission_definitions())) return "All scoped modules";
  return implode(", ", $enabled);
}

function admin_users_select_fields_sql(): string {
  $fields = [
    "u.admin_id",
    "u.username",
    "u.password_hash",
    "u.full_name",
    "u.role",
    "u.last_login",
    "u.date_created",
    "p.admin_id AS permissions_admin_id",
    "COALESCE(p.account_type, 'staff') AS account_type",
  ];

  foreach (admin_users_permission_definitions() as $definition) {
    $column = $definition["column"];
    $fields[] = "p.{$column}";
  }

  return implode(",\n       ", $fields);
}

function admin_users_ensure_schema(mysqli $conn): void {
  admin_users_sql($conn, "
    CREATE TABLE IF NOT EXISTS admin_user_permissions (
      admin_id INT(11) NOT NULL,
      account_type ENUM('staff','student_leader') NOT NULL DEFAULT 'staff',
      can_manage_announcements TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_events TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_buildings TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_rooms TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_facilities TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_feedback TINYINT(1) NOT NULL DEFAULT 0,
      can_manage_map TINYINT(1) NOT NULL DEFAULT 0,
      can_view_error_logs TINYINT(1) NOT NULL DEFAULT 0,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!admin_users_col($conn, "admin_user_permissions", "account_type")) {
    admin_users_sql($conn, "ALTER TABLE admin_user_permissions ADD COLUMN account_type ENUM('staff','student_leader') NOT NULL DEFAULT 'staff' AFTER admin_id");
  }
  foreach (admin_users_permission_definitions() as $definition) {
    $column = $definition["column"];
    if (!admin_users_col($conn, "admin_user_permissions", $column)) {
      admin_users_sql($conn, "ALTER TABLE admin_user_permissions ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0");
    }
  }
  if (!admin_users_col($conn, "admin_user_permissions", "updated_at")) {
    admin_users_sql($conn, "ALTER TABLE admin_user_permissions ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
  }
}

function admin_users_fetch_by_username(mysqli $conn, string $username): ?array {
  admin_users_ensure_schema($conn);
  $sql = "SELECT " . admin_users_select_fields_sql() . "
          FROM admin_users u
          LEFT JOIN admin_user_permissions p ON p.admin_id = u.admin_id
          WHERE u.username = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param("s", $username);
  if (!$stmt->execute()) {
    $message = $stmt->error;
    $stmt->close();
    throw new RuntimeException($message);
  }
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}

function admin_users_fetch_by_id(mysqli $conn, int $adminId): ?array {
  admin_users_ensure_schema($conn);
  $sql = "SELECT " . admin_users_select_fields_sql() . "
          FROM admin_users u
          LEFT JOIN admin_user_permissions p ON p.admin_id = u.admin_id
          WHERE u.admin_id = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param("i", $adminId);
  if (!$stmt->execute()) {
    $message = $stmt->error;
    $stmt->close();
    throw new RuntimeException($message);
  }
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}

function admin_users_fetch_all(mysqli $conn): array {
  admin_users_ensure_schema($conn);
  $sql = "SELECT " . admin_users_select_fields_sql() . "
          FROM admin_users u
          LEFT JOIN admin_user_permissions p ON p.admin_id = u.admin_id
          ORDER BY (u.role = 'superadmin') DESC, COALESCE(NULLIF(u.full_name, ''), u.username) ASC, u.username ASC";
  $result = $conn->query($sql);
  if (!($result instanceof mysqli_result)) {
    throw new RuntimeException($conn->error);
  }
  $rows = [];
  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }
  return $rows;
}

function admin_users_count_superadmins(mysqli $conn, ?int $excludeAdminId = null): int {
  $sql = "SELECT COUNT(*) AS cnt FROM admin_users WHERE role = 'superadmin'";
  if ($excludeAdminId !== null && $excludeAdminId > 0) {
    $sql .= " AND admin_id <> ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new RuntimeException($conn->error);
    }
    $stmt->bind_param("i", $excludeAdminId);
    if (!$stmt->execute()) {
      $message = $stmt->error;
      $stmt->close();
      throw new RuntimeException($message);
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int)($row["cnt"] ?? 0);
  }

  $result = $conn->query($sql);
  if (!($result instanceof mysqli_result)) {
    throw new RuntimeException($conn->error);
  }
  $row = $result->fetch_assoc();
  return (int)($row["cnt"] ?? 0);
}

function admin_users_bind_params(mysqli_stmt $stmt, string $types, array $values): void {
  $refs = [];
  $refs[] = &$types;
  foreach ($values as $index => $value) {
    $refs[] = &$values[$index];
  }
  call_user_func_array([$stmt, "bind_param"], $refs);
}

function admin_users_upsert_permissions(mysqli $conn, int $adminId, string $accountType, array $permissions): bool {
  admin_users_ensure_schema($conn);

  $columns = ["admin_id", "account_type"];
  $placeholders = ["?", "?"];
  $updates = ["account_type = VALUES(account_type)"];
  $types = "is";
  $values = [$adminId, admin_users_normalize_account_type($accountType)];

  foreach (admin_users_permission_definitions() as $key => $definition) {
    $column = $definition["column"];
    $columns[] = $column;
    $placeholders[] = "?";
    $updates[] = "{$column} = VALUES({$column})";
    $types .= "i";
    $values[] = !empty($permissions[$key]) ? 1 : 0;
  }

  $sql = "INSERT INTO admin_user_permissions (`" . implode("`, `", $columns) . "`)
          VALUES (" . implode(", ", $placeholders) . ")
          ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return false;
  }

  admin_users_bind_params($stmt, $types, $values);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function admin_users_delete_permissions(mysqli $conn, int $adminId): void {
  admin_users_ensure_schema($conn);
  $stmt = $conn->prepare("DELETE FROM admin_user_permissions WHERE admin_id = ?");
  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }
  $stmt->bind_param("i", $adminId);
  $stmt->execute();
  $stmt->close();
}

function admin_users_apply_session(array $row): void {
  $role = admin_users_normalize_role((string)($row["role"] ?? "staff"));
  $accountType = admin_users_normalize_account_type((string)($row["account_type"] ?? "staff"));
  $username = (string)($row["username"] ?? "");
  $fullName = trim((string)($row["full_name"] ?? ""));
  if ($fullName === "") {
    $fullName = $username;
  }

  $_SESSION["admin_logged_in"] = true;
  $_SESSION["admin_id"] = (int)($row["admin_id"] ?? 0);
  $_SESSION["admin_username"] = $username;
  $_SESSION["admin_full_name"] = $fullName;
  $_SESSION["admin_role"] = $role;
  $_SESSION["admin_account_type"] = $accountType;
  $_SESSION["admin_permissions"] = admin_users_permissions_from_row($row);
}
