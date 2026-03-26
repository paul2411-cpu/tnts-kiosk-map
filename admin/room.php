<?php
require_once __DIR__ . "/inc/auth.php";
require_admin_permission("manage_rooms", "You do not have access to manage rooms.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/map_sync.php";
require_once __DIR__ . "/inc/building_identity.php";
require_once __DIR__ . "/inc/map_entities.php";
require_once __DIR__ . "/inc/map_naming.php";
require_once __DIR__ . "/inc/destination_harmony.php";
app_logger_set_default_subsystem("room_admin");

$MODEL_DIR = __DIR__ . "/../models";
$ORIGINAL_MODEL_NAME = "tnts_navigation.glb";

if (empty($_SESSION["room_editor_csrf"])) {
  try {
    $_SESSION["room_editor_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["room_editor_csrf"] = bin2hex(hash("sha256", uniqid((string)mt_rand(), true), true));
  }
}
$ROOM_EDITOR_CSRF = (string)$_SESSION["room_editor_csrf"];

function rm_fail(int $status, string $msg): void {
  app_log_http_problem($status, $msg, [
    "action" => trim((string)($_GET["action"] ?? "")),
    "modelFile" => trim((string)($_GET["model"] ?? "")),
  ], [
    "subsystem" => "room_admin",
    "event" => "http_error",
  ]);
  http_response_code($status);
  echo json_encode(["ok" => false, "error" => $msg], JSON_PRETTY_PRINT);
  exit;
}

function rm_post_csrf(): void {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") rm_fail(405, "POST required");
  $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
  $sessionToken = (string)($_SESSION["room_editor_csrf"] ?? "");
  if (!is_string($token) || $token === "" || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    rm_fail(403, "CSRF validation failed");
  }
}

function rm_model_name(string $raw): ?string {
  $base = basename($raw);
  if ($base === "" || $base === "." || $base === "..") return null;
  if (!preg_match('/\\.glb$/i', $base)) return null;
  return $base;
}

function rm_room_name_variants(string $name): array {
  $base = trim($name);
  if ($base === "") return [];
  $out = [$base];

  $parsedObject = map_naming_parse_room_object($base);
  if (is_array($parsedObject)) {
    $parsedRoomName = trim((string)($parsedObject["room_name"] ?? ""));
    $parsedRoomNumber = trim((string)($parsedObject["room_number"] ?? ""));
    if ($parsedRoomName !== "") $out[] = $parsedRoomName;
    if ($parsedRoomNumber !== "") {
      $out[] = "ROOM " . $parsedRoomNumber;
      $out[] = "ROOM_" . $parsedRoomNumber;
      $out[] = "ROOM" . $parsedRoomNumber;
    }
  }

  $spaced = preg_replace('/[_-]+/', ' ', $base);
  $spaced = trim((string)preg_replace('/\s+/', ' ', (string)$spaced));
  if ($spaced !== "" && strcasecmp($spaced, $base) !== 0) $out[] = $spaced;

  $canonicalNumbers = [];
  if (preg_match('/^room[\s._-]*([0-9]+)$/i', $base, $m)) {
    $canonicalNumbers[] = (string)$m[1];
  }
  if (preg_match('/^room[\s._-]*([0-9]+)[._-](\d{1,3})$/i', $base, $m)) {
    $canonicalNumbers[] = (string)$m[1];
  }

  foreach ($canonicalNumbers as $num) {
    $out[] = "ROOM " . $num;
    $out[] = "ROOM_" . $num;
    $out[] = "ROOM" . $num;
  }

  $uniq = [];
  foreach ($out as $v) {
    $k = mb_strtolower(trim((string)$v));
    if ($k === "" || isset($uniq[$k])) continue;
    $uniq[$k] = trim((string)$v);
  }
  return array_values($uniq);
}

function rm_name_lookup_tokens(string $value): array {
  $raw = trim($value);
  if ($raw === "") return [];

  $candidates = [$raw];
  $deSuffixed = preg_replace('/([._-]\d+)+$/', '', $raw);
  $deSuffixed = trim((string)$deSuffixed);
  if ($deSuffixed !== "" && strcasecmp($deSuffixed, $raw) !== 0) $candidates[] = $deSuffixed;

  $tokens = [];
  foreach ($candidates as $candidate) {
    $token = mb_strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $candidate));
    if ($token === "" || isset($tokens[$token])) continue;
    $tokens[$token] = $token;
  }
  return array_values($tokens);
}

function rm_parse_string_list($raw): array {
  if (is_array($raw)) {
    $items = $raw;
  } else {
    $decoded = json_decode((string)$raw, true);
    $items = is_array($decoded) ? $decoded : [];
  }

  $out = [];
  foreach ($items as $item) {
    $value = trim((string)$item);
    if ($value === "") continue;
    $key = mb_strtolower($value);
    if (isset($out[$key])) continue;
    $out[$key] = $value;
  }
  return array_values($out);
}

function rm_col(mysqli $conn, string $table, string $col): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeCol = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function rm_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) throw new RuntimeException($conn->error);
}

function rm_valid_glb(string $raw): bool {
  if (strlen($raw) < 12) return false;
  $hdr = unpack("a4magic/Vversion/Vlength", substr($raw, 0, 12));
  if (!is_array($hdr)) return false;
  if (($hdr["magic"] ?? "") !== "glTF") return false;
  if ((int)($hdr["version"] ?? 0) !== 2) return false;
  return (int)($hdr["length"] ?? 0) === strlen($raw);
}

