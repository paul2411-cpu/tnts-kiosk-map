<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/map_sync.php";
require_once __DIR__ . "/inc/building_identity.php";
app_logger_set_default_subsystem("building_admin");

$MODEL_DIR = __DIR__ . "/../models";
$ORIGINAL_MODEL_NAME = "tnts_navigation.glb";

if (empty($_SESSION["building_editor_csrf"])) {
  try {
    $_SESSION["building_editor_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["building_editor_csrf"] = bin2hex(hash("sha256", uniqid((string)mt_rand(), true), true));
  }
}
$BUILDING_EDITOR_CSRF = (string)$_SESSION["building_editor_csrf"];

function bld_fail(int $status, string $msg): void {
  app_log_http_problem($status, $msg, [
    "action" => trim((string)($_GET["action"] ?? "")),
    "modelFile" => trim((string)($_GET["model"] ?? "")),
  ], [
    "subsystem" => "building_admin",
    "event" => "http_error",
  ]);
  http_response_code($status);
  echo json_encode(["ok" => false, "error" => $msg], JSON_PRETTY_PRINT);
  exit;
}

function bld_post_csrf(): void {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") bld_fail(405, "POST required");
  $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
  $sessionToken = (string)($_SESSION["building_editor_csrf"] ?? "");
  if (!is_string($token) || $token === "" || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    bld_fail(403, "CSRF validation failed");
  }
}

function bld_model_name(string $raw): ?string {
  $base = basename($raw);
  if ($base === "" || $base === "." || $base === "..") return null;
  if (!preg_match('/\.glb$/i', $base)) return null;
  return $base;
}

function bld_is_road_like_name(string $raw): bool {
  $name = trim($raw);
  if ($name === "") return false;
  return (bool)preg_match('/^road(?:[._-]\d+)?$/i', $name);
}

function bld_col(mysqli $conn, string $table, string $col): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeCol = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function bld_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) throw new RuntimeException($conn->error);
}

function bld_valid_glb(string $raw): bool {
  if (strlen($raw) < 12) return false;
  $hdr = unpack("a4magic/Vversion/Vlength", substr($raw, 0, 12));
  if (!is_array($hdr)) return false;
  if (($hdr["magic"] ?? "") !== "glTF") return false;
  if ((int)($hdr["version"] ?? 0) !== 2) return false;
  return (int)($hdr["length"] ?? 0) === strlen($raw);
}

function bld_atomic_write(string $path, string $raw): bool {
  try {
    $suffix = bin2hex(random_bytes(6));
  } catch (Throwable $_) {
    $suffix = (string)mt_rand(100000, 999999);
  }
  $tmp = $path . ".tmp." . $suffix;
  $ok = file_put_contents($tmp, $raw, LOCK_EX);
  if ($ok === false) return false;
  if (@rename($tmp, $path)) return true;
  @unlink($path);
  $renamed = @rename($tmp, $path);
  if (!$renamed) @unlink($tmp);
  return $renamed;
}

