<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/db.php";

$MODEL_DIR = __DIR__ . "/../models";
$ORIGINAL_MODEL_NAME = "tnts_navigation.glb";

if (empty($_SESSION["map_import_csrf"])) {
  try {
    $_SESSION["map_import_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["map_import_csrf"] = bin2hex(hash("sha256", uniqid((string)mt_rand(), true), true));
  }
}
$MAP_IMPORT_CSRF = (string)$_SESSION["map_import_csrf"];

function import_json_error(int $status, string $message): void {
  http_response_code($status);
  echo json_encode(["ok" => false, "error" => $message], JSON_PRETTY_PRINT);
  exit;
}

function import_is_same_origin(): bool {
  $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
  $referer = $_SERVER["HTTP_REFERER"] ?? "";
  $reqHostRaw = trim((string)($_SERVER["HTTP_HOST"] ?? ""));
  if ($reqHostRaw === "") return false;
  $reqHost = strtolower($reqHostRaw);
  $reqPort = isset($_SERVER["SERVER_PORT"]) ? (int)$_SERVER["SERVER_PORT"] : null;
  if (strpos($reqHostRaw, ":") !== false && preg_match('/^(.+):(\d+)$/', $reqHostRaw, $m)) {
    $reqHost = strtolower($m[1]);
    $reqPort = (int)$m[2];
  }

  if (is_string($origin) && $origin !== "") {
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts["host"])) return false;
    $originHost = strtolower((string)$parts["host"]);
    $originPort = isset($parts["port"]) ? (int)$parts["port"] : null;
    if ($originHost !== $reqHost) return false;
    if ($originPort !== null && $reqPort !== null && $originPort !== $reqPort) return false;
    return true;
  }

  if (is_string($referer) && $referer !== "") {
    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts["host"])) return false;
    $refHost = strtolower((string)$parts["host"]);
    if ($refHost !== $reqHost) return false;
    $refPort = isset($parts["port"]) ? (int)$parts["port"] : null;
    if ($refPort !== null && $reqPort !== null && $refPort !== $reqPort) return false;
    return true;
  }

  return true;
}

function import_verify_post_csrf(): void {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    import_json_error(405, "POST required");
  }
  if (!import_is_same_origin()) {
    import_json_error(403, "Cross-origin request denied");
  }
  $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
  $sessionToken = isset($_SESSION["map_import_csrf"]) ? (string)$_SESSION["map_import_csrf"] : "";
  if (!is_string($token) || $token === "" || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    import_json_error(403, "CSRF validation failed");
  }
}

function import_sanitize_glb_name(string $name): ?string {
  $base = basename($name);
  if ($base === "" || $base === "." || $base === "..") return null;
  if (!preg_match('/\.glb$/i', $base)) return null;
  return $base;
}

function import_list_glb_models(string $dir): array {
  $items = [];
  if (!is_dir($dir)) return $items;
  foreach (scandir($dir) as $f) {
    if ($f === "." || $f === "..") continue;
    if (!preg_match('/\.glb$/i', $f)) continue;
    $items[] = $f;
  }
  sort($items, SORT_NATURAL | SORT_FLAG_CASE);
  return $items;
}

function import_safe_atomic_write_bytes(string $path, string $content): bool {
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    return false;
  }

  try {
    $suffix = bin2hex(random_bytes(8));
  } catch (Throwable $_) {
    $suffix = (string)mt_rand(100000, 999999);
  }

  $tmp = $path . ".tmp." . $suffix;
  $written = file_put_contents($tmp, $content, LOCK_EX);
  if ($written === false) {
    @unlink($tmp);
    return false;
  }

  if (@rename($tmp, $path)) return true;
  @unlink($path);
  if (@rename($tmp, $path)) return true;
  @unlink($tmp);
  return false;
}

function import_is_valid_glb_binary(string $raw): bool {
  if (strlen($raw) < 12) return false;
  $hdr = unpack("a4magic/Vversion/Vlength", substr($raw, 0, 12));
  if (!is_array($hdr)) return false;
  if (($hdr["magic"] ?? "") !== "glTF") return false;
  if ((int)($hdr["version"] ?? 0) !== 2) return false;
  if ((int)($hdr["length"] ?? 0) !== strlen($raw)) return false;
  return true;
}

function import_glb_node_count(string $raw): ?int {
  if (!import_is_valid_glb_binary($raw)) return null;
  $len = strlen($raw);
  $offset = 12;
  while ($offset + 8 <= $len) {
    $chunkLenData = unpack("Vlen", substr($raw, $offset, 4));
    if (!is_array($chunkLenData)) return null;
    $chunkLen = (int)$chunkLenData["len"];
    $chunkType = substr($raw, $offset + 4, 4);
    $offset += 8;
    if ($chunkLen < 0 || $offset + $chunkLen > $len) return null;
    if ($chunkType === "JSON") {
      $jsonRaw = substr($raw, $offset, $chunkLen);
      $json = json_decode($jsonRaw, true);
      if (!is_array($json)) return null;
      if (!isset($json["nodes"]) || !is_array($json["nodes"])) return 0;
      return count($json["nodes"]);
    }
    $offset += $chunkLen;
  }
  return null;
}

function import_list_model_backups(string $modelDir, string $modelFile): array {
  $safe = import_sanitize_glb_name($modelFile);
  if ($safe === null) return [];
  $backupDir = $modelDir . "/backups";
  if (!is_dir($backupDir)) return [];
  $base = preg_replace('/\.glb$/i', '', $safe);
  $pattern = $backupDir . "/" . $base . "_backup_*.glb";
  $files = glob($pattern);
  if (!is_array($files) || !count($files)) return [];
  usort($files, function ($a, $b) {
    $ta = @filemtime($a);
    $tb = @filemtime($b);
    if ($ta === $tb) return strcmp((string)$b, (string)$a);
    return ((int)$tb <=> (int)$ta);
  });
  return $files;
}

function import_clean_building_name(string $name): string {
  $v = trim($name);
  $v = preg_replace('/\s+/', ' ', $v);
  if (!is_string($v)) return "";
  return substr($v, 0, 100);
}

function import_clean_room_name(string $name): string {
  $v = trim($name);
  $v = preg_replace('/\s+/', ' ', $v);
  if (!is_string($v)) return "";
  return substr($v, 0, 100);
}

function import_db_query_or_throw(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException("SQL failed: " . $conn->error);
  }
}