function rm_atomic_write(string $path, string $raw): bool {
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

function rm_ensure_schema(mysqli $conn): void {
  rm_sql($conn, "
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

  rm_sql($conn, "
    CREATE TABLE IF NOT EXISTS rooms (
      room_id INT AUTO_INCREMENT PRIMARY KEY,
      building_id INT NULL,
      room_name VARCHAR(255) NOT NULL,
      room_number VARCHAR(50) NULL,
      room_type VARCHAR(100) NULL,
      floor_number VARCHAR(50) NULL,
      building_name VARCHAR(255) NULL,
      description TEXT NULL,
      indoor_guide_text TEXT NULL,
      image_path VARCHAR(255) NULL,
      source_model_file VARCHAR(255) NULL,
      first_seen_version_id INT NULL,
      last_seen_version_id INT NULL,
      is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1,
      last_edited_at DATETIME NULL,
      last_edited_by_admin_id INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!rm_col($conn, "rooms", "building_id")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN building_id INT NULL AFTER room_id");
  if (!rm_col($conn, "rooms", "room_number")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN room_number VARCHAR(50) NULL AFTER room_name");
  if (!rm_col($conn, "rooms", "room_type")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN room_type VARCHAR(100) NULL AFTER room_number");
  if (!rm_col($conn, "rooms", "floor_number")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN floor_number VARCHAR(50) NULL AFTER room_type");
  if (!rm_col($conn, "rooms", "building_name")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN building_name VARCHAR(255) NULL AFTER floor_number");
  if (!rm_col($conn, "rooms", "description")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN description TEXT NULL AFTER building_name");
  if (!rm_col($conn, "rooms", "indoor_guide_text")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN indoor_guide_text TEXT NULL AFTER description");
  if (!rm_col($conn, "rooms", "image_path")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN image_path VARCHAR(255) NULL AFTER indoor_guide_text");
  if (!rm_col($conn, "rooms", "source_model_file")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN source_model_file VARCHAR(255) NULL AFTER image_path");
  if (!rm_col($conn, "rooms", "first_seen_version_id")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  if (!rm_col($conn, "rooms", "last_seen_version_id")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  if (!rm_col($conn, "rooms", "is_present_in_latest")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  if (!rm_col($conn, "rooms", "last_edited_at")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN last_edited_at DATETIME NULL AFTER is_present_in_latest");
  if (!rm_col($conn, "rooms", "last_edited_by_admin_id")) rm_sql($conn, "ALTER TABLE rooms ADD COLUMN last_edited_by_admin_id INT NULL AFTER last_edited_at");
  map_entities_ensure_schema($conn);
  harmony_ensure_schema($conn);
}

function rm_version_id(mysqli $conn, string $modelFile, string $modelHash, ?int $adminId): int {
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

function rm_resolve_building(mysqli $conn, string $modelFile, string $buildingName, bool $allowFallback = true): ?array {
  $name = trim($buildingName);
  if ($name === "") return null;

  $hasObjectName = rm_col($conn, "buildings", "model_object_name");
  $hasEntityType = rm_col($conn, "buildings", "entity_type");
  $selectCols = "building_id, building_name, "
    . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name")
    . ", " . ($hasEntityType ? "entity_type" : "'building' AS entity_type");

  $lookups = [];
  if (rm_col($conn, "buildings", "source_model_file")) {
    if ($hasObjectName) {
      $lookups[] = ["SELECT {$selectCols} FROM buildings WHERE source_model_file = ? AND model_object_name = ? ORDER BY building_id DESC LIMIT 1", "ss", [$modelFile, $name]];
    }
    $lookups[] = ["SELECT {$selectCols} FROM buildings WHERE source_model_file = ? AND building_name = ? ORDER BY building_id DESC LIMIT 1", "ss", [$modelFile, $name]];
  }
  if ($allowFallback) {
    $parentModel = harmony_get_model_parent($conn, $modelFile);
    if ($parentModel !== "" && rm_col($conn, "buildings", "source_model_file")) {
      if ($hasObjectName) {
        $lookups[] = ["SELECT {$selectCols} FROM buildings WHERE source_model_file = ? AND model_object_name = ? ORDER BY building_id DESC LIMIT 1", "ss", [$parentModel, $name]];
      }
      $lookups[] = ["SELECT {$selectCols} FROM buildings WHERE source_model_file = ? AND building_name = ? ORDER BY building_id DESC LIMIT 1", "ss", [$parentModel, $name]];
    }
    if ($hasObjectName) {
      $lookups[] = ["SELECT {$selectCols} FROM buildings WHERE building_uid = ? ORDER BY building_id DESC LIMIT 1", "s", [map_identity_resolve_uid($conn, $name, $modelFile)]];
    }
  }

  foreach ($lookups as [$sql, $types, $params]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare building lookup");
    if ($types === "ss") $stmt->bind_param("ss", $params[0], $params[1]);
    else $stmt->bind_param("s", $params[0]);
    if (!$stmt->execute()) throw new RuntimeException("Failed to resolve building");
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row && isset($row["building_id"])) {
      return [
        "building_id" => (int)$row["building_id"],
        "building_name" => trim((string)($row["building_name"] ?? $name)),
        "model_object_name" => trim((string)($row["model_object_name"] ?? "")),
        "entity_type" => trim((string)($row["entity_type"] ?? "building")) ?: "building",
      ];
    }
  }

  return null;
}

function rm_ensure_building_for_model(mysqli $conn, string $modelFile, int $versionId, string $buildingName): ?array {
  $reference = trim($buildingName);
  if ($reference === "") return null;

  $classified = map_naming_classify_top_level($reference);
  $classifiedDisplay = trim((string)($classified["display_name"] ?? ""));
  $classifiedObject = trim((string)($classified["base_name"] ?? $reference));
  $classifiedEntityType = trim((string)($classified["entity_type"] ?? "building")) ?: "building";

  $meta = rm_resolve_building($conn, $modelFile, $reference, false);
  if (!$meta && $classifiedDisplay !== "" && strcasecmp($classifiedDisplay, $reference) !== 0) {
    $meta = rm_resolve_building($conn, $modelFile, $classifiedDisplay, false);
  }
  if ($meta) return $meta;

  $template = rm_resolve_building($conn, $modelFile, $reference, true);
  if (!$template && $classifiedDisplay !== "" && strcasecmp($classifiedDisplay, $reference) !== 0) {
    $template = rm_resolve_building($conn, $modelFile, $classifiedDisplay, true);
  }

  $description = "";
  $imagePath = "";
  $resolvedName = $classifiedDisplay !== "" ? $classifiedDisplay : $reference;
  $resolvedObjectName = $classifiedObject !== "" ? $classifiedObject : $reference;
  $resolvedEntityType = $classifiedEntityType;
  $buildingUid = "";

  if ($template) {
    $stmt = $conn->prepare("SELECT building_uid, description, image_path, model_object_name, entity_type FROM buildings WHERE building_id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException("Failed to prepare building template query");
    $templateId = (int)$template["building_id"];
    $stmt->bind_param("i", $templateId);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load building template");
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
      $description = trim((string)($row["description"] ?? ""));
      $imagePath = trim((string)($row["image_path"] ?? ""));
      $buildingUid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
      $resolvedObjectName = trim((string)($row["model_object_name"] ?? $resolvedObjectName)) ?: $resolvedObjectName;
      $resolvedEntityType = trim((string)($row["entity_type"] ?? $resolvedEntityType)) ?: $resolvedEntityType;
    }
    $resolvedName = trim((string)($template["building_name"] ?? $resolvedName)) ?: $resolvedName;
  } elseif (empty($classified["include"]) || (($classified["bucket"] ?? "") !== "buildings")) {
    return null;
  }

  if ($buildingUid === "") {
    $buildingUid = map_identity_resolve_uid($conn, $resolvedName, $modelFile);
  }

  $stmt = $conn->prepare("
    INSERT INTO buildings (
      building_uid,
      building_name,
      model_object_name,
      entity_type,
      description,
      image_path,
      source_model_file,
      first_seen_version_id,
      last_seen_version_id,
      is_present_in_latest
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
  ");
  if (!$stmt) throw new RuntimeException("Failed to prepare building clone insert");
  $stmt->bind_param("sssssssii", $buildingUid, $resolvedName, $resolvedObjectName, $resolvedEntityType, $description, $imagePath, $modelFile, $versionId, $versionId);
  if (!$stmt->execute()) throw new RuntimeException("Failed to clone building into current model");
  $newId = (int)$stmt->insert_id;
  $stmt->close();

  return [
    "building_id" => $newId,
    "building_name" => $resolvedName !== "" ? $resolvedName : $reference,
    "building_uid" => $buildingUid,
    "model_object_name" => $resolvedObjectName,
    "entity_type" => $resolvedEntityType,
  ];
}

function rm_room_meta_sql_cols(mysqli $conn): array {
  $hasSource = rm_col($conn, "rooms", "source_model_file");
  $hasBuildingId = rm_col($conn, "rooms", "building_id");
  $hasBuildingName = rm_col($conn, "rooms", "building_name");
  $hasBuildingUid = rm_col($conn, "buildings", "building_uid");
  $hasRoomBuildingUid = rm_col($conn, "rooms", "building_uid");
  $hasRoomUid = rm_col($conn, "rooms", "room_uid");
  $hasIndoorGuideText = rm_col($conn, "rooms", "indoor_guide_text");
  $hasObjectName = rm_col($conn, "rooms", "model_object_name");
  $buildingExpr = $hasBuildingId
    ? "COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ") AS building_name"
    : (($hasBuildingName ? "r.building_name" : "NULL") . " AS building_name");
  $buildingUidExpr = $hasBuildingId
    ? "COALESCE("
      . ($hasBuildingUid ? "NULLIF(TRIM(b.building_uid), '')" : "NULL")
      . ", "
      . ($hasRoomBuildingUid ? "NULLIF(TRIM(r.building_uid), '')" : "NULL")
      . ") AS building_uid"
    : (($hasRoomBuildingUid ? "r.building_uid" : "NULL") . " AS building_uid");

  $sqlCols = "r.room_id, "
    . ($hasBuildingId ? "r.building_id, " : "NULL AS building_id, ")
    . ($hasRoomUid ? "r.room_uid, " : "NULL AS room_uid, ")
    . "{$buildingUidExpr}, "
    . "r.room_name, "
    . ($hasObjectName ? "r.model_object_name" : "NULL AS model_object_name")
    . ", r.room_number, r.room_type, r.floor_number, {$buildingExpr}, r.description, "
    . ($hasIndoorGuideText ? "r.indoor_guide_text" : "NULL AS indoor_guide_text")
    . ", r.image_path, "
    . (rm_col($conn, "rooms", "last_seen_version_id") ? "r.last_seen_version_id" : "NULL AS last_seen_version_id")
    . ", " . (rm_col($conn, "rooms", "last_edited_at") ? "r.last_edited_at" : "NULL AS last_edited_at")
    . ", " . ($hasSource ? "r.source_model_file" : "NULL AS source_model_file");

  return [$sqlCols, $hasSource, $hasBuildingId];
}

function rm_fetch_room_meta_candidates(mysqli $conn, string $sqlCols, bool $hasSource, bool $hasBuildingId, array $roomNames, string $model): array {
  $roomNames = array_values(array_filter(array_map("trim", $roomNames), static function($value) {
    return $value !== "";
  }));
  if (!$roomNames) return [];

  $hasPresent = rm_col($conn, "rooms", "is_present_in_latest");
  $hasBuildingPresent = rm_col($conn, "buildings", "is_present_in_latest");

  $placeholders = implode(", ", array_fill(0, count($roomNames), "?"));
  $sql = "SELECT {$sqlCols} FROM rooms r "
    . ($hasBuildingId ? "LEFT JOIN buildings b ON b.building_id = r.building_id " : "")
    . "WHERE r.room_name IN ({$placeholders})";
  if ($hasPresent) $sql .= " AND (r.is_present_in_latest = 1 OR r.is_present_in_latest IS NULL)";
  if ($hasBuildingId && $hasBuildingPresent) $sql .= " AND (b.building_id IS NULL OR b.is_present_in_latest = 1 OR b.is_present_in_latest IS NULL)";

  $types = str_repeat("s", count($roomNames));
  $params = $roomNames;
  if ($model !== "" && $hasSource) {
    $sql .= " AND r.source_model_file = ?";
    $types .= "s";
    $params[] = $model;
  }

  $sql .= " ORDER BY r.room_id DESC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new RuntimeException("Failed to prepare room metadata lookup");
  $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()) throw new RuntimeException("Failed to load room metadata");
  $res = $stmt->get_result();
  $rows = [];
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  $stmt->close();

  $deduped = [];
  foreach ($rows as $row) {
    $key = isset($row["room_id"]) ? "id:" . (int)$row["room_id"] : mb_strtolower(trim((string)($row["room_name"] ?? "")) . "|" . trim((string)($row["building_name"] ?? "")));
    if (isset($deduped[$key])) continue;
    $deduped[$key] = $row;
  }
  return array_values($deduped);
}

function rm_pick_room_meta_candidate(array $rows, array $buildingHints): array {
  if (!$rows) return ["room" => null, "rows" => []];
  if (count($rows) === 1) return ["room" => $rows[0], "rows" => $rows];

  $hintTokens = [];
  foreach ($buildingHints as $hint) {
    foreach (rm_name_lookup_tokens((string)$hint) as $token) {
      $hintTokens[$token] = true;
    }
  }

  if ($hintTokens) {
    $scored = [];
    foreach ($rows as $row) {
      $score = 0;
      foreach (rm_name_lookup_tokens((string)($row["building_name"] ?? "")) as $token) {
        if (isset($hintTokens[$token])) $score++;
      }
      if ($score > 0) {
        $scored[] = ["score" => $score, "row" => $row];
      }
    }

    usort($scored, static function($a, $b) {
      if ($b["score"] !== $a["score"]) return $b["score"] <=> $a["score"];
      return ((int)($b["row"]["room_id"] ?? 0)) <=> ((int)($a["row"]["room_id"] ?? 0));
    });

    if (count($scored) === 1 || ($scored && $scored[0]["score"] > ($scored[1]["score"] ?? 0))) {
      return ["room" => $scored[0]["row"], "rows" => $rows];
    }
  }

  return ["room" => null, "rows" => $rows];
}

function rm_editor_model_file(): ?string {
  return map_sync_resolve_editor_model(dirname(__DIR__));
}

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");
  $action = (string)$_GET["action"];
  try {
    rm_ensure_schema($conn);
  } catch (Throwable $e) {
    rm_fail(500, $e->getMessage());
  }

  if ($action === "list_models") {
    $editorModel = rm_editor_model_file();
    $publicState = map_sync_resolve_public_model(dirname(__DIR__));
    $publicModel = trim((string)($publicState["modelFile"] ?? ""));
    $rows = [];
    if (is_dir($MODEL_DIR)) {
      foreach (scandir($MODEL_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\\.glb$/i', $f)) continue;
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

  if ($action === "list_rooms") {
    rm_ensure_schema($conn);
    $model = "";
    if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
      $safe = rm_model_name((string)$_GET["model"]);
      if ($safe === null) rm_fail(400, "Invalid model");
      $model = $safe;
    }
    $hasSource = rm_col($conn, "rooms", "source_model_file");
    $hasPresent = rm_col($conn, "rooms", "is_present_in_latest");
    $hasVersion = rm_col($conn, "rooms", "last_seen_version_id");
    $hasEdited = rm_col($conn, "rooms", "last_edited_at");
    $hasBuildingId = rm_col($conn, "rooms", "building_id");
    $hasBuildingName = rm_col($conn, "rooms", "building_name");
    $hasBuildingUid = rm_col($conn, "buildings", "building_uid");
    $hasRoomBuildingUid = rm_col($conn, "rooms", "building_uid");
    $hasRoomUid = rm_col($conn, "rooms", "room_uid");
    $hasBuildingPresent = rm_col($conn, "buildings", "is_present_in_latest");
    $buildingExpr = $hasBuildingId
      ? "COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ") AS building_name"
      : (($hasBuildingName ? "r.building_name" : "NULL") . " AS building_name");
    $buildingUidExpr = $hasBuildingId
      ? "COALESCE("
        . ($hasBuildingUid ? "NULLIF(TRIM(b.building_uid), '')" : "NULL")
        . ", "
        . ($hasRoomBuildingUid ? "NULLIF(TRIM(r.building_uid), '')" : "NULL")
        . ") AS building_uid"
      : (($hasRoomBuildingUid ? "r.building_uid" : "NULL") . " AS building_uid");

    $sql = "SELECT r.room_id, "
      . ($hasRoomUid ? "r.room_uid" : "NULL AS room_uid")
      . ", {$buildingUidExpr}, r.room_name, "
      . (rm_col($conn, "rooms", "model_object_name") ? "r.model_object_name" : "NULL AS model_object_name")
      . ", r.room_number, r.room_type, r.floor_number, {$buildingExpr}, r.description, r.image_path, "
      . ($hasSource ? "r.source_model_file" : "NULL AS source_model_file")
      . ", " . ($hasVersion ? "r.last_seen_version_id" : "NULL AS last_seen_version_id")
      . ", " . ($hasEdited ? "r.last_edited_at" : "NULL AS last_edited_at")
      . " FROM rooms r "
      . ($hasBuildingId ? "LEFT JOIN buildings b ON b.building_id = r.building_id " : "")
      . "WHERE r.room_name IS NOT NULL AND r.room_name <> ''";
    if ($hasPresent) $sql .= " AND (r.is_present_in_latest = 1 OR r.is_present_in_latest IS NULL)";
    if ($hasBuildingId && $hasBuildingPresent) $sql .= " AND (b.building_id IS NULL OR b.is_present_in_latest = 1 OR b.is_present_in_latest IS NULL)";

    if ($model !== "" && $hasSource) {
      $sql .= " AND r.source_model_file = ? ORDER BY building_name ASC, r.room_name ASC";
      $stmt = $conn->prepare($sql);
      if (!$stmt) rm_fail(500, "Failed to prepare query");
      $stmt->bind_param("s", $model);
      if (!$stmt->execute()) rm_fail(500, "Failed to query rooms");
      $res = $stmt->get_result();
    } else {
      $sql .= " ORDER BY building_name ASC, r.room_name ASC";
      $res = $conn->query($sql);
      if (!($res instanceof mysqli_result)) rm_fail(500, "Failed to query rooms");
    }

    $rows = [];
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    echo json_encode(["ok" => true, "rooms" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "get_room_meta") {
    rm_ensure_schema($conn);
    $name = trim((string)($_GET["name"] ?? ""));
    $roomId = isset($_GET["roomId"]) && is_numeric($_GET["roomId"]) ? (int)$_GET["roomId"] : 0;
    if ($name === "" && $roomId <= 0) rm_fail(400, "Missing room identifier");
    $model = "";
    if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
      $safe = rm_model_name((string)$_GET["model"]);
      if ($safe === null) rm_fail(400, "Invalid model");
      $model = $safe;
    }
    $buildingHints = rm_parse_string_list($_GET["buildingHints"] ?? []);
    [$sqlCols, $hasSource, $hasBuildingId] = rm_room_meta_sql_cols($conn);

    $row = null;
    $matchedBy = "";
    $candidates = [];

    if ($roomId > 0) {
      $sql = "SELECT {$sqlCols} FROM rooms r "
        . ($hasBuildingId ? "LEFT JOIN buildings b ON b.building_id = r.building_id " : "")
        . "WHERE r.room_id = ?";
      $types = "i";
      $params = [$roomId];
      if ($model !== "" && $hasSource) {
        $sql .= " AND r.source_model_file = ?";
        $types .= "s";
        $params[] = $model;
      }
      $sql .= " LIMIT 1";
      $stmt = $conn->prepare($sql);
      if (!$stmt) rm_fail(500, "Failed to prepare room lookup");
      $stmt->bind_param($types, ...$params);
      if (!$stmt->execute()) rm_fail(500, "Failed to load room metadata");
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row) $matchedBy = "room_id";
    }

    if (!$row && $name !== "") {
      if (rm_col($conn, "rooms", "model_object_name")) {
        $sql = "SELECT {$sqlCols} FROM rooms r "
          . ($hasBuildingId ? "LEFT JOIN buildings b ON b.building_id = r.building_id " : "")
          . "WHERE r.model_object_name = ?";
        $types = "s";
        $params = [$name];
        if ($model !== "" && $hasSource) {
          $sql .= " AND r.source_model_file = ?";
          $types .= "s";
          $params[] = $model;
        }
        $sql .= " ORDER BY r.room_id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) rm_fail(500, "Failed to prepare room object lookup");
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) rm_fail(500, "Failed to load room metadata");
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) $matchedBy = "object_name";
      }

      if (!$row) {
        $variants = rm_room_name_variants($name);
        $candidates = rm_fetch_room_meta_candidates($conn, $sqlCols, $hasSource, $hasBuildingId, $variants, $model);
        $picked = rm_pick_room_meta_candidate($candidates, $buildingHints);
        $row = $picked["room"];
        $candidates = $picked["rows"];
        if ($row) {
          $matchedBy = $buildingHints ? "building_hint" : "room_name";
        }
      }
    }

    if ($row) {
      echo json_encode([
        "ok" => true,
        "found" => true,
        "ambiguous" => false,
        "matchedBy" => $matchedBy,
        "room" => $row
      ], JSON_PRETTY_PRINT);
      exit;
    }

    $candidatePayload = array_map(static function(array $candidate): array {
      return [
        "room_id" => isset($candidate["room_id"]) ? (int)$candidate["room_id"] : null,
        "building_id" => isset($candidate["building_id"]) ? (int)$candidate["building_id"] : null,
        "room_name" => trim((string)($candidate["room_name"] ?? "")),
        "model_object_name" => trim((string)($candidate["model_object_name"] ?? "")),
        "room_number" => trim((string)($candidate["room_number"] ?? "")),
        "floor_number" => trim((string)($candidate["floor_number"] ?? "")),
        "building_name" => trim((string)($candidate["building_name"] ?? "")),
        "source_model_file" => trim((string)($candidate["source_model_file"] ?? ""))
      ];
    }, $candidates);

    echo json_encode([
      "ok" => true,
      "found" => false,
      "ambiguous" => count($candidatePayload) > 1,
      "matchedBy" => "",
      "room" => null,
      "candidates" => $candidatePayload
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_room") {
    rm_post_csrf();
    $modelFile = rm_model_name((string)($_POST["modelFile"] ?? ""));
    if ($modelFile === null) rm_fail(400, "Invalid model file");
    $editableModel = rm_editor_model_file();
    if ($editableModel !== null && strcasecmp($editableModel, $modelFile) !== 0) {
      rm_fail(409, "Only the default admin model can be edited here. Switch the default model in Map Editor before editing this snapshot.");
    }
    $modelPath = $MODEL_DIR . "/" . $modelFile;
    if (!file_exists($modelPath)) rm_fail(404, "Model not found");

    $oldName = trim((string)($_POST["oldName"] ?? ""));
    $newName = trim((string)($_POST["newName"] ?? ""));
    $roomId = isset($_POST["roomId"]) && is_numeric($_POST["roomId"]) ? (int)$_POST["roomId"] : 0;
    $roomNumber = trim((string)($_POST["roomNumber"] ?? ""));
    $roomType = trim((string)($_POST["roomType"] ?? ""));
    $floorNumber = trim((string)($_POST["floorNumber"] ?? ""));
    $buildingName = trim((string)($_POST["buildingName"] ?? ""));
    $description = trim((string)($_POST["description"] ?? ""));
    $indoorGuideText = str_replace("\r\n", "\n", (string)($_POST["indoorGuideText"] ?? ""));
    $indoorGuideText = trim((string)preg_replace("/\n{3,}/", "\n\n", $indoorGuideText));
    $imagePath = trim((string)($_POST["imagePath"] ?? ""));

    if ($oldName === "") rm_fail(400, "Missing old name");
    if ($newName === "") rm_fail(400, "Missing new name");
    if (mb_strlen($newName) > 100) rm_fail(400, "Room name too long");
    if (mb_strlen($roomNumber) > 50) rm_fail(400, "Room number too long");
    if (mb_strlen($roomType) > 100) rm_fail(400, "Room type too long");
    if (mb_strlen($floorNumber) > 50) rm_fail(400, "Floor number too long");
    if (mb_strlen($buildingName) > 255) rm_fail(400, "Building name too long");
    if (mb_strlen($indoorGuideText) > 8000) rm_fail(400, "Indoor guide text is too long");
    if (mb_strlen($imagePath) > 255) rm_fail(400, "Image path too long");
    $parsedRoomObject = map_naming_parse_room_object($oldName);
    if (!is_array($parsedRoomObject)) {
      rm_fail(400, "Selected object is not a room-style destination");
    }
    $roomObjectName = trim((string)($parsedRoomObject["model_object_name"] ?? $oldName));
    if ($roomNumber === "") {
      $roomNumber = trim((string)($parsedRoomObject["room_number"] ?? ""));
    }

    if (!isset($_FILES["glb"])) rm_fail(400, "Missing GLB upload");
    if ((int)($_FILES["glb"]["error"] ?? 1) !== UPLOAD_ERR_OK) rm_fail(400, "GLB upload error");
    $tmpPath = (string)($_FILES["glb"]["tmp_name"] ?? "");
    $incoming = @file_get_contents($tmpPath);
    if (!is_string($incoming) || !rm_valid_glb($incoming)) rm_fail(400, "Invalid GLB payload");

    $backupDir = $MODEL_DIR . "/backups";
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
      rm_fail(500, "Failed to create backup directory");
    }
    $base = preg_replace('/\\.glb$/i', "", $modelFile);
    $backupName = $base . "_backup_" . date("Ymd_His") . "_room_edit.glb";
    $backupPath = $backupDir . "/" . $backupName;
    if (!@copy($modelPath, $backupPath)) rm_fail(500, "Failed to backup model");
    if (!rm_atomic_write($modelPath, $incoming)) rm_fail(500, "Failed to save model");

    try {
      rm_ensure_schema($conn);
      $hash = @hash_file("sha256", $modelPath);
      if (!is_string($hash) || $hash === "") throw new RuntimeException("Failed to hash model");
      $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
      if (is_int($adminId) && $adminId <= 0) $adminId = null;

      $conn->begin_transaction();
      $versionId = rm_version_id($conn, $modelFile, $hash, $adminId);
      $parentModel = harmony_get_model_parent($conn, $modelFile);

      $id = 0;
      $storedObjectName = $roomObjectName;
      $existingRoomName = "";
      $roomUid = "";
      if ($roomId > 0) {
        if (rm_col($conn, "rooms", "source_model_file")) {
          $stmt = $conn->prepare("SELECT room_id, room_name, building_name, model_object_name, building_id FROM rooms WHERE room_id = ? AND source_model_file = ? LIMIT 1");
          if (!$stmt) throw new RuntimeException("Failed to prepare exact room lookup");
          $stmt->bind_param("is", $roomId, $modelFile);
        } else {
          $stmt = $conn->prepare("SELECT room_id, room_name, building_name, model_object_name, building_id FROM rooms WHERE room_id = ? LIMIT 1");
          if (!$stmt) throw new RuntimeException("Failed to prepare exact room lookup");
          $stmt->bind_param("i", $roomId);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed room lookup");
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && isset($row["room_id"])) {
          $id = (int)$row["room_id"];
          $existingRoomName = trim((string)($row["room_name"] ?? ""));
          $storedObjectName = trim((string)($row["model_object_name"] ?? $storedObjectName)) ?: $storedObjectName;
        } else {
          throw new RuntimeException("Selected room record could not be found for this model. Reload the room metadata and try again.");
        }
        $stmt->close();
      } else {
        if (rm_col($conn, "rooms", "model_object_name")) {
          $stmt = $conn->prepare("SELECT room_id, room_name, building_name, model_object_name, building_id FROM rooms WHERE source_model_file = ? AND model_object_name = ? ORDER BY room_id DESC LIMIT 1");
          if (!$stmt) throw new RuntimeException("Failed to prepare room object lookup");
          $stmt->bind_param("ss", $modelFile, $roomObjectName);
          if (!$stmt->execute()) throw new RuntimeException("Failed room lookup");
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
          if ($row && isset($row["room_id"])) {
            $id = (int)$row["room_id"];
            $existingRoomName = trim((string)($row["room_name"] ?? ""));
            $storedObjectName = trim((string)($row["model_object_name"] ?? $storedObjectName)) ?: $storedObjectName;
          }
          $stmt->close();
        }

        $lookupNames = array_values(array_unique(array_merge(
          rm_room_name_variants($oldName),
          rm_room_name_variants($newName)
        )));

        if ($buildingName !== "" && rm_col($conn, "rooms", "building_name")) {
          foreach ($lookupNames as $candidate) {
            if ($id > 0) break;
            $stmt = $conn->prepare("SELECT room_id FROM rooms WHERE source_model_file = ? AND room_name = ? AND building_name = ? ORDER BY room_id DESC LIMIT 1");
            if (!$stmt) throw new RuntimeException("Failed to prepare building-scoped room lookup");
            $stmt->bind_param("sss", $modelFile, $candidate, $buildingName);
            if (!$stmt->execute()) throw new RuntimeException("Failed room lookup");
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row && isset($row["room_id"])) {
              $id = (int)$row["room_id"];
              $existingRoomName = trim((string)($row["room_name"] ?? ""));
              $storedObjectName = trim((string)($row["model_object_name"] ?? $storedObjectName)) ?: $storedObjectName;
            }
            $stmt->close();
          }
        }

        foreach ($lookupNames as $candidate) {
          if ($id > 0) break;
          $stmt = $conn->prepare("SELECT room_id FROM rooms WHERE source_model_file = ? AND room_name = ? ORDER BY room_id DESC LIMIT 1");
          if (!$stmt) throw new RuntimeException("Failed to prepare model lookup");
          $stmt->bind_param("ss", $modelFile, $candidate);
          if (!$stmt->execute()) throw new RuntimeException("Failed room lookup");
          $res = $stmt->get_result();
          $row = $res ? $res->fetch_assoc() : null;
          if ($row && isset($row["room_id"])) {
            $id = (int)$row["room_id"];
            $existingRoomName = trim((string)($row["room_name"] ?? ""));
            $storedObjectName = trim((string)($row["model_object_name"] ?? $storedObjectName)) ?: $storedObjectName;
          }
          $stmt->close();
        }
      }

      $hasBuildingId = rm_col($conn, "rooms", "building_id");
      $existingBuildingId = null;
      $existingBuildingName = "";
      if ($id > 0 && $hasBuildingId) {
          $stmt = $conn->prepare("SELECT building_id, building_uid, building_name, room_name, room_uid, model_object_name FROM rooms WHERE room_id = ? LIMIT 1");
          if (!$stmt) throw new RuntimeException("Failed to prepare existing room lookup");
          $stmt->bind_param("i", $id);
        if (!$stmt->execute()) throw new RuntimeException("Failed to query existing room");
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
          if ($buildingName === "") $buildingName = trim((string)($row["building_name"] ?? ""));
          $existingBuildingName = trim((string)($row["building_name"] ?? ""));
          $existingRoomName = trim((string)($row["room_name"] ?? $existingRoomName));
          $roomUid = harmony_normalize_uid((string)($row["room_uid"] ?? ""), "room");
          $storedObjectName = trim((string)($row["model_object_name"] ?? $storedObjectName)) ?: $storedObjectName;
          if (isset($row["building_id"]) && $row["building_id"] !== null) $existingBuildingId = (int)$row["building_id"];
        }
      }
      $oldGuideBuildingName = $existingBuildingName !== "" ? $existingBuildingName : $buildingName;
      if ($storedObjectName === "") $storedObjectName = $roomObjectName;

      $buildingMeta = rm_ensure_building_for_model($conn, $modelFile, $versionId, $buildingName);
      if (!$buildingMeta && $existingBuildingId !== null) {
        $stmt = $conn->prepare("SELECT building_id, building_uid, building_name, source_model_file FROM buildings WHERE building_id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException("Failed to prepare fallback building lookup");
        $stmt->bind_param("i", $existingBuildingId);
        if (!$stmt->execute()) throw new RuntimeException("Failed to load fallback building");
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row && isset($row["building_id"])) {
          $fallbackBuildingName = trim((string)($row["building_name"] ?? $buildingName));
          $fallbackSourceModel = trim((string)($row["source_model_file"] ?? ""));
          if ($fallbackSourceModel !== "" && strcasecmp($fallbackSourceModel, $modelFile) !== 0) {
            $buildingMeta = rm_ensure_building_for_model($conn, $modelFile, $versionId, $fallbackBuildingName);
          } else {
            $buildingMeta = [
              "building_id" => (int)$row["building_id"],
              "building_uid" => harmony_normalize_uid((string)($row["building_uid"] ?? ""), "bld"),
              "building_name" => $fallbackBuildingName
            ];
          }
        }
      }

      if ($hasBuildingId && !$buildingMeta) {
        throw new RuntimeException("Select a valid building before saving this room");
      }
      if ($buildingMeta) {
        $buildingName = (string)$buildingMeta["building_name"];
      }
      $roomBuildingUid = harmony_normalize_uid((string)($buildingMeta["building_uid"] ?? ""), "bld");
      if ($roomBuildingUid === "") {
        $roomBuildingUid = map_identity_resolve_uid($conn, $buildingName, $modelFile);
      }
      if ($roomUid === "") {
        $roomUid = harmony_resolve_room_uid($conn, $roomBuildingUid, $newName, $storedObjectName, $modelFile, $parentModel);
      }
      if ($existingRoomName === "") {
        $existingRoomName = $newName;
      }

      $inserted = false;
      if ($id > 0) {
        if ($hasBuildingId && $buildingMeta) {
          $buildingId = (int)$buildingMeta["building_id"];
          if ($adminId === null) {
            $stmt = $conn->prepare("UPDATE rooms SET building_id=?, building_uid=?, room_uid=?, model_object_name=?, room_name=?, room_number=?, room_type=?, floor_number=?, building_name=?, description=?, indoor_guide_text=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=NULL WHERE room_id=?");
            if (!$stmt) throw new RuntimeException("Failed to prepare update");
            $types = "i" . str_repeat("s", 12) . "ii";
            $stmt->bind_param($types, $buildingId, $roomBuildingUid, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $id);
          } else {
            $stmt = $conn->prepare("UPDATE rooms SET building_id=?, building_uid=?, room_uid=?, model_object_name=?, room_name=?, room_number=?, room_type=?, floor_number=?, building_name=?, description=?, indoor_guide_text=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=? WHERE room_id=?");
            if (!$stmt) throw new RuntimeException("Failed to prepare update");
            $types = "i" . str_repeat("s", 12) . "iii";
            $stmt->bind_param($types, $buildingId, $roomBuildingUid, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $adminId, $id);
          }
        } elseif ($adminId === null) {
          $stmt = $conn->prepare("UPDATE rooms SET room_uid=?, model_object_name=?, room_name=?, room_number=?, room_type=?, floor_number=?, building_name=?, description=?, indoor_guide_text=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=NULL WHERE room_id=?");
          if (!$stmt) throw new RuntimeException("Failed to prepare update");
          $types = str_repeat("s", 11) . "ii";
          $stmt->bind_param($types, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $id);
        } else {
          $stmt = $conn->prepare("UPDATE rooms SET room_uid=?, model_object_name=?, room_name=?, room_number=?, room_type=?, floor_number=?, building_name=?, description=?, indoor_guide_text=?, image_path=?, source_model_file=?, last_seen_version_id=?, is_present_in_latest=1, last_edited_at=NOW(), last_edited_by_admin_id=? WHERE room_id=?");
          if (!$stmt) throw new RuntimeException("Failed to prepare update");
          $types = str_repeat("s", 11) . "iii";
          $stmt->bind_param($types, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $adminId, $id);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to update room");
        $stmt->close();
      } else {
        $inserted = true;
        if ($hasBuildingId && $buildingMeta) {
          $buildingId = (int)$buildingMeta["building_id"];
          if ($adminId === null) {
            $stmt = $conn->prepare("INSERT INTO rooms (building_id, building_uid, room_uid, model_object_name, room_name, room_number, room_type, floor_number, building_name, description, indoor_guide_text, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)");
            if (!$stmt) throw new RuntimeException("Failed to prepare insert");
            $types = "i" . str_repeat("s", 12) . "ii";
            $stmt->bind_param($types, $buildingId, $roomBuildingUid, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $versionId);
          } else {
            $stmt = $conn->prepare("INSERT INTO rooms (building_id, building_uid, room_uid, model_object_name, room_name, room_number, room_type, floor_number, building_name, description, indoor_guide_text, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)");
            if (!$stmt) throw new RuntimeException("Failed to prepare insert");
            $types = "i" . str_repeat("s", 12) . "iii";
            $stmt->bind_param($types, $buildingId, $roomBuildingUid, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $versionId, $adminId);
          }
        } elseif ($adminId === null) {
          $stmt = $conn->prepare("INSERT INTO rooms (room_uid, model_object_name, room_name, room_number, room_type, floor_number, building_name, description, indoor_guide_text, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)");
          if (!$stmt) throw new RuntimeException("Failed to prepare insert");
          $types = str_repeat("s", 11) . "ii";
          $stmt->bind_param($types, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $versionId);
        } else {
          $stmt = $conn->prepare("INSERT INTO rooms (room_uid, model_object_name, room_name, room_number, room_type, floor_number, building_name, description, indoor_guide_text, image_path, source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest, last_edited_at, last_edited_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)");
          if (!$stmt) throw new RuntimeException("Failed to prepare insert");
          $types = str_repeat("s", 11) . "iii";
          $stmt->bind_param($types, $roomUid, $storedObjectName, $newName, $roomNumber, $roomType, $floorNumber, $buildingName, $description, $indoorGuideText, $imagePath, $modelFile, $versionId, $versionId, $adminId);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to insert room");
        $id = (int)$stmt->insert_id;
        $stmt->close();
      }

      if (
        !$inserted
        && (
          strcasecmp($existingRoomName, $newName) !== 0
          || strcasecmp($oldGuideBuildingName, $buildingName) !== 0
        )
      ) {
        if (!map_sync_retarget_room_guide_entries(__DIR__ . "/..", $modelFile, $oldGuideBuildingName, $existingRoomName, $buildingName, $newName)) {
          throw new RuntimeException("Failed to sync published room text guides");
        }
      }

      harmony_sync_room_supplement_guide(
        $conn,
        $modelFile,
        $roomBuildingUid,
        $roomUid,
        $buildingName,
        $newName,
        $storedObjectName,
        $indoorGuideText,
        $adminId
      );

      $meta = $conn->prepare("SELECT DATE_FORMAT(last_edited_at, '%Y-%m-%d %H:%i:%s') AS edited_at FROM rooms WHERE room_id = ? LIMIT 1");
      if (!$meta) throw new RuntimeException("Failed to prepare metadata query");
      $meta->bind_param("i", $id);
      if (!$meta->execute()) throw new RuntimeException("Failed to query metadata");
      $mres = $meta->get_result();
      $mrow = $mres ? $mres->fetch_assoc() : null;
      $edited = (string)($mrow["edited_at"] ?? "");
      $meta->close();

      $conn->commit();
      app_log("info", "Room saved", [
        "action" => $action,
        "modelFile" => $modelFile,
        "roomId" => $id,
        "objectName" => $storedObjectName,
        "oldName" => $existingRoomName,
        "newName" => $newName,
        "buildingName" => $buildingName,
        "inserted" => $inserted,
        "versionId" => $versionId,
      ], [
        "subsystem" => "room_admin",
        "event" => "save_room",
      ]);
      echo json_encode([
        "ok" => true,
        "roomId" => $id,
        "versionId" => $versionId,
        "editedAt" => $edited,
        "inserted" => $inserted,
        "backupFile" => "models/backups/" . $backupName
      ], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      $backupRaw = @file_get_contents($backupPath);
      if (is_string($backupRaw) && rm_valid_glb($backupRaw)) {
        rm_atomic_write($modelPath, $backupRaw);
      }
      rm_fail(500, "Save failed: " . $e->getMessage());
    }
  }

  rm_fail(404, "Unknown action");
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Rooms", "rooms");
?>

<div class="card">
  <div class="section-title">Room Editor (Map + DB)</div>
  <div style="color:#667085;font-weight:800;line-height:1.6;">
    Load a map model, click a room or named space in the 3D viewport, edit details, and save.<br>
    Save keeps the GLB object name stable and updates the database label, room metadata, and text-guide links.
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="row" style="grid-template-columns:140px 1fr 130px 130px;">
    <div class="label">Map Model</div>
    <select id="model-select" class="select"></select>
    <button id="load-model-btn" type="button" class="btn primary">Load</button>
    <button id="reload-models-btn" type="button" class="btn gray">Reload</button>
  </div>
  <div id="room-status" style="margin-top:10px;color:#334155;font-weight:800;">Ready.</div>
</div>

<div style="display:grid;grid-template-columns:minmax(480px,1.35fr) minmax(360px,1fr);gap:12px;margin-top:12px;">
  <div class="card">
    <div class="section-title">3D Room Picker</div>
    <div id="room-map-viewport" style="width:100%;height:560px;border:1px solid #d0d5dd;border-radius:12px;overflow:hidden;background:#f8fafc;"></div>
  </div>

  <div class="card">
    <div class="section-title">Selected Room</div>
    <form class="form" onsubmit="return false;">
      <div class="row"><div class="label">Selected (Map)</div><input id="selected-object-name" class="input" readonly /></div>
      <div class="row"><div class="label">Source Model</div><input id="selected-model-file" class="input" readonly /></div>
      <div class="row"><div class="label">Display Name</div><input id="room-new-name" class="input" /></div>
      <div class="row"><div class="label">Room Number</div><input id="room-number" class="input" /></div>
      <div class="row"><div class="label">Room Type</div><input id="room-type" class="input" /></div>
      <div class="row"><div class="label">Floor Number</div><input id="room-floor" class="input" /></div>
      <div class="row"><div class="label">Building</div><input id="room-building" class="input" /></div>
      <div class="row"><div class="label">Description</div><textarea id="room-description" class="textarea"></textarea></div>
      <div class="row"><div class="label">Indoor Guide</div><textarea id="room-indoor-guide" class="textarea" placeholder="One instruction per line. Example:&#10;Enter through the main entrance.&#10;Turn right at the lobby.&#10;Room 100 is the second door on the left."></textarea></div>
      <div class="row"><div class="label">Image Path</div><input id="room-image-path" class="input" placeholder="/assets/rooms/101.jpg" /></div>
      <div class="row"><div class="label">Version ID</div><input id="room-version" class="input" readonly /></div>
      <div class="row"><div class="label">Last Edited</div><input id="room-edited-at" class="input" readonly /></div>
      <div class="actions">
        <button id="save-room-btn" type="button" class="btn primary">Save Room</button>
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
        <th>ID</th><th>Display Name</th><th>Object Name</th><th>Number</th><th>Type</th><th>Floor</th><th>Building</th><th>Model</th><th>Version</th><th>Last Edited</th>
      </tr>
    </thead>
    <tbody id="rooms-table-body">
      <tr><td colspan="10">No data loaded yet.</td></tr>
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
import { isGenericSceneName, isGroundLikeName, isRoadLikeObjectName, parseRoomObjectName } from "../js/map-entity-utils.js";

const CSRF = <?= json_encode($ROOM_EDITOR_CSRF) ?>;
const ORIGINAL = <?= json_encode($ORIGINAL_MODEL_NAME) ?>;

const modelSel = document.getElementById("model-select");
const loadBtn = document.getElementById("load-model-btn");
const reloadBtn = document.getElementById("reload-models-btn");
const statusEl = document.getElementById("room-status");
const viewport = document.getElementById("room-map-viewport");
const tableBody = document.getElementById("rooms-table-body");

const selectedName = document.getElementById("selected-object-name");
const selectedModel = document.getElementById("selected-model-file");
const newName = document.getElementById("room-new-name");
const roomNumber = document.getElementById("room-number");
const roomType = document.getElementById("room-type");
const roomFloor = document.getElementById("room-floor");
const roomBuilding = document.getElementById("room-building");
const desc = document.getElementById("room-description");
const indoorGuide = document.getElementById("room-indoor-guide");
const imagePath = document.getElementById("room-image-path");
const version = document.getElementById("room-version");
const editedAt = document.getElementById("room-edited-at");
const saveBtn = document.getElementById("save-room-btn");
const reloadMetaBtn = document.getElementById("reload-meta-btn");
const clearBtn = document.getElementById("clear-selection-btn");

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xf5f6f8);
const camera = new THREE.PerspectiveCamera(55, 1, 0.01, 100000);
camera.position.set(20, 20, 20);
const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
viewport.appendChild(renderer.domElement);
renderer.domElement.style.display = "block";
renderer.domElement.style.width = "100%";
renderer.domElement.style.height = "100%";
renderer.domElement.style.touchAction = "none";
const canvas = renderer.domElement;
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
let roomPickMeshes = [];
let meshToRoom = new WeakMap();
let roomNodeCount = 0;
let selectedDbRoomId = 0;
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
  return isGroundLikeName(name);
}

function isGenericName(name) {
  return isGenericSceneName(name);
}

function isRoadLikeName(name) {
  return isRoadLikeObjectName(name);
}

function roomNodeLabel(obj) {
  const name = String(obj?.name || "").trim();
  if (name) return name;
  const udName = String(obj?.userData?.name || "").trim();
  if (udName) return udName;
  const extraName = String(obj?.userData?.extras?.name || "").trim();
  if (extraName) return extraName;
  return "";
}

function isTargetRoomName(name) {
  return !!parseRoomObjectName(name || "");
}

function parseRoomLabelDetails(name) {
  const raw = String(name || "").trim();
  if (!raw) return { canonicalName: "", roomNumber: "" };
  const parsed = parseRoomObjectName(raw);
  if (parsed) {
    return {
      canonicalName: String(parsed.roomName || raw),
      roomNumber: String(parsed.roomNumber || "")
    };
  }
  return { canonicalName: raw, roomNumber: "" };
}

function collectRoomBuildingHints(obj, root) {
  const out = [];
  const seen = new Set();
  let p = obj?.parent || null;
  while (p && p !== root) {
    const label = roomNodeLabel(p);
    if (label && !isGenericName(label) && !isGroundName(label) && !isRoadLikeName(label) && !isTargetRoomName(label)) {
      const key = label.trim().toLowerCase();
      if (!seen.has(key)) {
        seen.add(key);
        out.push(label.trim());
      }
    }
    p = p.parent;
  }
  return out;
}

function buildRoomPickIndex(root) {
  roomPickMeshes = [];
  meshToRoom = new WeakMap();
  roomNodeCount = 0;
  if (!root) return;

  root.traverse((obj) => {
    const label = roomNodeLabel(obj);
    if (!label || isGenericName(label) || isGroundName(label) || !isTargetRoomName(label)) return;
    roomNodeCount++;
    if (!obj.name || String(obj.name).trim() === "") obj.name = label;

    obj.traverse((child) => {
      if (!child || !child.isMesh) return;
      roomPickMeshes.push(child);
      meshToRoom.set(child, obj);
    });
  });
}

function pickRoomCandidate(obj, root) {
  let p = obj;
  let fallback = null;
  while (p && p !== root) {
    const mapped = meshToRoom.get(p);
    if (mapped) return mapped;

    const label = roomNodeLabel(p);
    if (label && !isGenericName(label) && !isGroundName(label)) {
      if (!fallback) fallback = p;
      if (isTargetRoomName(label)) {
        if (!p.name || String(p.name).trim() === "") p.name = label;
        return p;
      }
    }
    p = p.parent;
  }
  if (roomNodeCount === 0 && fallback) return fallback;
  return null;
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
  selectedDbRoomId = 0;
  selectedName.value = "";
  newName.value = "";
  roomNumber.value = "";
  roomType.value = "";
  roomFloor.value = "";
  roomBuilding.value = "";
  desc.value = "";
  indoorGuide.value = "";
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
  const parsed = parseRoomLabelDetails(selectedRoot.name || "");
  newName.value = parsed.canonicalName || selectedRoot.name || "";
  roomNumber.value = parsed.roomNumber || "";
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
    const root = pickRoomCandidate(h.object, mapRoot);
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
  const res = await fetch("room.php?action=list_models", { cache: "no-store" });
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
    await loadRoomsTable();
    return;
  }
  rows.forEach((r) => {
    const o = document.createElement("option");
    o.value = r.file;
    const tags = [];
    if (r.isLive) tags.push("published");
    if (r.isEditable) tags.push("editable");
    if (r.isOriginal) tags.push("original");
    if (!r.isEditable && !r.isLive) tags.push("snapshot");
    else if (!r.isEditable && r.isLive) tags.push("read-only");
    o.textContent = tags.length ? `${r.file} (${tags.join(", ")})` : r.file;
    modelSel.appendChild(o);
  });
  modelSel.disabled = false;
  const names = rows.map((r) => r.file);
  const preferred = rows.find((r) => r.isEditable)?.file || rows.find((r) => r.isLive)?.file || (names.includes(ORIGINAL) ? ORIGINAL : names[0]);
  modelSel.value = names.includes(previousValue) ? previousValue : preferred;
  selectedModel.value = modelSel.value || "";
  updateEditorMode();
  await loadRoomsTable();
}

async function loadRoomsTable() {
  let url = "room.php?action=list_rooms";
  if (modelSel.value) url += `&model=${encodeURIComponent(modelSel.value)}`;
  const res = await fetch(url, { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  const rows = Array.isArray(data.rooms) ? data.rooms : [];
  tableBody.innerHTML = "";
  if (!rows.length) {
    tableBody.innerHTML = "<tr><td colspan=\"10\">No metadata for this model.</td></tr>";
    return;
  }
  rows.forEach((r) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${esc(r.room_id)}</td><td>${esc(r.room_name)}</td><td>${esc(r.model_object_name || "-")}</td><td>${esc(r.room_number || "-")}</td><td>${esc(r.room_type || "-")}</td><td>${esc(r.floor_number || "-")}</td><td>${esc(r.building_name || "-")}</td><td>${esc(r.source_model_file || "-")}</td><td>${esc(r.last_seen_version_id || "-")}</td><td>${esc(r.last_edited_at || "-")}</td>`;
    tableBody.appendChild(tr);
  });
}

async function loadSelectedMeta() {
  if (!selectedRoot || !selectedRoot.name || !modelSel.value) return;
  selectedDbRoomId = 0;
  const parsedSelected = parseRoomLabelDetails(selectedRoot.name || "");
  const buildingHints = collectRoomBuildingHints(selectedRoot, mapRoot);
  const url = new URL("room.php", window.location.href);
  url.searchParams.set("action", "get_room_meta");
  url.searchParams.set("model", modelSel.value);
  url.searchParams.set("name", selectedRoot.name);
  if (buildingHints.length) {
    url.searchParams.set("buildingHints", JSON.stringify(buildingHints));
  }
  const res = await fetch(url.toString(), { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  if (!data.found || !data.room) {
    newName.value = parsedSelected.canonicalName || selectedRoot.name || "";
    roomNumber.value = parsedSelected.roomNumber || "";
    roomType.value = "";
    roomFloor.value = "";
    roomBuilding.value = buildingHints[0] || "";
    desc.value = "";
    indoorGuide.value = "";
    imagePath.value = "";
    version.value = "";
    editedAt.value = "";
    if (data?.ambiguous) {
      const names = Array.isArray(data.candidates)
        ? data.candidates
            .map((candidate) => String(candidate?.building_name || "").trim())
            .filter(Boolean)
        : [];
      const summary = names.length ? ` Matching buildings: ${Array.from(new Set(names)).join(", ")}.` : "";
      setStatus(`Multiple DB room matches were found for ${selectedRoot.name}.${summary}`, "#b54708");
    } else if (buildingHints.length) {
      setStatus(`No DB metadata matched ${selectedRoot.name}. Inferred building hint: ${buildingHints[0]}.`, "#b54708");
    } else {
      setStatus(`No DB metadata matched ${selectedRoot.name}.`, "#b54708");
    }
    return;
  }
  const b = data.room;
  const parsedDb = parseRoomLabelDetails(String(b.room_name || selectedRoot.name || ""));
  const dbRoomNumber = b.room_number == null ? "" : String(b.room_number).trim();
  selectedDbRoomId = Number(b.room_id || 0);
  newName.value = String(b.room_name || parsedDb.canonicalName || parsedSelected.canonicalName || selectedRoot.name || "");
  roomNumber.value = dbRoomNumber || parsedDb.roomNumber || parsedSelected.roomNumber || "";
  roomType.value = String(b.room_type || "");
  roomFloor.value = b.floor_number == null ? "" : String(b.floor_number);
  roomBuilding.value = String(b.building_name || "");
  desc.value = String(b.description || "");
  indoorGuide.value = String(b.indoor_guide_text || "");
  imagePath.value = String(b.image_path || "");
  version.value = b.last_seen_version_id != null ? String(b.last_seen_version_id) : "";
  editedAt.value = String(b.last_edited_at || "");
  setStatus(`Loaded DB metadata for ${String(b.room_name || selectedRoot.name)}${b.building_name ? ` in ${b.building_name}` : ""}.`, "#0f766e");
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
  roomPickMeshes = [];
  meshToRoom = new WeakMap();
  roomNodeCount = 0;
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
        buildRoomPickIndex(mapRoot);
        fitToRoot(mapRoot);
        resolve();
      },
      undefined,
      (err) => reject(err)
    );
  });
  await loadRoomsTable();
  if (roomNodeCount > 0) {
    if (isCurrentModelEditable()) {
      setStatus(`Model loaded. Room/space nodes: ${roomNodeCount}, clickable room meshes: ${roomPickMeshes.length}. Hover or tap/click a room or named space to edit.`, "#0f766e");
    } else {
      setStatus(`Model loaded in archive mode. Room/space nodes: ${roomNodeCount}, clickable room meshes: ${roomPickMeshes.length}. You can inspect metadata, but saving is disabled for this snapshot.`, "#b54708");
    }
  } else {
    setStatus("Model loaded, but no room-style nodes were found in this scene graph.", "#b42318");
  }
}

async function saveRoom() {
  if (!mapRoot || !selectedRoot) {
    setStatus("Select a room first.", "#b42318");
    return;
  }
  if (!modelSel.value) {
    setStatus("Select model first.", "#b42318");
    return;
  }
  if (!isCurrentModelEditable()) {
    setStatus("This snapshot is archive-only here. Switch the default model in Map Editor before saving room changes.", "#b54708");
    return;
  }
  const oldName = String(selectedRoot.name || "").trim();
  const nextName = String(newName.value || "").trim();
  if (!oldName || !nextName) {
    setStatus("Room name is required.", "#b42318");
    return;
  }

  setStatus(`Saving ${oldName} as ${nextName} ...`);
  try {
    const binary = await exportBinary(mapRoot);
    const fd = new FormData();
    fd.append("modelFile", modelSel.value);
    fd.append("oldName", oldName);
    fd.append("newName", nextName);
    if (selectedDbRoomId > 0) fd.append("roomId", String(selectedDbRoomId));
    fd.append("roomNumber", roomNumber.value || "");
    fd.append("roomType", roomType.value || "");
    fd.append("floorNumber", roomFloor.value || "");
    fd.append("buildingName", roomBuilding.value || "");
    fd.append("description", desc.value || "");
    fd.append("indoorGuideText", indoorGuide.value || "");
    fd.append("imagePath", imagePath.value || "");
    fd.append("glb", new Blob([binary], { type: "model/gltf-binary" }), modelSel.value);

    const res = await fetch("room.php?action=save_room", {
      method: "POST",
      headers: { "X-CSRF-Token": CSRF },
      body: fd
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);

    selectedName.value = oldName;
    selectedDbRoomId = Number(data.roomId || selectedDbRoomId || 0);
    version.value = data.versionId != null ? String(data.versionId) : "";
    editedAt.value = String(data.editedAt || "");
    await loadRoomsTable();
    setStatus(`Saved ${nextName}. Backup: ${data.backupFile || "created"}.`, "#0f766e");
  } catch (err) {
    selectedName.value = oldName;
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
  loadRoomsTable().catch((err) => setStatus(`Table refresh failed: ${err?.message || err}`, "#b42318"));
});
saveBtn.addEventListener("click", () => {
  saveRoom().catch((err) => setStatus(`Save failed: ${err?.message || err}`, "#b42318"));
});
reloadMetaBtn.addEventListener("click", () => {
  if (!selectedRoot) {
    setStatus("Select a room first.", "#b42318");
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