function bld_ensure_schema(mysqli $conn): void {
  bld_sql($conn, "
    CREATE TABLE IF NOT EXISTS map_versions (
      version_id INT AUTO_INCREMENT PRIMARY KEY,
      model_file VARCHAR(255) NOT NULL,
      model_hash CHAR(64) NOT NULL,
      imported_by_admin_id INT NULL,
      total_buildings INT NOT NULL DEFAULT 0,
      total_rooms INT NOT NULL DEFAULT 0,
      date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_model_hash (model_file, model_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  if (!bld_col($conn, "buildings", "source_model_file")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN source_model_file VARCHAR(255) NULL AFTER image_path");
  if (!bld_col($conn, "buildings", "first_seen_version_id")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  if (!bld_col($conn, "buildings", "last_seen_version_id")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  if (!bld_col($conn, "buildings", "is_present_in_latest")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  if (!bld_col($conn, "buildings", "last_edited_at")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN last_edited_at DATETIME NULL AFTER is_present_in_latest");
  if (!bld_col($conn, "buildings", "last_edited_by_admin_id")) bld_sql($conn, "ALTER TABLE buildings ADD COLUMN last_edited_by_admin_id INT NULL AFTER last_edited_at");
  map_identity_ensure_schema($conn);
}

function bld_version_id(mysqli $conn, string $modelFile, string $modelHash, ?int $adminId): int {
  $sel = $conn->prepare("SELECT version_id FROM map_versions WHERE model_file = ? AND model_hash = ? LIMIT 1");
  if (!$sel) throw new RuntimeException("Failed to prepare version lookup");
  $sel->bind_param("ss", $modelFile, $modelHash);
  if (!$sel->execute()) throw new RuntimeException("Failed to query map_versions");
  $res = $sel->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $sel->close();
  if ($row && isset($row["version_id"])) return (int)$row["version_id"];

  if ($adminId === null) {
    $ins = $conn->prepare("INSERT INTO map_versions (model_file, model_hash, imported_by_admin_id, total_buildings, total_rooms) VALUES (?, ?, NULL, 0, 0)");
    if (!$ins) throw new RuntimeException("Failed to prepare version insert");
    $ins->bind_param("ss", $modelFile, $modelHash);
  } else {
    $ins = $conn->prepare("INSERT INTO map_versions (model_file, model_hash, imported_by_admin_id, total_buildings, total_rooms) VALUES (?, ?, ?, 0, 0)");
    if (!$ins) throw new RuntimeException("Failed to prepare version insert");
    $ins->bind_param("ssi", $modelFile, $modelHash, $adminId);
  }
  if (!$ins->execute()) throw new RuntimeException("Failed to insert map version");
  $id = (int)$ins->insert_id;
  $ins->close();
  return $id;
}

function bld_sync_linked_room_names(mysqli $conn, int $buildingId, string $buildingUid, string $oldName, string $newName, string $modelFile): void {
  if (!bld_col($conn, "rooms", "building_name")) return;
  $hasBuildingUid = bld_col($conn, "rooms", "building_uid");
  $safeUid = map_identity_normalize_uid($buildingUid);

  if (bld_col($conn, "rooms", "building_id")) {
    if ($hasBuildingUid) {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ?, building_uid = ? WHERE building_id = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare linked room sync");
      $stmt->bind_param("ssi", $newName, $safeUid, $buildingId);
    } else {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ? WHERE building_id = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare linked room sync");
      $stmt->bind_param("si", $newName, $buildingId);
    }
    if (!$stmt->execute()) throw new RuntimeException("Failed to sync linked room names");
    $stmt->close();
    return;
  }

  if (bld_col($conn, "rooms", "source_model_file")) {
    if ($hasBuildingUid) {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ?, building_uid = ? WHERE building_name = ? AND source_model_file = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare fallback room sync");
      $stmt->bind_param("ssss", $newName, $safeUid, $oldName, $modelFile);
    } else {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ? WHERE building_name = ? AND source_model_file = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare fallback room sync");
      $stmt->bind_param("sss", $newName, $oldName, $modelFile);
    }
  } else {
    if ($hasBuildingUid) {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ?, building_uid = ? WHERE building_name = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare fallback room sync");
      $stmt->bind_param("sss", $newName, $safeUid, $oldName);
    } else {
      $stmt = $conn->prepare("UPDATE rooms SET building_name = ? WHERE building_name = ?");
      if (!$stmt) throw new RuntimeException("Failed to prepare fallback room sync");
      $stmt->bind_param("ss", $newName, $oldName);
    }
  }
  if (!$stmt->execute()) throw new RuntimeException("Failed to sync fallback room names");
  $stmt->close();
}

function bld_editor_model_file(): ?string {
  return map_sync_resolve_editor_model(dirname(__DIR__));
}

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");
  $action = (string)$_GET["action"];

  if ($action === "list_models") {
    $editorModel = bld_editor_model_file();
    $publicState = map_sync_resolve_public_model(dirname(__DIR__));
    $publicModel = trim((string)($publicState["modelFile"] ?? ""));
    $rows = [];
    if (is_dir($MODEL_DIR)) {
      foreach (scandir($MODEL_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.glb$/i', $f)) continue;
        $rows[] = [
          "file" => $f,
          "isOriginal" => strcasecmp($f, $ORIGINAL_MODEL_NAME) === 0,
          "isDefault" => ($editorModel !== null && strcasecmp($f, $editorModel) === 0),
          "isLive" => ($publicModel !== "" && strcasecmp($f, $publicModel) === 0),
          "isEditable" => ($editorModel !== null && strcasecmp($f, $editorModel) === 0)
        ];
      }
    }
    usort($rows, static function(array $a, array $b): int {
      $editableCmp = ((int)!empty($a["isEditable"])) <=> ((int)!empty($b["isEditable"]));
      if ($editableCmp !== 0) return -$editableCmp;
      $liveCmp = ((int)!empty($a["isLive"])) <=> ((int)!empty($b["isLive"]));
      if ($liveCmp !== 0) return -$liveCmp;
      return strnatcasecmp((string)$a["file"], (string)$b["file"]);
    });
    echo json_encode(["ok" => true, "models" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "list_buildings") {
    $model = "";
    if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
      $safe = bld_model_name((string)$_GET["model"]);
      if ($safe === null) bld_fail(400, "Invalid model");
      $model = $safe;
    }
    $hasSource = bld_col($conn, "buildings", "source_model_file");
    $hasPresent = bld_col($conn, "buildings", "is_present_in_latest");
    $hasVersion = bld_col($conn, "buildings", "last_seen_version_id");
    $hasEdited = bld_col($conn, "buildings", "last_edited_at");

    $sql = "SELECT building_id, building_name, description, image_path, "
      . ($hasSource ? "source_model_file" : "NULL AS source_model_file")
      . ", " . ($hasVersion ? "last_seen_version_id" : "NULL AS last_seen_version_id")
      . ", " . ($hasEdited ? "last_edited_at" : "NULL AS last_edited_at")
      . " FROM buildings WHERE building_name IS NOT NULL AND building_name <> ''";
    if ($hasPresent) $sql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";

    if ($model !== "" && $hasSource) {
      $sql .= " AND source_model_file = ? ORDER BY building_name ASC";
      $stmt = $conn->prepare($sql);
      if (!$stmt) bld_fail(500, "Failed to prepare query");
      $stmt->bind_param("s", $model);
      if (!$stmt->execute()) bld_fail(500, "Failed to query buildings");
      $res = $stmt->get_result();
    } else {
      $sql .= " ORDER BY building_name ASC";
      $res = $conn->query($sql);
      if (!($res instanceof mysqli_result)) bld_fail(500, "Failed to query buildings");
    }

    $rows = [];
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    echo json_encode(["ok" => true, "buildings" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "get_building_meta") {
    $name = trim((string)($_GET["name"] ?? ""));
    if ($name === "") bld_fail(400, "Missing building name");
    $model = "";
    if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
      $safe = bld_model_name((string)$_GET["model"]);
      if ($safe === null) bld_fail(400, "Invalid model");
      $model = $safe;
    }
    $hasSource = bld_col($conn, "buildings", "source_model_file");
    $sqlCols = "building_id, building_name, description, image_path, "
      . (bld_col($conn, "buildings", "last_seen_version_id") ? "last_seen_version_id" : "NULL AS last_seen_version_id")
      . ", " . (bld_col($conn, "buildings", "last_edited_at") ? "last_edited_at" : "NULL AS last_edited_at")
      . ", " . ($hasSource ? "source_model_file" : "NULL AS source_model_file");
    $row = null;
    if ($model !== "" && $hasSource) {
      $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE source_model_file = ? AND building_name = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("ss", $model, $name);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
        }
      }
    }
    if (!$row) {
      $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE building_name = ? ORDER BY building_id DESC LIMIT 1");
      if ($stmt) {
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
        }
      }
    }
    echo json_encode(["ok" => true, "found" => (bool)$row, "building" => $row ?: null], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_building") {
    bld_post_csrf();
    $modelFile = bld_model_name((string)($_POST["modelFile"] ?? ""));
    if ($modelFile === null) bld_fail(400, "Invalid model file");
    $editableModel = bld_editor_model_file();
    if ($editableModel !== null && strcasecmp($editableModel, $modelFile) !== 0) {
      bld_fail(409, "Only the default admin model can be edited here. Switch the default model in Map Editor before editing this snapshot.");
    }
    $modelPath = $MODEL_DIR . "/" . $modelFile;
    if (!file_exists($modelPath)) bld_fail(404, "Model not found");

    $oldName = trim((string)($_POST["oldName"] ?? ""));
    $newName = trim((string)($_POST["newName"] ?? ""));
    $description = trim((string)($_POST["description"] ?? ""));
    $imagePath = trim((string)($_POST["imagePath"] ?? ""));
    if ($oldName === "") bld_fail(400, "Missing old name");
    if ($newName === "") bld_fail(400, "Missing new name");
    if (mb_strlen($newName) > 100) bld_fail(400, "Building name too long");
    if (mb_strlen($imagePath) > 255) bld_fail(400, "Image path too long");
    if (bld_is_road_like_name($oldName) || bld_is_road_like_name($newName)) {
      bld_fail(400, "Road objects cannot be saved as buildings");
    }

    if (!isset($_FILES["glb"])) bld_fail(400, "Missing GLB upload");
    if ((int)($_FILES["glb"]["error"] ?? 1) !== UPLOAD_ERR_OK) bld_fail(400, "GLB upload error");
    $tmpPath = (string)($_FILES["glb"]["tmp_name"] ?? "");
    $incoming = @file_get_contents($tmpPath);
    if (!is_string($incoming) || !bld_valid_glb($incoming)) bld_fail(400, "Invalid GLB payload");

    $backupDir = $MODEL_DIR . "/backups";
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
      bld_fail(500, "Failed to create backup directory");
    }
    $base = preg_replace('/\.glb$/i', "", $modelFile);
    $backupName = $base . "_backup_" . date("Ymd_His") . "_building_edit.glb";
    $backupPath = $backupDir . "/" . $backupName;
    if (!@copy($modelPath, $backupPath)) bld_fail(500, "Failed to backup model");
    if (!bld_atomic_write($modelPath, $incoming)) bld_fail(500, "Failed to save model");

    $projectRoot = dirname(__DIR__);
    $overlayDir = map_sync_paths($projectRoot)["overlayDir"];
    $roadnetPath = $overlayDir . "/roadnet_" . $modelFile . ".json";
    $routesPath = $overlayDir . "/routes_" . $modelFile . ".json";
    $guidesPath = $overlayDir . "/guides_" . $modelFile . ".json";
    $roadnetBackup = file_exists($roadnetPath) ? @file_get_contents($roadnetPath) : null;
    $routesBackup = file_exists($routesPath) ? @file_get_contents($routesPath) : null;
    $guidesBackup = file_exists($guidesPath) ? @file_get_contents($guidesPath) : null;

    try {
      bld_ensure_schema($conn);
      $hash = @hash_file("sha256", $modelPath);
      if (!is_string($hash) || $hash === "") throw new RuntimeException("Failed to hash model");
      $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
      if (is_int($adminId) && $adminId <= 0) $adminId = null;

      $conn->begin_transaction();
      $versionId = bld_version_id($conn, $modelFile, $hash, $adminId);

      $id = 0;
      $buildingUid = "";
      $buildingObjectName = $oldName;
      $lookups = [
        ["SELECT building_id, building_uid, model_object_name FROM buildings WHERE source_model_file = ? AND building_name = ? LIMIT 1", "ss", [$modelFile, $oldName]],
        ["SELECT building_id, building_uid, model_object_name FROM buildings WHERE source_model_file = ? AND building_name = ? LIMIT 1", "ss", [$modelFile, $newName]],
      ];
      foreach ($lookups as $q) {
        if ($id > 0) break;
        [$sql, $types, $params] = $q;
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException("Failed to prepare lookup");
        $stmt->bind_param("ss", $params[0], $params[1]);
        if (!$stmt->execute()) throw new RuntimeException("Failed building lookup");
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && isset($row["building_id"])) {
          $id = (int)$row["building_id"];
          $buildingUid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
          $buildingObjectName = trim((string)($row["model_object_name"] ?? $buildingObjectName));
        }
        $stmt->close();
      }

      if ($oldName !== $newName) {
        $dupStmt = $conn->prepare("SELECT building_id FROM buildings WHERE source_model_file = ? AND building_name = ? LIMIT 1");
        if (!$dupStmt) throw new RuntimeException("Failed to prepare duplicate building check");
        $dupStmt->bind_param("ss", $modelFile, $newName);
        if (!$dupStmt->execute()) {
          $dupStmt->close();
          throw new RuntimeException("Failed to check duplicate building names");
        }
        $dupRes = $dupStmt->get_result();
        $dupRow = $dupRes ? $dupRes->fetch_assoc() : null;
        $dupStmt->close();
        $dupId = $dupRow ? (int)($dupRow["building_id"] ?? 0) : 0;
        if ($dupId > 0 && $dupId !== $id) {
          throw new RuntimeException("Another building in this model already uses that name.");
        }
      }

      if ($buildingUid === "") {
        $buildingUid = map_identity_resolve_uid($conn, $oldName !== "" ? $oldName : $newName, $modelFile);
      }
      if ($buildingObjectName === "") {
        $buildingObjectName = $oldName !== "" ? $oldName : $newName;
      }

      $inserted = false;
      if ($id > 0) {
        if ($adminId === null) {
          $stmt = $conn->prepare("UPDATE buildings SET building_uid=?, building_name=?, model_object_name=?, description=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=NULL WHERE building_id=?");
          if (!$stmt) throw new RuntimeException("Failed to prepare update");
          $stmt->bind_param("ssssssii", $buildingUid, $newName, $buildingObjectName, $description, $imagePath, $modelFile, $versionId, $id);
        } else {
          $stmt = $conn->prepare("UPDATE buildings SET building_uid=?, building_name=?, model_object_name=?, description=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=? WHERE building_id=?");
          if (!$stmt) throw new RuntimeException("Failed to prepare update");
          $stmt->bind_param("ssssssiii", $buildingUid, $newName, $buildingObjectName, $description, $imagePath, $modelFile, $versionId, $adminId, $id);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to update building");
        $stmt->close();
      } else {
        $inserted = true;
        if ($adminId === null) {
          $stmt = $conn->prepare("INSERT INTO buildings (building_uid, building_name, model_object_name, description, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)");
          if (!$stmt) throw new RuntimeException("Failed to prepare insert");
          $stmt->bind_param("ssssssii", $buildingUid, $newName, $buildingObjectName, $description, $imagePath, $modelFile, $versionId, $versionId);
        } else {
          $stmt = $conn->prepare("INSERT INTO buildings (building_uid, building_name, model_object_name, description, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)");
          if (!$stmt) throw new RuntimeException("Failed to prepare insert");
          $stmt->bind_param("ssssssiii", $buildingUid, $newName, $buildingObjectName, $description, $imagePath, $modelFile, $versionId, $versionId, $adminId);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to insert building");
        $id = (int)$stmt->insert_id;
        $stmt->close();
      }

      bld_sync_linked_room_names($conn, $id, $buildingUid, $oldName, $newName, $modelFile);

      if ($oldName !== $newName && !map_sync_rename_roadnet_links($projectRoot, $modelFile, $oldName, $newName, $buildingUid)) {
        throw new RuntimeException("Failed to sync published road links for renamed building");
      }
      if ($oldName !== $newName && !map_sync_rename_route_entry($projectRoot, $modelFile, $oldName, $newName, $buildingUid)) {
        throw new RuntimeException("Failed to sync published routes for renamed building");
      }
      if ($oldName !== $newName && !map_sync_rename_guide_entries($projectRoot, $modelFile, $oldName, $newName, $buildingUid)) {
        throw new RuntimeException("Failed to sync published text guides for renamed building");
      }

      $meta = $conn->prepare("SELECT DATE_FORMAT(last_edited_at, '%Y-%m-%d %H:%i:%s') AS edited_at FROM buildings WHERE building_id = ? LIMIT 1");
      if (!$meta) throw new RuntimeException("Failed to prepare metadata query");
      $meta->bind_param("i", $id);
      if (!$meta->execute()) throw new RuntimeException("Failed to query metadata");
      $mres = $meta->get_result();
      $mrow = $mres ? $mres->fetch_assoc() : null;
      $edited = (string)($mrow["edited_at"] ?? "");
      $meta->close();

      $conn->commit();
      app_log("info", "Building saved", [
        "action" => $action,
        "modelFile" => $modelFile,
        "buildingId" => $id,
        "buildingUid" => $buildingUid,
        "oldName" => $oldName,
        "newName" => $newName,
        "inserted" => $inserted,
        "versionId" => $versionId,
      ], [
        "subsystem" => "building_admin",
        "event" => "save_building",
      ]);
      echo json_encode([
        "ok" => true,
        "buildingId" => $id,
        "versionId" => $versionId,
        "editedAt" => $edited,
        "inserted" => $inserted,
        "backupFile" => "models/backups/" . $backupName
      ], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      $backupRaw = @file_get_contents($backupPath);
      if (is_string($backupRaw) && bld_valid_glb($backupRaw)) {
        bld_atomic_write($modelPath, $backupRaw);
      }
      if (is_string($roadnetBackup)) {
        $decodedRoadnet = json_decode($roadnetBackup, true);
        if (is_array($decodedRoadnet)) {
          map_sync_atomic_write_json($roadnetPath, $decodedRoadnet);
        }
      }
      if (is_string($routesBackup)) {
        $decodedRoutes = json_decode($routesBackup, true);
        if (is_array($decodedRoutes)) {
          map_sync_atomic_write_json($routesPath, $decodedRoutes);
        }
      }
      if (is_string($guidesBackup)) {
        $decodedGuides = json_decode($guidesBackup, true);
        if (is_array($decodedGuides)) {
          map_sync_atomic_write_json($guidesPath, $decodedGuides);
        }
      }
      bld_fail(500, "Save failed: " . $e->getMessage());
    }
  }

  bld_fail(404, "Unknown action");
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Buildings", "buildings");
?>

<div class="card">
  <div class="section-title">Building Editor (Map + DB)</div>
  <div style="color:#667085;font-weight:800;line-height:1.6;">
    Load a map model, click a building in the 3D viewport, edit details, and save.<br>
    Save engraves the new building name into GLB and updates database metadata with map version and edit timestamp.
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="row" style="grid-template-columns:140px 1fr 130px 130px;">
    <div class="label">Map Model</div>
    <select id="model-select" class="select"></select>
    <button id="load-model-btn" type="button" class="btn primary">Load</button>
    <button id="reload-models-btn" type="button" class="btn gray">Reload</button>
  </div>
  <div id="building-status" style="margin-top:10px;color:#334155;font-weight:800;">Ready.</div>
</div>

<div style="display:grid;grid-template-columns:minmax(480px,1.35fr) minmax(360px,1fr);gap:12px;margin-top:12px;">
  <div class="card">
    <div class="section-title">3D Building Picker</div>
    <div id="building-map-viewport" style="width:100%;height:560px;border:1px solid #d0d5dd;border-radius:12px;overflow:hidden;background:#f8fafc;"></div>
  </div>

  <div class="card">
    <div class="section-title">Selected Building</div>
    <form class="form" onsubmit="return false;">
      <div class="row"><div class="label">Selected (Map)</div><input id="selected-object-name" class="input" readonly /></div>
      <div class="row"><div class="label">Source Model</div><input id="selected-model-file" class="input" readonly /></div>
      <div class="row"><div class="label">Building Name</div><input id="building-new-name" class="input" /></div>
      <div class="row"><div class="label">Description</div><textarea id="building-description" class="textarea"></textarea></div>
      <div class="row"><div class="label">Image Path</div><input id="building-image-path" class="input" placeholder="/assets/buildings/arca.jpg" /></div>
      <div class="row"><div class="label">Version ID</div><input id="building-version" class="input" readonly /></div>
      <div class="row"><div class="label">Last Edited</div><input id="building-edited-at" class="input" readonly /></div>
      <div class="actions">
        <button id="save-building-btn" type="button" class="btn primary">Save Building</button>
        <button id="reload-meta-btn" type="button" class="btn blue">Reload DB Meta</button>
        <button id="clear-selection-btn" type="button" class="btn gray">Clear</button>
      </div>
    </form>
  </div>
</div>

<div class="table-wrap" style="margin-top:12px;">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Building</th><th>Description</th><th>Image</th><th>Model</th><th>Version</th><th>Last Edited</th>
      </tr>
    </thead>
    <tbody id="buildings-table-body">
      <tr><td colspan="7">No data loaded yet.</td></tr>
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
import { OrbitControls } from "three/addons/controls/OrbitControls.js";

const CSRF = <?= json_encode($BUILDING_EDITOR_CSRF) ?>;
const ORIGINAL = <?= json_encode($ORIGINAL_MODEL_NAME) ?>;

const modelSel = document.getElementById("model-select");
const loadBtn = document.getElementById("load-model-btn");
const reloadBtn = document.getElementById("reload-models-btn");
const statusEl = document.getElementById("building-status");
const viewport = document.getElementById("building-map-viewport");
const tableBody = document.getElementById("buildings-table-body");

const selectedName = document.getElementById("selected-object-name");
const selectedModel = document.getElementById("selected-model-file");
const newName = document.getElementById("building-new-name");
const desc = document.getElementById("building-description");
const imagePath = document.getElementById("building-image-path");
const version = document.getElementById("building-version");
const editedAt = document.getElementById("building-edited-at");
const saveBtn = document.getElementById("save-building-btn");
const reloadMetaBtn = document.getElementById("reload-meta-btn");
const clearBtn = document.getElementById("clear-selection-btn");

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xf5f6f8);
const camera = new THREE.PerspectiveCamera(55, 1, 0.01, 100000);
camera.position.set(20, 20, 20);
const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
viewport.appendChild(renderer.domElement);
const canvas = renderer.domElement;
canvas.style.display = "block";
canvas.style.width = "100%";
canvas.style.height = "100%";
canvas.style.touchAction = "none";
const controls = new OrbitControls(camera, canvas);
controls.enableDamping = true;
controls.enableRotate = true;
controls.enablePan = true;
controls.enableZoom = true;
controls.screenSpacePanning = true;
controls.mouseButtons = {
  LEFT: THREE.MOUSE.ROTATE,
  MIDDLE: THREE.MOUSE.DOLLY,
  RIGHT: THREE.MOUSE.PAN
};
controls.touches = {
  ONE: THREE.TOUCH.ROTATE,
  TWO: THREE.TOUCH.DOLLY_PAN
};
scene.add(new THREE.AmbientLight(0xffffff, 0.95));
const dl = new THREE.DirectionalLight(0xffffff, 1.0);
dl.position.set(15, 18, 12);
scene.add(dl);
scene.add(new THREE.GridHelper(260, 65, 0xcbd5e1, 0xe5e7eb));

const loader = new GLTFLoader();
const exporter = new GLTFExporter();
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

let mapRoot = null;
let selectedRoot = null;
let hoverRoot = null;
let selectedBox = null;
let hoverBox = null;
let modelRows = [];
const TOUCH_TAP_SLOP_PX = 10;
const TOUCH_CLICK_SUPPRESS_MS = 450;
const activeTouchPointerIds = new Set();
let touchTap = null;
let suppressSceneClickUntil = 0;

function setStatus(msg, color = "#334155") {
  statusEl.textContent = String(msg || "");
  statusEl.style.color = color;
}

function esc(v) {
  return String(v ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll("\"", "&quot;")
    .replaceAll("'", "&#39;");
}

function currentModelMeta() {
  return modelRows.find((row) => String(row?.file || "") === String(modelSel.value || "")) || null;
}

function isCurrentModelEditable() {
  const meta = currentModelMeta();
  return !meta || !!meta.isEditable;
}

function updateEditorMode() {
  const meta = currentModelMeta();
  const editable = isCurrentModelEditable();
  saveBtn.disabled = !editable;
  saveBtn.title = editable ? "" : "Archive models are read-only here. Switch the default model in Map Editor to edit this snapshot.";
  if (!editable && meta?.file) {
    setStatus(`Archive snapshot selected: ${meta.file}. Switch the default model in Map Editor to edit this snapshot.`, "#b54708");
  }
}

function isGroundName(name) {
  const n = String(name || "").trim().toLowerCase();
  if (!n) return false;
  if (n === "ground" || n === "cground") return true;
  return /(^|[^a-z0-9])c?ground($|[^a-z0-9])/i.test(n);
}

function isRoadLikeName(name) {
  const n = String(name || "").trim();
  if (!n) return false;
  return /^road(?:[._-]\d+)?$/i.test(n);
}

function isGenericName(name) {
  const raw = String(name || "").trim().toLowerCase();
  const d = raw.replace(/([._-]\d+)+$/g, "");
  return d === "scene" || d === "auxscene" || d === "root" || d === "rootnode" || d === "gltf" || d === "model" || d === "group";
}

function topNamedAncestor(obj, root) {
  let p = obj;
  let top = null;
  while (p && p !== root) {
    if (p.name && !isGenericName(p.name) && !isGroundName(p.name) && !isRoadLikeName(p.name)) top = p;
    p = p.parent;
  }
  return top;
}

function clearHelper(ref) {
  if (!ref) return null;
  if (ref.parent) ref.parent.remove(ref);
  ref.geometry?.dispose?.();
  if (Array.isArray(ref.material)) ref.material.forEach((m) => m?.dispose?.());
  else ref.material?.dispose?.();
  return null;
}

function clearSelectionForm() {
  selectedName.value = "";
  newName.value = "";
  desc.value = "";
  imagePath.value = "";
  version.value = "";
  editedAt.value = "";
}

function setSelection(root) {
  selectedRoot = root || null;
  selectedBox = clearHelper(selectedBox);
  if (!selectedRoot) {
    clearSelectionForm();
    return;
  }
  selectedBox = new THREE.BoxHelper(selectedRoot, 0x000000);
  if (selectedBox.material) selectedBox.material.linewidth = 2;
  scene.add(selectedBox);
  selectedName.value = selectedRoot.name || "";
  selectedModel.value = modelSel.value || "";
  newName.value = selectedRoot.name || "";
  loadSelectedMeta().catch((err) => setStatus(`Meta load failed: ${err?.message || err}`, "#b42318"));
}

function setHover(root) {
  hoverRoot = root || null;
  hoverBox = clearHelper(hoverBox);
  if (!hoverRoot || (selectedRoot && hoverRoot === selectedRoot)) return;
  hoverBox = new THREE.BoxHelper(hoverRoot, 0x000000);
  if (hoverBox.material) hoverBox.material.linewidth = 2;
  scene.add(hoverBox);
}

function resize() {
  const rect = viewport.getBoundingClientRect();
  renderer.setSize(Math.max(1, rect.width), Math.max(1, rect.height), false);
  camera.aspect = Math.max(1, rect.width) / Math.max(1, rect.height);
  camera.updateProjectionMatrix();
}

function setMouse(event) {
  const rect = canvas.getBoundingClientRect();
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
}

function pick(event) {
  if (!mapRoot) return null;
  setMouse(event);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObject(mapRoot, true);
  for (const h of hits) {
    const root = topNamedAncestor(h.object, mapRoot);
    if (root) return root;
  }
  return null;
}

function isEventOnCanvas(event) {
  const rect = canvas.getBoundingClientRect();
  return (
    event.clientX >= rect.left &&
    event.clientX <= rect.right &&
    event.clientY >= rect.top &&
    event.clientY <= rect.bottom
  );
}

function fitToRoot(root) {
  const box = new THREE.Box3().setFromObject(root);
  if (box.isEmpty()) return;
  const size = box.getSize(new THREE.Vector3());
  const center = box.getCenter(new THREE.Vector3());
  const d = Math.max(size.x, size.y, size.z) || 1;
  camera.position.set(center.x + d * 0.65, center.y + d * 0.95, center.z + d * 0.65);
  controls.target.copy(center);
  controls.update();
}

function findExportRoot(root) {
  let p = root;
  const seen = new Set();
  while (p && !seen.has(p)) {
    seen.add(p);
    if (!isGenericName(p.name) || (p.isMesh || p.isLine || p.isPoints)) break;
    if (!Array.isArray(p.children) || p.children.length !== 1) break;
    const c = p.children[0];
    if (!c || !isGenericName(c.name) || (c.isMesh || c.isLine || c.isPoints)) break;
    p = c;
  }
  return p;
}

function exportBinary(root) {
  return new Promise((resolve, reject) => {
    const effective = findExportRoot(root) || root;
    const outScene = new THREE.Scene();
    outScene.name = "Scene";
    const flatten = effective && isGenericName(effective.name) && Array.isArray(effective.children) && effective.children.length > 0;
    if (flatten) effective.children.forEach((c) => outScene.add(c.clone(true)));
    else outScene.add(effective.clone(true));
    exporter.parse(
      outScene,
      (res) => {
        if (res instanceof ArrayBuffer) resolve(res);
        else reject(new Error("Exporter did not return GLB binary"));
      },
      (err) => reject(err),
      { binary: true }
    );
  });
}

async function loadModels() {
  const previousValue = modelSel.value || "";
  const res = await fetch("building.php?action=list_models", { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  const rows = Array.isArray(data.models) ? data.models : [];
  modelRows = rows;
  modelSel.innerHTML = "";
  if (!rows.length) {
    modelSel.disabled = true;
    modelSel.innerHTML = "<option value=\"\">No model</option>";
    selectedModel.value = "";
    saveBtn.disabled = true;
    await loadBuildingsTable();
    return;
  }
  rows.forEach((r) => {
    const o = document.createElement("option");
    o.value = r.file;
    const tags = [];
    if (r.isEditable) tags.push("default/editable");
    else if (r.isLive) tags.push("live");
    if (r.isOriginal) tags.push("original");
    if (!r.isEditable) tags.push("archive");
    o.textContent = tags.length ? `${r.file} (${tags.join(", ")})` : r.file;
    modelSel.appendChild(o);
  });
  modelSel.disabled = false;
  const names = rows.map((r) => r.file);
  const preferred = rows.find((r) => r.isEditable)?.file || rows.find((r) => r.isLive)?.file || (names.includes(ORIGINAL) ? ORIGINAL : names[0]);
  modelSel.value = names.includes(previousValue) ? previousValue : preferred;
  selectedModel.value = modelSel.value || "";
  updateEditorMode();
  await loadBuildingsTable();
}

async function loadBuildingsTable() {
  let url = "building.php?action=list_buildings";
  if (modelSel.value) url += `&model=${encodeURIComponent(modelSel.value)}`;
  const res = await fetch(url, { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  const rows = Array.isArray(data.buildings) ? data.buildings : [];
  tableBody.innerHTML = "";
  if (!rows.length) {
    tableBody.innerHTML = "<tr><td colspan=\"7\">No metadata for this model.</td></tr>";
    return;
  }
  rows.forEach((r) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${esc(r.building_id)}</td><td>${esc(r.building_name)}</td><td>${esc(r.description || "-")}</td><td>${esc(r.image_path || "-")}</td><td>${esc(r.source_model_file || "-")}</td><td>${esc(r.last_seen_version_id || "-")}</td><td>${esc(r.last_edited_at || "-")}</td>`;
    tableBody.appendChild(tr);
  });
}

async function loadSelectedMeta() {
  if (!selectedRoot || !selectedRoot.name || !modelSel.value) return;
  const url = `building.php?action=get_building_meta&model=${encodeURIComponent(modelSel.value)}&name=${encodeURIComponent(selectedRoot.name)}`;
  const res = await fetch(url, { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  if (!data.found || !data.building) {
    desc.value = "";
    imagePath.value = "";
    version.value = "";
    editedAt.value = "";
    return;
  }
  const b = data.building;
  desc.value = String(b.description || "");
  imagePath.value = String(b.image_path || "");
  version.value = b.last_seen_version_id != null ? String(b.last_seen_version_id) : "";
  editedAt.value = String(b.last_edited_at || "");
}

async function loadMapModel() {
  if (!modelSel.value) {
    setStatus("Select model first.", "#b42318");
    return;
  }
  setStatus(`Loading ${modelSel.value} ...`);
  selectedModel.value = modelSel.value;
  setHover(null);
  setSelection(null);
  if (mapRoot) {
    scene.remove(mapRoot);
    mapRoot = null;
  }
  await new Promise((resolve, reject) => {
    loader.load(
      `../models/${encodeURIComponent(modelSel.value)}?v=${Date.now()}`,
      (gltf) => {
        mapRoot = gltf.scene;
        scene.add(mapRoot);
        fitToRoot(mapRoot);
        resolve();
      },
      undefined,
      (err) => reject(err)
    );
  });
  await loadBuildingsTable();
  if (isCurrentModelEditable()) {
    setStatus("Model loaded. Tap/click a building to edit.", "#0f766e");
  } else {
    setStatus("Model loaded in archive mode. You can inspect metadata, but saving is disabled for this snapshot.", "#b54708");
  }
}

async function saveBuilding() {
  if (!mapRoot || !selectedRoot) {
    setStatus("Select a building first.", "#b42318");
    return;
  }
  if (!modelSel.value) {
    setStatus("Select model first.", "#b42318");
    return;
  }
  if (!isCurrentModelEditable()) {
    setStatus("This snapshot is archive-only here. Switch the default model in Map Editor before saving building changes.", "#b54708");
    return;
  }
  const oldName = String(selectedRoot.name || "").trim();
  const nextName = String(newName.value || "").trim();
  if (!oldName || !nextName) {
    setStatus("Building name is required.", "#b42318");
    return;
  }

  const prev = selectedRoot.name;
  selectedRoot.name = nextName;
  setStatus(`Saving ${oldName} -> ${nextName} ...`);
  try {
    const binary = await exportBinary(mapRoot);
    const fd = new FormData();
    fd.append("modelFile", modelSel.value);
    fd.append("oldName", oldName);
    fd.append("newName", nextName);
    fd.append("description", desc.value || "");
    fd.append("imagePath", imagePath.value || "");
    fd.append("glb", new Blob([binary], { type: "model/gltf-binary" }), modelSel.value);

    const res = await fetch("building.php?action=save_building", {
      method: "POST",
      headers: { "X-CSRF-Token": CSRF },
      body: fd
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);

    selectedName.value = nextName;
    version.value = data.versionId != null ? String(data.versionId) : "";
    editedAt.value = String(data.editedAt || "");
    await loadBuildingsTable();
    setStatus(`Saved ${nextName}. Backup: ${data.backupFile || "created"}.`, "#0f766e");
  } catch (err) {
    selectedRoot.name = prev;
    selectedName.value = prev;
    setStatus(`Save failed: ${err?.message || err}`, "#b42318");
  }
}

function animate() {
  requestAnimationFrame(animate);
  controls.update();
  if (hoverBox) hoverBox.update();
  if (selectedBox) selectedBox.update();
  renderer.render(scene, camera);
}

canvas.addEventListener("pointerdown", (e) => {
  if (e.pointerType !== "touch" && e.pointerType !== "pen") return;
  activeTouchPointerIds.add(e.pointerId);
  if (touchTap) touchTap.hadMultiTouch = touchTap.hadMultiTouch || activeTouchPointerIds.size > 1;
  touchTap = {
    pointerId: e.pointerId,
    startX: e.clientX,
    startY: e.clientY,
    moved: false,
    hadMultiTouch: activeTouchPointerIds.size > 1
  };
}, true);

canvas.addEventListener("pointermove", (e) => {
  if (!mapRoot) return;
  if (touchTap && touchTap.pointerId === e.pointerId) {
    const dx = Math.abs(e.clientX - touchTap.startX);
    const dy = Math.abs(e.clientY - touchTap.startY);
    if (dx > TOUCH_TAP_SLOP_PX || dy > TOUCH_TAP_SLOP_PX) touchTap.moved = true;
  }
  if (e.pointerType === "touch" || e.pointerType === "pen") return;
  const hit = pick(e);
  setHover(hit);
});
canvas.addEventListener("pointerleave", () => setHover(null));
window.addEventListener("pointerup", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  if (!touchTap || touchTap.pointerId !== e.pointerId) return;
  const tap = touchTap;
  touchTap = null;
  if (tap.hadMultiTouch || tap.moved || !isEventOnCanvas(e)) return;
  suppressSceneClickUntil = performance.now() + TOUCH_CLICK_SUPPRESS_MS;
  setSelection(pick(e));
}, true);
window.addEventListener("pointercancel", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  if (touchTap && touchTap.pointerId === e.pointerId) touchTap = null;
}, true);
viewport.addEventListener("click", (e) => {
  if (performance.now() < suppressSceneClickUntil) return;
  setSelection(pick(e));
});

loadBtn.addEventListener("click", () => {
  loadMapModel().catch((err) => setStatus(`Load failed: ${err?.message || err}`, "#b42318"));
});
reloadBtn.addEventListener("click", () => {
  loadModels()
    .then(() => setStatus("Model list reloaded.", "#334155"))
    .catch((err) => setStatus(`Reload failed: ${err?.message || err}`, "#b42318"));
});
modelSel.addEventListener("change", () => {
  selectedModel.value = modelSel.value || "";
  setSelection(null);
  updateEditorMode();
  loadBuildingsTable().catch((err) => setStatus(`Table refresh failed: ${err?.message || err}`, "#b42318"));
});
saveBtn.addEventListener("click", () => {
  saveBuilding().catch((err) => setStatus(`Save failed: ${err?.message || err}`, "#b42318"));
});
reloadMetaBtn.addEventListener("click", () => {
  if (!selectedRoot) {
    setStatus("Select a building first.", "#b42318");
    return;
  }
  loadSelectedMeta()
    .then(() => setStatus("DB metadata reloaded.", "#334155"))
    .catch((err) => setStatus(`Meta reload failed: ${err?.message || err}`, "#b42318"));
});
clearBtn.addEventListener("click", () => {
  setHover(null);
  setSelection(null);
  setStatus("Selection cleared.", "#334155");
});

window.addEventListener("resize", resize);
resize();
animate();

(async () => {
  try {
    await loadModels();
    if (modelSel.value) await loadMapModel();
    else setStatus("No GLB model available.", "#b42318");
  } catch (err) {
    setStatus(`Init failed: ${err?.message || err}`, "#b42318");
  }
})();
</script>

<?php admin_layout_end(); ?>