function import_column_exists(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function import_ensure_schema(mysqli $conn): void {
  import_db_query_or_throw($conn, "
    CREATE TABLE IF NOT EXISTS map_versions (
      version_id INT AUTO_INCREMENT PRIMARY KEY,
      model_file VARCHAR(255) NOT NULL,
      model_hash CHAR(64) NOT NULL,
      imported_by_admin_id INT NULL,
      total_buildings INT NOT NULL DEFAULT 0,
      total_rooms INT NOT NULL DEFAULT 0,
      date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_model_hash (model_file, model_hash),
      KEY idx_date_created (date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!import_column_exists($conn, "buildings", "source_model_file")) {
    import_db_query_or_throw($conn, "ALTER TABLE buildings ADD COLUMN source_model_file VARCHAR(255) NULL AFTER image_path");
  }
  if (!import_column_exists($conn, "buildings", "first_seen_version_id")) {
    import_db_query_or_throw($conn, "ALTER TABLE buildings ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  }
  if (!import_column_exists($conn, "buildings", "last_seen_version_id")) {
    import_db_query_or_throw($conn, "ALTER TABLE buildings ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  }
  if (!import_column_exists($conn, "buildings", "is_present_in_latest")) {
    import_db_query_or_throw($conn, "ALTER TABLE buildings ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  }

  if (!import_column_exists($conn, "rooms", "source_model_file")) {
    import_db_query_or_throw($conn, "ALTER TABLE rooms ADD COLUMN source_model_file VARCHAR(255) NULL AFTER description");
  }
  if (!import_column_exists($conn, "rooms", "first_seen_version_id")) {
    import_db_query_or_throw($conn, "ALTER TABLE rooms ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  }
  if (!import_column_exists($conn, "rooms", "last_seen_version_id")) {
    import_db_query_or_throw($conn, "ALTER TABLE rooms ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  }
  if (!import_column_exists($conn, "rooms", "is_present_in_latest")) {
    import_db_query_or_throw($conn, "ALTER TABLE rooms ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  }
}

function import_get_or_create_version_id(
  mysqli $conn,
  string $modelFile,
  string $modelHash,
  ?int $adminId,
  int $totalBuildings,
  int $totalRooms
): int {
  $select = $conn->prepare("SELECT version_id FROM map_versions WHERE model_file = ? AND model_hash = ? LIMIT 1");
  if (!$select) {
    throw new RuntimeException("Failed to prepare version lookup statement");
  }
  $select->bind_param("ss", $modelFile, $modelHash);
  if (!$select->execute()) {
    throw new RuntimeException("Failed to query map_versions");
  }
  $res = $select->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $select->close();

  if ($row && isset($row["version_id"])) {
    $versionId = (int)$row["version_id"];
    if ($adminId === null) {
      $update = $conn->prepare("UPDATE map_versions SET imported_by_admin_id = NULL, total_buildings = ?, total_rooms = ? WHERE version_id = ?");
      if (!$update) {
        throw new RuntimeException("Failed to prepare version update statement");
      }
      $update->bind_param("iii", $totalBuildings, $totalRooms, $versionId);
    } else {
      $update = $conn->prepare("UPDATE map_versions SET imported_by_admin_id = ?, total_buildings = ?, total_rooms = ? WHERE version_id = ?");
      if (!$update) {
        throw new RuntimeException("Failed to prepare version update statement");
      }
      $update->bind_param("iiii", $adminId, $totalBuildings, $totalRooms, $versionId);
    }
    if (!$update->execute()) {
      throw new RuntimeException("Failed to update map_versions row");
    }
    $update->close();
    return $versionId;
  }

  $insert = $conn->prepare("
    INSERT INTO map_versions (model_file, model_hash, imported_by_admin_id, total_buildings, total_rooms)
    VALUES (?, ?, ?, ?, ?)
  ");
  if ($adminId === null) {
    $insert = $conn->prepare("
      INSERT INTO map_versions (model_file, model_hash, imported_by_admin_id, total_buildings, total_rooms)
      VALUES (?, ?, NULL, ?, ?)
    ");
    if (!$insert) {
      throw new RuntimeException("Failed to prepare version insert statement");
    }
    $insert->bind_param("ssii", $modelFile, $modelHash, $totalBuildings, $totalRooms);
  } else {
    if (!$insert) {
      throw new RuntimeException("Failed to prepare version insert statement");
    }
    $insert->bind_param("ssiii", $modelFile, $modelHash, $adminId, $totalBuildings, $totalRooms);
  }
  if (!$insert->execute()) {
    throw new RuntimeException("Failed to insert map_versions row");
  }
  $versionId = (int)$insert->insert_id;
  $insert->close();
  return $versionId;
}

function import_copy_text(?string $value): string {
  return trim((string)($value ?? ""));
}

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");
  $action = (string)$_GET["action"];

  if ($action === "list_models") {
    $models = import_list_glb_models($MODEL_DIR);
    $rows = [];
    foreach ($models as $f) {
      $rows[] = [
        "file" => $f,
        "isOriginal" => (strcasecmp($f, $ORIGINAL_MODEL_NAME) === 0)
      ];
    }
    echo json_encode(["ok" => true, "models" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "list_backups") {
    $nameRaw = isset($_GET["name"]) ? (string)$_GET["name"] : "";
    $safeName = import_sanitize_glb_name($nameRaw);
    if ($safeName === null) {
      import_json_error(400, "Invalid model name");
    }
    $files = import_list_model_backups($MODEL_DIR, $safeName);
    $rows = [];
    foreach ($files as $f) {
      $rows[] = [
        "file" => basename($f),
        "mtime" => @filemtime($f) ?: null
      ];
    }
    echo json_encode(["ok" => true, "backups" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_corrected_model") {
    import_verify_post_csrf();

    $nameRaw = isset($_GET["name"]) ? (string)$_GET["name"] : "";
    $safeName = import_sanitize_glb_name($nameRaw);
    if ($safeName === null) {
      import_json_error(400, "Invalid model file name");
    }

    $modelPath = $MODEL_DIR . "/" . $safeName;
    if (!file_exists($modelPath)) {
      import_json_error(404, "Model file not found");
    }

    $raw = file_get_contents("php://input");
    $contentType = strtolower((string)($_SERVER["CONTENT_TYPE"] ?? ""));
    if ($contentType !== "" && strpos($contentType, "model/gltf-binary") === false && strpos($contentType, "application/octet-stream") === false) {
      import_json_error(415, "Unsupported content type");
    }
    if ($raw === false || !import_is_valid_glb_binary($raw)) {
      import_json_error(400, "Invalid GLB binary payload");
    }

    $currentRaw = @file_get_contents($modelPath);
    $currentNodeCount = is_string($currentRaw) ? import_glb_node_count($currentRaw) : null;
    $incomingNodeCount = import_glb_node_count($raw);
    if (is_int($currentNodeCount) && is_int($incomingNodeCount) && $currentNodeCount > 0) {
      // Safety guard: reject suspiciously destructive overwrite.
      if ($incomingNodeCount < (int)floor($currentNodeCount * 0.70)) {
        import_json_error(
          400,
          "Safety check failed: incoming model node count dropped too much ({$incomingNodeCount} vs {$currentNodeCount})"
        );
      }
    }

    $backupDir = $MODEL_DIR . "/backups";
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
      import_json_error(500, "Failed to create model backup directory");
    }
    $base = preg_replace('/\.glb$/i', '', $safeName);
    $backupName = $base . "_backup_" . date("Ymd_His") . ".glb";
    $backupPath = $backupDir . "/" . $backupName;
    if (!@copy($modelPath, $backupPath)) {
      import_json_error(500, "Failed to create model backup before overwrite");
    }

    if (!import_safe_atomic_write_bytes($modelPath, $raw)) {
      import_json_error(500, "Failed to overwrite model file");
    }

    echo json_encode([
      "ok" => true,
      "file" => $safeName,
      "backupFile" => "models/backups/" . $backupName,
      "currentNodeCount" => $currentNodeCount,
      "incomingNodeCount" => $incomingNodeCount
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "restore_last_backup") {
    import_verify_post_csrf();

    $nameRaw = isset($_GET["name"]) ? (string)$_GET["name"] : "";
    $safeName = import_sanitize_glb_name($nameRaw);
    if ($safeName === null) {
      import_json_error(400, "Invalid model file name");
    }

    $modelPath = $MODEL_DIR . "/" . $safeName;
    if (!file_exists($modelPath)) {
      import_json_error(404, "Model file not found");
    }

    $backups = import_list_model_backups($MODEL_DIR, $safeName);
    if (!count($backups)) {
      import_json_error(404, "No backup found for model");
    }
    $latestBackup = $backups[0];

    $backupDir = $MODEL_DIR . "/backups";
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
      import_json_error(500, "Failed to create backup directory");
    }

    $base = preg_replace('/\.glb$/i', '', $safeName);
    $preRestoreBackupName = $base . "_backup_" . date("Ymd_His") . "_pre_restore.glb";
    $preRestoreBackupPath = $backupDir . "/" . $preRestoreBackupName;
    if (!@copy($modelPath, $preRestoreBackupPath)) {
      import_json_error(500, "Failed to create pre-restore backup");
    }

    $restoreRaw = @file_get_contents($latestBackup);
    if (!is_string($restoreRaw) || !import_is_valid_glb_binary($restoreRaw)) {
      import_json_error(500, "Backup file is invalid");
    }
    if (!import_safe_atomic_write_bytes($modelPath, $restoreRaw)) {
      import_json_error(500, "Failed to restore model from backup");
    }

    echo json_encode([
      "ok" => true,
      "file" => $safeName,
      "restoredFrom" => "models/backups/" . basename($latestBackup),
      "preRestoreBackup" => "models/backups/" . $preRestoreBackupName
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "restore_backup") {
    import_verify_post_csrf();

    $nameRaw = isset($_GET["name"]) ? (string)$_GET["name"] : "";
    $safeName = import_sanitize_glb_name($nameRaw);
    if ($safeName === null) {
      import_json_error(400, "Invalid model file name");
    }
    $backupRaw = isset($_GET["backup"]) ? basename((string)$_GET["backup"]) : "";
    if ($backupRaw === "" || !preg_match('/\.glb$/i', $backupRaw)) {
      import_json_error(400, "Invalid backup file name");
    }

    $modelPath = $MODEL_DIR . "/" . $safeName;
    if (!file_exists($modelPath)) {
      import_json_error(404, "Model file not found");
    }

    $backupDir = $MODEL_DIR . "/backups";
    if (!is_dir($backupDir)) {
      import_json_error(404, "Backup directory not found");
    }
    $base = preg_replace('/\.glb$/i', '', $safeName);
    if (!preg_match('/^' . preg_quote($base, '/') . '_backup_.*\.glb$/i', $backupRaw)) {
      import_json_error(400, "Backup file does not match selected model");
    }
    $backupPath = $backupDir . "/" . $backupRaw;
    if (!file_exists($backupPath)) {
      import_json_error(404, "Selected backup file not found");
    }

    $preRestoreBackupName = $base . "_backup_" . date("Ymd_His") . "_pre_restore.glb";
    $preRestoreBackupPath = $backupDir . "/" . $preRestoreBackupName;
    if (!@copy($modelPath, $preRestoreBackupPath)) {
      import_json_error(500, "Failed to create pre-restore backup");
    }

    $restoreRaw = @file_get_contents($backupPath);
    if (!is_string($restoreRaw) || !import_is_valid_glb_binary($restoreRaw)) {
      import_json_error(500, "Backup file is invalid");
    }
    if (!import_safe_atomic_write_bytes($modelPath, $restoreRaw)) {
      import_json_error(500, "Failed to restore model from backup");
    }

    echo json_encode([
      "ok" => true,
      "file" => $safeName,
      "restoredFrom" => "models/backups/" . $backupRaw,
      "preRestoreBackup" => "models/backups/" . $preRestoreBackupName
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "import_entities") {
    import_verify_post_csrf();

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!is_array($data)) {
      import_json_error(400, "Invalid JSON payload");
    }

    $modelFile = isset($data["modelFile"]) ? import_sanitize_glb_name((string)$data["modelFile"]) : null;
    if ($modelFile === null) {
      import_json_error(400, "Invalid modelFile");
    }
    $modelPath = $MODEL_DIR . "/" . $modelFile;
    if (!file_exists($modelPath)) {
      import_json_error(404, "Model file not found");
    }
    $modelHash = @hash_file("sha256", $modelPath);
    if (!is_string($modelHash) || $modelHash === "") {
      import_json_error(500, "Failed to hash selected model");
    }

    $buildingsIn = isset($data["buildings"]) && is_array($data["buildings"]) ? $data["buildings"] : null;
    if ($buildingsIn === null) {
      import_json_error(400, "Missing buildings[] payload");
    }

    try {
      import_ensure_schema($conn);
    } catch (Throwable $e) {
      import_json_error(500, "Schema update failed: " . $e->getMessage());
    }

    $totalBuildings = 0;
    $totalRooms = 0;
    foreach ($buildingsIn as $buildingRow) {
      if (!is_array($buildingRow)) continue;
      $buildingName = import_clean_building_name((string)($buildingRow["name"] ?? ""));
      if ($buildingName === "") continue;
      $totalBuildings++;
      $roomsIn = isset($buildingRow["rooms"]) && is_array($buildingRow["rooms"]) ? $buildingRow["rooms"] : [];
      $uniqueRooms = [];
      foreach ($roomsIn as $roomRow) {
        if (!is_array($roomRow)) continue;
        $roomName = import_clean_room_name((string)($roomRow["name"] ?? ""));
        if ($roomName === "") continue;
        $uniqueRooms[strtolower($roomName)] = true;
      }
      $totalRooms += count($uniqueRooms);
    }

    $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
    if (is_int($adminId) && $adminId <= 0) $adminId = null;

    $summary = [
      "versionId" => 0,
      "modelFile" => $modelFile,
      "modelHash" => $modelHash,
      "buildingsInserted" => 0,
      "buildingsExisting" => 0,
      "roomsInserted" => 0,
      "roomsExisting" => 0,
      "buildingsUnclassified" => 0,
      "skippedBuildingNames" => 0,
      "skippedRoomNames" => 0,
      "buildingsMissingAfterSync" => 0,
      "roomsMissingAfterSync" => 0
    ];

    $conn->begin_transaction();
    try {
      $versionId = import_get_or_create_version_id(
        $conn,
        $modelFile,
        $modelHash,
        $adminId,
        $totalBuildings,
        $totalRooms
      );
      $summary["versionId"] = $versionId;

      $markBuildingsNotSeenStmt = $conn->prepare("
        UPDATE buildings
        SET is_present_in_latest = 0, last_seen_version_id = ?
        WHERE source_model_file = ?
      ");
      $markRoomsNotSeenStmt = $conn->prepare("
        UPDATE rooms
        SET is_present_in_latest = 0, last_seen_version_id = ?
        WHERE source_model_file = ?
      ");
      $selectBuildingStmt = $conn->prepare("
        SELECT building_id
        FROM buildings
        WHERE source_model_file = ? AND building_name = ?
        LIMIT 1
      ");
      $selectBuildingTemplateStmt = $conn->prepare("
        SELECT description, image_path
        FROM buildings
        WHERE building_name = ?
        ORDER BY building_id DESC
        LIMIT 1
      ");
      $insertBuildingStmt = $conn->prepare("
        INSERT INTO buildings (building_name, description, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest)
        VALUES (?, ?, ?, ?, ?, ?, 1)
      ");
      $updateBuildingSeenStmt = $conn->prepare("
        UPDATE buildings
        SET last_seen_version_id = ?, is_present_in_latest = 1
        WHERE building_id = ?
      ");
      $selectRoomStmt = $conn->prepare("
        SELECT room_id, room_name
        FROM rooms
        WHERE source_model_file = ? AND building_id = ?
      ");
      $selectRoomTemplateStmt = $conn->prepare("
        SELECT room_number, room_type, floor_number, description, image_path
        FROM rooms
        WHERE room_name = ? AND building_name = ?
        ORDER BY room_id DESC
        LIMIT 1
      ");
      $insertRoomStmt = $conn->prepare("
        INSERT INTO rooms (building_id, room_name, room_number, room_type, floor_number, building_name, description, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
      ");
      $updateRoomSeenStmt = $conn->prepare("
        UPDATE rooms
        SET building_id = ?, building_name = ?, last_seen_version_id = ?, is_present_in_latest = 1
        WHERE room_id = ?
      ");
      $countMissingBuildingsStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM buildings WHERE source_model_file = ? AND is_present_in_latest = 0");
      $countMissingRoomsStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM rooms WHERE source_model_file = ? AND is_present_in_latest = 0");

      if (
        !$markBuildingsNotSeenStmt || !$markRoomsNotSeenStmt ||
        !$selectBuildingStmt || !$selectBuildingTemplateStmt || !$insertBuildingStmt || !$updateBuildingSeenStmt ||
        !$selectRoomStmt || !$selectRoomTemplateStmt || !$insertRoomStmt || !$updateRoomSeenStmt ||
        !$countMissingBuildingsStmt || !$countMissingRoomsStmt
      ) {
        throw new RuntimeException("Failed to prepare SQL statements");
      }

      $markBuildingsNotSeenStmt->bind_param("is", $versionId, $modelFile);
      if (!$markBuildingsNotSeenStmt->execute()) {
        throw new RuntimeException("Failed to mark stale buildings");
      }
      $markRoomsNotSeenStmt->bind_param("is", $versionId, $modelFile);
      if (!$markRoomsNotSeenStmt->execute()) {
        throw new RuntimeException("Failed to mark stale rooms");
      }

      foreach ($buildingsIn as $buildingRow) {
        if (!is_array($buildingRow)) continue;

        $buildingNameRaw = isset($buildingRow["name"]) ? (string)$buildingRow["name"] : "";
        $buildingName = import_clean_building_name($buildingNameRaw);
        if ($buildingName === "") {
          $summary["skippedBuildingNames"]++;
          continue;
        }

        $selectBuildingStmt->bind_param("ss", $modelFile, $buildingName);
        if (!$selectBuildingStmt->execute()) {
          throw new RuntimeException("Failed to query buildings table");
        }
        $buildingResult = $selectBuildingStmt->get_result();
        $existing = $buildingResult ? $buildingResult->fetch_assoc() : null;
        $buildingId = 0;

        if ($existing && isset($existing["building_id"])) {
          $buildingId = (int)$existing["building_id"];
          $summary["buildingsExisting"]++;
          $updateBuildingSeenStmt->bind_param("ii", $versionId, $buildingId);
          if (!$updateBuildingSeenStmt->execute()) {
            throw new RuntimeException("Failed to update building sync markers: " . $buildingName);
          }
        } else {
          $buildingDescription = "";
          $buildingImagePath = "";
          $selectBuildingTemplateStmt->bind_param("s", $buildingName);
          if (!$selectBuildingTemplateStmt->execute()) {
            throw new RuntimeException("Failed to query building template");
          }
          $templateRes = $selectBuildingTemplateStmt->get_result();
          $templateRow = $templateRes ? $templateRes->fetch_assoc() : null;
          if ($templateRow) {
            $buildingDescription = import_copy_text($templateRow["description"] ?? "");
            $buildingImagePath = import_copy_text($templateRow["image_path"] ?? "");
          }

          $insertBuildingStmt->bind_param("ssssii", $buildingName, $buildingDescription, $buildingImagePath, $modelFile, $versionId, $versionId);
          if (!$insertBuildingStmt->execute()) {
            throw new RuntimeException("Failed to insert building: " . $buildingName);
          }
          $buildingId = (int)$insertBuildingStmt->insert_id;
          $summary["buildingsInserted"]++;
        }

        if ($buildingId <= 0) {
          throw new RuntimeException("Invalid building id resolved for: " . $buildingName);
        }

        $roomsIn = isset($buildingRow["rooms"]) && is_array($buildingRow["rooms"]) ? $buildingRow["rooms"] : [];
        if (!count($roomsIn)) {
          $summary["buildingsUnclassified"]++;
          continue;
        }

        $selectRoomStmt->bind_param("si", $modelFile, $buildingId);
        if (!$selectRoomStmt->execute()) {
          throw new RuntimeException("Failed to query rooms table");
        }
        $roomResult = $selectRoomStmt->get_result();
        $existingRoomsByName = [];
        if ($roomResult) {
          while ($row = $roomResult->fetch_assoc()) {
            $n = isset($row["room_name"]) ? strtolower(trim((string)$row["room_name"])) : "";
            if ($n !== "") $existingRoomsByName[$n] = (int)($row["room_id"] ?? 0);
          }
        }

        $roomsInDeduped = [];
        foreach ($roomsIn as $roomRow) {
          if (!is_array($roomRow)) continue;
          $roomNameRaw = isset($roomRow["name"]) ? (string)$roomRow["name"] : "";
          $roomName = import_clean_room_name($roomNameRaw);
          if ($roomName === "") {
            $summary["skippedRoomNames"]++;
            continue;
          }
          $roomsInDeduped[strtolower($roomName)] = $roomName;
        }

        foreach ($roomsInDeduped as $roomKey => $roomName) {
          if (isset($existingRoomsByName[$roomKey])) {
            $existingRoomId = (int)$existingRoomsByName[$roomKey];
            $updateRoomSeenStmt->bind_param("isii", $buildingId, $buildingName, $versionId, $existingRoomId);
            if (!$updateRoomSeenStmt->execute()) {
              throw new RuntimeException("Failed to update room sync markers: " . $roomName);
            }
            $summary["roomsExisting"]++;
            continue;
          }

          $roomNumber = "";
          $roomType = "";
          $floorNumber = "";
          $roomDescription = "";
          $roomImagePath = "";
          $selectRoomTemplateStmt->bind_param("ss", $roomName, $buildingName);
          if (!$selectRoomTemplateStmt->execute()) {
            throw new RuntimeException("Failed to query room template");
          }
          $roomTemplateRes = $selectRoomTemplateStmt->get_result();
          $roomTemplate = $roomTemplateRes ? $roomTemplateRes->fetch_assoc() : null;
          if ($roomTemplate) {
            $roomNumber = import_copy_text($roomTemplate["room_number"] ?? "");
            $roomType = import_copy_text($roomTemplate["room_type"] ?? "");
            $floorNumber = import_copy_text($roomTemplate["floor_number"] ?? "");
            $roomDescription = import_copy_text($roomTemplate["description"] ?? "");
            $roomImagePath = import_copy_text($roomTemplate["image_path"] ?? "");
          }

          $insertRoomStmt->bind_param(
            "issssssssii",
            $buildingId,
            $roomName,
            $roomNumber,
            $roomType,
            $floorNumber,
            $buildingName,
            $roomDescription,
            $roomImagePath,
            $modelFile,
            $versionId,
            $versionId
          );
          if (!$insertRoomStmt->execute()) {
            throw new RuntimeException("Failed to insert room: " . $roomName);
          }
          $existingRoomsByName[$roomKey] = (int)$insertRoomStmt->insert_id;
          $summary["roomsInserted"]++;
        }
      }

      $countMissingBuildingsStmt->bind_param("s", $modelFile);
      if (!$countMissingBuildingsStmt->execute()) {
        throw new RuntimeException("Failed to count missing buildings");
      }
      $missingBuildingsRes = $countMissingBuildingsStmt->get_result();
      $missingBuildingsRow = $missingBuildingsRes ? $missingBuildingsRes->fetch_assoc() : null;
      $summary["buildingsMissingAfterSync"] = $missingBuildingsRow ? (int)($missingBuildingsRow["cnt"] ?? 0) : 0;

      $countMissingRoomsStmt->bind_param("s", $modelFile);
      if (!$countMissingRoomsStmt->execute()) {
        throw new RuntimeException("Failed to count missing rooms");
      }
      $missingRoomsRes = $countMissingRoomsStmt->get_result();
      $missingRoomsRow = $missingRoomsRes ? $missingRoomsRes->fetch_assoc() : null;
      $summary["roomsMissingAfterSync"] = $missingRoomsRow ? (int)($missingRoomsRow["cnt"] ?? 0) : 0;

      $conn->commit();
    } catch (Throwable $e) {
      $conn->rollback();
      import_json_error(500, "Import failed: " . $e->getMessage());
    }

    echo json_encode([
      "ok" => true,
      "modelFile" => $modelFile,
      "summary" => $summary
    ], JSON_PRETTY_PRINT);
    exit;
  }

  import_json_error(404, "Unknown action");
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Map Import", "mapimport");
?>

<div class="card">
  <div class="section-title">Import 3D Map Details to Database</div>
  <div style="color:#667085;font-weight:800;line-height:1.6;">
    Access: <code>/admin/mapImport.php</code> only.<br>
    Use this page to scan a GLB model, auto-correct Blender duplicate room-name artifacts, optionally write corrected names back to the same GLB, then sync buildings/rooms to DB with map version tracking.
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="section-title">Model Selection</div>
  <div class="row" style="grid-template-columns:180px 1fr 200px;">
    <div class="label">GLB Model</div>
    <select id="model-select" class="select"></select>
    <button id="reload-models-btn" class="btn gray" type="button">Reload Models</button>
  </div>
  <div class="row" style="grid-template-columns:180px 1fr 320px; margin-top:10px;">
    <div class="label">Model Backups</div>
    <select id="backup-select" class="select"></select>
    <div class="actions" style="justify-content:flex-end;">
      <button id="reload-backups-btn" class="btn gray" type="button">Reload Backups</button>
      <button id="scan-backup-btn" class="btn gray" type="button">Scan Selected Backup</button>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="actions">
    <button id="scan-btn" class="btn gray" type="button">1) Scan Model</button>
    <button id="fix-names-btn" class="btn blue" type="button" disabled>2) Save Name Fixes to GLB</button>
    <button id="import-btn" class="btn primary" type="button" disabled>3) Import to DB</button>
    <button id="undo-btn" class="btn danger" type="button">Restore Backup</button>
  </div>
  <div id="import-status" style="margin-top:10px;color:#334155;font-weight:800;">Ready.</div>
</div>

<div class="table-wrap" style="margin-top:12px;">
  <table>
    <thead>
      <tr>
        <th>Building</th>
        <th>Classification</th>
        <th>Rooms Detected (After Scan Rules)</th>
      </tr>
    </thead>
    <tbody id="preview-body">
      <tr>
        <td colspan="3">No scan yet.</td>
      </tr>
    </tbody>
  </table>
</div>

<div class="table-wrap" style="margin-top:12px;">
  <table>
    <thead>
      <tr>
        <th>Building</th>
        <th>Old Object Name</th>
        <th>New Object Name</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody id="corrections-body">
      <tr>
        <td colspan="4">No scan yet.</td>
      </tr>
    </tbody>
  </table>
</div>

<div class="table-wrap" style="margin-top:12px;">
  <table>
    <thead>
      <tr>
        <th>Building</th>
        <th>Raw Object Name (In GLB)</th>
        <th>Final Room Name (Scan Rule)</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody id="audit-body">
      <tr>
        <td colspan="4">No scan yet.</td>
      </tr>
    </tbody>
  </table>
</div>

<script type="importmap">
{
  "imports": {
    "three": "../three.js-master/build/three.module.js",
    "three/addons/": "../three.js-master/examples/jsm/"
  }
}
</script>

<script type="module">
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { GLTFExporter } from "three/addons/exporters/GLTFExporter.js";

const CSRF_TOKEN = <?= json_encode($MAP_IMPORT_CSRF) ?>;
const ORIGINAL_MODEL_NAME = <?= json_encode($ORIGINAL_MODEL_NAME) ?>;

const modelSelect = document.getElementById("model-select");
const reloadModelsBtn = document.getElementById("reload-models-btn");
const backupSelect = document.getElementById("backup-select");
const reloadBackupsBtn = document.getElementById("reload-backups-btn");
const scanBackupBtn = document.getElementById("scan-backup-btn");
const scanBtn = document.getElementById("scan-btn");
const fixNamesBtn = document.getElementById("fix-names-btn");
const importBtn = document.getElementById("import-btn");
const undoBtn = document.getElementById("undo-btn");
const statusEl = document.getElementById("import-status");
const previewBody = document.getElementById("preview-body");
const correctionsBody = document.getElementById("corrections-body");
const auditBody = document.getElementById("audit-body");

const GENERIC_NODE_NAMES = new Set(["scene", "auxscene", "root", "rootnode", "gltf", "model", "group"]);
const KIOSK_ROUTE_RE = /^KIOSK_START(?:\.\d+|\d+)?$/i;

let selectedModelFile = "";
let selectedBackupFile = "";
let scannedModelFile = "";
let scannedFromBackup = false;
let scannedBackupFile = "";
let loadedSceneRoot = null;
let extractedBuildings = [];
let correctionItems = [];
let roomAuditItems = [];
let scanBusy = false;
let saveBusy = false;
let importBusy = false;
let restoreBusy = false;

function setStatus(msg, color = "#334155") {
  statusEl.textContent = msg;
  statusEl.style.color = color;
}

function escapeHtml(s) {
  return String(s || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll("\"", "&quot;")
    .replaceAll("'", "&#39;");
}

function isGroundLikeName(name) {
  const n = String(name || "").trim();
  if (!n) return false;
  const lower = n.toLowerCase();
  if (lower === "ground" || lower === "cground") return true;
  return /(^|[^a-z0-9])c?ground($|[^a-z0-9])/i.test(n);
}

function isGenericNodeName(name) {
  if (!name) return true;
  const raw = String(name).trim().toLowerCase();
  if (GENERIC_NODE_NAMES.has(raw)) return true;

  // Export/import pipelines often append numeric suffixes to wrapper nodes.
  // Treat variants like AuxScene_1 / Scene.001 / root-2 as generic too.
  const deSuffixed = raw.replace(/([._-]\d+)+$/g, "");
  return GENERIC_NODE_NAMES.has(deSuffixed);
}

function normalizeBuildingName(name) {
  let n = String(name || "").trim();
  if (!n) return "";
  n = n.replace(/\.\d+$/, "");
  n = n.replace(/\s+/g, " ").trim();
  if (!n) return "";
  if (isGroundLikeName(n)) return "";
  if (KIOSK_ROUTE_RE.test(n)) return "";
  return n;
}

function getTopLevelNamedAncestor(obj, sceneRoot) {
  if (!obj || !sceneRoot) return null;
  let p = obj;
  let top = null;
  while (p && p !== sceneRoot) {
    if (p.name && !isGenericNodeName(p.name) && !isGroundLikeName(p.name)) {
      top = p;
    }
    p = p.parent;
  }
  return top;
}

function parseRoomCandidate(rawName) {
  const source = String(rawName || "").trim();
  if (!source) return null;
  if (!/^ROOM(?:$|[\s._-]|\d)/i.test(source)) return null;

  let tail = source.replace(/^ROOM/i, "");
  tail = tail.replace(/^[\s._-]+/, "");
  tail = tail.replace(/\s+/g, "");
  if (!tail) return null;
  if (!/^[0-9._-]+$/.test(tail)) return null;

  const explicit = tail.match(/^(\d+)[._-](\d{3})$/);
  if (explicit) {
    return {
      kind: "explicit_suffix",
      baseDigits: explicit[1],
      suffixDigits: explicit[2],
      originalDigits: explicit[1] + explicit[2]
    };
  }

  // GLTFLoader auto-uniquifies duplicate object names (e.g. ROOM_31_1).
  // Treat trailing numeric token as a possible loader suffix.
  const loaderAlias = tail.match(/^(\d+)[._-](\d{1,3})$/);
  if (loaderAlias) {
    return {
      kind: "loader_suffix",
      baseDigits: loaderAlias[1],
      suffixDigits: loaderAlias[2],
      originalDigits: loaderAlias[1] + loaderAlias[2]
    };
  }

  const plainDigits = tail.match(/^(\d+)$/);
  if (!plainDigits) return null;
  const digits = plainDigits[1];
  if (digits.length <= 3) {
    return {
      kind: "plain",
      baseDigits: digits,
      suffixDigits: "",
      originalDigits: digits
    };
  }

  return {
    kind: "compact_suffix",
    baseDigits: digits.slice(0, -3),
    suffixDigits: digits.slice(-3),
    originalDigits: digits
  };
}

function buildExtraction(sceneRoot) {
  const buildingEntries = new Map();
  const canonicalBaseSet = new Set();
  const canonicalByBuilding = new Map();
  const compactByBaseCount = new Map();

  sceneRoot.traverse((obj) => {
    if (!obj) return;
    const root = getTopLevelNamedAncestor(obj, sceneRoot);
    if (!root || !root.name) return;
    const buildingName = normalizeBuildingName(root.name);
    if (!buildingName) return;

    let entry = buildingEntries.get(buildingName);
    if (!entry) {
      entry = { name: buildingName, roomCandidates: [] };
      buildingEntries.set(buildingName, entry);
    }

    if (!obj.name) return;
    const parsed = parseRoomCandidate(obj.name);
    if (!parsed) return;

    const row = {
      buildingName,
      object: obj,
      oldName: String(obj.name),
      parsed,
      finalName: "",
      reason: ""
    };
    entry.roomCandidates.push(row);

    if (parsed.kind === "plain" || parsed.kind === "explicit_suffix" || parsed.kind === "loader_suffix") {
      canonicalBaseSet.add(parsed.baseDigits);
      if (!canonicalByBuilding.has(buildingName)) {
        canonicalByBuilding.set(buildingName, new Set());
      }
      canonicalByBuilding.get(buildingName).add(parsed.baseDigits);
    }
    if (parsed.kind === "compact_suffix") {
      compactByBaseCount.set(parsed.baseDigits, (compactByBaseCount.get(parsed.baseDigits) || 0) + 1);
    }
  });

  const corrections = [];
  const auditItems = [];
  const buildingsOut = [];
  for (const b of buildingEntries.values()) {
    const roomMap = new Map();
    for (const row of b.roomCandidates) {
      const p = row.parsed;
      let finalDigits = p.originalDigits;
      let reason = "";

      if (p.kind === "plain") {
        finalDigits = p.baseDigits;
      } else if (p.kind === "explicit_suffix") {
        finalDigits = p.baseDigits;
        reason = `Blender duplicate suffix .${p.suffixDigits} removed`;
      } else if (p.kind === "compact_suffix") {
        const suffixNum = Number(p.suffixDigits);
        const compactLooksLikeDuplicate = p.baseDigits.length >= 1 && p.baseDigits.length <= 4 && suffixNum >= 1 && suffixNum <= 999;
        const crossBuildingEvidence = canonicalBaseSet.has(p.baseDigits);
        const sameBuildingEvidence = canonicalByBuilding.get(b.name)?.has(p.baseDigits) || false;
        const repeatedCompactBase = (compactByBaseCount.get(p.baseDigits) || 0) >= 2;

        if (compactLooksLikeDuplicate && (crossBuildingEvidence || sameBuildingEvidence || repeatedCompactBase)) {
          finalDigits = p.baseDigits;
          reason = crossBuildingEvidence || sameBuildingEvidence
            ? `Compact duplicate suffix ${p.suffixDigits} removed (matching room number evidence found)`
            : `Compact duplicate suffix ${p.suffixDigits} removed (repeated compact duplicate pattern)`;
        }
      } else if (p.kind === "loader_suffix") {
        const suffixNum = Number(p.suffixDigits);
        const loaderLooksLikeDuplicate =
          p.baseDigits.length >= 1 &&
          p.baseDigits.length <= 4 &&
          suffixNum >= 1 &&
          suffixNum <= 999;
        const crossBuildingEvidence = canonicalBaseSet.has(p.baseDigits);
        const sameBuildingEvidence = canonicalByBuilding.get(b.name)?.has(p.baseDigits) || false;

        if (loaderLooksLikeDuplicate && (crossBuildingEvidence || sameBuildingEvidence)) {
          finalDigits = p.baseDigits;
          reason = `Loader duplicate suffix ${p.suffixDigits} normalized to room ${p.baseDigits}`;
        }
      }

      const finalName = `ROOM ${finalDigits}`;
      row.finalName = finalName;
      row.reason = reason || "Standardized room naming format";
      const loaderAliasAlreadyCanonical =
        p.kind === "loader_suffix" &&
        finalDigits === p.baseDigits &&
        reason.startsWith("Loader duplicate suffix");
      const isAlreadyEngraved = loaderAliasAlreadyCanonical || String(row.oldName).trim() === finalName;

      if (!isAlreadyEngraved) {
        corrections.push({
          buildingName: row.buildingName,
          object: row.object,
          oldName: row.oldName,
          newName: finalName,
          reason: row.reason
        });
      }
      auditItems.push({
        buildingName: row.buildingName,
        oldName: row.oldName,
        newName: finalName,
        status: isAlreadyEngraved ? "already_engraved" : "needs_rename"
      });

      if (!roomMap.has(finalName)) {
        roomMap.set(finalName, { name: finalName });
      }
    }

    const rooms = Array.from(roomMap.values()).sort((a, b2) => a.name.localeCompare(b2.name));
    buildingsOut.push({
      name: b.name,
      classification: rooms.length ? "building" : "unclassified",
      rooms
    });
  }

  buildingsOut.sort((a, b) => a.name.localeCompare(b.name));
  auditItems.sort((a, b) => {
    const byBuilding = a.buildingName.localeCompare(b.buildingName);
    if (byBuilding !== 0) return byBuilding;
    const byNew = a.newName.localeCompare(b.newName);
    if (byNew !== 0) return byNew;
    return a.oldName.localeCompare(b.oldName);
  });
  return { buildingsOut, corrections, auditItems };
}

function renderPreview(buildings) {
  previewBody.innerHTML = "";
  if (!Array.isArray(buildings) || !buildings.length) {
    previewBody.innerHTML = "<tr><td colspan=\"3\">No buildings detected from model.</td></tr>";
    return;
  }

  for (const b of buildings) {
    const roomNames = b.rooms.map((r) => r.name).join(", ");
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${escapeHtml(b.name)}</td>
      <td>${escapeHtml(b.classification)}</td>
      <td>${roomNames ? escapeHtml(roomNames) : "-"}</td>
    `;
    previewBody.appendChild(tr);
  }
}

function renderCorrections(corrections) {
  correctionsBody.innerHTML = "";
  if (!Array.isArray(corrections) || !corrections.length) {
    correctionsBody.innerHTML = "<tr><td colspan=\"4\">No name corrections required.</td></tr>";
    return;
  }
  for (const c of corrections) {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${escapeHtml(c.buildingName)}</td>
      <td>${escapeHtml(c.oldName)}</td>
      <td>${escapeHtml(c.newName)}</td>
      <td>${escapeHtml(c.reason)}</td>
    `;
    correctionsBody.appendChild(tr);
  }
}

function renderAudit(items) {
  auditBody.innerHTML = "";
  if (!Array.isArray(items) || !items.length) {
    auditBody.innerHTML = "<tr><td colspan=\"4\">No room objects detected in scan.</td></tr>";
    return;
  }

  for (const item of items) {
    const statusLabel = item.status === "already_engraved" ? "Already Engraved" : "Needs Rename";
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${escapeHtml(item.buildingName)}</td>
      <td>${escapeHtml(item.oldName)}</td>
      <td>${escapeHtml(item.newName)}</td>
      <td>${escapeHtml(statusLabel)}</td>
    `;
    auditBody.appendChild(tr);
  }
}

function loadModel(url) {
  const loader = new GLTFLoader();
  return new Promise((resolve, reject) => {
    loader.load(url, (gltf) => resolve(gltf.scene), undefined, (err) => reject(err));
  });
}

function isRenderableNode(obj) {
  return !!(obj && (obj.isMesh || obj.isLine || obj.isPoints));
}

function findEffectiveExportRoot(sceneRoot) {
  if (!sceneRoot) return null;

  // Avoid exporting transient wrapper chains like AuxScene -> Scene.
  let root = sceneRoot;
  const visited = new Set();
  while (root && !visited.has(root)) {
    visited.add(root);
    const children = Array.isArray(root.children) ? root.children : [];
    if (children.length !== 1) break;
    if (!isGenericNodeName(root.name) || isRenderableNode(root)) break;

    const child = children[0];
    if (!child || !isGenericNodeName(child.name) || isRenderableNode(child)) break;
    root = child;
  }
  return root;
}

async function loadModelList() {
  const res = await fetch("mapImport.php?action=list_models", { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || `HTTP ${res.status}`);
  }
  const models = Array.isArray(data.models) ? data.models : [];
  modelSelect.innerHTML = "";
  if (!models.length) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "No .glb files found";
    modelSelect.appendChild(opt);
    modelSelect.disabled = true;
    selectedModelFile = "";
    selectedBackupFile = "";
    if (backupSelect) {
      backupSelect.innerHTML = "";
      const bOpt = document.createElement("option");
      bOpt.value = "";
      bOpt.textContent = "No backups found";
      backupSelect.appendChild(bOpt);
      backupSelect.disabled = true;
    }
    if (scanBackupBtn) scanBackupBtn.disabled = true;
    if (undoBtn) undoBtn.disabled = true;
    return;
  }

  for (const m of models) {
    const opt = document.createElement("option");
    opt.value = m.file;
    opt.textContent = m.isOriginal ? `${m.file} (original)` : m.file;
    modelSelect.appendChild(opt);
  }
  modelSelect.disabled = false;

  const names = models.map((m) => m.file);
  if (selectedModelFile && names.includes(selectedModelFile)) {
    modelSelect.value = selectedModelFile;
  } else if (names.includes(ORIGINAL_MODEL_NAME)) {
    selectedModelFile = ORIGINAL_MODEL_NAME;
    modelSelect.value = selectedModelFile;
  } else {
    selectedModelFile = names[0];
    modelSelect.value = selectedModelFile;
  }

  await loadBackupList();
}

function formatBackupTime(ts) {
  const n = Number(ts || 0);
  if (!Number.isFinite(n) || n <= 0) return "unknown date";
  try {
    const d = new Date(n * 1000);
    return d.toLocaleString();
  } catch (_) {
    return "unknown date";
  }
}

async function loadBackupList() {
  if (!backupSelect) return;
  backupSelect.innerHTML = "";
  if (!selectedModelFile) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "Select model first";
    backupSelect.appendChild(opt);
    backupSelect.disabled = true;
    selectedBackupFile = "";
    if (scanBackupBtn) scanBackupBtn.disabled = true;
    if (undoBtn) undoBtn.disabled = true;
    return;
  }

  const res = await fetch(`mapImport.php?action=list_backups&name=${encodeURIComponent(selectedModelFile)}`, { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || `HTTP ${res.status}`);
  }

  const backups = Array.isArray(data.backups) ? data.backups : [];
  if (!backups.length) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "No backups found";
    backupSelect.appendChild(opt);
    backupSelect.disabled = true;
    selectedBackupFile = "";
    if (scanBackupBtn) scanBackupBtn.disabled = true;
    if (undoBtn) undoBtn.disabled = true;
    return;
  }

  for (const b of backups) {
    const opt = document.createElement("option");
    opt.value = b.file;
    opt.textContent = `${b.file} (${formatBackupTime(b.mtime)})`;
    backupSelect.appendChild(opt);
  }
  backupSelect.disabled = false;

  const names = backups.map((b) => b.file);
  if (selectedBackupFile && names.includes(selectedBackupFile)) {
    backupSelect.value = selectedBackupFile;
  } else {
    selectedBackupFile = names[0];
    backupSelect.value = selectedBackupFile;
  }
  if (scanBackupBtn) scanBackupBtn.disabled = false;
  if (undoBtn) undoBtn.disabled = false;
}

async function scanModel() {
  if (scanBusy) return;
  if (!selectedModelFile) {
    setStatus("Select a model first.", "#b42318");
    return;
  }
  scanBusy = true;
  importBtn.disabled = true;
  fixNamesBtn.disabled = true;
  if (scanBackupBtn) scanBackupBtn.disabled = true;
  setStatus(`Scanning ${selectedModelFile} ...`);

  try {
    const modelUrl = `../models/${encodeURIComponent(selectedModelFile)}?v=${Date.now()}`;
    loadedSceneRoot = await loadModel(modelUrl);
    const extraction = buildExtraction(loadedSceneRoot);
    extractedBuildings = extraction.buildingsOut;
    correctionItems = extraction.corrections;
    roomAuditItems = extraction.auditItems;
    scannedModelFile = selectedModelFile;
    scannedFromBackup = false;
    scannedBackupFile = "";

    renderPreview(extractedBuildings);
    renderCorrections(correctionItems);
    renderAudit(roomAuditItems);

    let roomCount = 0;
    let unclassifiedCount = 0;
    for (const b of extractedBuildings) {
      roomCount += b.rooms.length;
      if (!b.rooms.length) unclassifiedCount++;
    }

    importBtn.disabled = extractedBuildings.length === 0;
    fixNamesBtn.disabled = correctionItems.length === 0;
    const alreadyEngraved = roomAuditItems.filter((x) => x.status === "already_engraved").length;
    const pendingRename = roomAuditItems.filter((x) => x.status === "needs_rename").length;
    setStatus(
      `Scan complete (live model): ${extractedBuildings.length} buildings, ${roomCount} rooms, ${unclassifiedCount} unclassified. Room audit: ${roomAuditItems.length} detected, ${alreadyEngraved} already engraved, ${pendingRename} needs rename.`,
      "#0f766e"
    );
  } catch (err) {
    console.error(err);
    scannedModelFile = "";
    scannedFromBackup = false;
    scannedBackupFile = "";
    loadedSceneRoot = null;
    extractedBuildings = [];
    correctionItems = [];
    roomAuditItems = [];
    renderPreview([]);
    renderCorrections([]);
    renderAudit([]);
    importBtn.disabled = true;
    fixNamesBtn.disabled = true;
    setStatus(`Scan failed: ${err?.message || err}`, "#b42318");
  } finally {
    scanBusy = false;
    const hasBackup = !!(backupSelect && !backupSelect.disabled && backupSelect.value);
    if (scanBackupBtn) scanBackupBtn.disabled = !hasBackup;
  }
}

async function scanBackupModel() {
  if (scanBusy) return;
  if (!selectedModelFile) {
    setStatus("Select a model first.", "#b42318");
    return;
  }
  const backupFile = (backupSelect && backupSelect.value) ? backupSelect.value : "";
  if (!backupFile) {
    setStatus("Select a backup file first.", "#b42318");
    return;
  }

  scanBusy = true;
  importBtn.disabled = true;
  fixNamesBtn.disabled = true;
  if (scanBackupBtn) scanBackupBtn.disabled = true;
  setStatus(`Scanning backup ${backupFile} ...`);

  try {
    const backupUrl = `../models/backups/${encodeURIComponent(backupFile)}?v=${Date.now()}`;
    loadedSceneRoot = await loadModel(backupUrl);
    const extraction = buildExtraction(loadedSceneRoot);
    extractedBuildings = extraction.buildingsOut;
    correctionItems = extraction.corrections;
    roomAuditItems = extraction.auditItems;
    scannedModelFile = selectedModelFile;
    scannedFromBackup = true;
    scannedBackupFile = backupFile;

    renderPreview(extractedBuildings);
    renderCorrections(correctionItems);
    renderAudit(roomAuditItems);

    let roomCount = 0;
    let unclassifiedCount = 0;
    for (const b of extractedBuildings) {
      roomCount += b.rooms.length;
      if (!b.rooms.length) unclassifiedCount++;
    }
    const alreadyEngraved = roomAuditItems.filter((x) => x.status === "already_engraved").length;
    const pendingRename = roomAuditItems.filter((x) => x.status === "needs_rename").length;
    setStatus(
      `Scan complete (backup): ${extractedBuildings.length} buildings, ${roomCount} rooms, ${unclassifiedCount} unclassified. Room audit: ${roomAuditItems.length} detected, ${alreadyEngraved} already engraved, ${pendingRename} needs rename. Save/Import disabled for backup scan.`,
      "#0f766e"
    );
  } catch (err) {
    console.error(err);
    scannedModelFile = "";
    scannedFromBackup = false;
    scannedBackupFile = "";
    loadedSceneRoot = null;
    extractedBuildings = [];
    correctionItems = [];
    roomAuditItems = [];
    renderPreview([]);
    renderCorrections([]);
    renderAudit([]);
    setStatus(`Backup scan failed: ${err?.message || err}`, "#b42318");
  } finally {
    scanBusy = false;
    if (scanBackupBtn) scanBackupBtn.disabled = !(backupSelect && !backupSelect.disabled && !!backupSelect.value);
  }
}

function exportSceneToBinary(sceneRoot) {
  return new Promise((resolve, reject) => {
    const exporter = new GLTFExporter();
    const effectiveRoot = findEffectiveExportRoot(sceneRoot) || sceneRoot;
    const exportScene = new THREE.Scene();
    exportScene.name = "Scene";

    const shouldFlattenGenericRoot = !!effectiveRoot && isGenericNodeName(effectiveRoot.name);
    const exportChildren = shouldFlattenGenericRoot && Array.isArray(effectiveRoot?.children)
      ? effectiveRoot.children
      : [];
    if (exportChildren.length) {
      for (const child of exportChildren) {
        exportScene.add(child.clone(true));
      }
    } else if (effectiveRoot) {
      exportScene.add(effectiveRoot.clone(true));
    }

    exporter.parse(
      exportScene,
      (result) => {
        if (result instanceof ArrayBuffer) {
          resolve(result);
          return;
        }
        reject(new Error("Exporter did not return GLB binary"));
      },
      (err) => reject(err),
      { binary: true }
    );
  });
}

async function applyNameFixesToModel() {
  if (saveBusy) return;
  if (!loadedSceneRoot) {
    setStatus("Scan model first before saving name fixes.", "#b42318");
    return;
  }
  if (!correctionItems.length) {
    setStatus("No name fixes to save.", "#334155");
    return;
  }
  if (!selectedModelFile) {
    setStatus("Select a model first.", "#b42318");
    return;
  }
  if (scannedFromBackup) {
    setStatus(`Current scan is from backup (${scannedBackupFile || "selected backup"}). Scan live model before saving fixes.`, "#b42318");
    return;
  }
  if (!scannedModelFile || scannedModelFile !== selectedModelFile) {
    setStatus("Model selection changed. Scan this model again before saving fixes.", "#b42318");
    return;
  }

  const ok = confirm(`Apply ${correctionItems.length} object name fixes and overwrite ${selectedModelFile}? A backup file will be created.`);
  if (!ok) return;

  saveBusy = true;
  fixNamesBtn.disabled = true;
  scanBtn.disabled = true;
  importBtn.disabled = true;
  if (scanBackupBtn) scanBackupBtn.disabled = true;
  setStatus(`Applying ${correctionItems.length} name fixes to ${selectedModelFile} ...`);

  try {
    for (const item of correctionItems) {
      if (!item?.object) continue;
      item.object.name = item.newName;
    }

    const glbBinary = await exportSceneToBinary(loadedSceneRoot);
    const res = await fetch(`mapImport.php?action=save_corrected_model&name=${encodeURIComponent(selectedModelFile)}`, {
      method: "POST",
      headers: {
        "Content-Type": "model/gltf-binary",
        "X-CSRF-Token": CSRF_TOKEN
      },
      body: glbBinary
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }

    setStatus(`Model updated: ${selectedModelFile}. Backup: ${data.backupFile || "created"}. Re-scanning ...`, "#0f766e");
    await loadBackupList().catch(() => {});
    await scanModel();
  } catch (err) {
    console.error(err);
    setStatus(`Failed to save name fixes: ${err?.message || err}`, "#b42318");
  } finally {
    saveBusy = false;
    fixNamesBtn.disabled = correctionItems.length === 0;
    scanBtn.disabled = false;
    importBtn.disabled = extractedBuildings.length === 0;
    const hasBackup = !!(backupSelect && !backupSelect.disabled && backupSelect.value);
    if (scanBackupBtn) scanBackupBtn.disabled = !hasBackup;
  }
}

async function importToDatabase() {
  if (importBusy) return;
  if (!selectedModelFile) {
    setStatus("Select a model first.", "#b42318");
    return;
  }
  if (scannedFromBackup) {
    setStatus(`Current scan is from backup (${scannedBackupFile || "selected backup"}). Scan live model before importing.`, "#b42318");
    return;
  }
  if (!scannedModelFile || scannedModelFile !== selectedModelFile) {
    setStatus("Model selection changed. Scan this model again before importing.", "#b42318");
    return;
  }
  if (!Array.isArray(extractedBuildings) || extractedBuildings.length === 0) {
    setStatus("No scanned data to import.", "#b42318");
    return;
  }

  importBusy = true;
  importBtn.disabled = true;
  if (scanBackupBtn) scanBackupBtn.disabled = true;
  setStatus(`Importing ${selectedModelFile} scan data into database ...`);

  try {
    const res = await fetch("mapImport.php?action=import_entities", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": CSRF_TOKEN
      },
      body: JSON.stringify({
        modelFile: selectedModelFile,
        buildings: extractedBuildings
      })
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }

    const s = data.summary || {};
    const versionId = Number(s.versionId || 0);
    const hashShort = String(s.modelHash || "").slice(0, 12);
    setStatus(
      `Import complete for ${selectedModelFile} (version #${versionId}${hashShort ? `, hash ${hashShort}` : ""}): buildings inserted ${Number(s.buildingsInserted || 0)}, existing ${Number(s.buildingsExisting || 0)}, missing now ${Number(s.buildingsMissingAfterSync || 0)}; rooms inserted ${Number(s.roomsInserted || 0)}, existing ${Number(s.roomsExisting || 0)}, missing now ${Number(s.roomsMissingAfterSync || 0)}.`,
      "#0f766e"
    );
  } catch (err) {
    console.error(err);
    setStatus(`Import failed: ${err?.message || err}`, "#b42318");
  } finally {
    importBusy = false;
    importBtn.disabled = extractedBuildings.length === 0;
    const hasBackup = !!(backupSelect && !backupSelect.disabled && backupSelect.value);
    if (scanBackupBtn) scanBackupBtn.disabled = !hasBackup;
  }
}

async function restoreLastBackup() {
  if (restoreBusy) return;
  if (!selectedModelFile) {
    setStatus("Select a model first.", "#b42318");
    return;
  }

  const backupFile = (backupSelect && backupSelect.value) ? backupSelect.value : "";
  const usingSpecificBackup = !!backupFile;
  const confirmMsg = usingSpecificBackup
    ? `Restore ${selectedModelFile} from selected backup?\n\n${backupFile}`
    : `Restore ${selectedModelFile} from its latest backup?`;
  const ok = confirm(confirmMsg);
  if (!ok) return;

  restoreBusy = true;
  undoBtn.disabled = true;
  if (scanBackupBtn) scanBackupBtn.disabled = true;
  scanBtn.disabled = true;
  fixNamesBtn.disabled = true;
  importBtn.disabled = true;
  setStatus(usingSpecificBackup
    ? `Restoring ${selectedModelFile} from selected backup ...`
    : `Restoring ${selectedModelFile} from latest backup ...`);

  try {
    const endpoint = usingSpecificBackup
      ? `mapImport.php?action=restore_backup&name=${encodeURIComponent(selectedModelFile)}&backup=${encodeURIComponent(backupFile)}`
      : `mapImport.php?action=restore_last_backup&name=${encodeURIComponent(selectedModelFile)}`;

    const res = await fetch(endpoint, {
      method: "POST",
      headers: {
        "X-CSRF-Token": CSRF_TOKEN
      }
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }

    setStatus(
      `Restore complete. Source: ${data.restoredFrom || "latest backup"}. Pre-restore backup: ${data.preRestoreBackup || "created"}. Re-scanning ...`,
      "#0f766e"
    );

    await loadBackupList().catch(() => {});
    scannedModelFile = "";
    loadedSceneRoot = null;
    extractedBuildings = [];
    correctionItems = [];
    roomAuditItems = [];
    await scanModel();
  } catch (err) {
    console.error(err);
    setStatus(`Restore failed: ${err?.message || err}`, "#b42318");
  } finally {
    restoreBusy = false;
    const hasBackup = !!(backupSelect && !backupSelect.disabled && backupSelect.value);
    undoBtn.disabled = !hasBackup;
    if (scanBackupBtn) scanBackupBtn.disabled = !hasBackup;
    scanBtn.disabled = false;
    fixNamesBtn.disabled = correctionItems.length === 0;
    importBtn.disabled = extractedBuildings.length === 0;
  }
}

modelSelect.addEventListener("change", () => {
  selectedModelFile = modelSelect.value || "";
  selectedBackupFile = "";
  scannedModelFile = "";
  scannedFromBackup = false;
  scannedBackupFile = "";
  loadedSceneRoot = null;
  extractedBuildings = [];
  correctionItems = [];
  roomAuditItems = [];
  renderPreview([]);
  renderCorrections([]);
  renderAudit([]);
  importBtn.disabled = true;
  fixNamesBtn.disabled = true;
  setStatus("Model changed. Scan again.", "#334155");
  loadBackupList().catch((err) => {
    console.error(err);
    setStatus(`Failed to load backups: ${err?.message || err}`, "#b42318");
  });
});

reloadModelsBtn.addEventListener("click", async () => {
  try {
    await loadModelList();
    setStatus("Model list refreshed.");
  } catch (err) {
    console.error(err);
    setStatus(`Failed to load models: ${err?.message || err}`, "#b42318");
  }
});

backupSelect?.addEventListener("change", () => {
  selectedBackupFile = backupSelect.value || "";
  const hasBackup = !!selectedBackupFile;
  if (scanBackupBtn) scanBackupBtn.disabled = !hasBackup;
  if (undoBtn) undoBtn.disabled = !hasBackup;
});

reloadBackupsBtn?.addEventListener("click", async () => {
  try {
    await loadBackupList();
    setStatus("Backup list refreshed.");
  } catch (err) {
    console.error(err);
    setStatus(`Failed to load backups: ${err?.message || err}`, "#b42318");
  }
});

scanBtn.addEventListener("click", () => {
  scanModel();
});

scanBackupBtn?.addEventListener("click", () => {
  scanBackupModel();
});

fixNamesBtn.addEventListener("click", () => {
  applyNameFixesToModel();
});

importBtn.addEventListener("click", () => {
  importToDatabase();
});

undoBtn.addEventListener("click", () => {
  restoreLastBackup();
});

(async () => {
  try {
    await loadModelList();
    setStatus("Ready. Select model and scan.");
  } catch (err) {
    console.error(err);
    setStatus(`Failed to initialize: ${err?.message || err}`, "#b42318");
  }
})();
</script>

<?php admin_layout_end(); ?>
