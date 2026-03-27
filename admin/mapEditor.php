<?php
require_once __DIR__ . "/inc/auth.php";
require_admin_permission("manage_map", "You do not have access to the map tools.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/building_identity.php";
require_once __DIR__ . "/inc/map_entities.php";
require_once __DIR__ . "/inc/destination_harmony.php";
app_logger_set_default_subsystem("map_editor");

$ASSET_DIR = __DIR__ . "/assets_map";
$OVERLAY_PATH = __DIR__ . "/overlays/map_overlay.json";
$MODEL_DIR = __DIR__ . "/../models";
$DRAFT_STATE_DIR = __DIR__ . "/overlays/drafts";
$DRAFT_MODEL_DIR = __DIR__ . "/../models/drafts";
$DEFAULT_MODEL_PATH = __DIR__ . "/overlays/default_model.json";
$LIVE_MAP_PATH = __DIR__ . "/overlays/map_live.json";
$RELEASES_PATH = __DIR__ . "/overlays/map_releases.json";
$ORIGINAL_MODEL_NAME = "tnts_navigation.glb";

function editor_normalize_model_file_name(string $name): string {
  $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($name));
  if ($safe === "" || $safe === "." || $safe === "..") return "";
  if (!preg_match('/\.glb$/i', $safe)) {
    $safe .= ".glb";
  }
  return $safe;
}

function editor_get_draft_glb_file_name(string $modelFile): string {
  $safe = editor_normalize_model_file_name($modelFile);
  if ($safe === "") return "";
  return "draft_" . $safe;
}

function editor_get_draft_state_path(string $modelFile): string {
  global $DRAFT_STATE_DIR;
  $safe = editor_normalize_model_file_name($modelFile);
  return $safe === "" ? "" : ($DRAFT_STATE_DIR . "/draft_" . $safe . ".json");
}

function editor_get_draft_glb_path(string $modelFile): string {
  global $DRAFT_MODEL_DIR;
  $file = editor_get_draft_glb_file_name($modelFile);
  return $file === "" ? "" : ($DRAFT_MODEL_DIR . "/" . $file);
}

function editor_get_draft_glb_url(string $modelFile): string {
  $file = editor_get_draft_glb_file_name($modelFile);
  return $file === "" ? "" : ("../models/drafts/" . rawurlencode($file));
}

if (empty($_SESSION["map_editor_csrf"])) {
  try {
    $_SESSION["map_editor_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $fallback = function_exists("openssl_random_pseudo_bytes") ? openssl_random_pseudo_bytes(32) : false;
    if (!is_string($fallback) || strlen($fallback) < 32) {
      $fallback = hash("sha256", uniqid((string)mt_rand(), true), true);
    }
    $_SESSION["map_editor_csrf"] = bin2hex($fallback);
  }
}
$MAP_EDITOR_CSRF = (string)$_SESSION["map_editor_csrf"];

if (empty($_SESSION["map_import_csrf"])) {
  try {
    $_SESSION["map_import_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $fallback = function_exists("openssl_random_pseudo_bytes") ? openssl_random_pseudo_bytes(32) : false;
    if (!is_string($fallback) || strlen($fallback) < 32) {
      $fallback = hash("sha256", uniqid((string)mt_rand(), true), true);
    }
    $_SESSION["map_import_csrf"] = bin2hex($fallback);
  }
}
$MAP_IMPORT_CSRF = (string)$_SESSION["map_import_csrf"];

function json_error_and_exit(int $status, string $message): void {
  app_log_http_problem($status, $message, [
    "action" => trim((string)($_GET["action"] ?? "")),
    "queryModel" => trim((string)($_GET["name"] ?? $_GET["file"] ?? "")),
  ], [
    "subsystem" => "map_editor",
    "event" => "http_error",
  ]);
  http_response_code($status);
  echo json_encode(["ok" => false, "error" => $message], JSON_PRETTY_PRINT);
  exit;
}

function is_same_origin_request(): bool {
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

  // Some clients may omit both headers on same-origin requests.
  return true;
}

function verify_csrf_or_origin(): void {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_error_and_exit(405, "POST required");
  }

  if (!is_same_origin_request()) {
    json_error_and_exit(403, "Cross-origin request denied");
  }

  $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
  $sessionToken = isset($_SESSION["map_editor_csrf"]) ? (string)$_SESSION["map_editor_csrf"] : "";
  if (!is_string($token) || $token === "" || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    json_error_and_exit(403, "CSRF validation failed");
  }
}

function safe_atomic_write_bytes(string $path, string $content): bool {
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

  // Windows fallback (rename cannot overwrite existing files).
  @unlink($path);
  if (@rename($tmp, $path)) return true;

  @unlink($tmp);
  return false;
}

function safe_atomic_write_json(string $path, $data): bool {
  $json = json_encode($data, JSON_PRETTY_PRINT);
  if ($json === false) return false;
  return safe_atomic_write_bytes($path, $json);
}

function restore_file_from_backup(string $path, ?string $backup): void {
  if ($backup === null) {
    if (file_exists($path)) @unlink($path);
    return;
  }
  safe_atomic_write_bytes($path, $backup);
}

function is_numeric_vec3($value): bool {
  return is_array($value)
    && count($value) >= 3
    && is_numeric($value[0])
    && is_numeric($value[1])
    && is_numeric($value[2]);
}

function validate_overlay_item($item, ?string &$error = null): bool {
  if (!is_array($item)) {
    $error = "Item must be an object";
    return false;
  }

  $type = isset($item["type"]) ? (string)$item["type"] : (isset($item["points"]) ? "road" : "asset");
  if ($type === "road") {
    if (!isset($item["points"]) || !is_array($item["points"])) {
      $error = "Road item points must be an array";
      return false;
    }
    foreach ($item["points"] as $idx => $pt) {
      if (!is_numeric_vec3($pt)) {
        $error = "Road item point {$idx} must be numeric [x,y,z]";
        return false;
      }
    }
    if (isset($item["position"]) && !is_numeric_vec3($item["position"])) {
      $error = "Road item position must be numeric [x,y,z]";
      return false;
    }
    if (isset($item["rotation"]) && !is_numeric_vec3($item["rotation"])) {
      $error = "Road item rotation must be numeric [x,y,z]";
      return false;
    }
    if (isset($item["scale"]) && !is_numeric_vec3($item["scale"])) {
      $error = "Road item scale must be numeric [x,y,z]";
      return false;
    }
    return true;
  }

  if (empty($item["asset"]) || !is_string($item["asset"])) {
    $error = "Asset item requires a valid asset path";
    return false;
  }
  if (!isset($item["position"]) || !is_numeric_vec3($item["position"])) {
    $error = "Asset item position must be numeric [x,y,z]";
    return false;
  }
  if (!isset($item["rotation"]) || !is_numeric_vec3($item["rotation"])) {
    $error = "Asset item rotation must be numeric [x,y,z]";
    return false;
  }
  if (!isset($item["scale"]) || !is_numeric_vec3($item["scale"])) {
    $error = "Asset item scale must be numeric [x,y,z]";
    return false;
  }
  return true;
}

function validate_roadnet_payload($data, ?string &$error = null): bool {
  if (!is_array($data) || !isset($data["roads"]) || !is_array($data["roads"])) {
    $error = "Roadnet payload must include roads[]";
    return false;
  }
  foreach ($data["roads"] as $idx => $roadItem) {
    $itemError = null;
    if (!validate_overlay_item(array_merge(["type" => "road"], is_array($roadItem) ? $roadItem : []), $itemError)) {
      $error = "roads[{$idx}] invalid: " . ($itemError ?: "Malformed road item");
      return false;
    }
  }
  return true;
}

function validate_routes_payload($data, ?string &$error = null): bool {
  if (!is_array($data) || !isset($data["routes"]) || !is_array($data["routes"])) {
    $error = "Routes payload must include routes{}";
    return false;
  }
  foreach ($data["routes"] as $routeKey => $routeEntry) {
    if (!is_array($routeEntry)) {
      $error = "routes[{$routeKey}] must be an object";
      return false;
    }
    $routeName = trim((string)($routeEntry["name"] ?? $routeKey));
    if ($routeName === "") {
      $error = "routes[{$routeKey}] requires a destination name";
      return false;
    }
    if (!isset($routeEntry["points"]) || !is_array($routeEntry["points"]) || count($routeEntry["points"]) < 2) {
      $error = "routes[{$routeKey}] must include at least two points";
      return false;
    }
    foreach ($routeEntry["points"] as $idx => $pt) {
      if (!is_numeric_vec3($pt)) {
        $error = "routes[{$routeKey}].points[{$idx}] must be numeric [x,y,z]";
        return false;
      }
    }
    if (array_key_exists("distance", $routeEntry) && $routeEntry["distance"] !== null && !is_numeric($routeEntry["distance"])) {
      $error = "routes[{$routeKey}].distance must be numeric";
      return false;
    }
  }
  return true;
}

function validate_guide_steps_payload($steps, string $label, ?string &$error = null): bool {
  if (!is_array($steps)) {
    $error = "{$label} must be an array";
    return false;
  }
  foreach ($steps as $idx => $step) {
    if (!is_array($step)) {
      $error = "{$label}[{$idx}] must be an object";
      return false;
    }
    $text = trim((string)($step["text"] ?? ""));
    if ($text === "") {
      $error = "{$label}[{$idx}] requires text";
      return false;
    }
    if (isset($step["kind"]) && !is_string($step["kind"])) {
      $error = "{$label}[{$idx}].kind must be a string";
      return false;
    }
  }
  return true;
}

function validate_guides_payload($data, ?string &$error = null): bool {
  if (!is_array($data) || !isset($data["entries"]) || !is_array($data["entries"])) {
    $error = "Guides payload must include entries{}";
    return false;
  }

  foreach ($data["entries"] as $guideKey => $guideEntry) {
    if (!is_array($guideEntry)) {
      $error = "entries[{$guideKey}] must be an object";
      return false;
    }

    $destinationType = trim((string)($guideEntry["destinationType"] ?? $guideEntry["type"] ?? "building"));
    if ($destinationType === "") {
      $error = "entries[{$guideKey}] requires destinationType";
      return false;
    }

    $guideMode = trim((string)($guideEntry["guideMode"] ?? "auto"));
    if ($guideMode !== "" && !in_array($guideMode, ["auto", "manual", "mixed"], true)) {
      $error = "entries[{$guideKey}].guideMode must be auto, manual, or mixed";
      return false;
    }

    $buildingName = trim((string)($guideEntry["buildingName"] ?? $guideEntry["name"] ?? ""));
    if ($buildingName === "" && $destinationType !== "landmark") {
      $error = "entries[{$guideKey}] requires buildingName";
      return false;
    }

    if (isset($guideEntry["roomName"]) && !is_string($guideEntry["roomName"])) {
      $error = "entries[{$guideKey}].roomName must be a string";
      return false;
    }
    if (isset($guideEntry["manualText"]) && !is_string($guideEntry["manualText"])) {
      $error = "entries[{$guideKey}].manualText must be a string";
      return false;
    }
    if (isset($guideEntry["roomSupplementText"]) && !is_string($guideEntry["roomSupplementText"])) {
      $error = "entries[{$guideKey}].roomSupplementText must be a string";
      return false;
    }
    if (isset($guideEntry["roomUid"]) && !is_string($guideEntry["roomUid"])) {
      $error = "entries[{$guideKey}].roomUid must be a string";
      return false;
    }
    if (isset($guideEntry["facilityUid"]) && !is_string($guideEntry["facilityUid"])) {
      $error = "entries[{$guideKey}].facilityUid must be a string";
      return false;
    }
    if (isset($guideEntry["routeSignature"]) && !is_string($guideEntry["routeSignature"])) {
      $error = "entries[{$guideKey}].routeSignature must be a string";
      return false;
    }
    if (isset($guideEntry["sourceRouteSignature"]) && !is_string($guideEntry["sourceRouteSignature"])) {
      $error = "entries[{$guideKey}].sourceRouteSignature must be a string";
      return false;
    }
    if (isset($guideEntry["status"]) && !is_string($guideEntry["status"])) {
      $error = "entries[{$guideKey}].status must be a string";
      return false;
    }
    if (array_key_exists("distance", $guideEntry) && $guideEntry["distance"] !== null && !is_numeric($guideEntry["distance"])) {
      $error = "entries[{$guideKey}].distance must be numeric";
      return false;
    }

    if (isset($guideEntry["autoSteps"]) && !validate_guide_steps_payload($guideEntry["autoSteps"], "entries[{$guideKey}].autoSteps", $error)) {
      return false;
    }
    if (isset($guideEntry["finalSteps"]) && !validate_guide_steps_payload($guideEntry["finalSteps"], "entries[{$guideKey}].finalSteps", $error)) {
      return false;
    }

    if (isset($guideEntry["notes"])) {
      if (!is_array($guideEntry["notes"])) {
        $error = "entries[{$guideKey}].notes must be an array";
        return false;
      }
      foreach ($guideEntry["notes"] as $noteIdx => $note) {
        if (!is_string($note)) {
          $error = "entries[{$guideKey}].notes[{$noteIdx}] must be a string";
          return false;
        }
      }
    }
  }

  return true;
}

function validate_draft_payload($data, ?string &$error = null): bool {
  if (!is_array($data)) {
    $error = "Draft payload must be an object";
    return false;
  }

  $overlay = $data["overlay"] ?? ["version" => 2, "items" => []];
  if (!is_array($overlay) || !isset($overlay["items"]) || !is_array($overlay["items"])) {
    $error = "Draft payload must include overlay.items[]";
    return false;
  }
  foreach ($overlay["items"] as $idx => $item) {
    $itemError = null;
    if (!validate_overlay_item($item, $itemError)) {
      $error = "overlay.items[{$idx}] invalid: " . ($itemError ?: "Malformed overlay item");
      return false;
    }
  }

  $roadsPayload = ["roads" => is_array($data["roads"] ?? null) ? $data["roads"] : null];
  if (!validate_roadnet_payload($roadsPayload, $error)) {
    return false;
  }

  $routesPayload = ["routes" => is_array($data["routes"] ?? null) ? $data["routes"] : null];
  if (!validate_routes_payload($routesPayload, $error)) {
    return false;
  }

  $guidesPayload = is_array($data["guides"] ?? null)
    ? $data["guides"]
    : ["entries" => null];
  if (!validate_guides_payload($guidesPayload, $error)) {
    return false;
  }

  if (isset($data["hasBaseDraft"]) && !is_bool($data["hasBaseDraft"])) {
    $error = "Draft payload hasBaseDraft must be true or false";
    return false;
  }
  if (isset($data["baseDraftFile"]) && !is_string($data["baseDraftFile"])) {
    $error = "Draft payload baseDraftFile must be a string";
    return false;
  }

  return true;
}

function is_valid_glb_binary(string $raw): bool {
  if (strlen($raw) < 12) return false;
  $hdr = unpack("a4magic/Vversion/Vlength", substr($raw, 0, 12));
  if (!is_array($hdr)) return false;
  if (($hdr["magic"] ?? "") !== "glTF") return false;
  if ((int)($hdr["version"] ?? 0) !== 2) return false;
  if ((int)($hdr["length"] ?? 0) !== strlen($raw)) return false;
  return true;
}

function editor_db_col(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function editor_db_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) throw new RuntimeException($conn->error);
}

function editor_ensure_snapshot_schema(mysqli $conn): void {
  editor_db_sql($conn, "
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
  if (!editor_db_col($conn, "buildings", "source_model_file")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN source_model_file VARCHAR(255) NULL AFTER image_path");
  if (!editor_db_col($conn, "buildings", "first_seen_version_id")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  if (!editor_db_col($conn, "buildings", "last_seen_version_id")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  if (!editor_db_col($conn, "buildings", "is_present_in_latest")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  if (!editor_db_col($conn, "buildings", "last_edited_at")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN last_edited_at DATETIME NULL AFTER is_present_in_latest");
  if (!editor_db_col($conn, "buildings", "last_edited_by_admin_id")) editor_db_sql($conn, "ALTER TABLE buildings ADD COLUMN last_edited_by_admin_id INT NULL AFTER last_edited_at");
  if (!editor_db_col($conn, "rooms", "building_id")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN building_id INT NULL AFTER room_id");
  if (!editor_db_col($conn, "rooms", "room_number")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN room_number VARCHAR(50) NULL AFTER room_name");
  if (!editor_db_col($conn, "rooms", "room_type")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN room_type VARCHAR(100) NULL AFTER room_number");
  if (!editor_db_col($conn, "rooms", "floor_number")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN floor_number VARCHAR(50) NULL AFTER room_type");
  if (!editor_db_col($conn, "rooms", "building_name")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN building_name VARCHAR(255) NULL AFTER floor_number");
  if (!editor_db_col($conn, "rooms", "description")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN description TEXT NULL AFTER building_name");
  if (!editor_db_col($conn, "rooms", "indoor_guide_text")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN indoor_guide_text TEXT NULL AFTER description");
  if (!editor_db_col($conn, "rooms", "image_path")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN image_path VARCHAR(255) NULL AFTER indoor_guide_text");
  if (!editor_db_col($conn, "rooms", "source_model_file")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN source_model_file VARCHAR(255) NULL AFTER image_path");
  if (!editor_db_col($conn, "rooms", "first_seen_version_id")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  if (!editor_db_col($conn, "rooms", "last_seen_version_id")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  if (!editor_db_col($conn, "rooms", "is_present_in_latest")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  if (!editor_db_col($conn, "rooms", "last_edited_at")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN last_edited_at DATETIME NULL AFTER is_present_in_latest");
  if (!editor_db_col($conn, "rooms", "last_edited_by_admin_id")) editor_db_sql($conn, "ALTER TABLE rooms ADD COLUMN last_edited_by_admin_id INT NULL AFTER last_edited_at");
  map_entities_ensure_schema($conn);
  harmony_ensure_schema($conn);
}

function editor_get_or_create_version_id(mysqli $conn, string $modelFile, string $modelHash): int {
  $stmt = $conn->prepare("SELECT version_id FROM map_versions WHERE model_file = ? AND model_hash = ? LIMIT 1");
  if (!$stmt) throw new RuntimeException("Failed to prepare map version lookup");
  $stmt->bind_param("ss", $modelFile, $modelHash);
  if (!$stmt->execute()) throw new RuntimeException("Failed to query map_versions");
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if ($row && isset($row["version_id"])) return (int)$row["version_id"];

  $stmt = $conn->prepare("INSERT INTO map_versions (model_file, model_hash, imported_by_admin_id, total_buildings, total_rooms) VALUES (?, ?, NULL, 0, 0)");
  if (!$stmt) throw new RuntimeException("Failed to prepare map version insert");
  $stmt->bind_param("ss", $modelFile, $modelHash);
  if (!$stmt->execute()) throw new RuntimeException("Failed to insert map version");
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function editor_refresh_version_totals(mysqli $conn, int $versionId, string $modelFile): void {
  $safeModel = $conn->real_escape_string($modelFile);
  $buildingCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM buildings WHERE source_model_file = '{$safeModel}' AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)");
  $roomCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM rooms WHERE source_model_file = '{$safeModel}' AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)");
  $buildingCount = ($buildingCountRes instanceof mysqli_result) ? (int)(($buildingCountRes->fetch_assoc()["cnt"] ?? 0)) : 0;
  $roomCount = ($roomCountRes instanceof mysqli_result) ? (int)(($roomCountRes->fetch_assoc()["cnt"] ?? 0)) : 0;

  $stmt = $conn->prepare("UPDATE map_versions SET total_buildings = ?, total_rooms = ? WHERE version_id = ?");
  if (!$stmt) throw new RuntimeException("Failed to prepare version total update");
  $stmt->bind_param("iii", $buildingCount, $roomCount, $versionId);
  if (!$stmt->execute()) throw new RuntimeException("Failed to update version totals");
  $stmt->close();
}

function editor_clone_model_snapshot(mysqli $conn, string $sourceModel, string $targetModel, int $targetVersionId): void {
  if ($sourceModel === "" || $targetModel === "" || $sourceModel === $targetModel) return;

  $buildingSql = "
    SELECT building_id, building_uid, building_name, model_object_name, entity_type, description, image_path, last_edited_at, last_edited_by_admin_id
    FROM buildings
    WHERE source_model_file = ? AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)
    ORDER BY building_id ASC
  ";
  $buildingStmt = $conn->prepare($buildingSql);
  if (!$buildingStmt) throw new RuntimeException("Failed to prepare source building query");
  $buildingStmt->bind_param("s", $sourceModel);
  if (!$buildingStmt->execute()) throw new RuntimeException("Failed to load source building snapshot");
  $buildingRes = $buildingStmt->get_result();
  $sourceBuildings = [];
  if ($buildingRes instanceof mysqli_result) {
    while ($row = $buildingRes->fetch_assoc()) $sourceBuildings[] = $row;
  }
  $buildingStmt->close();
  if (!$sourceBuildings) return;

  $insertBuildingStmt = $conn->prepare("
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
      is_present_in_latest,
      last_edited_at,
      last_edited_by_admin_id
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
  ");
  if (!$insertBuildingStmt) throw new RuntimeException("Failed to prepare cloned building insert");

  $insertRoomStmt = $conn->prepare("
    INSERT INTO rooms (
      building_id,
      building_uid,
      room_uid,
      model_object_name,
      room_name,
      room_number,
      room_type,
      floor_number,
      building_name,
      description,
      indoor_guide_text,
      image_path,
      source_model_file,
      first_seen_version_id,
      last_seen_version_id,
      is_present_in_latest,
      last_edited_at,
      last_edited_by_admin_id
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
  ");
  if (!$insertRoomStmt) throw new RuntimeException("Failed to prepare cloned room insert");

  $roomQueryStmt = $conn->prepare("
    SELECT room_uid, room_name, room_number, room_type, floor_number, building_name, building_uid, description, indoor_guide_text, image_path, model_object_name, last_edited_at, last_edited_by_admin_id
    FROM rooms
    WHERE source_model_file = ? AND building_id = ? AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)
    ORDER BY room_id ASC
  ");
  if (!$roomQueryStmt) throw new RuntimeException("Failed to prepare source room query");

  foreach ($sourceBuildings as $building) {
    $sourceBuildingId = (int)($building["building_id"] ?? 0);
    if ($sourceBuildingId <= 0) continue;

    $buildingUid = map_identity_normalize_uid((string)($building["building_uid"] ?? ""));
    if ($buildingUid === "") {
      $buildingUid = map_identity_resolve_uid($conn, (string)($building["building_name"] ?? ""), $sourceModel);
    }
    $buildingName = trim((string)($building["building_name"] ?? ""));
    $buildingObjectName = trim((string)($building["model_object_name"] ?? ""));
    $buildingEntityType = trim((string)($building["entity_type"] ?? "building")) ?: "building";
    if ($buildingObjectName === "") $buildingObjectName = $buildingName;
    if ($buildingName === "" || $buildingObjectName === "") continue;
    $buildingDescription = trim((string)($building["description"] ?? ""));
    $buildingImagePath = trim((string)($building["image_path"] ?? ""));
    $buildingEditedAt = isset($building["last_edited_at"]) ? (string)$building["last_edited_at"] : null;
    $buildingEditedBy = isset($building["last_edited_by_admin_id"]) ? (int)$building["last_edited_by_admin_id"] : null;

    $insertBuildingStmt->bind_param(
      "sssssssiisi",
      $buildingUid,
      $buildingName,
      $buildingObjectName,
      $buildingEntityType,
      $buildingDescription,
      $buildingImagePath,
      $targetModel,
      $targetVersionId,
      $targetVersionId,
      $buildingEditedAt,
      $buildingEditedBy
    );
    if (!$insertBuildingStmt->execute()) throw new RuntimeException("Failed to clone building snapshot: " . $buildingName);
    $newBuildingId = (int)$insertBuildingStmt->insert_id;

    $roomQueryStmt->bind_param("si", $sourceModel, $sourceBuildingId);
    if (!$roomQueryStmt->execute()) throw new RuntimeException("Failed to load source room snapshot: " . $buildingName);
    $roomRes = $roomQueryStmt->get_result();
    if (!($roomRes instanceof mysqli_result)) continue;

    while ($room = $roomRes->fetch_assoc()) {
      $roomName = trim((string)($room["room_name"] ?? ""));
      if ($roomName === "") continue;
      $roomBuildingUid = map_identity_normalize_uid((string)($room["building_uid"] ?? ""));
      if ($roomBuildingUid === "") $roomBuildingUid = $buildingUid;
      $roomUid = harmony_normalize_uid((string)($room["room_uid"] ?? ""), "room");
      if ($roomUid === "") {
        $roomUid = harmony_resolve_room_uid($conn, $roomBuildingUid, $roomName, (string)($room["model_object_name"] ?? ""), $sourceModel);
      }
      $roomNumber = trim((string)($room["room_number"] ?? ""));
      $roomType = trim((string)($room["room_type"] ?? ""));
      $floorNumber = trim((string)($room["floor_number"] ?? ""));
      $roomBuildingName = trim((string)($room["building_name"] ?? $buildingName));
      $roomDescription = trim((string)($room["description"] ?? ""));
      $roomIndoorGuideText = trim((string)($room["indoor_guide_text"] ?? ""));
      $roomImagePath = trim((string)($room["image_path"] ?? ""));
      $roomObjectName = trim((string)($room["model_object_name"] ?? ""));
      if ($roomObjectName === "") $roomObjectName = $roomName;
      $roomEditedAt = isset($room["last_edited_at"]) ? (string)$room["last_edited_at"] : null;
      $roomEditedBy = isset($room["last_edited_by_admin_id"]) ? (int)$room["last_edited_by_admin_id"] : null;

      $insertRoomStmt->bind_param(
        "issssssssssssiisi",
        $newBuildingId,
        $roomBuildingUid,
        $roomUid,
        $roomObjectName,
        $roomName,
        $roomNumber,
        $roomType,
        $floorNumber,
        $roomBuildingName,
        $roomDescription,
        $roomIndoorGuideText,
        $roomImagePath,
        $targetModel,
        $targetVersionId,
        $targetVersionId,
        $roomEditedAt,
        $roomEditedBy
      );
      if (!$insertRoomStmt->execute()) throw new RuntimeException("Failed to clone room snapshot: " . $roomName);
    }
  }

  $roomQueryStmt->close();
  $insertRoomStmt->close();
  $insertBuildingStmt->close();
}

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");
  $action = (string)$_GET["action"];

  if (in_array($action, ["save_routes", "save_roadnet", "save_guides", "save_navigation_bundle", "save_draft_state", "save_draft_glb", "set_default_model", "publish_map", "save_overlay", "export_glb"], true)) {
    verify_csrf_or_origin();
  }

  if ($action === "list_assets") {
    $items = [];
    if (is_dir($ASSET_DIR)) {
      foreach (scandir($ASSET_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.(glb|gltf)$/i', $f)) continue;
        $items[] = [
          "label" => pathinfo($f, PATHINFO_FILENAME),
          "path"  => "assets_map/" . $f
        ];
      }
    }
    echo json_encode(["ok" => true, "assets" => $items], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_routes") {
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      echo json_encode(["ok" => true, "routes" => new stdClass()], JSON_PRETTY_PRINT);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }
    $path = __DIR__ . "/overlays/routes_" . $safe . ".json";
    if (file_exists($path)) {
      echo file_get_contents($path);
    } else {
      echo json_encode(["ok" => true, "routes" => new stdClass()], JSON_PRETTY_PRINT);
    }
    exit;
  }

  if ($action === "save_routes") {

    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid name"]);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data["routes"]) || !is_array($data["routes"])) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
      exit;
    }

    $overlayDir = __DIR__ . "/overlays";
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $path = $overlayDir . "/routes_" . $safe . ".json";
    $payload = [
      "ok" => true,
      "model" => $safe,
      "updated" => time(),
      "routes" => $data["routes"]
    ];
    if (!safe_atomic_write_json($path, $payload)) {
      json_error_and_exit(500, "Failed to save routes file");
    }
    echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_roadnet") {
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      echo json_encode([
        "ok" => true,
        "exists" => false,
        "roads" => []
      ], JSON_PRETTY_PRINT);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $path = __DIR__ . "/overlays/roadnet_" . $safe . ".json";
    if (!file_exists($path)) {
      echo json_encode([
        "ok" => true,
        "model" => $safe,
        "exists" => false,
        "roads" => []
      ], JSON_PRETTY_PRINT);
      exit;
    }

    $raw = file_get_contents($path);
    $json = json_decode($raw, true);
    $roads = (is_array($json) && isset($json["roads"]) && is_array($json["roads"])) ? $json["roads"] : [];
    $updated = (is_array($json) && isset($json["updated"])) ? $json["updated"] : null;

    echo json_encode([
      "ok" => true,
      "model" => $safe,
      "exists" => true,
      "updated" => $updated,
      "roads" => $roads
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_roadnet") {

    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid name"]);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!validate_roadnet_payload($data, $payloadError)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => $payloadError ?: "Invalid JSON"], JSON_PRETTY_PRINT);
      exit;
    }

    $overlayDir = __DIR__ . "/overlays";
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $path = $overlayDir . "/roadnet_" . $safe . ".json";
    $payload = [
      "ok" => true,
      "model" => $safe,
      "updated" => time(),
      "roads" => $data["roads"]
    ];
    if (!safe_atomic_write_json($path, $payload)) {
      json_error_and_exit(500, "Failed to save roadnet file");
    }
    echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_guides") {
    editor_ensure_snapshot_schema($conn);
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      echo json_encode([
        "ok" => true,
        "exists" => false,
        "entries" => new stdClass()
      ], JSON_PRETTY_PRINT);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $path = __DIR__ . "/overlays/guides_" . $safe . ".json";
    $hasFile = file_exists($path);
    $entries = [];
    $updated = null;
    if ($hasFile) {
      $raw = file_get_contents($path);
      $json = json_decode($raw, true);
      if (is_array($json) && isset($json["entries"]) && is_array($json["entries"])) {
        $entries = $json["entries"];
      }
      $updated = (is_array($json) && isset($json["updated"])) ? $json["updated"] : null;
    }

    $sourceRows = harmony_load_guide_source_rows($conn, $safe);
    $entries = harmony_merge_guide_source_rows($entries, $sourceRows);
    if (!$hasFile && !$entries) {
      echo json_encode([
        "ok" => true,
        "model" => $safe,
        "exists" => false,
        "entries" => new stdClass()
      ], JSON_PRETTY_PRINT);
      exit;
    }

    echo json_encode([
      "ok" => true,
      "model" => $safe,
      "exists" => ($hasFile || !empty($entries)),
      "updated" => $updated,
      "entries" => !empty($entries) ? $entries : new stdClass()
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_guides") {
    editor_ensure_snapshot_schema($conn);
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid name"]);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!validate_guides_payload($data, $payloadError)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => $payloadError ?: "Invalid JSON"], JSON_PRETTY_PRINT);
      exit;
    }

    $overlayDir = __DIR__ . "/overlays";
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $path = $overlayDir . "/guides_" . $safe . ".json";
    $payload = $data;
    $payload["ok"] = true;
    $payload["model"] = $safe;
    $payload["updated"] = time();
    $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
    if (is_int($adminId) && $adminId <= 0) $adminId = null;
    $backup = file_exists($path) ? file_get_contents($path) : null;
    $conn->begin_transaction();
    try {
      harmony_persist_guide_source_entries($conn, $safe, is_array($payload["entries"] ?? null) ? $payload["entries"] : [], $adminId);
      if (!safe_atomic_write_json($path, $payload)) {
        throw new RuntimeException("Failed to save guides file");
      }
      $conn->commit();
    } catch (Throwable $e) {
      $conn->rollback();
      restore_file_from_backup($path, $backup);
      json_error_and_exit(500, "Failed to save guides file: " . $e->getMessage());
    }
    echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_navigation_bundle") {
    editor_ensure_snapshot_schema($conn);
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid name"]);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $roadsPayload = ["roads" => is_array($data) ? ($data["roads"] ?? null) : null];
    $routesPayload = ["routes" => is_array($data) ? ($data["routes"] ?? null) : null];
    $guidesPayload = is_array($data) && isset($data["guides"]) && is_array($data["guides"])
      ? $data["guides"]
      : ["entries" => null];

    if (!validate_roadnet_payload($roadsPayload, $payloadError)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => $payloadError ?: "Invalid roadnet payload"], JSON_PRETTY_PRINT);
      exit;
    }
    if (!validate_routes_payload($routesPayload, $payloadError)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => $payloadError ?: "Invalid routes payload"], JSON_PRETTY_PRINT);
      exit;
    }
    if (!validate_guides_payload($guidesPayload, $payloadError)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => $payloadError ?: "Invalid guides payload"], JSON_PRETTY_PRINT);
      exit;
    }

    $overlayDir = __DIR__ . "/overlays";
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $updated = time();
    $roadnetPath = $overlayDir . "/roadnet_" . $safe . ".json";
    $routesPath = $overlayDir . "/routes_" . $safe . ".json";
    $guidesPath = $overlayDir . "/guides_" . $safe . ".json";

    $roadnetBackup = file_exists($roadnetPath) ? file_get_contents($roadnetPath) : null;
    $routesBackup = file_exists($routesPath) ? file_get_contents($routesPath) : null;
    $guidesBackup = file_exists($guidesPath) ? file_get_contents($guidesPath) : null;

    $roadnetOut = [
      "ok" => true,
      "model" => $safe,
      "updated" => $updated,
      "roads" => $roadsPayload["roads"]
    ];
    $routesOut = [
      "ok" => true,
      "model" => $safe,
      "updated" => $updated,
      "routes" => $routesPayload["routes"]
    ];
    $guidesOut = $guidesPayload;
    $guidesOut["ok"] = true;
    $guidesOut["model"] = $safe;
    $guidesOut["updated"] = $updated;
    $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
    if (is_int($adminId) && $adminId <= 0) $adminId = null;

    try {
      $conn->begin_transaction();
      harmony_persist_guide_source_entries($conn, $safe, is_array($guidesPayload["entries"] ?? null) ? $guidesPayload["entries"] : [], $adminId);
      if (!safe_atomic_write_json($roadnetPath, $roadnetOut)) {
        throw new RuntimeException("Failed to save roadnet file");
      }
      if (!safe_atomic_write_json($routesPath, $routesOut)) {
        throw new RuntimeException("Failed to save routes file");
      }
      if (!safe_atomic_write_json($guidesPath, $guidesOut)) {
        throw new RuntimeException("Failed to save guides file");
      }
      $conn->commit();
    } catch (Throwable $e) {
      try { $conn->rollback(); } catch (Throwable $_) {}
      restore_file_from_backup($roadnetPath, $roadnetBackup);
      restore_file_from_backup($routesPath, $routesBackup);
      restore_file_from_backup($guidesPath, $guidesBackup);
      json_error_and_exit(500, "Failed to save navigation bundle: " . $e->getMessage());
    }

    app_log("info", "Navigation bundle saved", [
      "action" => $action,
      "modelFile" => $safe,
      "roadCount" => count(is_array($roadnetOut["roads"]) ? $roadnetOut["roads"] : []),
      "routeCount" => is_array($routesOut["routes"]) ? count($routesOut["routes"]) : 0,
      "guideCount" => is_array($guidesOut["entries"] ?? null) ? count($guidesOut["entries"]) : 0,
    ], [
      "subsystem" => "map_editor",
      "event" => "save_navigation_bundle",
    ]);
    echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_draft_state") {
    $safe = editor_normalize_model_file_name(isset($_GET["name"]) ? trim((string)$_GET["name"]) : "");
    if ($safe === "") {
      echo json_encode(["ok" => true, "exists" => false, "model" => ""], JSON_PRETTY_PRINT);
      exit;
    }

    $path = editor_get_draft_state_path($safe);
    if ($path === "" || !file_exists($path)) {
      echo json_encode(["ok" => true, "exists" => false, "model" => $safe], JSON_PRETTY_PRINT);
      exit;
    }

    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) {
      json_error_and_exit(500, "Draft state file is invalid JSON");
    }

    $overlay = (isset($json["overlay"]) && is_array($json["overlay"]))
      ? $json["overlay"]
      : ["version" => 2, "items" => []];
    if (!isset($overlay["items"]) || !is_array($overlay["items"])) {
      $overlay["items"] = [];
    }

    $roads = isset($json["roads"]) && is_array($json["roads"]) ? $json["roads"] : [];
    $routes = isset($json["routes"]) && is_array($json["routes"]) ? $json["routes"] : [];
    $guides = isset($json["guides"]) && is_array($json["guides"]) ? $json["guides"] : ["entries" => []];
    if (!isset($guides["entries"]) || !is_array($guides["entries"])) {
      $guides["entries"] = [];
    }

    $baseDraftFile = basename((string)($json["baseDraftFile"] ?? ""));
    if ($baseDraftFile === "") {
      $baseDraftFile = editor_get_draft_glb_file_name($safe);
    }
    $baseDraftPath = $baseDraftFile !== "" ? ($DRAFT_MODEL_DIR . "/" . $baseDraftFile) : "";
    $hasBaseDraft = !empty($json["hasBaseDraft"]) && $baseDraftPath !== "" && file_exists($baseDraftPath);

    echo json_encode([
      "ok" => true,
      "exists" => true,
      "model" => $safe,
      "savedAt" => isset($json["savedAt"]) ? (int)$json["savedAt"] : (filemtime($path) ?: time()),
      "hasBaseDraft" => $hasBaseDraft,
      "baseDraftFile" => $hasBaseDraft ? $baseDraftFile : "",
      "baseDraftUrl" => $hasBaseDraft ? ("../models/drafts/" . rawurlencode($baseDraftFile)) : "",
      "overlay" => $overlay,
      "roads" => $roads,
      "routes" => $routes,
      "guides" => $guides
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_draft_state") {
    $safe = editor_normalize_model_file_name(isset($_GET["name"]) ? trim((string)$_GET["name"]) : "");
    if ($safe === "") {
      json_error_and_exit(400, "Invalid name");
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!validate_draft_payload($data, $payloadError)) {
      json_error_and_exit(400, $payloadError ?: "Invalid draft payload");
    }

    if (!is_dir($DRAFT_STATE_DIR) && !mkdir($DRAFT_STATE_DIR, 0775, true) && !is_dir($DRAFT_STATE_DIR)) {
      json_error_and_exit(500, "Failed to initialize draft state directory");
    }

    $overlay = is_array($data["overlay"] ?? null) ? $data["overlay"] : ["version" => 2, "items" => []];
    $overlay["version"] = (int)($overlay["version"] ?? 2) ?: 2;
    $overlay["items"] = is_array($overlay["items"] ?? null) ? $overlay["items"] : [];

    $hasBaseDraft = !empty($data["hasBaseDraft"]);
    $baseDraftFile = basename((string)($data["baseDraftFile"] ?? ""));
    if ($hasBaseDraft && $baseDraftFile === "") {
      $baseDraftFile = editor_get_draft_glb_file_name($safe);
    }
    if (!$hasBaseDraft) {
      $baseDraftFile = "";
    }

    $payload = [
      "ok" => true,
      "model" => $safe,
      "savedAt" => time(),
      "hasBaseDraft" => $hasBaseDraft,
      "baseDraftFile" => $baseDraftFile,
      "overlay" => $overlay,
      "roads" => is_array($data["roads"] ?? null) ? $data["roads"] : [],
      "routes" => is_array($data["routes"] ?? null) ? $data["routes"] : [],
      "guides" => is_array($data["guides"] ?? null) ? $data["guides"] : ["entries" => []]
    ];

    $path = editor_get_draft_state_path($safe);
    if ($path === "" || !safe_atomic_write_json($path, $payload)) {
      json_error_and_exit(500, "Failed to save draft state");
    }

    app_log("info", "Draft state saved", [
      "action" => $action,
      "modelFile" => $safe,
      "hasBaseDraft" => $hasBaseDraft,
      "overlayItemCount" => count($overlay["items"]),
      "roadCount" => count($payload["roads"]),
      "routeCount" => count($payload["routes"]),
      "guideCount" => is_array($payload["guides"]["entries"] ?? null) ? count($payload["guides"]["entries"]) : 0,
    ], [
      "subsystem" => "map_editor",
      "event" => "save_draft_state",
    ]);
    echo json_encode([
      "ok" => true,
      "model" => $safe,
      "savedAt" => $payload["savedAt"],
      "hasBaseDraft" => $hasBaseDraft,
      "baseDraftFile" => $baseDraftFile,
      "baseDraftUrl" => $hasBaseDraft ? editor_get_draft_glb_url($safe) : ""
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "save_draft_glb") {
    $safe = editor_normalize_model_file_name(isset($_GET["name"]) ? trim((string)$_GET["name"]) : "");
    if ($safe === "") {
      json_error_and_exit(400, "Invalid name");
    }

    $raw = file_get_contents("php://input");
    $contentType = strtolower((string)($_SERVER["CONTENT_TYPE"] ?? ""));
    if ($contentType !== "" && strpos($contentType, "model/gltf-binary") === false && strpos($contentType, "application/octet-stream") === false) {
      json_error_and_exit(415, "Unsupported content type");
    }
    if ($raw === false || !is_valid_glb_binary($raw)) {
      json_error_and_exit(400, "Empty or invalid GLB");
    }

    if (!is_dir($DRAFT_MODEL_DIR) && !mkdir($DRAFT_MODEL_DIR, 0775, true) && !is_dir($DRAFT_MODEL_DIR)) {
      json_error_and_exit(500, "Failed to initialize draft model directory");
    }

    $file = editor_get_draft_glb_file_name($safe);
    $path = editor_get_draft_glb_path($safe);
    if ($file === "" || $path === "" || !safe_atomic_write_bytes($path, $raw)) {
      json_error_and_exit(500, "Failed to save draft GLB");
    }

    app_log("info", "Draft GLB saved", [
      "action" => $action,
      "modelFile" => $safe,
      "draftFile" => $file,
      "byteLength" => strlen($raw),
    ], [
      "subsystem" => "map_editor",
      "event" => "save_draft_glb",
    ]);
    echo json_encode([
      "ok" => true,
      "model" => $safe,
      "savedAt" => time(),
      "file" => $file,
      "url" => editor_get_draft_glb_url($safe)
    ], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "list_models") {
    $defaultModel = $ORIGINAL_MODEL_NAME;
    if (file_exists($DEFAULT_MODEL_PATH)) {
      $raw = file_get_contents($DEFAULT_MODEL_PATH);
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json["file"])) {
        $candidate = basename($json["file"]);
        if (preg_match('/\.glb$/i', $candidate) && file_exists($MODEL_DIR . "/" . $candidate)) {
          $defaultModel = $candidate;
        }
      }
    }

    $items = [];
    if (is_dir($MODEL_DIR)) {
      foreach (scandir($MODEL_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.glb$/i', $f)) continue;
        $items[] = [
          "file" => $f,
          "label" => pathinfo($f, PATHINFO_FILENAME),
          "url" => "../models/" . $f,
          "isOriginal" => ($f === $ORIGINAL_MODEL_NAME),
          "isDefault" => ($f === $defaultModel),
        ];
      }
    }
    echo json_encode(["ok" => true, "models" => $items], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_model_buildings") {
    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      echo json_encode(["ok" => true, "model" => "", "buildings" => []], JSON_PRETTY_PRINT);
      exit;
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    try {
      editor_ensure_snapshot_schema($conn);
      $rows = map_entities_fetch_model_destinations($conn, $safe, true);
      $items = [];
      foreach ($rows as $row) {
        $name = trim((string)($row["name"] ?? $row["building_name"] ?? ""));
        if ($name === "") continue;
        $items[] = [
          "id" => isset($row["id"]) ? (int)$row["id"] : (isset($row["building_id"]) ? (int)$row["building_id"] : null),
          "buildingUid" => map_identity_normalize_uid((string)($row["buildingUid"] ?? $row["building_uid"] ?? "")),
          "name" => $name,
          "objectName" => trim((string)($row["objectName"] ?? $row["model_object_name"] ?? $name)),
          "entityType" => trim((string)($row["entityType"] ?? "building")) ?: "building",
          "description" => trim((string)($row["description"] ?? "")),
          "imagePath" => trim((string)($row["imagePath"] ?? $row["image_path"] ?? "")),
          "modelFile" => trim((string)($row["modelFile"] ?? $row["source_model_file"] ?? $safe)),
          "location" => trim((string)($row["location"] ?? "")),
          "contactInfo" => trim((string)($row["contactInfo"] ?? "")),
        ];
      }
      echo json_encode(["ok" => true, "model" => $safe, "buildings" => $items], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      json_error_and_exit(500, "Failed to load model building catalog: " . $e->getMessage());
    }
  }

  if ($action === "harmony_health") {
    try {
      editor_ensure_snapshot_schema($conn);
      $model = "";
      if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
        $candidate = editor_normalize_model_file_name((string)$_GET["model"]);
        if ($candidate === "") {
          json_error_and_exit(400, "Invalid model file");
        }
        $model = $candidate;
      }
      $report = harmony_collect_health_report($conn, $model);
      echo json_encode([
        "ok" => true,
        "report" => $report
      ], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      json_error_and_exit(500, "Failed to build harmony diagnostics: " . $e->getMessage());
    }
  }

  if ($action === "get_default_model") {
    $defaultModel = $ORIGINAL_MODEL_NAME;
    if (file_exists($DEFAULT_MODEL_PATH)) {
      $raw = file_get_contents($DEFAULT_MODEL_PATH);
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json["file"])) {
        $candidate = basename($json["file"]);
        if (preg_match('/\.glb$/i', $candidate) && file_exists($MODEL_DIR . "/" . $candidate)) {
          $defaultModel = $candidate;
        }
      }
    }
    echo json_encode(["ok" => true, "file" => $defaultModel], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "set_default_model") {

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $file = is_array($data) && !empty($data["file"]) ? basename($data["file"]) : "";

    if ($file === "" || !preg_match('/\.glb$/i', $file)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid file"]);
      exit;
    }

    $path = $MODEL_DIR . "/" . $file;
    if (!file_exists($path)) {
      http_response_code(404);
      echo json_encode(["ok" => false, "error" => "File not found"]);
      exit;
    }

    $overlayDir = dirname($DEFAULT_MODEL_PATH);
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $payload = ["file" => $file, "updated" => time()];
    if (!safe_atomic_write_json($DEFAULT_MODEL_PATH, $payload)) {
      json_error_and_exit(500, "Failed to update default model");
    }
    app_log("info", "Default model updated", [
      "action" => $action,
      "modelFile" => $file,
    ], [
      "subsystem" => "map_editor",
      "event" => "set_default_model",
    ]);
    echo json_encode(["ok" => true, "file" => $file], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "publish_map") {
    editor_ensure_snapshot_schema($conn);

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $file = is_array($data) && !empty($data["file"]) ? basename($data["file"]) : "";
    if ($file === "" || !preg_match('/\.glb$/i', $file)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid file"]);
      exit;
    }

    $modelPath = $MODEL_DIR . "/" . $file;
    if (!file_exists($modelPath)) {
      http_response_code(404);
      echo json_encode(["ok" => false, "error" => "Model file not found"]);
      exit;
    }

    $overlayDir = dirname($LIVE_MAP_PATH);
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $roadnetFile = "roadnet_" . $file . ".json";
    $roadnetPath = $overlayDir . "/" . $roadnetFile;
    $routesFile = "routes_" . $file . ".json";
    $routesPath = $overlayDir . "/" . $routesFile;
    $guidesFile = "guides_" . $file . ".json";
    $guidesPath = $overlayDir . "/" . $guidesFile;
    $roadnetBackup = file_exists($roadnetPath) ? file_get_contents($roadnetPath) : null;
    $liveBackup = file_exists($LIVE_MAP_PATH) ? file_get_contents($LIVE_MAP_PATH) : null;
    $releasesBackup = file_exists($RELEASES_PATH) ? file_get_contents($RELEASES_PATH) : null;

    $publishedAt = time();
    $version = date("YmdHis") . "_" . substr(md5($file . "|" . microtime(true)), 0, 8);
    $manifest = [
      "ok" => true,
      "modelFile" => $file,
      "roadnetFile" => $roadnetFile,
      "routesFile" => $routesFile,
      "guidesFile" => $guidesFile,
      "version" => $version,
      "publishedAt" => $publishedAt
    ];
    try {
      if (!file_exists($roadnetPath)) {
        $emptyRoadnet = [
          "ok" => true,
          "model" => $file,
          "updated" => time(),
          "roads" => []
        ];
        if (!safe_atomic_write_json($roadnetPath, $emptyRoadnet)) {
          throw new RuntimeException("Failed to initialize roadnet file");
        }
      }

      if (!file_exists($routesPath)) {
        $emptyRoutes = [
          "ok" => true,
          "model" => $file,
          "updated" => time(),
          "routes" => new stdClass()
        ];
        if (!safe_atomic_write_json($routesPath, $emptyRoutes)) {
          throw new RuntimeException("Failed to initialize routes file");
        }
      }

      if (!file_exists($guidesPath)) {
        $emptyGuides = [
          "ok" => true,
          "model" => $file,
          "updated" => time(),
          "entries" => new stdClass()
        ];
        if (!safe_atomic_write_json($guidesPath, $emptyGuides)) {
          throw new RuntimeException("Failed to initialize guides file");
        }
      }

      if (!safe_atomic_write_json($LIVE_MAP_PATH, $manifest)) {
        throw new RuntimeException("Failed to write live map manifest");
      }

      $history = [];
      if (file_exists($RELEASES_PATH)) {
        $old = json_decode(file_get_contents($RELEASES_PATH), true);
        if (is_array($old)) $history = $old;
      }
      array_unshift($history, [
        "file" => $file,
        "roadnetFile" => $roadnetFile,
        "routesFile" => $routesFile,
        "guidesFile" => $guidesFile,
        "version" => $version,
        "publishedAt" => $publishedAt
      ]);
      if (count($history) > 100) $history = array_slice($history, 0, 100);
      if (!safe_atomic_write_json($RELEASES_PATH, $history)) {
        throw new RuntimeException("Failed to update publish history");
      }
    } catch (Throwable $e) {
      restore_file_from_backup($roadnetPath, $roadnetBackup);
      restore_file_from_backup($LIVE_MAP_PATH, $liveBackup);
      restore_file_from_backup($RELEASES_PATH, $releasesBackup);
      json_error_and_exit(500, "Publish failed: " . $e->getMessage());
    }

    app_log("info", "Map published", [
      "action" => $action,
      "modelFile" => $file,
      "version" => $version,
      "publishedAt" => $publishedAt,
      "roadnetFile" => $roadnetFile,
      "routesFile" => $routesFile,
      "guidesFile" => $guidesFile,
    ], [
      "subsystem" => "map_editor",
      "event" => "publish_map",
    ]);
    echo json_encode(["ok" => true, "published" => $manifest], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "load_overlay") {
    if (file_exists($OVERLAY_PATH)) {
      echo file_get_contents($OVERLAY_PATH);
    } else {
      echo json_encode(["version" => 1, "items" => []], JSON_PRETTY_PRINT);
    }
    exit;
  }

  if ($action === "save_overlay") {

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data["items"]) || !is_array($data["items"])) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
      exit;
    }
    foreach ($data["items"] as $idx => $item) {
      if (!validate_overlay_item($item, $itemError)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "items[{$idx}] invalid: " . ($itemError ?: "Malformed item")], JSON_PRETTY_PRINT);
        exit;
      }
    }

    $overlayDir = dirname($OVERLAY_PATH);
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    if (!safe_atomic_write_json($OVERLAY_PATH, $data)) {
      json_error_and_exit(500, "Failed to save overlay file");
    }
    app_log("info", "Overlay saved", [
      "action" => $action,
      "itemCount" => count($data["items"]),
    ], [
      "subsystem" => "map_editor",
      "event" => "save_overlay",
    ]);
    echo json_encode(["ok" => true]);
    exit;
  }

  if ($action === "export_glb") {

    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $sourceRaw = isset($_GET["sourceModel"]) ? trim((string)$_GET["sourceModel"]) : "";
    $sourceModel = $sourceRaw !== "" ? basename($sourceRaw) : "";
    if ($sourceModel !== "" && !preg_match('/\.glb$/i', $sourceModel)) {
      json_error_and_exit(400, "Invalid source model");
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      $safe = "tnts_map_export_" . date("Ymd_His") . ".glb";
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $exportDir = __DIR__ . "/../models";
    if (!is_dir($exportDir)) mkdir($exportDir, 0775, true);

    $base = preg_replace('/\.glb$/i', '', $safe);
    $finalName = $safe;
    $path = $exportDir . "/" . $finalName;
    $i = 1;
    while (file_exists($path)) {
      $finalName = $base . "_" . $i . ".glb";
      $path = $exportDir . "/" . $finalName;
      $i++;
    }

    $raw = file_get_contents("php://input");
    $contentType = strtolower((string)($_SERVER["CONTENT_TYPE"] ?? ""));
    if ($contentType !== "" && strpos($contentType, "model/gltf-binary") === false && strpos($contentType, "application/octet-stream") === false) {
      json_error_and_exit(415, "Unsupported content type");
    }
    if ($raw === false || !is_valid_glb_binary($raw)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Empty or invalid GLB"]);
      exit;
    }

    if (!safe_atomic_write_bytes($path, $raw)) {
      json_error_and_exit(500, "Failed to save GLB file");
    }

    $snapshotWarning = null;
    try {
      editor_ensure_snapshot_schema($conn);
      if ($sourceModel !== "" && strcasecmp($sourceModel, $finalName) !== 0) {
        $hash = @hash_file("sha256", $path);
        if (!is_string($hash) || $hash === "") {
          throw new RuntimeException("Failed to hash exported model");
        }
        $conn->begin_transaction();
        try {
          $versionId = editor_get_or_create_version_id($conn, $finalName, $hash);
          editor_clone_model_snapshot($conn, $sourceModel, $finalName, $versionId);
          harmony_set_model_parent($conn, $finalName, $sourceModel);
          editor_refresh_version_totals($conn, $versionId, $finalName);
          $conn->commit();
        } catch (Throwable $e) {
          $conn->rollback();
          throw $e;
        }
      }
    } catch (Throwable $e) {
      $snapshotWarning = $e->getMessage();
    }

    app_log("info", "GLB exported", [
      "action" => $action,
      "sourceModel" => $sourceModel,
      "exportedFile" => $finalName,
      "snapshotWarning" => $snapshotWarning,
      "byteLength" => strlen($raw),
    ], [
      "subsystem" => "map_editor",
      "event" => "export_glb",
    ]);
    echo json_encode([
      "ok" => true,
      "file" => $finalName,
      "path" => "models/" . $finalName,
      "snapshotWarning" => $snapshotWarning
    ], JSON_PRETTY_PRINT);
    exit;
  }

  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Unknown action"]);
  exit;
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Map Editor", "mapeditor");
?>

<style>
  .topbar {
    display: none !important;
  }
  .admin-shell {
    height: 100vh !important;
  }
  .sidebar {
    height: 100vh !important;
    overflow: hidden !important;
  }
  .main {
    padding: 0 !important;
    height: 100vh !important;
  }
  .content {
    margin-top: 0 !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
    height: 100% !important;
  }
  .map-box {
    margin-top: 0 !important;
    border: none !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    height: 100vh !important;
  }
  .me-layout {
    --side-w: 360px;
    position: relative;
    height: 100% !important;
  }
  .me-stage-wrap {
    position: relative;
    height: 100% !important;
  }
  .me-layout.me-collapsed {
    --side-w: 360px;
  }
  .me-float-sidebar {
    position: absolute;
    top: 12px;
    right: 12px;
    width: var(--side-w, 360px);
    height: 100%;
    z-index: 60;
  }
  .me-layout.me-collapsed .me-float-sidebar {
    display: none !important;
  }
  .me-float-toggle {
    position: absolute;
    right: 14px;
    top: 14px;
    z-index: 70;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    background: #fff;
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    display: none;
  }
  .me-sidebar {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 10px;
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.02);
  }
  .me-sidebar .map-title {
    font-size: 12px !important;
    font-weight: 900 !important;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #111827;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 6px 8px;
    margin-bottom: 8px !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }
  .me-sidebar-toggle {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    background: #fff;
    font-weight: 900;
    cursor: pointer;
  }
  .me-section-title {
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: #111827;
    margin-bottom: 6px;
  }
  .me-subtext {
    font-size: 12px;
    color: #6b7280;
    font-weight: 700;
    line-height: 1.45;
  }
  .me-sidebar hr {
    margin: 10px 0 !important;
    border: none !important;
    border-top: 1px solid #e5e7eb !important;
  }
  .me-sidebar .btn {
    padding: 6px 10px !important;
    border-radius: 8px !important;
    border: 1px solid #e5e7eb !important;
    background: #fff !important;
    font-weight: 800 !important;
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.02);
  }
  .me-sidebar .btn:hover {
    background: #f8fafc !important;
  }
  .me-sidebar .btn:disabled {
    opacity: 0.6 !important;
  }
  .me-sidebar input[type="text"],
  .me-sidebar input[type="range"],
  .me-sidebar select {
    border-radius: 8px !important;
    border: 1px solid #e5e7eb !important;
    padding: 6px 8px !important;
    background: #fff !important;
    font-weight: 700 !important;
  }
  .me-sidebar #building-list button,
  .me-sidebar #asset-list button {
    padding: 8px 10px !important;
    border-radius: 8px !important;
    border: 1px solid #e5e7eb !important;
    background: #fff !important;
    font-weight: 800 !important;
    text-align: left !important;
    cursor: pointer !important;
  }
  .me-sidebar #building-list button:hover,
  .me-sidebar #asset-list button:hover {
    background: #f8fafc !important;
  }
  .me-sidebar #building-list,
  .me-sidebar #asset-list {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 6px;
  }
  .me-sidebar #road-controls {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
  }
  .me-sidebar .me-chip {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 4px 6px;
    font-size: 11px;
    font-weight: 800;
    color: #374151;
  }
  .me-tool-buttons .btn {
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    font-size: 0 !important;
    position: relative;
  }
  .me-tool-buttons .btn::after {
    content: attr(data-short);
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 900;
    color: #111827;
  }
  .me-tool-buttons .btn:disabled::after {
    color: #9ca3af;
  }
  .me-viewport-wrap {
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
  }
  .me-viewport-wrap .map-box,
  .me-viewport-wrap .card {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
  }
  .me-route-banner {
    position: absolute;
    top: 14px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 55;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(17,24,39,0.88);
    color: #fff;
    font-weight: 900;
    font-size: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    box-shadow: 0 6px 18px rgba(0,0,0,0.15);
    display: none;
    pointer-events: none;
  }
  .me-guide-box {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f8fafc;
    padding: 10px;
    display: grid;
    gap: 10px;
  }
  .me-guide-meta {
    display: grid;
    gap: 4px;
    font-size: 12px;
    font-weight: 800;
    color: #374151;
  }
  .me-guide-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #e5e7eb;
    color: #111827;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  .me-guide-status[data-state="ok"] {
    background: #dcfce7;
    color: #166534;
  }
  .me-guide-status[data-state="stale"] {
    background: #fef3c7;
    color: #92400e;
  }
  .me-guide-status[data-state="missing_route"],
  .me-guide-status[data-state="orphaned"] {
    background: #fee2e2;
    color: #991b1b;
  }
  .me-guide-preview,
  .me-guide-editor {
    border: 1px solid #dbe3ec;
    border-radius: 10px;
    background: #fff;
    padding: 10px;
  }
  .me-guide-preview {
    max-height: 180px;
    overflow: auto;
  }
  .me-guide-steps {
    display: grid;
    gap: 8px;
  }
  .me-guide-step {
    display: grid;
    grid-template-columns: 24px 1fr;
    gap: 8px;
    align-items: start;
    color: #111827;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
  }
  .me-guide-step-num {
    width: 24px;
    height: 24px;
    border-radius: 999px;
    background: #111827;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 900;
  }
  .me-guide-editor textarea {
    width: 100%;
    min-height: 140px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: 10px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
    resize: vertical;
    background: #fff;
  }
  .me-guide-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .me-modal {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 80;
    pointer-events: none;
  }
  .me-modal.active {
    display: flex;
    pointer-events: auto;
  }
  .me-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.35);
  }
  .me-modal-card {
    position: relative;
    z-index: 1;
    min-width: 260px;
    max-width: 360px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 18px 40px rgba(0,0,0,0.18);
    text-align: center;
  }
  .me-modal-title {
    font-weight: 900;
    font-size: 13px;
    color: #111827;
    margin-bottom: 6px;
  }
  .me-modal-text {
    font-size: 12px;
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 12px;
    white-space: pre-line;
    text-align: left;
  }
  .me-modal-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
  }
  .me-modal-actions .btn {
    padding: 8px 12px !important;
    border-radius: 10px !important;
    border: 1px solid #e5e7eb !important;
    background: #fff !important;
    font-weight: 900 !important;
  }
  .me-modal-actions .btn-danger {
    background: #ef4444 !important;
    border-color: #ef4444 !important;
    color: #fff !important;
  }
</style>

<div class="map-box me-viewport-wrap">
  <div id="map-layout" class="me-layout" style="padding:0; align-items:stretch;">
    <div class="me-stage-wrap">
      <div id="map-stage" style="width:100%; height: 100%; background:#f3f4f6; border-radius:14px; overflow:hidden; position:relative;"></div>
      <button id="sidebar-float-toggle" class="me-float-toggle" type="button" title="Show panel">&#9776;</button>

      <!-- Special points panel -->
      <div id="special-points-panel"
           style="position:absolute; left:14px; top:14px; z-index:40;
                  width:260px; max-height:45vh; overflow:auto;
                  background:rgba(255,255,255,0.96);
                  border:1px solid #e5e7eb; border-radius:12px;
                  padding:10px 12px; box-shadow:0 8px 20px rgba(0,0,0,0.08);">
        <div style="font-weight:900; font-size:12px; color:#111827; margin-bottom:6px;">Special Points</div>
        <div id="special-points-list" style="font-size:12px; color:#374151; font-weight:700; line-height:1.45;"></div>
      </div>

      <div id="route-banner" class="me-route-banner">Route</div>

      <div id="delete-confirm" class="me-modal" aria-hidden="true">
        <div class="me-modal-backdrop"></div>
        <div class="me-modal-card" role="dialog" aria-modal="true" aria-labelledby="delete-confirm-title">
          <div id="delete-confirm-title" class="me-modal-title">Delete item?</div>
          <div class="me-modal-text" id="delete-confirm-text">This action cannot be undone.</div>
          <div class="me-modal-actions">
            <button id="delete-confirm-no" class="btn" type="button">Cancel</button>
            <button id="delete-confirm-yes" class="btn btn-danger" type="button">Delete</button>
          </div>
        </div>
      </div>

      <!-- Angle readout -->
      <div id="angle-readout"
           style="position:absolute; left:0; top:0; display:none; pointer-events:none;
                  transform: translate(12px, 12px);
                  padding:6px 8px; border-radius:10px;
                  font-weight:900; font-size:12px;
                  background:rgba(0,0,0,0.55);
                  border:1px solid rgba(255,255,255,0.20);
                  color:#fff; z-index:50;">
        0.0Ã‚Â°
      </div>

      <div style="position:absolute; left:12px; bottom:12px; background:rgba(255,255,255,0.9); padding:8px 10px; border-radius:12px; font-weight:800; color:#374151; border:1px solid #e5e7eb;">
        Hover highlights (temporary). Tap/click selects (stays). Move requires Move tool active. Two-finger zoom/pan.
      </div>
    </div>

    <div class="me-sidebar me-float-sidebar" style="height: 100%; overflow:auto;">
      <div class="map-title me-sidebar-header">
        <span>3D Map Editor</span>
        <button id="sidebar-toggle" class="me-sidebar-toggle" type="button" title="Collapse panel">&#9656;</button>
      </div>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:10px;">
        <button id="btn-undo" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Undo</button>
        <button id="btn-redo" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Redo</button>
        <button id="btn-commit" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Lock</button>
        <button id="btn-edit" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Unlock</button>
        <button id="btn-top" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Top View</button>
        <button id="btn-reset" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Reset View</button>
      </div>

      <div id="status" class="me-subtext" style="font-weight:900; margin-bottom:12px;">Booting&hellip;</div>
      <div id="dirty-indicator" class="me-subtext" style="font-weight:800; margin:-8px 0 12px 0; color:#6b7280;">Saved</div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div class="me-section-title">Base Model</div>
      <select id="base-model-select" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; font-weight:700; margin-bottom:6px;"></select>
      <div style="display:flex; gap:8px; margin-bottom:6px;">
        <button id="base-model-default" class="btn" type="button" style="padding:6px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Set Default</button>
      </div>
      <div id="base-model-note" class="me-subtext" style="margin-bottom:10px;">
        Switch between original and exported maps. Exported maps skip overlay assets but still load editable roads from per-model roadnet.
      </div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div class="me-section-title">Buildings</div>
      <div id="building-selected" class="me-subtext" style="margin-bottom:6px;">Selected: None</div>
      <input id="building-filter" type="text" placeholder="Filter buildings" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; font-weight:700; margin-bottom:8px;">
      <div id="building-list" style="display:flex; flex-direction:column; gap:6px; max-height:180px; overflow:auto;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div class="me-section-title">Text Guides</div>
      <div class="me-subtext" style="margin-bottom:8px;">
        Auto-generate turn text from the current road network, then review or override it manually per destination.
      </div>
      <div class="me-guide-box">
        <select id="guide-target-select" style="width:100%;"></select>
        <div class="me-guide-meta">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
            <span>Guide Status</span>
            <span id="guide-status-badge" class="me-guide-status" data-state="ok">OK</span>
          </div>
          <div id="guide-status-text" class="me-subtext" style="margin:0;">No destination selected.</div>
          <div id="guide-route-summary" class="me-subtext" style="margin:0;">Route summary unavailable.</div>
        </div>
        <label class="me-subtext" style="font-weight:900; color:#111827;">Auto-generated preview</label>
        <div id="guide-auto-preview" class="me-guide-preview">
          <div class="me-subtext">No guide available yet.</div>
        </div>
        <div class="me-guide-editor">
          <div class="me-subtext" style="font-weight:900; color:#111827; margin-bottom:8px;">Manual override</div>
          <textarea id="guide-manual-text" placeholder="One instruction per line. Example:&#10;Start at the kiosk.&#10;Go straight.&#10;Turn right near Admin Building.&#10;Arrive at AP LRC."></textarea>
        </div>
        <div class="me-guide-actions">
          <button id="guide-use-auto" class="btn" type="button">Use Auto</button>
          <button id="guide-fill-auto" class="btn" type="button">Copy Auto To Editor</button>
          <button id="guide-save-manual" class="btn" type="button">Apply Manual Text</button>
          <button id="guide-clear-manual" class="btn" type="button">Clear Manual</button>
        </div>
      </div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div class="me-section-title">Assets</div>
      <div id="asset-list" style="display:flex; flex-direction:column; gap:8px;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div class="me-section-title">Tools</div>
      <div id="tool-indicator" class="me-subtext" style="font-weight:800; margin-bottom:10px;">Active tool: None</div>
      <div class="me-tool-buttons" style="display:flex; gap:8px; flex-wrap:wrap;">
        <button id="tool-move" class="btn" type="button" data-short="M" title="Move" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Move</button>
        <button id="tool-rotate" class="btn" type="button" data-short="R" title="Rotate" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Rotate</button>
        <button id="tool-scale" class="btn" type="button" data-short="S" title="Scale" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Scale</button>
        <button id="tool-road" class="btn" type="button" data-short="Rd" title="Road" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Road</button>
        <button id="tool-delete" class="btn" type="button" data-short="Del" title="Delete" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Delete</button>
        <button id="tool-save" class="btn" type="button" data-short="Sv" title="Save" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Save</button>
        <button id="tool-export" class="btn" type="button" data-short="GLB" title="Export GLB" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Export GLB</button>
        <button id="tool-publish" class="btn" type="button" data-short="Pub" title="Publish Current Map" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Publish</button>
        <button id="tool-cancel" class="btn" type="button" data-short="X" title="Cancel Tool" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;">Cancel Tool</button>
      </div>

      <div id="road-controls" style="display:none; margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
        <div class="me-section-title">Road Tool</div>
        <div class="me-subtext" style="margin-bottom:10px;">
          Tap the map to place a road point. Tap a selected road to insert a point between existing nodes. Drag a road point to draw a segment (Draw) or reposition it (Move). Use two-finger gestures to pan/zoom.
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px;">
          <div class="me-subtext" style="font-weight:900;">Width</div>
          <div id="road-width-readout" class="me-subtext" style="font-weight:900;">12</div>
        </div>
        <input id="road-width" type="range" min="2" max="60" step="1" value="12" style="width:100%;">

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
          <button id="road-finish" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Finish Road</button>
          <button id="road-cancel" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Cancel Draft</button>
          <button id="road-new" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">New Road</button>
          <button id="road-snap" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Snap: On</button>
          <button id="road-building-snap" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Attach: Off</button>
          <button id="road-auto-intersect" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Auto-Intersect: Off</button>
          <button id="road-drag-mode" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Drag: Draw</button>
          <button id="road-kiosk" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Kiosk Start</button>
        </div>

        <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
          <div class="me-subtext" style="font-weight:800; margin-bottom:10px;">
            Selected point: <span id="road-point-selected" style="font-weight:900; color:#111827;">None</span>
            <span style="margin-left:10px;">Extend from: <span id="road-extend-from" style="font-weight:900; color:#111827;">Auto</span></span>
          </div>
        </div>
      </div>
    </div>
  </div>
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
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { TransformControls } from "three/addons/controls/TransformControls.js";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { GLTFExporter } from "three/addons/exporters/GLTFExporter.js";
import { buildGuideKey, buildGuideStepsFromPoints, buildRouteSignature } from "../js/guide-utils.js";
import { buildRoomNormalizationContext, classifyTopLevelObjectName, parseRoomObjectName } from "../js/map-entity-utils.js";

const CSRF_TOKEN = <?= json_encode($MAP_EDITOR_CSRF) ?>;
const MAP_IMPORT_CSRF_TOKEN = <?= json_encode($MAP_IMPORT_CSRF) ?>;

// Allow editing base-model objects in the editor.
const ALLOW_EDIT_BASE_MODEL = true;

// REQUIRE pressing a tool button before tool works
let currentTool = "none"; // "none" | "move" | "rotate" | "scale" | "road"

// Roads (generated geometry)
const ROAD_Y_OFFSET = 0.8;
const ROAD_DEFAULT_WIDTH = 12;
const ROAD_SNAP_STEP = 10;
const ROAD_SNAP_FINE_DEG = 5; // when Snap is Off, lock angle to 5Ã‚Â° increments
const ROAD_MIN_POINT_DIST = 2;
const ROAD_HANDLE_BASE_SIZE = 3.5;
const ROAD_MITER_LIMIT = 2.5;
const ROAD_BUILDING_SNAP_DIST = 18; // world units (XZ) to snap a dragged road point to a building side
const ROAD_BUILDING_GAP = 0; // extra clearance between road edge and building wall (0 = touch)
const ROAD_AUTO_INTERSECT_DIST = 8; // endpoint merge distance
const ROAD_THICKNESS = 0.3; // vertical thickness of road mesh

let roadSnapEnabled = true;
let roadBuildingSnapEnabled = false;
let roadAutoIntersectEnabled = false;
let roadDragMode = "draw"; // "draw" | "move" (draw creates a new segment from a point; move drags an existing point)
let overlayReady = false;
let roadDraft = null; // { points: THREE.Vector3[], width: number, mesh: THREE.Mesh|null }
let isDraggingRoadHandle = false;
let roadHandleDrag = null; // { road: THREE.Object3D, index: number, pointerId: number, beforePoints: number[][], beforeWidth: number }
let roadTap = null; // { pointerId: number, startX: number, startY: number, hadMultiTouch: boolean }
let isDraggingRoadSegment = false;
let roadSegmentDrag = null; // { road: THREE.Object3D, startIndex: number, pointerId: number, startX: number, startY: number, startWorld: THREE.Vector3, lastEndWorld: THREE.Vector3|null, beforePoints: number[][], beforeWidth: number }
let roadSelectedPointIndex = null; // number (0-based) or null
let roadExtendFrom = null; // "start" | "end" | null (overrides nearest-end extension)
let roadExtendFromRoadId = null; // road id string|null (guards extend-from state)
let roadKioskPlaceMode = false;

const statusEl = document.getElementById("status");
const dirtyIndicatorEl = document.getElementById("dirty-indicator");
const mapStage = document.getElementById("map-stage");
const angleReadoutEl = document.getElementById("angle-readout");
const layoutEl = document.getElementById("map-layout");
const sidebarToggleBtn = document.getElementById("sidebar-toggle");
const sidebarFloatToggleBtn = document.getElementById("sidebar-float-toggle");

const btnReset = document.getElementById("btn-reset");
const btnTop = document.getElementById("btn-top");

 const btnUndo = document.getElementById("btn-undo");
 const btnRedo = document.getElementById("btn-redo");
 const btnCommit = document.getElementById("btn-commit");
 const btnEdit = document.getElementById("btn-edit");

const assetListEl = document.getElementById("asset-list");
const baseModelSelect = document.getElementById("base-model-select");
const baseModelNote = document.getElementById("base-model-note");
const baseModelDefaultBtn = document.getElementById("base-model-default");
const buildingListEl = document.getElementById("building-list");
const buildingFilterEl = document.getElementById("building-filter");
const buildingSelectedEl = document.getElementById("building-selected");
const guideTargetSelectEl = document.getElementById("guide-target-select");
const guideStatusBadgeEl = document.getElementById("guide-status-badge");
const guideStatusTextEl = document.getElementById("guide-status-text");
const guideRouteSummaryEl = document.getElementById("guide-route-summary");
const guideAutoPreviewEl = document.getElementById("guide-auto-preview");
const guideManualTextEl = document.getElementById("guide-manual-text");
const guideUseAutoBtn = document.getElementById("guide-use-auto");
const guideFillAutoBtn = document.getElementById("guide-fill-auto");
const guideSaveManualBtn = document.getElementById("guide-save-manual");
const guideClearManualBtn = document.getElementById("guide-clear-manual");

const toolMove = document.getElementById("tool-move");
const toolRotate = document.getElementById("tool-rotate");
const toolScale = document.getElementById("tool-scale");
const toolRoad = document.getElementById("tool-road");
const toolDelete = document.getElementById("tool-delete");
const toolSave = document.getElementById("tool-save");
const toolExport = document.getElementById("tool-export");
const toolPublish = document.getElementById("tool-publish");
const toolCancel = document.getElementById("tool-cancel");
const toolIndicator = document.getElementById("tool-indicator");

const roadControls = document.getElementById("road-controls");
const roadWidthEl = document.getElementById("road-width");
const roadWidthReadoutEl = document.getElementById("road-width-readout");
const roadFinishBtn = document.getElementById("road-finish");
const roadCancelBtn = document.getElementById("road-cancel");
const roadNewBtn = document.getElementById("road-new");
const roadSnapBtn = document.getElementById("road-snap");
const roadBuildingSnapBtn = document.getElementById("road-building-snap");
const roadAutoIntersectBtn = document.getElementById("road-auto-intersect");
const roadDragModeBtn = document.getElementById("road-drag-mode");
const roadKioskBtn = document.getElementById("road-kiosk");
const roadPointSelectedEl = document.getElementById("road-point-selected");
const roadExtendFromEl = document.getElementById("road-extend-from");

const specialPointsPanel = document.getElementById("special-points-panel");
const specialPointsListEl = document.getElementById("special-points-list");
const routeBannerEl = document.getElementById("route-banner");
const deleteConfirmEl = document.getElementById("delete-confirm");
const deleteConfirmTitleEl = document.getElementById("delete-confirm-title");
const deleteConfirmTextEl = document.getElementById("delete-confirm-text");
const deleteConfirmYesBtn = document.getElementById("delete-confirm-yes");
const deleteConfirmNoBtn = document.getElementById("delete-confirm-no");

let buildingKbTargetInput = null;
let buildingKbShift = false;

function ensureBuildingKeyboardStyles() {
  if (document.getElementById("me-kb-styles")) return;

  const style = document.createElement("style");
  style.id = "me-kb-styles";
  style.textContent = `
    #me-kb-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.35);
      display: none;
      align-items: flex-end;
      justify-content: center;
      z-index: 9999;
      touch-action: manipulation;
    }
    #me-kb {
      width: min(900px, 98vw);
      background: #f3f3f3;
      border-top-left-radius: 16px;
      border-top-right-radius: 16px;
      box-shadow: 0 -10px 36px rgba(0, 0, 0, 0.25);
      padding: 12px 12px 14px;
      user-select: none;
    }
    #me-kb .kb-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 0 2px 8px;
    }
    #me-kb .kb-title {
      font-size: 13px;
      font-weight: 900;
      color: #222;
      letter-spacing: 0.2px;
    }
    #me-kb .kb-actions {
      display: flex;
      gap: 8px;
    }
    .me-kb-btn {
      border: 1px solid #c9c9c9;
      background: #fff;
      border-radius: 9px;
      padding: 8px 10px;
      font-size: 13px;
      font-weight: 900;
      min-width: 60px;
      cursor: pointer;
      touch-action: manipulation;
    }
    .me-kb-btn:active {
      transform: translateY(1px);
    }
    #me-kb .kb-rows {
      display: grid;
      gap: 8px;
    }
    .me-kb-row {
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 1fr;
      gap: 7px;
    }
    .me-kb-key {
      border: 1px solid #bdbdbd;
      background: #fff;
      border-radius: 10px;
      padding: 12px 0;
      font-size: 15px;
      font-weight: 900;
      text-align: center;
      cursor: pointer;
      touch-action: manipulation;
    }
    .me-kb-key:active {
      background: #ececec;
      transform: translateY(1px);
    }
    .me-kb-key.wide {
      grid-column: span 2;
    }
    .me-kb-key.space {
      grid-column: span 5;
    }
    .me-kb-key.primary {
      background: #1f2937;
      color: #fff;
      border-color: #1f2937;
    }
    .me-kb-key.primary:active {
      filter: brightness(0.95);
    }
    @media (max-width: 520px) {
      .me-kb-key {
        font-size: 13px;
        padding: 11px 0;
      }
      .me-kb-btn {
        font-size: 12px;
        padding: 8px 9px;
      }
    }
  `;
  document.head.appendChild(style);
}

function createBuildingKeyboard() {
  ensureBuildingKeyboardStyles();
  if (document.getElementById("me-kb-overlay")) return;

  const overlay = document.createElement("div");
  overlay.id = "me-kb-overlay";

  const kb = document.createElement("div");
  kb.id = "me-kb";
  kb.innerHTML = `
    <div class="kb-top">
      <div class="kb-title">Building Filter Keyboard</div>
      <div class="kb-actions">
        <button class="me-kb-btn" data-kb-action="clear" type="button">CLEAR</button>
        <button class="me-kb-btn" data-kb-action="close" type="button">CLOSE</button>
      </div>
    </div>
    <div class="kb-rows">
      <div class="me-kb-row">
        ${"QWERTYUIOP".split("").map((k) => `<div class="me-kb-key" data-kb-key="${k}">${k}</div>`).join("")}
      </div>
      <div class="me-kb-row">
        ${"ASDFGHJKL".split("").map((k) => `<div class="me-kb-key" data-kb-key="${k}">${k}</div>`).join("")}
        <div class="me-kb-key wide" data-kb-action="backspace">BACK</div>
      </div>
      <div class="me-kb-row">
        <div class="me-kb-key wide" data-kb-action="shift">SHIFT</div>
        ${"ZXCVBNM".split("").map((k) => `<div class="me-kb-key" data-kb-key="${k}">${k}</div>`).join("")}
        <div class="me-kb-key wide" data-kb-action="enter">ENTER</div>
      </div>
      <div class="me-kb-row">
        <div class="me-kb-key wide" data-kb-key="-">-</div>
        <div class="me-kb-key wide" data-kb-key="'">'</div>
        <div class="me-kb-key space" data-kb-action="space">SPACE</div>
        <div class="me-kb-key wide primary" data-kb-action="done">DONE</div>
      </div>
    </div>
  `;

  overlay.appendChild(kb);
  document.body.appendChild(overlay);

  overlay.addEventListener("pointerdown", (e) => {
    if (e.target === overlay) hideBuildingKeyboard();
  }, { passive: false });
}

function ensureInputFocusAndCaretEnd(inputEl) {
  if (!inputEl) return;
  try {
    inputEl.focus({ preventScroll: true });
  } catch (_) {
    try { inputEl.focus(); } catch (_) {}
  }

  const endPos = inputEl.value.length;
  try { inputEl.setSelectionRange(endPos, endPos); } catch (_) {}
}

function getInputCaretSafe(inputEl) {
  const len = inputEl.value.length;
  let start = Number.isFinite(inputEl.selectionStart) ? inputEl.selectionStart : len;
  let end = Number.isFinite(inputEl.selectionEnd) ? inputEl.selectionEnd : len;
  start = Math.max(0, Math.min(start, len));
  end = Math.max(0, Math.min(end, len));
  return { start, end };
}

function emitFilterInputEvent(inputEl) {
  if (!inputEl) return;
  inputEl.dispatchEvent(new Event("input", { bubbles: true }));
}

function insertInputText(inputEl, text) {
  if (!inputEl) return;
  ensureInputFocusAndCaretEnd(inputEl);
  const { start, end } = getInputCaretSafe(inputEl);
  const before = inputEl.value.slice(0, start);
  const after = inputEl.value.slice(end);
  inputEl.value = before + text + after;
  const nextPos = start + text.length;
  try { inputEl.setSelectionRange(nextPos, nextPos); } catch (_) {}
  emitFilterInputEvent(inputEl);
}

function backspaceInputText(inputEl) {
  if (!inputEl) return;
  ensureInputFocusAndCaretEnd(inputEl);
  const { start, end } = getInputCaretSafe(inputEl);
  if (start !== end) {
    inputEl.value = inputEl.value.slice(0, start) + inputEl.value.slice(end);
    try { inputEl.setSelectionRange(start, start); } catch (_) {}
  } else if (start > 0) {
    inputEl.value = inputEl.value.slice(0, start - 1) + inputEl.value.slice(start);
    try { inputEl.setSelectionRange(start - 1, start - 1); } catch (_) {}
  }
  emitFilterInputEvent(inputEl);
}

function clearInputText(inputEl) {
  if (!inputEl) return;
  inputEl.value = "";
  emitFilterInputEvent(inputEl);
  ensureInputFocusAndCaretEnd(inputEl);
}

function hideBuildingKeyboard() {
  const overlay = document.getElementById("me-kb-overlay");
  if (overlay) overlay.style.display = "none";
  buildingKbShift = false;
}

function showBuildingKeyboardFor(inputEl) {
  createBuildingKeyboard();
  buildingKbTargetInput = inputEl;
  const overlay = document.getElementById("me-kb-overlay");
  if (overlay) overlay.style.display = "flex";
  ensureInputFocusAndCaretEnd(inputEl);
}

function selectFirstFilteredBuilding() {
  const first = buildingListEl?.querySelector?.("button");
  if (!first) return false;
  first.click();
  return true;
}

function handleBuildingKeyboardPress(e) {
  const keyEl = e.target.closest("[data-kb-key], [data-kb-action]");
  if (!keyEl || !buildingKbTargetInput) return;

  e.preventDefault();
  e.stopPropagation();

  ensureInputFocusAndCaretEnd(buildingKbTargetInput);

  const action = keyEl.getAttribute("data-kb-action");
  const key = keyEl.getAttribute("data-kb-key");

  if (action) {
    switch (action) {
      case "close":
        hideBuildingKeyboard();
        return;
      case "done":
      case "enter":
        refreshBuildingList();
        selectFirstFilteredBuilding();
        hideBuildingKeyboard();
        return;
      case "clear":
        clearInputText(buildingKbTargetInput);
        return;
      case "backspace":
        backspaceInputText(buildingKbTargetInput);
        return;
      case "space":
        insertInputText(buildingKbTargetInput, " ");
        return;
      case "shift":
        buildingKbShift = !buildingKbShift;
        return;
      default:
        return;
    }
  }

  if (key) {
    const char = buildingKbShift ? key.toUpperCase() : key.toLowerCase();
    insertInputText(buildingKbTargetInput, char);
    if (buildingKbShift) buildingKbShift = false;
  }
}

function setupBuildingFilterKeyboard() {
  if (!buildingFilterEl) return;

  buildingFilterEl.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter") return;
    ev.preventDefault();
    refreshBuildingList();
    selectFirstFilteredBuilding();
  });

  buildingFilterEl.setAttribute("autocomplete", "off");
  buildingFilterEl.setAttribute("autocorrect", "off");
  buildingFilterEl.setAttribute("autocapitalize", "off");
  buildingFilterEl.setAttribute("spellcheck", "false");
}

function withCsrfHeaders(headers = undefined) {
  const out = new Headers(headers || {});
  out.set("X-CSRF-Token", CSRF_TOKEN);
  return out;
}

function apiFetch(url, options = {}) {
  const opts = { credentials: "same-origin", ...options };
  const method = String(opts.method || "GET").toUpperCase();
  if (method !== "GET" && method !== "HEAD") {
    opts.headers = withCsrfHeaders(opts.headers);
  } else if (typeof opts.cache === "undefined") {
    opts.cache = "no-store";
  }
  return fetch(url, opts);
}

 function setStatus(msg) {
   if (statusEl) statusEl.textContent = msg;
   console.log("[MapEditor]", msg);
 }
 
 function updateCommitButtons() {
   const hasSelection = !!selected;
   const locked = !!selected?.userData?.locked;
 
   if (btnCommit) {
     btnCommit.disabled = !hasSelection || locked;
     btnCommit.style.opacity = btnCommit.disabled ? "0.6" : "1";
   }
   if (btnEdit) {
     btnEdit.disabled = !hasSelection || !locked;
     btnEdit.style.opacity = btnEdit.disabled ? "0.6" : "1";
   }
 }

function showError(title, err) {
  console.error(err);
  if (typeof window.tntsReportClientError === "function") {
    const payload = {
      error: {
        message: String(err?.message || err || ""),
        stack: String(err?.stack || ""),
      }
    };
    if (err && typeof err === "object") {
      if (err.details && typeof err.details === "object") payload.details = err.details;
      if (err.context && typeof err.context === "object") payload.context = err.context;
    }
    window.tntsReportClientError("map_editor_ui", title, payload);
  }
  setStatus(title);
  const pre = document.createElement("pre");
  pre.style.whiteSpace = "pre-wrap";
  pre.style.padding = "12px";
  pre.style.margin = "12px";
  pre.style.background = "#fff";
  pre.style.border = "1px solid #e5e7eb";
  pre.style.borderRadius = "12px";
  pre.textContent = `${title}\n\n${String(err?.message || err)}`;
  mapStage.appendChild(pre);
}

function setActiveToolButton(btn) {
  [toolMove, toolRotate, toolScale, toolRoad].forEach(b => {
    if (!b) return;
    b.style.outline = "none";
    b.style.boxShadow = "none";
  });
  if (btn) {
    btn.style.outline = "2px solid #111827";
    btn.style.outlineOffset = "2px";
  }
}

function updateToolIndicator() {
  if (!toolIndicator) return;
  const labels = { none: "None", move: "Move", rotate: "Rotate", scale: "Scale", road: "Road" };
  const label = labels[currentTool] || "None";
  toolIndicator.textContent = `Active tool: ${label}`;
  if (toolCancel) {
    toolCancel.disabled = currentTool === "none";
    toolCancel.style.opacity = toolCancel.disabled ? "0.6" : "1";
  }
  updateRoadControls();
}

function setSidebarCollapsed(collapsed) {
  if (!layoutEl) return;
  layoutEl.classList.toggle("me-collapsed", !!collapsed);
  if (sidebarToggleBtn) {
    sidebarToggleBtn.textContent = collapsed ? "\u25C0" : "\u25B6";
    sidebarToggleBtn.title = collapsed ? "Expand panel" : "Collapse panel";
  }
  if (sidebarFloatToggleBtn) {
    sidebarFloatToggleBtn.textContent = collapsed ? "\u2630" : "\u00D7";
    sidebarFloatToggleBtn.title = collapsed ? "Show panel" : "Hide panel";
    sidebarFloatToggleBtn.style.display = collapsed ? "block" : "none";
  }
  requestAnimationFrame(() => resize());
}

function updateSpecialPointsPanel() {
  if (!specialPointsPanel || !specialPointsListEl) return;
  if (!overlayReady) return;
  const items = [];
  const seenBuildings = new Set();

  try {
    overlayRoot?.children?.forEach?.((obj) => {
      if (!isRoadObject(obj)) return;
      ensureRoadPointMetaArrays(obj);
      const metaArr = Array.isArray(obj.userData?.road?.pointMeta) ? obj.userData.road.pointMeta : [];
      for (const meta of metaArr) {
        if (isKioskMeta(meta)) continue;
        if (!meta || !meta.building || !meta.building.name) continue;
        const buildingName = String(meta.building.name || "").trim();
        if (!buildingName || seenBuildings.has(buildingName)) continue;
        seenBuildings.add(buildingName);
        const label = meta.label || `${buildingName}_${meta.building.side || "side"}`;
        items.push({ buildingName, label });
      }
    });
  } catch (_) {}

  if (!items.length) {
    specialPointsListEl.textContent = "None";
    return;
  }

  items.sort((a, b) => (a.buildingName || "").localeCompare(b.buildingName || ""));
  specialPointsListEl.textContent = "";
  for (const it of items) {
    const row = document.createElement("div");
    row.textContent = `${it.buildingName} Pathway = ${it.label}`;
    specialPointsListEl.appendChild(row);
  }
}

// -------------------- Routing (kiosk -> building entrance) --------------------
let routeLine = null;
let savedRoutes = {};
const ROUTE_LINE_WIDTH = 1.2;
const ROUTE_LINE_Y_BIAS = 0.2;
const routeMaterial = new THREE.MeshBasicMaterial({
  color: 0xf59e0b,
  transparent: true,
  opacity: 0.9,
  polygonOffset: true,
  polygonOffsetFactor: -8,
  polygonOffsetUnits: -8
});

function setRouteBanner(text) {
  if (!routeBannerEl) return;
  if (!text) {
    routeBannerEl.style.display = "none";
    routeBannerEl.textContent = "";
    return;
  }
  routeBannerEl.textContent = text;
  routeBannerEl.style.display = "block";
}

function clearRouteLine() {
  if (!routeLine) return;
  scene.remove(routeLine);
  routeLine.geometry?.dispose?.();
  routeLine = null;
}

function routeKey(name) {
  return normalizeBuildingName(name);
}

function setSavedRoutes(routes) {
  savedRoutes = normalizeSavedRoutesPayload(routes);
}

function buildBuildingIdentityCandidateKeys({ buildingUid = "", buildingName = "", objectName = "" } = {}) {
  const resolved = resolveBuildingIdentity({ buildingUid, buildingName, objectName });
  const keys = new Set([
    buildBuildingIdentityKey(resolved),
    normalizeBuildingUid(resolved.buildingUid),
    normalizeBuildingObjectName(resolved.objectName),
    routeKey(resolved.buildingName),
    routeKey(resolved.objectName),
    routeKey(buildingName),
    normalizeBuildingObjectName(objectName)
  ].filter(Boolean));
  return Array.from(keys);
}

function buildBuildingIdentityAliasLookup(entries = []) {
  const lookup = new Map();
  for (const rawEntry of (Array.isArray(entries) ? entries : [])) {
    if (!rawEntry || typeof rawEntry !== "object") continue;
    const resolved = resolveBuildingIdentity({
      buildingUid: rawEntry?.buildingUid || rawEntry?.uid || "",
      buildingName: rawEntry?.buildingName || rawEntry?.name || "",
      objectName: rawEntry?.objectName || rawEntry?.modelObjectName || rawEntry?.name || ""
    });
    const entry = {
      ...rawEntry,
      buildingUid: resolved.buildingUid,
      buildingName: String(rawEntry?.buildingName || rawEntry?.name || resolved.buildingName || "").trim(),
      objectName: String(rawEntry?.objectName || rawEntry?.modelObjectName || resolved.objectName || "").trim()
    };
    for (const key of buildBuildingIdentityCandidateKeys(entry)) {
      if (!lookup.has(key)) lookup.set(key, entry);
    }
  }
  return lookup;
}

function findBuildingIdentityLookupMatch(lookup, identity = {}) {
  const candidateKeys = buildBuildingIdentityCandidateKeys(identity);
  for (const key of candidateKeys) {
    if (lookup?.has(key)) {
      return {
        matched: true,
        matchedKey: key,
        candidateKeys,
        entry: lookup.get(key) || null
      };
    }
  }
  return {
    matched: false,
    matchedKey: "",
    candidateKeys,
    entry: null
  };
}

function getSavedRouteEntry(name, opts = {}) {
  const candidateKeys = buildBuildingIdentityCandidateKeys({
    buildingUid: opts?.buildingUid || "",
    buildingName: name,
    objectName: opts?.objectName || name
  });

  for (const key of candidateKeys) {
    if (savedRoutes?.[key]) return savedRoutes[key];
  }

  for (const [rawKey, rawEntry] of Object.entries(savedRoutes || {})) {
    const entry = normalizeRouteEntry(rawKey, rawEntry);
    const entryKeys = new Set(buildBuildingIdentityCandidateKeys({
      buildingUid: entry.buildingUid,
      buildingName: entry.name,
      objectName: entry.objectName || rawKey
    }));
    for (const key of candidateKeys) {
      if (entryKeys.has(key)) return entry;
    }
  }

  return null;
}

function hasLiveRoadData() {
  return !!overlayRoot?.children?.some?.(obj => isRoadObject(obj));
}

function getGuideWorkingEntryForSelection(routes = null) {
  if (!guideSelectionKey) return null;
  const working = buildGuideWorkingEntries({
    routes: routes && typeof routes === "object"
      ? routes
      : buildEditorPreviewRoutes()
  });
  return working[guideSelectionKey] || null;
}

function ensureGuideRawEntryForSelection(entry = null) {
  const workingEntry = entry || getGuideWorkingEntryForSelection();
  if (!workingEntry || !workingEntry.key) return null;
  const rawEntries = getLoadedGuideRawEntries();
  if (!rawEntries[workingEntry.key]) {
    rawEntries[workingEntry.key] = normalizeLoadedGuideEntry(workingEntry.key, workingEntry);
  }
  rawEntries[workingEntry.key].destinationType = workingEntry.destinationType;
  rawEntries[workingEntry.key].buildingName = workingEntry.buildingName;
  rawEntries[workingEntry.key].buildingUid = String(workingEntry.buildingUid || "").trim();
  rawEntries[workingEntry.key].objectName = String(workingEntry.objectName || "").trim();
  rawEntries[workingEntry.key].roomName = workingEntry.roomName;
  return rawEntries[workingEntry.key];
}

guideTargetSelectEl?.addEventListener("change", () => {
  guideSelectionKey = String(guideTargetSelectEl.value || "").trim();
  renderGuideEditorPanel();
});

guideManualTextEl?.addEventListener("input", () => {
  const entry = getGuideWorkingEntryForSelection();
  const raw = ensureGuideRawEntryForSelection(entry);
  if (!raw) return;
  raw.manualText = String(guideManualTextEl.value || "").replace(/\r\n/g, "\n");
  scheduleDirtyRefresh();
});

guideUseAutoBtn?.addEventListener("click", () => {
  const raw = ensureGuideRawEntryForSelection();
  if (!raw) return;
  raw.manualText = "";
  raw.sourceRouteSignature = "";
  if (guideManualTextEl) guideManualTextEl.value = "";
  renderGuideEditorPanel();
  scheduleDirtyRefresh();
  setStatus("Guide reset to auto-generated text");
});

guideFillAutoBtn?.addEventListener("click", () => {
  const entry = getGuideWorkingEntryForSelection();
  const raw = ensureGuideRawEntryForSelection(entry);
  if (!entry || !raw) return;
  const nextText = guideStepsToText(entry.autoSteps);
  raw.manualText = nextText;
  raw.sourceRouteSignature = String(entry.routeSignature || "");
  if (guideManualTextEl) guideManualTextEl.value = nextText;
  renderGuideEditorPanel({ preserveEditorText: true });
  scheduleDirtyRefresh();
  setStatus("Copied auto guide text into the manual editor");
});

guideSaveManualBtn?.addEventListener("click", () => {
  const entry = getGuideWorkingEntryForSelection();
  const raw = ensureGuideRawEntryForSelection(entry);
  if (!entry || !raw) return;
  raw.manualText = String(guideManualTextEl?.value || "").replace(/\r\n/g, "\n");
  raw.sourceRouteSignature = String(entry.routeSignature || "");
  renderGuideEditorPanel();
  scheduleDirtyRefresh();
  setStatus(raw.manualText.trim() ? "Manual guide applied" : "Guide reset to auto-generated text");
});

guideClearManualBtn?.addEventListener("click", () => {
  const raw = ensureGuideRawEntryForSelection();
  if (!raw) return;
  raw.manualText = "";
  raw.sourceRouteSignature = "";
  if (guideManualTextEl) guideManualTextEl.value = "";
  renderGuideEditorPanel();
  scheduleDirtyRefresh();
  setStatus("Manual guide text cleared");
});

function normalizeObjectSet(value) {
  if (value instanceof Set) return value;
  if (Array.isArray(value)) return new Set(value.filter(Boolean));
  return value ? new Set([value]) : new Set();
}

function getRoadObjects(opts = {}) {
  const excludeObjects = normalizeObjectSet(opts.excludeObjects);
  const roads = [];
  for (const obj of (overlayRoot?.children || [])) {
    if (!isRoadObject(obj)) continue;
    if (excludeObjects.has(obj)) continue;
    roads.push(obj);
  }
  return roads;
}

function sortedUniqueStrings(values) {
  return Array.from(new Set((Array.isArray(values) ? values : [])
    .map(v => String(v || "").trim())
    .filter(Boolean)))
    .sort((a, b) => a.localeCompare(b));
}

function formatNameList(names, limit = 5) {
  const items = sortedUniqueStrings(names);
  if (!items.length) return "";
  if (items.length <= limit) return items.join(", ");
  return `${items.slice(0, limit).join(", ")}, +${items.length - limit} more`;
}

function pluralize(count, singular, plural = `${singular}s`) {
  return Number(count) === 1 ? singular : plural;
}

function getSceneBuildingNameMap(opts = {}) {
  const names = new Map();
  const excludeObjects = normalizeObjectSet(opts.excludeObjects);
  if (!campusRoot) return names;

  campusRoot.traverse((obj) => {
    if (!obj) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;
    const root = getTopLevelNamedAncestor(obj);
    if (!root || !root.name) return;
    if (excludeObjects.has(obj) || excludeObjects.has(root)) return;
    const key = normalizeBuildingName(root.name);
    if (!key || names.has(key)) return;
    names.set(key, root.name);
  });

  return names;
}

function collectRouteNameMap(routes) {
  const out = new Map();
  if (!routes || typeof routes !== "object") return out;

  for (const [fallbackKey, entry] of Object.entries(routes)) {
    const normalizedEntry = normalizeRouteEntry(fallbackKey, entry);
    const raw = String(normalizedEntry?.name || fallbackKey || "").trim();
    const key = buildBuildingIdentityKey({
      buildingUid: normalizedEntry?.buildingUid,
      buildingName: raw,
      objectName: normalizedEntry?.objectName
    }) || routeKey(raw || fallbackKey);
    if (!key || out.has(key)) continue;
    out.set(key, raw || fallbackKey);
  }

  return out;
}

function diffRouteNameMaps(beforeMap, afterMap) {
  const lost = [];
  for (const [key, name] of (beforeMap || new Map()).entries()) {
    if (!afterMap?.has?.(key)) lost.push(name);
  }
  return sortedUniqueStrings(lost);
}

function getBaseObjectBuildingNames(obj) {
  if (!obj || obj.userData?.isPlaced) return [];
  const names = [];
  const rootLabel = normalizeExportBuildingName(getBuildingLabelForObject(obj) || obj.name || "");
  if (rootLabel) names.push(rootLabel);
  return sortedUniqueStrings(names);
}

function getSceneBuildingIdentityMap(opts = {}) {
  const entries = new Map();
  const excludeObjects = normalizeObjectSet(opts.excludeObjects);
  if (!campusRoot) return entries;

  campusRoot.traverse((obj) => {
    if (!obj) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;
    const root = getTopLevelNamedAncestor(obj);
    if (!root || !root.name) return;
    if (excludeObjects.has(obj) || excludeObjects.has(root)) return;

    const resolved = resolveBuildingIdentity({
      buildingName: root.name,
      objectName: root.name
    });
    const key = buildBuildingIdentityKey({
      buildingUid: resolved.buildingUid,
      buildingName: resolved.buildingName,
      objectName: root.name
    });
    if (!key || entries.has(key)) return;
    entries.set(key, {
      ...resolved,
      objectName: root.name
    });
  });

  return entries;
}

function getSceneDuplicateBuildingNames(opts = {}) {
  const counts = new Map();
  const labels = new Map();
  for (const entry of getSceneBuildingIdentityMap(opts).values()) {
    const displayName = String(entry?.buildingName || "").trim();
    const key = routeKey(displayName);
    if (!key) continue;
    if (!labels.has(key)) labels.set(key, displayName);
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  const duplicates = [];
  for (const [key, count] of counts.entries()) {
    if (count > 1) duplicates.push(labels.get(key) || key);
  }
  return duplicates;
}

function buildRoutingDiagnostics(opts = {}) {
  const routes = (opts.routes && typeof opts.routes === "object") ? opts.routes : computeSavedRoutes(opts);
  const routeMap = collectRouteNameMap(routes);
  const entranceEntries = getEntranceBuildingLinks(opts);
  const kioskPoints = findKioskStartPoints(opts);
  const entranceMap = new Map();
  const entranceDetails = [];
  for (const entry of entranceEntries) {
    const key = buildBuildingIdentityKey(entry);
    const name = String(entry?.buildingName || entry?.objectName || "").trim();
    if (key && name && !entranceMap.has(key)) entranceMap.set(key, name);
    entranceDetails.push({
      buildingUid: String(entry?.buildingUid || "").trim(),
      buildingName: name,
      objectName: String(entry?.objectName || "").trim(),
      candidateKeys: buildBuildingIdentityCandidateKeys(entry)
    });
  }

  const sceneBuildingMap = getSceneBuildingIdentityMap(opts);
  const sceneBuildingLookup = buildBuildingIdentityAliasLookup(Array.from(sceneBuildingMap.values()));
  const orphanedNames = [];
  const unreachableNames = [];
  const orphanedDetails = [];
  const unreachableDetails = [];
  const duplicateBuildingNames = getSceneDuplicateBuildingNames(opts);

  for (const entry of entranceEntries) {
    const name = String(entry?.buildingName || entry?.objectName || "").trim();
    const sceneMatch = findBuildingIdentityLookupMatch(sceneBuildingLookup, entry);
    const routeEntry = getGuideRouteEntry(routes, name, entry?.buildingUid || "", entry?.objectName || "");

    if (!sceneMatch.matched) {
      orphanedNames.push(name);
      orphanedDetails.push({
        buildingUid: String(entry?.buildingUid || "").trim(),
        buildingName: name,
        objectName: String(entry?.objectName || "").trim(),
        candidateKeys: sceneMatch.candidateKeys
      });
    }
    if (!routeEntry) {
      unreachableNames.push(name);
      unreachableDetails.push({
        buildingUid: String(entry?.buildingUid || "").trim(),
        buildingName: name,
        objectName: String(entry?.objectName || "").trim(),
        candidateKeys: buildBuildingIdentityCandidateKeys(entry)
      });
    }
  }

  return {
    routes,
    routeMap,
    routeNames: Array.from(routeMap.values()),
    routeCount: routeMap.size,
    entranceNames: Array.from(entranceMap.values()),
    entranceMap,
    entranceDetails,
    anchorCount: entranceMap.size,
    orphanedNames: sortedUniqueStrings(orphanedNames),
    orphanedDetails,
    unreachableNames: sortedUniqueStrings(unreachableNames),
    unreachableDetails,
    duplicateBuildingNames: sortedUniqueStrings(duplicateBuildingNames),
    hasKiosk: kioskPoints.length > 0,
    kioskCount: kioskPoints.length,
    roadCount: getRoadObjects(opts).length
  };
}

function buildRoutingPersistReport(actionLabel, opts = {}) {
  const diagnostics = buildRoutingDiagnostics(opts);
  const currentRouteMap = diagnostics.routeMap;
  const savedRouteMap = collectRouteNameMap(savedRoutes);
  const orphanedKeySet = new Set(diagnostics.orphanedNames.map(name => routeKey(name)));
  const unreachableNames = diagnostics.unreachableNames.filter(name => !orphanedKeySet.has(routeKey(name)));
  const lostFromSavedNames = diffRouteNameMaps(savedRouteMap, currentRouteMap);
  const lines = [];
  let severity = "ok";

  const bumpSeverity = (next) => {
    const rank = next === "critical" ? 2 : (next === "warning" ? 1 : 0);
    const current = severity === "critical" ? 2 : (severity === "warning" ? 1 : 0);
    if (rank > current) severity = next;
  };

  if (diagnostics.anchorCount > 0 && !diagnostics.hasKiosk) {
    bumpSeverity("critical");
    lines.push(`No kiosk start is linked to the road network. ${actionLabel} would leave ${diagnostics.anchorCount} linked ${pluralize(diagnostics.anchorCount, "destination")} without generated routes.`);
  }

  if (diagnostics.orphanedNames.length) {
    bumpSeverity("critical");
    lines.push(`Road links point to missing ${pluralize(diagnostics.orphanedNames.length, "building")}: ${formatNameList(diagnostics.orphanedNames)}.`);
  }

  if (diagnostics.duplicateBuildingNames.length) {
    bumpSeverity("critical");
    lines.push(`Duplicate building names exist in the current model: ${formatNameList(diagnostics.duplicateBuildingNames)}.`);
  }

  if (unreachableNames.length) {
    bumpSeverity("warning");
    lines.push(`Unreachable ${pluralize(unreachableNames.length, "destination")}: ${formatNameList(unreachableNames)}.`);
  }

  if (lostFromSavedNames.length) {
    bumpSeverity("warning");
    lines.push(`Compared with the currently saved routes, ${pluralize(lostFromSavedNames.length, "destination")} would be removed: ${formatNameList(lostFromSavedNames)}.`);
  }

  if (lines.length && (diagnostics.orphanedNames.length || unreachableNames.length || lostFromSavedNames.length)) {
    lines.push("Any generated text guides for the affected destinations would also need regeneration.");
  }

  return {
    diagnostics,
    severity,
    lines,
    lostFromSavedNames,
    hasIssues: lines.length > 0
  };
}

function reviewRoutingPersist(actionLabel, opts = {}) {
  const mode = opts.mode === "strict" ? "strict" : "warn";
  const report = buildRoutingPersistReport(actionLabel, opts);
  if (!report.hasIssues) return { proceed: true, report };

  const summary = [
    `${actionLabel} routing check`,
    "",
    ...report.lines
  ].join("\n");

  if (mode === "strict" && report.severity === "critical") {
    return { proceed: false, blocked: true, message: summary, report };
  }

  const ok = confirm(`${summary}\n\nContinue ${actionLabel.toLowerCase()} anyway?`);
  return {
    proceed: !!ok,
    blocked: false,
    message: summary,
    report
  };
}

function buildRoutingReviewLogPayload(review = {}) {
  const report = review?.report && typeof review.report === "object" ? review.report : review;
  const diagnostics = report?.diagnostics && typeof report.diagnostics === "object" ? report.diagnostics : {};
  const pickList = (value, limit = 10) => (Array.isArray(value) ? value.slice(0, limit) : []);
  return {
    severity: String(report?.severity || "").trim(),
    lines: pickList(report?.lines, 10),
    routeCount: Number(diagnostics.routeCount || 0),
    anchorCount: Number(diagnostics.anchorCount || 0),
    kioskCount: Number(diagnostics.kioskCount || 0),
    roadCount: Number(diagnostics.roadCount || 0),
    orphanedNames: pickList(diagnostics.orphanedNames, 10),
    orphanedDetails: pickList(diagnostics.orphanedDetails, 10),
    unreachableNames: pickList(diagnostics.unreachableNames, 10),
    unreachableDetails: pickList(diagnostics.unreachableDetails, 10),
    duplicateBuildingNames: pickList(diagnostics.duplicateBuildingNames, 10),
    lostFromSavedNames: pickList(report?.lostFromSavedNames, 10)
  };
}

function createRoutingReviewError(review = {}, actionLabel = "Routing validation") {
  const err = new Error(String(review?.message || `${actionLabel} failed`).trim() || `${actionLabel} failed`);
  err.details = {
    action: actionLabel,
    routingReview: buildRoutingReviewLogPayload(review)
  };
  return err;
}

function analyzeDeleteImpact(obj) {
  const impact = {
    severity: "normal",
    lines: []
  };

  if (!obj) return impact;

  if (isRoadObject(obj)) {
    const before = buildRoutingDiagnostics();
    const after = buildRoutingDiagnostics({ excludeObjects: [obj] });
    const lostDestinations = diffRouteNameMaps(before.routeMap, after.routeMap);
    const kioskRemoved = before.hasKiosk && !after.hasKiosk;

    if (kioskRemoved) {
      impact.severity = "critical";
      impact.lines.push("This road carries the kiosk start. Deleting it now would remove the navigation starting point.");
    }
    if (lostDestinations.length) {
      impact.severity = impact.severity === "critical" ? "critical" : "warning";
      impact.lines.push(`Generated routes would be removed for: ${formatNameList(lostDestinations)}.`);
    }
    if (!kioskRemoved && !lostDestinations.length && before.routeCount !== after.routeCount) {
      impact.severity = "warning";
      impact.lines.push(`Route count would change from ${before.routeCount} to ${after.routeCount}.`);
    }
    if (lostDestinations.length) {
      impact.lines.push("Any generated text guides for those destinations would also become invalid until routes are rebuilt.");
    }
    return impact;
  }

  if (!obj.userData?.isPlaced) {
    const deletedBuildingNames = getBaseObjectBuildingNames(obj);
    if (!deletedBuildingNames.length) return impact;

    const deletedKeys = new Set(
      deletedBuildingNames
        .map((name) => buildBuildingIdentityKey({ buildingName: name, objectName: name }))
        .filter(Boolean)
    );
    const linkedRouteNames = getEntranceBuildingLinks()
      .filter((entry) => deletedKeys.has(buildBuildingIdentityKey(entry)))
      .map((entry) => String(entry?.buildingName || entry?.objectName || "").trim())
      .filter(Boolean);

    if (linkedRouteNames.length) {
      impact.severity = "warning";
      impact.lines.push(`This building still has linked road ${pluralize(linkedRouteNames.length, "anchor")}: ${formatNameList(linkedRouteNames)}.`);
      impact.lines.push("Deleting the base object now would leave orphaned routing data until you remove or reassign those linked road points.");
      impact.lines.push("Any generated text guides for those destinations would also become invalid.");
    }
  }

  return impact;
}

function drawRouteFromWorldPoints(pointsWorld) {
  clearRouteLine();
  if (!Array.isArray(pointsWorld) || pointsWorld.length < 2) return false;
  const points = pointsWorld
    .filter(p => p && Number.isFinite(p.x) && Number.isFinite(p.z))
    .map(p => new THREE.Vector3(p.x, (p.y || 0) + ROUTE_LINE_Y_BIAS, p.z));
  if (points.length < 2) return false;

  const geom = buildRoadGeometry(points, ROUTE_LINE_WIDTH);
  routeLine = new THREE.Mesh(geom, routeMaterial);
  routeLine.renderOrder = 9997;
  routeLine.frustumCulled = false;
  scene.add(routeLine);
  return true;
}

// -------------------- Delete confirm modal --------------------
let pendingDeleteObj = null;
function showDeleteConfirm(obj) {
  if (!deleteConfirmEl) return false;
  const label = obj?.userData?.nameLabel || obj?.name || "item";
  const isBaseObject = !obj?.userData?.isPlaced;
  const impact = analyzeDeleteImpact(obj);
  if (deleteConfirmTitleEl) {
    if (impact.severity === "critical") {
      deleteConfirmTitleEl.textContent = "Delete item with routing impact?";
    } else if (impact.severity === "warning") {
      deleteConfirmTitleEl.textContent = "Delete item with linked routing data?";
    } else {
      deleteConfirmTitleEl.textContent = "Delete item?";
    }
  }
  if (deleteConfirmTextEl) {
    const lines = [];
    if (isBaseObject) {
      lines.push(`Delete base object "${label}"?`);
      lines.push("You can Undo/Redo this. Export GLB to persist base edits.");
    } else {
      lines.push(`Delete "${label}"?`);
    }
    if (impact.lines.length) {
      lines.push("");
      lines.push(...impact.lines);
    }
    deleteConfirmTextEl.textContent = lines.join("\n");
  }
  pendingDeleteObj = obj || null;
  deleteConfirmEl.classList.add("active");
  deleteConfirmEl.setAttribute("aria-hidden", "false");
  return true;
}
function hideDeleteConfirm() {
  if (!deleteConfirmEl) return;
  deleteConfirmEl.classList.remove("active");
  deleteConfirmEl.setAttribute("aria-hidden", "true");
  pendingDeleteObj = null;
}
deleteConfirmNoBtn?.addEventListener("click", () => {
  hideDeleteConfirm();
});
deleteConfirmYesBtn?.addEventListener("click", () => {
  if (pendingDeleteObj) {
    performDelete(pendingDeleteObj);
  }
  hideDeleteConfirm();
});

function normalizeBuildingName(name) {
  return String(name || "").trim().toLowerCase();
}

function normalizeBuildingUid(uid) {
  return String(uid || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "");
}

function normalizeBuildingObjectName(name) {
  const normalized = normalizeExportBuildingName(name);
  return normalizeBuildingName(normalized || name);
}

function normalizeDestinationEntityType(type) {
  const safe = String(type || "").trim().toLowerCase();
  if (["building", "venue", "area", "landmark", "facility"].includes(safe)) return safe;
  return "building";
}

function getDestinationEntityPriority(entityType) {
  switch (normalizeDestinationEntityType(entityType)) {
    case "facility":
      return 5;
    case "venue":
      return 4;
    case "area":
      return 3;
    case "landmark":
      return 2;
    default:
      return 1;
  }
}

function shouldPreferCatalogAlias(nextEntry, currentEntry) {
  if (!currentEntry) return true;
  const nextPriority = getDestinationEntityPriority(nextEntry?.entityType);
  const currentPriority = getDestinationEntityPriority(currentEntry?.entityType);
  if (nextPriority !== currentPriority) return nextPriority > currentPriority;

  const nextObject = String(nextEntry?.objectName || "").trim();
  const currentObject = String(currentEntry?.objectName || "").trim();
  if (!!nextObject !== !!currentObject) return !!nextObject;

  const nextUid = normalizeBuildingUid(nextEntry?.buildingUid);
  const currentUid = normalizeBuildingUid(currentEntry?.buildingUid);
  if (!!nextUid !== !!currentUid) return !!nextUid;

  return false;
}

function inferDestinationEntityType({ objectName = "", buildingName = "", catalogEntry = null } = {}) {
  const fromCatalog = normalizeDestinationEntityType(catalogEntry?.entityType || "");
  if (fromCatalog && fromCatalog !== "building") return fromCatalog;
  const classified = classifyTopLevelObjectName(String(objectName || buildingName || "").trim());
  if (classified?.include && classified?.entityType) {
    return normalizeDestinationEntityType(classified.entityType);
  }
  return normalizeDestinationEntityType(catalogEntry?.entityType || "building");
}

function getRoadAttachPolicy(entityType) {
  const safeType = normalizeDestinationEntityType(entityType);
  if (safeType === "building" || safeType === "venue") {
    return { entityType: safeType, attachable: true, blocking: true, maxLinks: 1 };
  }
  if (safeType === "area" || safeType === "facility") {
    return { entityType: safeType, attachable: true, blocking: false, maxLinks: 1 };
  }
  return { entityType: safeType, attachable: false, blocking: false, maxLinks: 0 };
}

function resetModelBuildingCatalog() {
  modelBuildingCatalog = [];
  modelBuildingsByUid = new Map();
  modelBuildingsByNameKey = new Map();
  modelBuildingsByObjectKey = new Map();
}

function registerModelBuildingCatalogEntry(rawEntry = {}) {
  const entry = {
    id: rawEntry?.id ?? null,
    buildingUid: normalizeBuildingUid(rawEntry?.buildingUid),
    name: String(rawEntry?.name || "").trim(),
    objectName: String(rawEntry?.objectName || rawEntry?.name || "").trim(),
    entityType: normalizeDestinationEntityType(rawEntry?.entityType || rawEntry?.destinationType || ""),
    description: String(rawEntry?.description || "").trim(),
    imagePath: String(rawEntry?.imagePath || "").trim(),
    modelFile: String(rawEntry?.modelFile || "").trim()
  };
  if (!entry.name && !entry.objectName && !entry.buildingUid) return null;

  modelBuildingCatalog.push(entry);
  if (entry.buildingUid && !modelBuildingsByUid.has(entry.buildingUid)) {
    modelBuildingsByUid.set(entry.buildingUid, entry);
  }

  const nameKey = normalizeBuildingName(entry.name);
  if (nameKey && (!modelBuildingsByNameKey.has(nameKey) || shouldPreferCatalogAlias(entry, modelBuildingsByNameKey.get(nameKey)))) {
    modelBuildingsByNameKey.set(nameKey, entry);
  }

  const objectKey = normalizeBuildingObjectName(entry.objectName);
  if (objectKey && (!modelBuildingsByObjectKey.has(objectKey) || shouldPreferCatalogAlias(entry, modelBuildingsByObjectKey.get(objectKey)))) {
    modelBuildingsByObjectKey.set(objectKey, entry);
  }

  return entry;
}

function setModelBuildingCatalog(entries = []) {
  resetModelBuildingCatalog();
  for (const rawEntry of (Array.isArray(entries) ? entries : [])) {
    registerModelBuildingCatalogEntry(rawEntry);
  }
}

async function loadModelBuildingCatalog(modelName) {
  if (!modelName) {
    resetModelBuildingCatalog();
    return [];
  }

  try {
    const res = await apiFetch(`mapEditor.php?action=load_model_buildings&name=${encodeURIComponent(modelName)}&_=${Date.now()}`);
    const data = await res.json();
    const buildings = Array.isArray(data?.buildings) ? data.buildings : [];
    setModelBuildingCatalog(buildings);
  } catch (_) {
    resetModelBuildingCatalog();
  }
  return modelBuildingCatalog;
}

function getModelBuildingCatalogEntry({ buildingUid = "", buildingName = "", objectName = "" } = {}) {
  const safeUid = normalizeBuildingUid(buildingUid);
  if (safeUid && modelBuildingsByUid.has(safeUid)) {
    return modelBuildingsByUid.get(safeUid) || null;
  }

  let best = null;

  for (const candidate of [objectName, buildingName]) {
    const objectKey = normalizeBuildingObjectName(candidate);
    if (objectKey && modelBuildingsByObjectKey.has(objectKey)) {
      const entry = modelBuildingsByObjectKey.get(objectKey) || null;
      if (entry && (!best || shouldPreferCatalogAlias(entry, best))) {
        best = entry;
      }
    }
  }

  for (const candidate of [buildingName, objectName]) {
    const nameKey = normalizeBuildingName(candidate);
    if (nameKey && modelBuildingsByNameKey.has(nameKey)) {
      const entry = modelBuildingsByNameKey.get(nameKey) || null;
      if (entry && (!best || shouldPreferCatalogAlias(entry, best))) {
        best = entry;
      }
    }
  }

  return best;
}

function resolveBuildingIdentity({ buildingUid = "", buildingName = "", objectName = "" } = {}) {
  const entry = getModelBuildingCatalogEntry({ buildingUid, buildingName, objectName });
  const resolvedObjectName = String(objectName || entry?.objectName || buildingName || entry?.name || "").trim();
  const resolvedBuildingName = String(buildingName || entry?.name || objectName || entry?.objectName || "").trim();
  return {
    buildingUid: normalizeBuildingUid(buildingUid || entry?.buildingUid || ""),
    buildingName: resolvedBuildingName,
    objectName: resolvedObjectName,
    entityType: inferDestinationEntityType({
      objectName: resolvedObjectName,
      buildingName: resolvedBuildingName,
      catalogEntry: entry || null
    }),
    catalogEntry: entry || null
  };
}

function resolveAttachTargetIdentity(input = {}) {
  const raw = (typeof input === "string")
    ? { buildingName: input, objectName: input }
    : (input && typeof input === "object" ? input : {});
  const resolved = resolveBuildingIdentity(raw);
  const entityType = normalizeDestinationEntityType(raw?.entityType || resolved.entityType || "");
  const key = buildBuildingIdentityKey({
    buildingUid: resolved.buildingUid,
    buildingName: resolved.buildingName,
    objectName: resolved.objectName
  });
  const policy = getRoadAttachPolicy(entityType);
  return {
    ...resolved,
    entityType,
    key,
    attachPolicy: policy
  };
}

function buildBuildingIdentityKey({ buildingUid = "", buildingName = "", objectName = "" } = {}) {
  const resolved = resolveBuildingIdentity({ buildingUid, buildingName, objectName });
  if (resolved.buildingUid) return resolved.buildingUid;
  const objectKey = normalizeBuildingObjectName(resolved.objectName);
  if (objectKey) return objectKey;
  return routeKey(resolved.buildingName);
}

function normalizeRouteEntry(rawKey, rawEntry = {}) {
  const fallbackName = String(rawEntry?.name || rawKey || "").trim();
  const resolved = resolveBuildingIdentity({
    buildingUid: rawEntry?.buildingUid || rawEntry?.destinationUid || rawEntry?.uid || "",
    buildingName: rawEntry?.name || rawEntry?.buildingName || rawEntry?.destinationName || fallbackName,
    objectName: rawEntry?.objectName || rawEntry?.modelObjectName || ""
  });
  return {
    ...rawEntry,
    name: resolved.buildingName || fallbackName,
    buildingUid: resolved.buildingUid,
    objectName: resolved.objectName,
    points: Array.isArray(rawEntry?.points) ? rawEntry.points : []
  };
}

function normalizeSavedRoutesPayload(routes) {
  const normalized = {};
  if (!routes || typeof routes !== "object") return normalized;

  for (const [rawKey, rawValue] of Object.entries(routes)) {
    if (!rawValue || typeof rawValue !== "object") continue;
    const entry = normalizeRouteEntry(rawKey, rawValue);
    const key = buildBuildingIdentityKey({
      buildingUid: entry.buildingUid,
      buildingName: entry.name,
      objectName: entry.objectName
    }) || routeKey(rawKey);
    if (!key) continue;
    normalized[key] = entry;
  }

  return normalized;
}

function buildEditorPreviewRoutes(routes = null) {
  const liveRoutes = (routes && typeof routes === "object")
    ? normalizeSavedRoutesPayload(routes)
    : (hasLiveRoadData() ? computeSavedRoutes() : {});
  const bakedRoutes = normalizeSavedRoutesPayload(savedRoutes);
  if (!hasLiveRoadData()) return bakedRoutes;
  return {
    ...bakedRoutes,
    ...liveRoutes
  };
}

const EXPORT_ENTITY_KIOSK_ROUTE_RE = /^KIOSK_START(?:\.\d+|\d+)?$/i;
const EXPORT_ENTITY_ROAD_RE = /^road(?:[._-]\d+)?$/i;

function normalizeExportBuildingName(name) {
  let n = String(name || "").trim();
  if (!n) return "";
  n = n.replace(/\.\d+$/, "");
  n = n.replace(/\s+/g, " ").trim();
  if (!n) return "";
  if (isGroundLikeName(n)) return "";
  if (EXPORT_ENTITY_KIOSK_ROUTE_RE.test(n)) return "";
  // Exported road groups are named road, road_1, road_2, etc.; never sync them as buildings.
  if (EXPORT_ENTITY_ROAD_RE.test(n)) return "";
  return n;
}

function getTopLevelNamedAncestorForScene(obj, sceneRoot) {
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

function parseExportRoomCandidate(rawName) {
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

function buildExportEntityExtraction(sceneRoot) {
  if (!sceneRoot) return [];

  const buildingEntries = new Map();
  const roomSources = [];

  sceneRoot.traverse((obj) => {
    if (!obj) return;
    const root = getTopLevelNamedAncestorForScene(obj, sceneRoot);
    if (!root || !root.name) return;
    const rootMeta = classifyTopLevelObjectName(root.name);
    if (!rootMeta?.include) return;
    const entryKey = String(rootMeta.baseName || root.name || "").trim();
    if (!entryKey) return;

    let entry = buildingEntries.get(entryKey);
    if (!entry) {
      entry = {
        name: String(rootMeta.displayName || rootMeta.baseName || root.name || "").trim(),
        objectName: String(rootMeta.baseName || root.name || "").trim(),
        classification: String(rootMeta.entityType || "building").trim() || "building",
        bucket: String(rootMeta.bucket || "buildings"),
        roomEntries: []
      };
      buildingEntries.set(entryKey, entry);
    }

    if (!obj.name) return;
    roomSources.push({ entry, object: obj, buildingName: entry.name });
  });

  const roomContext = buildRoomNormalizationContext(roomSources.map((row) => ({
    name: row?.object?.name || "",
    buildingName: row?.buildingName || ""
  })));

  for (const row of roomSources) {
    const entry = row?.entry;
    const obj = row?.object;
    const buildingName = String(row?.buildingName || "").trim();
    if (!entry || !obj?.name || !buildingName) continue;

    const parsed = parseRoomObjectName(obj.name, {
      canonicalBaseSet: roomContext.canonicalBaseSet,
      canonicalByBuilding: roomContext.canonicalByBuilding,
      compactByBaseCount: roomContext.compactByBaseCount,
      buildingName
    });
    if (!parsed) continue;

    entry.roomEntries.push({
      name: String(parsed.roomName || "").trim(),
      objectName: String(parsed.objectName || obj.name || "").trim(),
      roomNumber: String(parsed.roomNumber || "").trim()
    });
  }

  const buildingsOut = [];
  for (const entry of buildingEntries.values()) {
    const roomMap = new Map();
    for (const row of entry.roomEntries) {
      const roomKey = String(row.objectName || row.name || "").trim().toLowerCase();
      if (!roomKey || roomMap.has(roomKey)) continue;
      roomMap.set(roomKey, {
        name: row.name,
        objectName: row.objectName,
        roomNumber: row.roomNumber
      });
    }

    const rooms = Array.from(roomMap.values()).sort((a, b) => a.name.localeCompare(b.name));
    buildingsOut.push({
      name: entry.name,
      objectName: entry.objectName,
      bucket: entry.bucket,
      entityType: entry.classification,
      classification: rooms.length ? entry.classification : entry.classification,
      rooms
    });
  }

  buildingsOut.sort((a, b) => a.name.localeCompare(b.name));
  return buildingsOut;
}

async function syncModelEntitiesToDatabase(modelFile, sceneRoot = campusRoot) {
  const targetModel = String(modelFile || "").trim();
  if (!targetModel) throw new Error("Missing model file for database sync");

  let workingRoot = sceneRoot;
  let loadedScene = null;
  if (!workingRoot) {
    const loader = new GLTFLoader();
    const url = `../models/${encodeURIComponent(targetModel)}?v=${Date.now()}`;
    loadedScene = await new Promise((resolve, reject) => {
      loader.load(url, (gltf) => resolve(gltf.scene), undefined, (err) => reject(err));
    });
    workingRoot = loadedScene;
  }

  const buildings = buildExportEntityExtraction(workingRoot);
  if (!Array.isArray(buildings) || !buildings.length) {
    throw new Error(`No buildings detected for ${targetModel}`);
  }

  const res = await fetch("mapImport.php?action=import_entities", {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": MAP_IMPORT_CSRF_TOKEN
    },
    body: JSON.stringify({
      modelFile: targetModel,
      buildings
    })
  });
  const data = await res.json();
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || `HTTP ${res.status}`);
  }
  return data.summary || {};
}

function normalizeLoadedGuideEntry(rawKey, rawEntry = {}) {
  const destinationType = String(
    rawEntry?.destinationType ||
    rawEntry?.type ||
    (String(rawKey || "").startsWith("room::") ? "room" : "building")
  ).trim() || "building";
  const buildingName = String(
    rawEntry?.buildingName ||
    rawEntry?.name ||
    rawEntry?.routeName ||
    rawEntry?.destinationName ||
    ""
  ).trim();
  const roomName = String(rawEntry?.roomName || "").trim();
  const resolved = resolveBuildingIdentity({
    buildingUid: rawEntry?.buildingUid || rawEntry?.destinationUid || rawEntry?.uid || "",
    buildingName,
    objectName: rawEntry?.objectName || rawEntry?.modelObjectName || ""
  });
  const guideMode = String(rawEntry?.guideMode || "").trim().toLowerCase();
  let manualText = String(rawEntry?.manualText || "").replace(/\r\n/g, "\n").trim();
  const roomSupplementText = String(rawEntry?.roomSupplementText || "").replace(/\r\n/g, "\n").trim();
  if (!manualText && (guideMode === "manual" || guideMode === "mixed") && Array.isArray(rawEntry?.finalSteps)) {
    manualText = guideStepsToText(rawEntry.finalSteps);
  }
  const computedKey = buildGuideKey(destinationType, {
    buildingName: resolved.buildingName || buildingName,
    buildingUid: resolved.buildingUid,
    roomName
  });
  return {
    key: String(computedKey || rawEntry?.key || rawKey || ""),
    destinationType,
    buildingName: resolved.buildingName || buildingName,
    buildingUid: resolved.buildingUid,
    objectName: resolved.objectName,
    roomName,
    roomUid: String(rawEntry?.roomUid || "").trim(),
    facilityUid: String(rawEntry?.facilityUid || "").trim(),
    manualText,
    roomSupplementText,
    sourceRouteSignature: String(rawEntry?.sourceRouteSignature || "").trim()
  };
}

function setLoadedGuidesPayload(payload = {}) {
  const rawEntries = (payload && typeof payload.entries === "object" && payload.entries) ? payload.entries : {};
  const normalizedEntries = {};
  for (const [rawKey, rawEntry] of Object.entries(rawEntries)) {
    const entry = normalizeLoadedGuideEntry(rawKey, rawEntry);
    if (!entry.key) continue;
    normalizedEntries[entry.key] = entry;
  }
  loadedGuidePayload = {
    model: String(payload?.model || currentModelName || "").trim(),
    entries: normalizedEntries
  };
}

function getLoadedGuideRawEntries() {
  return (loadedGuidePayload && typeof loadedGuidePayload.entries === "object" && loadedGuidePayload.entries)
    ? loadedGuidePayload.entries
    : {};
}

function buildGuideRawSnapshotValue() {
  const entries = Object.values(getLoadedGuideRawEntries())
    .map((entry) => ({
      key: String(entry?.key || "").trim(),
      destinationType: String(entry?.destinationType || "building").trim() || "building",
      buildingName: String(entry?.buildingName || "").trim(),
      buildingUid: String(entry?.buildingUid || "").trim(),
      objectName: String(entry?.objectName || "").trim(),
      roomName: String(entry?.roomName || "").trim(),
      roomUid: String(entry?.roomUid || "").trim(),
      facilityUid: String(entry?.facilityUid || "").trim(),
      manualText: String(entry?.manualText || "").replace(/\r\n/g, "\n").trim(),
      roomSupplementText: String(entry?.roomSupplementText || "").replace(/\r\n/g, "\n").trim(),
      sourceRouteSignature: String(entry?.sourceRouteSignature || "").trim()
    }))
    .filter((entry) => entry.key && (entry.manualText || entry.roomSupplementText || entry.sourceRouteSignature))
    .sort((a, b) => a.key.localeCompare(b.key));
  return {
    model: String(currentModelName || "").trim(),
    entries
  };
}

function normalizeGuideLineText(line) {
  let text = String(line || "").trim();
  if (!text) return "";
  text = text.replace(/^(?:[-*]\s+|\d+[.)]\s+)/, "").trim();
  if (!text) return "";
  if (!/[.!?]$/.test(text)) text += ".";
  return text;
}

function parseManualGuideText(text) {
  return String(text || "")
    .split(/\r?\n/)
    .map(normalizeGuideLineText)
    .filter(Boolean)
    .map((line) => ({ kind: "manual", text: line }));
}

function guideStepsToText(steps) {
  return (Array.isArray(steps) ? steps : [])
    .map((step) => String(step?.text || "").trim())
    .filter(Boolean)
    .join("\n");
}

function formatGuideDistance(distance) {
  const safe = Number(distance);
  if (!Number.isFinite(safe) || safe <= 0) return "Distance unavailable";
  return `${Math.round(safe)} units`;
}

function getGuideRouteEntry(routes, buildingName, buildingUid = "", objectName = "") {
  const normalizedRoutes = normalizeSavedRoutesPayload(routes);
  const candidateKeys = new Set(buildBuildingIdentityCandidateKeys({ buildingUid, buildingName, objectName }));

  for (const key of candidateKeys) {
    if (normalizedRoutes?.[key]) return normalizedRoutes[key];
  }

  for (const [rawKey, rawEntry] of Object.entries(normalizedRoutes || {})) {
    const entry = normalizeRouteEntry(rawKey, rawEntry);
    const entryKeys = new Set(buildBuildingIdentityCandidateKeys({
      buildingUid: entry.buildingUid,
      buildingName: entry.name,
      objectName: entry.objectName || rawKey
    }));
    for (const key of candidateKeys) {
      if (entryKeys.has(key)) return entry;
    }
  }

  return null;
}

function buildGuideDestinationCatalog(routes = savedRoutes) {
  const byBuilding = new Map();
  const extraction = buildExportEntityExtraction(campusRoot);
  for (const building of (Array.isArray(extraction) ? extraction : [])) {
    const rawObjectName = String(building?.name || "").trim();
    if (!rawObjectName) continue;
    const resolved = resolveBuildingIdentity({
      buildingName: rawObjectName,
      objectName: rawObjectName
    });
    const buildingName = String(resolved.buildingName || rawObjectName).trim();
    const objectName = String(resolved.objectName || rawObjectName).trim();
    const key = buildBuildingIdentityKey({
      buildingUid: resolved.buildingUid,
      buildingName,
      objectName
    });
    if (!key) continue;
    if (!byBuilding.has(key)) {
      byBuilding.set(key, {
        buildingName,
        buildingUid: resolved.buildingUid,
        objectName,
        classification: String(building?.classification || "building").trim() || "building",
        rooms: []
      });
    }
    const entry = byBuilding.get(key);
    const roomNames = (Array.isArray(building?.rooms) ? building.rooms : [])
      .map((room) => String(room?.name || "").trim())
      .filter(Boolean);
    entry.rooms = sortedUniqueStrings([...entry.rooms, ...roomNames]);
  }

  for (const [rawKey, rawEntry] of Object.entries(normalizeSavedRoutesPayload(routes))) {
    const entry = normalizeRouteEntry(rawKey, rawEntry);
    const buildingName = String(entry?.name || rawKey || "").trim();
    if (!buildingName) continue;
    const objectName = String(entry?.objectName || buildingName).trim();
    const key = buildBuildingIdentityKey({
      buildingUid: entry?.buildingUid,
      buildingName,
      objectName
    });
    if (!key) continue;
    if (!byBuilding.has(key)) {
      byBuilding.set(key, {
        buildingName,
        buildingUid: String(entry?.buildingUid || "").trim(),
        objectName,
        classification: "building",
        rooms: []
      });
    }
  }

  const destinations = [];
  const buildings = Array.from(byBuilding.values()).sort((a, b) => a.buildingName.localeCompare(b.buildingName));
  for (const building of buildings) {
      const destinationType = String(building.classification || "building").trim() || "building";
      destinations.push({
        destinationType,
        buildingName: building.buildingName,
        buildingUid: building.buildingUid,
        objectName: building.objectName,
        roomName: "",
        destinationName: building.buildingName,
        usesBuildingRoute: false
    });
    for (const roomName of sortedUniqueStrings(building.rooms)) {
      destinations.push({
        destinationType: "room",
        buildingName: building.buildingName,
        buildingUid: building.buildingUid,
        objectName: building.objectName,
        roomName,
        destinationName: `${building.buildingName} / ${roomName}`,
        usesBuildingRoute: true
      });
    }
  }

  return destinations;
}

function buildGuideLandmarks() {
  const landmarks = [];
  const seenBuildings = new Set();
  if (campusRoot) {
    campusRoot.traverse((obj) => {
      if (!obj) return;
      const root = getTopLevelNamedAncestorForScene(obj, campusRoot);
      if (!root || !root.name) return;
      const buildingName = normalizeExportBuildingName(root.name);
      if (!buildingName || seenBuildings.has(buildingName)) return;
      seenBuildings.add(buildingName);
      const box = new THREE.Box3().setFromObject(root);
      if (box.isEmpty()) return;
      const center = box.getCenter(new THREE.Vector3());
      landmarks.push({
        type: "building",
        name: buildingName,
        x: center.x,
        y: center.y,
        z: center.z
      });
    });
  }

  for (const roadObj of getRoadObjects()) {
    ensureRoadPointMetaArrays(roadObj);
    const points = Array.isArray(roadObj.userData?.road?.points) ? roadObj.userData.road.points : [];
    const metaArr = Array.isArray(roadObj.userData?.road?.pointMeta) ? roadObj.userData.road.pointMeta : [];
    for (let i = 0; i < metaArr.length; i++) {
      const meta = metaArr[i];
      const label = String(meta?.label || "").trim();
      if (!label || isKioskMeta(meta)) continue;
      const point = Array.isArray(points[i]) ? vec3FromArray(points[i]) : null;
      if (!point) continue;
      const worldPoint = roadObj.localToWorld(point.clone());
      landmarks.push({
        type: "landmark",
        name: label,
        x: worldPoint.x,
        y: worldPoint.y,
        z: worldPoint.z
      });
    }
  }

  return landmarks;
}

function buildGuideStatusText(entry) {
  if (!entry) return "No destination selected.";
  if (entry.status === "orphaned") {
    return "This saved guide no longer matches a destination in the current model.";
  }
  if (entry.status === "missing_route") {
    return "This destination exists, but it does not have a generated route yet.";
  }
  if (entry.status === "stale") {
    return "The manual text is still present, but the route changed. Review it before publishing.";
  }
  if (entry.guideMode === "manual") {
    return "Using manual guide text for this destination.";
  }
  return "Using the current auto-generated guide text.";
}

function buildGuideRouteSummary(entry) {
  if (!entry) return "Route summary unavailable.";
  const source = entry.usesBuildingRoute && entry.roomName
    ? `Room fallback via ${entry.buildingName}`
    : `Route source: ${entry.routeName || entry.buildingName || "Unavailable"}`;
  return `${source} • ${formatGuideDistance(entry.distance)}`;
}

function buildGuideWorkingEntries(opts = {}) {
  const routes = (opts.routes && typeof opts.routes === "object")
    ? opts.routes
    : buildEditorPreviewRoutes();
  const rawEntries = getLoadedGuideRawEntries();
  const destinations = buildGuideDestinationCatalog(routes);
  const landmarks = buildGuideLandmarks();
  const working = {};
  const seenKeys = new Set();

  for (const destination of destinations) {
    const key = buildGuideKey(destination.destinationType, {
      buildingName: destination.buildingName,
      buildingUid: destination.buildingUid,
      roomName: destination.roomName
    });
    const raw = normalizeLoadedGuideEntry(key, rawEntries[key] || destination);
    const routeEntry = getGuideRouteEntry(routes, destination.buildingName, destination.buildingUid, destination.objectName);
    const routeSignature = routeEntry ? buildRouteSignature(routeEntry.points, routeEntry.distance) : "";
    const baseAutoSteps = routeEntry
      ? buildGuideStepsFromPoints(routeEntry.points, {
          destinationName: destination.roomName || destination.buildingName,
          arrivalText: destination.roomName
            ? `Arrive at ${destination.buildingName}. Proceed to ${destination.roomName}.`
            : `Arrive at ${destination.buildingName}.`,
          landmarks
        })
      : [];
    const manualText = String(raw.manualText || "").replace(/\r\n/g, "\n").trim();
    const roomSupplementText = String(raw.roomSupplementText || "").replace(/\r\n/g, "\n").trim();
    const roomSupplementSteps = destination.roomName && roomSupplementText
      ? parseManualGuideText(roomSupplementText)
      : [];
    const autoSteps = roomSupplementSteps.length ? [...baseAutoSteps, ...roomSupplementSteps] : baseAutoSteps;
    const finalSteps = manualText ? parseManualGuideText(manualText) : autoSteps;
    const isStale = !!manualText && !!raw.sourceRouteSignature && !!routeSignature && raw.sourceRouteSignature !== routeSignature;
    const notes = [];
    if (destination.roomName) notes.push(roomSupplementSteps.length ? "Room guidance uses the parent building route plus the saved room supplement." : "Room guidance currently follows the parent building route.");
    if (!routeEntry) notes.push("No route is currently available for this destination.");
    if (isStale) notes.push("Manual text should be reviewed because the route changed.");

    working[key] = {
      key,
      destinationType: destination.destinationType,
      buildingName: destination.buildingName,
      buildingUid: destination.buildingUid,
      objectName: destination.objectName,
      roomName: destination.roomName,
      roomUid: String(raw.roomUid || "").trim(),
      facilityUid: String(raw.facilityUid || "").trim(),
      destinationName: destination.destinationName,
      routeName: String(routeEntry?.name || destination.buildingName || "").trim(),
      routeSignature,
      sourceRouteSignature: String(raw.sourceRouteSignature || "").trim(),
      distance: routeEntry && Number.isFinite(Number(routeEntry.distance)) ? Number(routeEntry.distance) : null,
      autoSteps,
      finalSteps,
      manualText,
      roomSupplementText,
      guideMode: manualText ? "manual" : "auto",
      status: routeEntry ? (isStale ? "stale" : "ok") : "missing_route",
      routeAvailable: !!routeEntry,
      usesBuildingRoute: !!destination.usesBuildingRoute,
      notes
    };
    seenKeys.add(key);
  }

  for (const [rawKey, rawValue] of Object.entries(rawEntries)) {
    if (seenKeys.has(rawKey)) continue;
    const raw = normalizeLoadedGuideEntry(rawKey, rawValue);
    const manualText = String(raw.manualText || "").replace(/\r\n/g, "\n").trim();
    const roomSupplementText = String(raw.roomSupplementText || "").replace(/\r\n/g, "\n").trim();
    working[rawKey] = {
      key: rawKey,
      destinationType: raw.destinationType,
      buildingName: raw.buildingName,
      buildingUid: raw.buildingUid,
      objectName: raw.objectName,
      roomName: raw.roomName,
      roomUid: String(raw.roomUid || "").trim(),
      facilityUid: String(raw.facilityUid || "").trim(),
      destinationName: raw.roomName ? `${raw.buildingName} / ${raw.roomName}` : raw.buildingName,
      routeName: raw.buildingName,
      routeSignature: "",
      sourceRouteSignature: String(raw.sourceRouteSignature || "").trim(),
      distance: null,
      autoSteps: [],
      finalSteps: manualText ? parseManualGuideText(manualText) : [],
      manualText,
      roomSupplementText,
      guideMode: manualText ? "manual" : "auto",
      status: "orphaned",
      routeAvailable: false,
      usesBuildingRoute: raw.destinationType === "room",
      notes: ["This guide no longer matches a destination in the current model."]
    };
  }

  return working;
}

function sortGuideEntries(entries) {
  return (Array.isArray(entries) ? entries : []).slice().sort((a, b) => {
    const rank = (entry) => entry?.destinationType === "room" ? 1 : 0;
    const rankDiff = rank(a) - rank(b);
    if (rankDiff) return rankDiff;
    const buildingDiff = String(a?.buildingName || "").localeCompare(String(b?.buildingName || ""));
    if (buildingDiff) return buildingDiff;
    return String(a?.roomName || "").localeCompare(String(b?.roomName || ""));
  });
}

function renderGuideSteps(container, steps, emptyText) {
  if (!container) return;
  container.replaceChildren();
  const list = Array.isArray(steps) ? steps.filter((step) => String(step?.text || "").trim()) : [];
  if (!list.length) {
    const empty = document.createElement("div");
    empty.className = "me-subtext";
    empty.textContent = emptyText;
    container.appendChild(empty);
    return;
  }

  const wrap = document.createElement("div");
  wrap.className = "me-guide-steps";
  list.forEach((step, index) => {
    const row = document.createElement("div");
    row.className = "me-guide-step";

    const num = document.createElement("div");
    num.className = "me-guide-step-num";
    num.textContent = String(index + 1);

    const text = document.createElement("div");
    text.textContent = String(step?.text || "").trim();

    row.appendChild(num);
    row.appendChild(text);
    wrap.appendChild(row);
  });
  container.appendChild(wrap);
}

function syncGuideSelection(entries) {
  const entryMap = new Map((Array.isArray(entries) ? entries : []).map((entry) => [entry.key, entry]));
  if (guideSelectionKey && entryMap.has(guideSelectionKey)) return;

  let preferred = "";
  if (selected && !selected.userData?.isPlaced) {
    const selectedName = normalizeExportBuildingName(getBuildingLabelForObject(selected) || selected.name || "");
    if (selectedName) {
      const resolved = resolveBuildingIdentity({ buildingName: selectedName, objectName: selectedName });
      preferred = buildGuideKey("building", {
        buildingName: resolved.buildingName || selectedName,
        buildingUid: resolved.buildingUid
      });
    }
  }
  if (preferred && entryMap.has(preferred)) {
    guideSelectionKey = preferred;
    return;
  }

  guideSelectionKey = entries.length ? entries[0].key : "";
}

function renderGuideEditorPanel(opts = {}) {
  const routes = (opts.routes && typeof opts.routes === "object")
    ? opts.routes
    : buildEditorPreviewRoutes();
  const entriesMap = buildGuideWorkingEntries({ routes });
  const entries = sortGuideEntries(Object.values(entriesMap));
  syncGuideSelection(entries);

  if (guideTargetSelectEl) {
    const previousValue = guideSelectionKey;
    guideTargetSelectEl.replaceChildren();
    if (!entries.length) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "No guide destinations yet";
      guideTargetSelectEl.appendChild(opt);
      guideTargetSelectEl.disabled = true;
    } else {
      guideTargetSelectEl.disabled = false;
      entries.forEach((entry) => {
        const opt = document.createElement("option");
        opt.value = entry.key;
        const prefix = entry.destinationType === "room"
          ? "Room"
          : entry.destinationType === "site"
            ? "Site"
            : "Building";
        const label = entry.roomName
          ? `${entry.buildingName} / ${entry.roomName}`
          : entry.buildingName;
        const suffix = entry.status === "stale"
          ? " • stale"
          : entry.status === "missing_route"
            ? " • no route"
            : entry.status === "orphaned"
              ? " • orphaned"
              : "";
        opt.textContent = `${prefix}: ${label}${suffix}`;
        guideTargetSelectEl.appendChild(opt);
      });
    }
    guideTargetSelectEl.value = entriesMap[previousValue] ? previousValue : guideSelectionKey;
  }

  const entry = guideSelectionKey ? entriesMap[guideSelectionKey] : null;

  if (guideStatusBadgeEl) {
    const state = entry?.status || "ok";
    guideStatusBadgeEl.dataset.state = state;
    guideStatusBadgeEl.textContent = state.replace(/_/g, " ");
  }
  if (guideStatusTextEl) {
    guideStatusTextEl.textContent = buildGuideStatusText(entry);
  }
  if (guideRouteSummaryEl) {
    guideRouteSummaryEl.textContent = buildGuideRouteSummary(entry);
  }
  renderGuideSteps(guideAutoPreviewEl, entry?.autoSteps || [], "No auto-generated guide available yet.");

  if (guideManualTextEl && !opts.preserveEditorText) {
    guideManualTextEl.value = entry?.manualText || "";
    guideManualTextEl.disabled = !entry;
  }
  if (guideUseAutoBtn) guideUseAutoBtn.disabled = !entry || !entry.manualText;
  if (guideFillAutoBtn) guideFillAutoBtn.disabled = !entry || !(entry.autoSteps || []).length;
  if (guideSaveManualBtn) guideSaveManualBtn.disabled = !entry;
  if (guideClearManualBtn) guideClearManualBtn.disabled = !entry || !String(entry.manualText || "").trim();
}

function buildGuidesPayloadForModel(modelName, opts = {}) {
  const routes = (opts.routes && typeof opts.routes === "object")
    ? opts.routes
    : (hasLiveRoadData() ? computeSavedRoutes() : savedRoutes);
  const includeOrphaned = opts.includeOrphaned !== false;
  const workingEntries = buildGuideWorkingEntries({ routes });
  const payloadEntries = {};

  for (const entry of sortGuideEntries(Object.values(workingEntries))) {
    if (!includeOrphaned && entry.status === "orphaned") continue;
    const manualText = String(entry.manualText || "").replace(/\r\n/g, "\n").trim();
    const finalSteps = manualText ? parseManualGuideText(manualText) : (Array.isArray(entry.autoSteps) ? entry.autoSteps : []);
    payloadEntries[entry.key] = {
      key: entry.key,
      destinationType: entry.destinationType,
      buildingName: entry.buildingName,
      buildingUid: entry.buildingUid,
      objectName: entry.objectName,
      roomName: entry.roomName,
      roomUid: String(entry.roomUid || "").trim(),
      facilityUid: String(entry.facilityUid || "").trim(),
      destinationName: entry.destinationName,
      routeName: entry.routeName,
      distance: entry.distance,
      routeSignature: entry.routeSignature,
      sourceRouteSignature: manualText ? entry.routeSignature : "",
      guideMode: manualText ? "manual" : "auto",
      status: entry.status,
      usesBuildingRoute: !!entry.usesBuildingRoute,
      manualText,
      roomSupplementText: String(entry.roomSupplementText || "").replace(/\r\n/g, "\n").trim(),
      notes: Array.isArray(entry.notes) ? entry.notes : [],
      autoSteps: Array.isArray(entry.autoSteps) ? entry.autoSteps : [],
      finalSteps
    };
  }

  return {
    generatedAt: Date.now(),
    entries: payloadEntries
  };
}

function buildRoadGraph(opts = {}) {
  const nodes = new Map(); // graphNodeId -> Vector3 (world)
  const edges = new Map(); // graphNodeId -> [{ to, w }]
  const idAliases = new Map(); // raw pointId -> graphNodeId
  const edgeMin = new Map();   // undirected key -> { a, b, w }

  const baseTol = Number(ROAD_AUTO_INTERSECT_DIST);
  const mergeTol = Math.max(0.5, Math.min(3, Number.isFinite(baseTol) ? baseTol * 0.2 : 1.6));
  const mergeTolSq = mergeTol * mergeTol;
  const endpointSnapTol = Math.max(
    3,
    Math.min(
      Number(ROAD_DEFAULT_WIDTH) || 12,
      (Number.isFinite(baseTol) ? baseTol : 8) + 2
    )
  );
  const cellSize = Math.max(0.25, mergeTol);
  const nodeCells = new Map();
  let syntheticNodeIdx = 1;

  const toCell = (v) => Math.floor(v / cellSize);
  const cellKey = (x, z) => `${toCell(x)},${toCell(z)}`;
  const edgeKey = (a, b) => {
    const sa = String(a);
    const sb = String(b);
    return sa < sb ? `${sa}\u0000${sb}` : `${sb}\u0000${sa}`;
  };

  const setAlias = (rawId, nodeId) => {
    if (rawId == null || rawId === "" || nodeId == null || nodeId === "") return;
    if (!idAliases.has(rawId)) idAliases.set(rawId, nodeId);
  };

  const registerNodeInCell = (id, pos) => {
    const key = cellKey(pos.x, pos.z);
    if (!nodeCells.has(key)) nodeCells.set(key, []);
    nodeCells.get(key).push(id);
  };

  const findNearbyNodeId = (pos) => {
    const cx = toCell(pos.x);
    const cz = toCell(pos.z);
    let bestId = null;
    let bestSq = Infinity;
    for (let dx = -1; dx <= 1; dx++) {
      for (let dz = -1; dz <= 1; dz++) {
        const key = `${cx + dx},${cz + dz}`;
        const bucket = nodeCells.get(key);
        if (!bucket) continue;
        for (const id of bucket) {
          const p = nodes.get(id);
          if (!p) continue;
          const ddx = p.x - pos.x;
          const ddz = p.z - pos.z;
          const d2 = ddx * ddx + ddz * ddz;
          if (d2 <= mergeTolSq && d2 < bestSq) {
            bestSq = d2;
            bestId = id;
          }
        }
      }
    }
    return bestId;
  };

  const newSyntheticNodeId = () => {
    let id = `route_n_${syntheticNodeIdx++}`;
    while (nodes.has(id)) id = `route_n_${syntheticNodeIdx++}`;
    return id;
  };

  const getOrCreateNodeId = (worldPos, preferredId = null) => {
    if (!worldPos) return null;
    const hasPreferred = !(preferredId == null || preferredId === "");
    if (hasPreferred && nodes.has(preferredId)) {
      const existing = nodes.get(preferredId);
      if (existing) {
        const dx = existing.x - worldPos.x;
        const dz = existing.z - worldPos.z;
        if ((dx * dx + dz * dz) <= mergeTolSq) {
          setAlias(preferredId, preferredId);
          return preferredId;
        }
      }
    }

    const nearId = findNearbyNodeId(worldPos);
    if (nearId) {
      setAlias(preferredId, nearId);
      return nearId;
    }

    const nodeId = (hasPreferred && !nodes.has(preferredId)) ? preferredId : newSyntheticNodeId();
    nodes.set(nodeId, worldPos.clone());
    registerNodeInCell(nodeId, worldPos);
    setAlias(preferredId, nodeId);
    return nodeId;
  };

  const addUndirectedEdge = (a, b, w) => {
    if (a == null || a === "" || b == null || b === "" || a === b) return;
    if (!Number.isFinite(w) || w <= 1e-6) return;
    const key = edgeKey(a, b);
    const prev = edgeMin.get(key);
    if (!prev || w < prev.w) edgeMin.set(key, { a, b, w });
  };

  const segmentCutNear = (a, b) => {
    if (!a || !b || !a.point || !b.point) return false;
    if (Math.abs((a.t ?? 0) - (b.t ?? 0)) <= 1e-4) return true;
    const dx = a.point.x - b.point.x;
    const dz = a.point.z - b.point.z;
    return ((dx * dx + dz * dz) <= (mergeTolSq * 0.25));
  };

  const pushSegmentCut = (segment, cut) => {
    if (!segment || !cut || !cut.point || !Number.isFinite(cut.t)) return;
    const t = Math.max(0, Math.min(1, cut.t));
    const entry = { t, point: cut.point.clone(), pointId: cut.pointId || null };
    const existing = segment.cuts.find(c => segmentCutNear(c, entry));
    if (!existing) {
      segment.cuts.push(entry);
      return;
    }
    if (!existing.pointId && entry.pointId) existing.pointId = entry.pointId;
  };

  const projectPointOnSegmentXZ = (p, a, b, t) => {
    const tt = Math.max(0, Math.min(1, Number(t) || 0));
    const x = a.x + (b.x - a.x) * tt;
    const z = a.z + (b.z - a.z) * tt;
    const refY = Number.isFinite(p?.y) ? p.y : (a.y + b.y) * 0.5;
    return new THREE.Vector3(x, sampleRoadBaseYAtXZ(x, z, refY), z);
  };

  const connectEndpointToSegmentIfNear = (segEndpointOwner, endpointT, segTarget) => {
    if (!segEndpointOwner || !segTarget) return;
    const endpoint = endpointT === 0 ? segEndpointOwner.a : segEndpointOwner.b;
    if (!endpoint) return;

    const widthA = Number(segEndpointOwner.roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    const widthB = Number(segTarget.roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    const widthTol = Math.max(
      0,
      (((Number.isFinite(widthA) ? widthA : ROAD_DEFAULT_WIDTH) + (Number.isFinite(widthB) ? widthB : ROAD_DEFAULT_WIDTH)) * 0.5) + 1
    );
    const nearTol = Math.max(endpointSnapTol, widthTol);

    const res = pointSegmentDistanceXZ(endpoint, segTarget.a, segTarget.b);
    if (!res || !Number.isFinite(res.dist) || res.dist > nearTol) return;

    const tt = Math.max(0, Math.min(1, Number(res.t) || 0));
    const projected = projectPointOnSegmentXZ(endpoint, segTarget.a, segTarget.b, tt);
    const endpointPointId = endpointT === 0
      ? (segEndpointOwner.cuts[0]?.pointId || null)
      : (segEndpointOwner.cuts[1]?.pointId || null);
    pushSegmentCut(segEndpointOwner, { t: endpointT, point: endpoint, pointId: endpointPointId });
    pushSegmentCut(segTarget, { t: tt, point: projected });
  };

  const segments = [];
  for (const obj of getRoadObjects(opts)) {
    ensureRoadPointMetaArrays(obj);
    const ptsLocal = getRoadLocalPoints(obj);
    const ids = Array.isArray(obj.userData?.road?.pointIds) ? obj.userData.road.pointIds : [];
    if (!Array.isArray(ptsLocal) || ptsLocal.length < 2) continue;

    obj.updateMatrixWorld(true);
    const ptsWorld = ptsLocal.map(p => obj.localToWorld(p.clone()));

    for (let i = 0; i < ptsWorld.length - 1; i++) {
      const a = ptsWorld[i];
      const b = ptsWorld[i + 1];
      if (!a || !b) continue;
      const w = Math.hypot(b.x - a.x, b.z - a.z);
      if (!Number.isFinite(w) || w <= 1e-6) continue;
      segments.push({
        roadObj: obj,
        segIndex: i,
        a,
        b,
        cuts: [
          { t: 0, point: a.clone(), pointId: ids[i] || null },
          { t: 1, point: b.clone(), pointId: ids[i + 1] || null }
        ]
      });
    }
  }

  // Geometry-based splitting: connect any segment intersections, not just shared pointIds.
  for (let i = 0; i < segments.length; i++) {
    for (let j = i + 1; j < segments.length; j++) {
      const s1 = segments[i];
      const s2 = segments[j];
      if (!s1 || !s2) continue;
      if (s1.roadObj === s2.roadObj && Math.abs(s1.segIndex - s2.segIndex) <= 1) continue;

      const hit = segmentIntersectXZ(s1.a, s1.b, s2.a, s2.b, 1e-6);
      if (!hit) continue;
      const refY = (s1.a.y + s1.b.y + s2.a.y + s2.b.y) * 0.25;
      const p = new THREE.Vector3(hit.x, sampleRoadBaseYAtXZ(hit.x, hit.z, refY), hit.z);
      pushSegmentCut(s1, { t: hit.t, point: p });
      pushSegmentCut(s2, { t: hit.u, point: p });
    }
  }

  // Treat near endpoint-to-segment touches as graph junctions.
  for (let i = 0; i < segments.length; i++) {
    for (let j = i + 1; j < segments.length; j++) {
      const s1 = segments[i];
      const s2 = segments[j];
      if (!s1 || !s2) continue;
      if (s1.roadObj === s2.roadObj && Math.abs(s1.segIndex - s2.segIndex) <= 1) continue;
      connectEndpointToSegmentIfNear(s1, 0, s2);
      connectEndpointToSegmentIfNear(s1, 1, s2);
      connectEndpointToSegmentIfNear(s2, 0, s1);
      connectEndpointToSegmentIfNear(s2, 1, s1);
    }
  }

  for (const seg of segments) {
    seg.cuts.sort((a, b) => a.t - b.t);
    const resolved = [];
    for (const cut of seg.cuts) {
      const nodeId = getOrCreateNodeId(cut.point, cut.pointId || null);
      if (!nodeId) continue;
      if (resolved.length && resolved[resolved.length - 1].nodeId === nodeId) continue;
      resolved.push({ nodeId, point: cut.point });
    }
    for (let i = 0; i < resolved.length - 1; i++) {
      const c0 = resolved[i];
      const c1 = resolved[i + 1];
      const w = Math.hypot(c1.point.x - c0.point.x, c1.point.z - c0.point.z);
      addUndirectedEdge(c0.nodeId, c1.nodeId, w);
    }
  }

  for (const e of edgeMin.values()) {
    if (!edges.has(e.a)) edges.set(e.a, []);
    if (!edges.has(e.b)) edges.set(e.b, []);
    edges.get(e.a).push({ to: e.b, w: e.w });
    edges.get(e.b).push({ to: e.a, w: e.w });
  }

  return { nodes, edges, idAliases };
}

function resolveGraphNodeId(graph, rawId) {
  if (!graph || rawId == null || rawId === "") return null;
  if (graph.nodes?.has?.(rawId)) return rawId;
  const alias = graph.idAliases?.get?.(rawId);
  if (alias != null && alias !== "" && graph.nodes?.has?.(alias)) return alias;
  return null;
}

function findNearestGraphNodeId(nodes, worldPoint, maxDist = Infinity) {
  if (!nodes || !worldPoint) return null;
  let bestId = null;
  let bestDist = Infinity;
  for (const [id, p] of nodes.entries()) {
    if (!p) continue;
    const d = Math.hypot(p.x - worldPoint.x, p.z - worldPoint.z);
    if (d < bestDist) {
      bestDist = d;
      bestId = id;
    }
  }
  if (!bestId) return null;
  return (Number.isFinite(maxDist) && bestDist > maxDist) ? null : bestId;
}

function resolveCandidateNodeIds(graph, candidates) {
  if (!graph || !Array.isArray(candidates) || !candidates.length) return [];
  const ids = new Set();
  const attachMax = Math.max(12, Number(ROAD_DEFAULT_WIDTH) || 10);
  for (const it of candidates) {
    const byId = resolveGraphNodeId(graph, it?.id || null);
    if (byId) {
      ids.add(byId);
      continue;
    }
    if (it?.world) {
      const nearest = findNearestGraphNodeId(graph.nodes, it.world, attachMax);
      if (nearest) ids.add(nearest);
    }
  }
  return Array.from(ids);
}

function findKioskStartPoints(opts = {}) {
  const points = [];
  for (const obj of getRoadObjects(opts)) {
    ensureRoadPointMetaArrays(obj);
    const metaArr = Array.isArray(obj.userData?.road?.pointMeta) ? obj.userData.road.pointMeta : [];
    const idArr = Array.isArray(obj.userData?.road?.pointIds) ? obj.userData.road.pointIds : [];
    const ptsLocal = getRoadLocalPoints(obj);
    if (!metaArr.length || !ptsLocal.length) continue;
    obj.updateMatrixWorld(true);
    for (let i = 0; i < metaArr.length; i++) {
      const meta = metaArr[i];
      if (!isKioskMeta(meta)) continue;
      const local = ptsLocal[i] || null;
      const world = local ? obj.localToWorld(local.clone()) : null;
      points.push({ id: idArr[i] || meta?.id || null, world });
    }
  }
  return points;
}

function findKioskStartPointId() {
  const points = findKioskStartPoints();
  for (const p of points) {
    if (p?.id != null && p.id !== "") return p.id;
  }
  return points[0]?.id || null;
}

function getRoadMetaBuildingIdentity(meta) {
  if (!meta || isKioskMeta(meta)) return null;
  const building = (meta.building && typeof meta.building === "object") ? meta.building : {};
  const resolved = resolveAttachTargetIdentity({
    buildingUid: building.uid || meta.buildingUid || meta.destinationUid || "",
    buildingName: building.name || meta.buildingName || meta.name || "",
    objectName: building.objectName || meta.objectName || meta.modelObjectName || "",
    entityType: building.type || meta.entityType || ""
  });
  const key = String(resolved.key || "").trim();
  if (!key) return null;
  return {
    ...resolved,
    side: String(building.side || "").trim(),
    key
  };
}

function getEntranceBuildingLinks(opts = {}) {
  const entries = new Map();
  for (const obj of getRoadObjects(opts)) {
    ensureRoadPointMetaArrays(obj);
    const metaArr = Array.isArray(obj.userData?.road?.pointMeta) ? obj.userData.road.pointMeta : [];
    for (const meta of metaArr) {
      const identity = getRoadMetaBuildingIdentity(meta);
      if (!identity || !identity.key || entries.has(identity.key)) continue;
      entries.set(identity.key, identity);
    }
  }
  return Array.from(entries.values());
}

function findBuildingEntrancePoints(targetBuilding, opts = {}) {
  const resolvedTarget = typeof targetBuilding === "string"
    ? resolveBuildingIdentity({ buildingName: targetBuilding, objectName: targetBuilding })
    : resolveBuildingIdentity(targetBuilding || {});
  const targetKey = buildBuildingIdentityKey(resolvedTarget);
  if (!targetKey) return [];
  const points = [];

  for (const obj of getRoadObjects(opts)) {
    ensureRoadPointMetaArrays(obj);
    const metaArr = Array.isArray(obj.userData?.road?.pointMeta) ? obj.userData.road.pointMeta : [];
    const idArr = Array.isArray(obj.userData?.road?.pointIds) ? obj.userData.road.pointIds : [];
    const ptsLocal = getRoadLocalPoints(obj);
    if (!metaArr.length || !ptsLocal.length) continue;
    obj.updateMatrixWorld(true);
    for (let i = 0; i < metaArr.length; i++) {
      const meta = metaArr[i];
      const identity = getRoadMetaBuildingIdentity(meta);
      if (!identity || identity.key !== targetKey) continue;
      const local = ptsLocal[i] || null;
      const world = local ? obj.localToWorld(local.clone()) : null;
      points.push({ id: idArr[i] || meta?.id || null, world });
    }
  }

  return points;
}

function findBuildingEntrancePointIds(buildingName) {
  const ids = new Set();
  const points = findBuildingEntrancePoints(buildingName);
  for (const p of points) {
    if (p?.id != null && p.id !== "") ids.add(p.id);
  }
  return Array.from(ids);
}

function getEntranceBuildingNames(opts = {}) {
  return getEntranceBuildingLinks(opts)
    .map((entry) => String(entry?.buildingName || entry?.objectName || "").trim())
    .filter(Boolean);
}

function computeSavedRoutes(opts = {}) {
  const graph = buildRoadGraph(opts);
  const { nodes, edges } = graph;
  const kioskPoints = findKioskStartPoints(opts);
  const kioskNodeIds = resolveCandidateNodeIds(graph, kioskPoints);
  if (!kioskNodeIds.length) return {};

  const dijkstraRuns = kioskNodeIds.map(startId => {
    const { dist, prev } = dijkstra(nodes, edges, startId);
    return { startId, dist, prev };
  });

  const routes = {};
  const buildingLinks = getEntranceBuildingLinks(opts);

  for (const buildingLink of buildingLinks) {
    const key = buildBuildingIdentityKey(buildingLink);
    const targetPoints = findBuildingEntrancePoints(buildingLink, opts);
    const targetNodeIds = resolveCandidateNodeIds(graph, targetPoints);
    if (!targetNodeIds.length) continue;

    let best = null;
    for (const run of dijkstraRuns) {
      for (const targetId of targetNodeIds) {
        const d = run.dist.get(targetId);
        if (!Number.isFinite(d)) continue;
        if (!best || d < best.dist) {
          best = { startId: run.startId, targetId, dist: d, prev: run.prev };
        }
      }
    }
    if (!best || !Number.isFinite(best.dist)) continue;

    const path = buildPath(best.prev, best.startId, best.targetId);
    if (!path || path.length < 2) continue;

    const points = [];
    for (const id of path) {
      const p = nodes.get(id);
      if (!p) continue;
      points.push([p.x, p.y, p.z]);
    }
    if (points.length < 2) continue;

    routes[key] = {
      name: String(buildingLink?.buildingName || buildingLink?.objectName || "").trim(),
      buildingUid: String(buildingLink?.buildingUid || "").trim(),
      objectName: String(buildingLink?.objectName || "").trim(),
      distance: best.dist,
      points,
      updated: Date.now()
    };
  }

  return routes;
}

async function loadRoutesForModel(modelName) {
  if (!modelName) return;
  try {
    const res = await apiFetch(`mapEditor.php?action=load_routes&name=${encodeURIComponent(modelName)}&_=${Date.now()}`);
    const data = await res.json();
    if (data && data.routes && typeof data.routes === "object") {
      setSavedRoutes(data.routes);
    } else {
      setSavedRoutes({});
    }
  } catch (_) {
    setSavedRoutes({});
  }
  renderGuideEditorPanel();
}

async function saveRoutesForModel(modelName, routes) {
  if (!modelName) return false;
  try {
    const payload = { routes: routes || {} };
    const res = await apiFetch(`mapEditor.php?action=save_routes&name=${encodeURIComponent(modelName)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    return !!data?.ok;
  } catch (_) {
    return false;
  }
}

async function loadRoadnetForModel(modelName, opts = {}) {
  const {
    replaceExisting = true,
    keepExistingWhenMissing = false
  } = opts;

  if (!modelName) return { exists: false, loaded: 0 };

  let exists = false;
  let roads = [];
  try {
    const res = await apiFetch(`mapEditor.php?action=load_roadnet&name=${encodeURIComponent(modelName)}&_=${Date.now()}`);
    const data = await res.json();
    exists = !!data?.exists;
    roads = Array.isArray(data?.roads) ? data.roads : [];
  } catch (_) {
    exists = false;
    roads = [];
  }

  const shouldReplace = replaceExisting && (exists || !keepExistingWhenMissing);
  if (shouldReplace) clearRoadObjectsFromOverlay();

  let loaded = 0;
  for (const roadItem of roads) {
    const roadObj = spawnSerializedRoadObject(roadItem);
    if (roadObj) loaded++;
  }

  updateSpecialPointsPanel();
  return { exists, loaded };
}

async function saveRoadnetForModel(modelName, roads) {
  if (!modelName) return false;
  try {
    const payload = { roads: Array.isArray(roads) ? roads : [] };
    const res = await apiFetch(`mapEditor.php?action=save_roadnet&name=${encodeURIComponent(modelName)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    return !!data?.ok;
  } catch (_) {
    return false;
  }
}

async function loadGuidesForModel(modelName) {
  if (!modelName) {
    setLoadedGuidesPayload({ model: "", entries: {} });
    renderGuideEditorPanel();
    return;
  }
  try {
    const res = await apiFetch(`mapEditor.php?action=load_guides&name=${encodeURIComponent(modelName)}&_=${Date.now()}`);
    const data = await res.json();
    setLoadedGuidesPayload({
      model: modelName,
      entries: (data && typeof data.entries === "object") ? data.entries : {}
    });
  } catch (_) {
    setLoadedGuidesPayload({ model: modelName, entries: {} });
  }
  renderGuideEditorPanel();
}

async function saveNavigationBundleForModel(modelName, payload) {
  if (!modelName) return false;
  try {
    const res = await apiFetch(`mapEditor.php?action=save_navigation_bundle&name=${encodeURIComponent(modelName)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload || {})
    });
    const data = await res.json();
    return !!data?.ok;
  } catch (_) {
    return false;
  }
}

async function saveRoadDataForModel(modelName, opts = {}) {
  if (!modelName) throw new Error("No model selected");

  const roads = Array.isArray(opts.roads) ? opts.roads : serializeRoadnet();
  const routes = (opts.routes && typeof opts.routes === "object") ? opts.routes : computeSavedRoutes();
  const guides = (opts.guides && typeof opts.guides === "object")
    ? opts.guides
    : buildGuidesPayloadForModel(modelName, {
        routes,
        includeOrphaned: opts.includeOrphaned !== false
      });
  const shouldUpdateLocalState = opts.updateLocalState !== false && modelName === currentModelName;
  const bundleOk = await saveNavigationBundleForModel(modelName, {
    roads,
    routes,
    guides
  });

  if (!bundleOk) {
    throw new Error("Failed to save roadnet + routes + guides data");
  }

  if (shouldUpdateLocalState) {
    setSavedRoutes(routes);
    setLoadedGuidesPayload({ model: modelName, entries: guides.entries || {} });
    renderGuideEditorPanel({ routes });
  }
  return { roads, routes, guides };
}

function dijkstra(nodes, edges, startId) {
  const dist = new Map();
  const prev = new Map();
  const visited = new Set();

  for (const id of nodes.keys()) dist.set(id, Infinity);
  if (!nodes.has(startId)) return { dist, prev };
  dist.set(startId, 0);

  while (visited.size < nodes.size) {
    let u = null;
    let best = Infinity;
    for (const [id, d] of dist.entries()) {
      if (visited.has(id)) continue;
      if (d < best) { best = d; u = id; }
    }
    if (!u || best === Infinity) break;
    visited.add(u);
    const neighbors = edges.get(u) || [];
    for (const e of neighbors) {
      const alt = best + (Number(e?.w) || 0);
      if (alt < (dist.get(e.to) ?? Infinity)) {
        dist.set(e.to, alt);
        prev.set(e.to, u);
      }
    }
  }
  return { dist, prev };
}

function buildPath(prev, startId, endId) {
  const path = [];
  let cur = endId;
  while (cur) {
    path.push(cur);
    if (cur === startId) break;
    cur = prev.get(cur);
  }
  if (path[path.length - 1] !== startId) return null;
  return path.reverse();
}

function drawRouteLine(pathIds, nodes) {
  if (!Array.isArray(pathIds) || pathIds.length < 2) return false;

  const points = [];
  for (const id of pathIds) {
    const p = nodes.get(id);
    if (!p) continue;
    points.push(p);
  }
  return drawRouteFromWorldPoints(points);
}

function routeToBuilding(buildingName) {
  const name = String(buildingName || "").trim();
  if (!name) return;
  const key = routeKey(name);
  setGuideSelectionForBuilding(name);

  const drawSavedRouteFallback = (reason = "") => {
    const saved = getSavedRouteEntry(name);
    const savedPointsArr = Array.isArray(saved?.points) ? saved.points : [];
    if (savedPointsArr.length < 2) return false;

    const pts = savedPointsArr.map((p) => Array.isArray(p) ? vec3FromArray(p) : p).filter(Boolean);
    const ok = drawRouteFromWorldPoints(pts);
    if (!ok) return false;

    let dist = Number(saved?.distance);
    if (!Number.isFinite(dist)) {
      dist = 0;
      for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1], b = pts[i];
        dist += Math.hypot((b.x - a.x), (b.z - a.z));
      }
    }

    const suffix = reason ? ` (${reason})` : "";
    setRouteBanner(`Route to ${name} \u2022 ${dist.toFixed(0)} units${suffix}`);
    return true;
  };

  if (!hasLiveRoadData()) {
    if (drawSavedRouteFallback()) return;
    clearRouteLine();
    setRouteBanner(`No pathway for ${name} (no saved route)`);
    return;
  }

  const graph = buildRoadGraph();
  const { nodes, edges } = graph;

  const kioskPoints = findKioskStartPoints();
  if (!kioskPoints.length) {
    clearRouteLine();
    setRouteBanner("No kiosk start set");
    return;
  }
  const kioskNodeIds = resolveCandidateNodeIds(graph, kioskPoints);
  if (!kioskNodeIds.length) {
    clearRouteLine();
    setRouteBanner("Kiosk start is not connected to any road");
    return;
  }

  const targetPoints = findBuildingEntrancePoints(name);
  if (!targetPoints.length) {
    if (drawSavedRouteFallback("saved route fallback")) return;
    clearRouteLine();
    setRouteBanner(`No pathway for ${name} (no entrance point)`);
    return;
  }
  const validTargets = resolveCandidateNodeIds(graph, targetPoints);
  if (!validTargets.length) {
    if (drawSavedRouteFallback("saved route fallback")) return;
    clearRouteLine();
    setRouteBanner(`No pathway for ${name} (entrance not connected)`);
    return;
  }

  let best = null;
  for (const startId of kioskNodeIds) {
    const { dist, prev } = dijkstra(nodes, edges, startId);
    for (const targetId of validTargets) {
      const d = dist.get(targetId);
      if (!Number.isFinite(d)) continue;
      if (!best || d < best.dist) {
        best = { startId, targetId, dist: d, prev };
      }
    }
  }

  if (!best || !Number.isFinite(best.dist)) {
    if (drawSavedRouteFallback("saved route fallback")) return;
    clearRouteLine();
    setRouteBanner(`No route found to ${name}`);
    return;
  }

  const path = buildPath(best.prev, best.startId, best.targetId);
  if (!path || path.length < 2) {
    if (path && path.length === 1) {
      clearRouteLine();
      setRouteBanner(`Kiosk is at ${name}`);
      return;
    }
    if (drawSavedRouteFallback("saved route fallback")) return;
    clearRouteLine();
    setRouteBanner(`No route found to ${name}`);
    return;
  }

  const ok = drawRouteLine(path, nodes);
  if (!ok) {
    if (drawSavedRouteFallback("saved route fallback")) return;
    setRouteBanner(`No route found to ${name}`);
    return;
  }

  const pathPoints = path
    .map(id => nodes.get(id))
    .filter(p => p)
    .map(p => [p.x, p.y, p.z]);
  if (pathPoints.length >= 2) {
    savedRoutes[key] = {
      name,
      distance: best.dist,
      points: pathPoints,
      updated: Date.now()
    };
  }

  setRouteBanner(`Route to ${name} \u2022 ${best.dist.toFixed(0)} units`);
}

function clearActiveTool(showStatus = true) {
  currentTool = "none";
  roadKioskPlaceMode = false;
  setActiveToolButton(null);
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  syncTransformToSelection();
  updateToolIndicator();
  hideAngleReadout();
  if (controls) controls.enabled = true;
  if (showStatus) setStatus("Tool cleared");
}

setStatus("BootingÃ¢â‚¬Â¦");
updateToolIndicator();
setSidebarCollapsed(false);
sidebarToggleBtn?.addEventListener("click", () => {
  setSidebarCollapsed(true);
});
sidebarFloatToggleBtn?.addEventListener("click", () => {
  setSidebarCollapsed(!layoutEl?.classList.contains("me-collapsed"));
});

// Block TransformControls keyboard mode switching (W/E/R)
const TRANSFORM_KEY_BLOCK = new Set(["KeyW", "KeyE", "KeyR"]);
window.addEventListener("keydown", (e) => {
  if (TRANSFORM_KEY_BLOCK.has(e.code)) {
    e.preventDefault();
    e.stopImmediatePropagation();
  }
}, true);

// -------------------- Renderer / Scene / Camera --------------------
const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.outputColorSpace = THREE.SRGBColorSpace;
mapStage.appendChild(renderer.domElement);
const canvas = renderer.domElement;
canvas.style.display = "block";
canvas.style.width = "100%";
canvas.style.height = "100%";
canvas.style.touchAction = "none";

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xf3f4f6);

const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 10000);
camera.position.set(0, 500, 500);
const controls = new OrbitControls(camera, canvas);
controls.enableDamping = true;
controls.dampingFactor = 0.08;
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
canvas.addEventListener("contextmenu", (e) => e.preventDefault());

// Pointer tracking (helps make Road tool touch-friendly: 1-finger edits, 2-finger camera)
const activeTouchPointerIds = new Set();
function syncControlsForPointers() {
  if (currentTool === "road") {
    const touchCount = activeTouchPointerIds.size;
    controls.enabled = touchCount !== 1 && !isDraggingRoadHandle && !isDraggingRoadSegment;
  }
}
renderer.domElement.addEventListener("pointerdown", (e) => {
  if (e.pointerType === "touch" || e.pointerType === "pen") activeTouchPointerIds.add(e.pointerId);
  if (currentTool === "road" && roadTap && activeTouchPointerIds.size > 1) roadTap.hadMultiTouch = true;
  syncControlsForPointers();
}, true);
window.addEventListener("pointerup", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  syncControlsForPointers();
}, true);
window.addEventListener("pointercancel", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  syncControlsForPointers();
}, true);

// Lights
scene.add(new THREE.AmbientLight(0xffffff, 0.9));
const dir = new THREE.DirectionalLight(0xffffff, 0.8);
dir.position.set(200, 300, 150);
scene.add(dir);

// Helpers
scene.add(new THREE.GridHelper(5000, 200));
scene.add(new THREE.AxesHelper(100));

// Resize
function resize() {
  const w = mapStage.clientWidth;
  const h = mapStage.clientHeight;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}
window.addEventListener("resize", resize);

// -------------------- Base Model --------------------
const MODEL_DIR = "../models/";
const ORIGINAL_MODEL_NAME = "tnts_navigation.glb";
let currentModelName = ORIGINAL_MODEL_NAME;
let assetsLoaded = false;
let isModelSwitching = false;
let modelBuildingCatalog = [];
let modelBuildingsByUid = new Map();
let modelBuildingsByNameKey = new Map();
let modelBuildingsByObjectKey = new Map();

const loader = new GLTFLoader();

let campusRoot = null;
let defaultCameraState = null;
let isTopView = false;

// Overlay root (placed assets)
const overlayRoot = new THREE.Group();
overlayRoot.name = "overlayRoot";
scene.add(overlayRoot);
overlayReady = true;

let overlayBaselineSnapshot = null;
let baseBaselineSnapshot = null;
let dirtyRefreshTimer = null;
let loadedGuidePayload = { model: "", entries: {} };
let guideSelectionKey = "";
let currentDraftSession = {
  model: ORIGINAL_MODEL_NAME,
  exists: false,
  active: false,
  savedAt: 0,
  hasBaseDraft: false,
  baseDraftFile: "",
  baseDraftUrl: ""
};

function roundSnapshotNumber(n) {
  const v = Number(n);
  if (!Number.isFinite(v)) return 0;
  return Number(v.toFixed(6));
}
function normalizeDraftSavedAt(value) {
  const n = Number(value);
  if (!Number.isFinite(n) || n <= 0) return 0;
  return n < 1000000000000 ? n * 1000 : n;
}
function setCurrentDraftSession(meta = {}) {
  currentDraftSession = {
    model: String(meta?.model || currentModelName || "").trim(),
    exists: !!meta?.exists,
    active: !!meta?.active,
    savedAt: normalizeDraftSavedAt(meta?.savedAt),
    hasBaseDraft: !!meta?.hasBaseDraft,
    baseDraftFile: String(meta?.baseDraftFile || "").trim(),
    baseDraftUrl: String(meta?.baseDraftUrl || "").trim()
  };
}
function isDraftSessionActive() {
  return !!(currentDraftSession?.active && currentDraftSession?.model === currentModelName);
}
function hasSavedDraftForCurrentModel() {
  return !!(currentDraftSession?.exists && currentDraftSession?.model === currentModelName);
}
function isOverlaySaveAllowed() {
  return currentModelName === ORIGINAL_MODEL_NAME;
}
function isRoadnetSaveAllowed() {
  return !!currentModelName && !!campusRoot;
}
function updateOverlaySaveAvailability() {
  if (!toolSave) return;
  const canSave = isRoadnetSaveAllowed();
  toolSave.disabled = !canSave;
  toolSave.style.opacity = canSave ? "1" : "0.6";
  toolSave.textContent = "Save Draft";
  toolSave.dataset.short = "Dr";
  toolSave.title = "Save the current draft without overwriting the loaded committed model.";
}
function buildOverlaySnapshot() {
  const out = [];
  for (const obj of (overlayRoot?.children || [])) {
    if (!obj?.userData?.isPlaced) continue;
    const type = obj.userData?.type || "asset";
    const entry = {
      type,
      id: obj.userData?.id || null,
      asset: type === "asset" ? (obj.userData?.asset || null) : null,
      name: obj.userData?.nameLabel || "",
      position: [roundSnapshotNumber(obj.position.x), roundSnapshotNumber(obj.position.y), roundSnapshotNumber(obj.position.z)],
      rotation: [roundSnapshotNumber(obj.rotation.x), roundSnapshotNumber(obj.rotation.y), roundSnapshotNumber(obj.rotation.z)],
      scale: [roundSnapshotNumber(obj.scale.x), roundSnapshotNumber(obj.scale.y), roundSnapshotNumber(obj.scale.z)],
      locked: !!obj.userData?.locked,
    };
    if (type === "road") {
      const road = obj.userData?.road || {};
      entry.width = roundSnapshotNumber(Number(road.width || ROAD_DEFAULT_WIDTH));
      entry.points = Array.isArray(road.points)
        ? road.points.map((p) => Array.isArray(p)
          ? [roundSnapshotNumber(p[0]), roundSnapshotNumber(p[1]), roundSnapshotNumber(p[2])]
          : null)
        : [];
      entry.pointIds = Array.isArray(road.pointIds) ? road.pointIds.slice() : [];
      entry.pointMeta = Array.isArray(road.pointMeta)
        ? JSON.parse(JSON.stringify(road.pointMeta))
        : [];
    }
    out.push(entry);
  }
  return JSON.stringify({
    items: out,
    guides: buildGuideRawSnapshotValue()
  });
}
function buildBaseSnapshot() {
  if (!campusRoot) return "";
  const out = [];
  campusRoot.traverse((obj) => {
    if (!obj || obj.userData?.isPlaced) return;
    out.push([
      obj.uuid,
      roundSnapshotNumber(obj.position.x), roundSnapshotNumber(obj.position.y), roundSnapshotNumber(obj.position.z),
      roundSnapshotNumber(obj.quaternion.x), roundSnapshotNumber(obj.quaternion.y), roundSnapshotNumber(obj.quaternion.z), roundSnapshotNumber(obj.quaternion.w),
      roundSnapshotNumber(obj.scale.x), roundSnapshotNumber(obj.scale.y), roundSnapshotNumber(obj.scale.z),
    ]);
  });
  return JSON.stringify(out);
}
function hasUnsavedOverlayChanges() {
  if (overlayBaselineSnapshot == null) return false;
  return buildOverlaySnapshot() !== overlayBaselineSnapshot;
}
function hasUnsavedBaseChanges() {
  if (baseBaselineSnapshot == null) return false;
  return buildBaseSnapshot() !== baseBaselineSnapshot;
}
function getUnsavedChangeState() {
  const overlay = hasUnsavedOverlayChanges();
  const base = hasUnsavedBaseChanges();
  return { overlay, base, any: overlay || base };
}
function updateDirtyIndicator() {
  const state = getUnsavedChangeState();
  if (dirtyIndicatorEl) {
    if (!state.any) {
      if (isDraftSessionActive()) {
        dirtyIndicatorEl.textContent = "Draft saved";
        dirtyIndicatorEl.style.color = "#2563eb";
      } else if (hasSavedDraftForCurrentModel()) {
        dirtyIndicatorEl.textContent = "Committed version loaded (draft available)";
        dirtyIndicatorEl.style.color = "#6b7280";
      } else {
        dirtyIndicatorEl.textContent = "Committed version loaded";
        dirtyIndicatorEl.style.color = "#6b7280";
      }
    } else if (state.overlay && state.base) {
      dirtyIndicatorEl.textContent = "Unsaved draft changes: navigation + base model";
      dirtyIndicatorEl.style.color = "#b45309";
    } else if (state.overlay) {
      dirtyIndicatorEl.textContent = "Unsaved draft changes: navigation";
      dirtyIndicatorEl.style.color = "#b45309";
    } else {
      dirtyIndicatorEl.textContent = "Unsaved draft changes: base model";
      dirtyIndicatorEl.style.color = "#b45309";
    }
  }
  updateOverlaySaveAvailability();
}
function scheduleDirtyRefresh() {
  if (dirtyRefreshTimer != null) return;
  dirtyRefreshTimer = setTimeout(() => {
    dirtyRefreshTimer = null;
    updateDirtyIndicator();
  }, 0);
}
function captureDirtyBaselines(opts = {}) {
  const { overlay = true, base = true } = opts;
  if (overlay) overlayBaselineSnapshot = buildOverlaySnapshot();
  if (base) baseBaselineSnapshot = buildBaseSnapshot();
  updateDirtyIndicator();
}
function getUnsavedSwitchWarning() {
  const state = getUnsavedChangeState();
  if (!state.any) return "";
  if (state.overlay && state.base) {
    return "You have unsaved draft navigation edits and unsaved base-model edits.\n\nUse Save Draft to keep this workspace, or Export GLB to turn it into a committed child version.\n\nContinue switching models?";
  }
  if (state.overlay) {
    return "You have unsaved draft navigation edits.\n\nUse Save Draft to keep them before switching models.\n\nContinue switching models?";
  }
  return "You have unsaved draft base-model edits.\n\nUse Save Draft to keep them, then Export GLB when you want a committed child version.\n\nContinue switching models?";
}
function withBaseEditExportHint(msg, obj) {
  if (!obj) return msg;
  if (obj.userData?.isPlaced) return msg;
  if (obj.userData?.locked) return msg;
  return `${msg} Use Export GLB to persist this base-model edit.`;
}

// -------------------- Roads (generated geometry) --------------------
const roadDraftRoot = new THREE.Group();
roadDraftRoot.name = "roadDraftRoot";
scene.add(roadDraftRoot);

const roadEditRoot = new THREE.Group();
roadEditRoot.name = "roadEditRoot";
roadEditRoot.visible = false;
scene.add(roadEditRoot);

// Hover/ghost preview for next road segment (shown before tapping to place)
const roadHoverRoot = new THREE.Group();
roadHoverRoot.name = "roadHoverRoot";
roadHoverRoot.visible = false;
scene.add(roadHoverRoot);

const roadMaterial = new THREE.MeshStandardMaterial({
  color: 0x9ca3af,
  roughness: 1.0,
  metalness: 0.0,
  polygonOffset: true,
  polygonOffsetFactor: -4,
  polygonOffsetUnits: -4
});
const roadSideMaterial = new THREE.MeshStandardMaterial({
  color: 0x6b7280,
  roughness: 1.0,
  metalness: 0.0,
  polygonOffset: true,
  polygonOffsetFactor: -3,
  polygonOffsetUnits: -3
});
const roadPreviewMaterial = new THREE.MeshBasicMaterial({
  color: 0x2563eb,
  transparent: true,
  opacity: 0.25,
  depthTest: false
});
const roadHandleMaterial = new THREE.MeshBasicMaterial({
  color: 0xf59e0b,
  depthTest: false
});
const roadKioskHandleMaterial = new THREE.MeshBasicMaterial({
  color: 0xef4444,
  depthTest: false
});

const roadHandleGeometry = new THREE.SphereGeometry(1, 16, 16);

function hideRoadHoverPreview() {
  roadHoverRoot.visible = false;
}

function showRoadHoverPreviewSegment(aWorld, bWorld, width) {
  if (!aWorld || !bWorld) return hideRoadHoverPreview();
  roadHoverRoot.visible = true;
  ensureRoadMeshFor(roadHoverRoot, [aWorld, bWorld], width, { preview: true });
}

// Road snapping is angle-based (relative to the start point), NOT grid-based.
const ROAD_SURFACE_NORMAL_MIN_Y = 0.35; // only treat upward-ish faces as walkable placement surfaces
const ROAD_SURFACE_RAY_Y = 10000;
const _roadPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
const _roadPlaneHit = new THREE.Vector3();
const _roadDownOrigin = new THREE.Vector3();
const _roadDownDir = new THREE.Vector3(0, -1, 0);
const _roadRayOrigin = new THREE.Vector3();
const _roadRayDir = new THREE.Vector3();
const _roadFaceNormal = new THREE.Vector3();
const _roadNormalMatrix = new THREE.Matrix3();
let _roadPointIdSeq = 1;
let roadSnapBuildingCache = []; // { name: string, obj: THREE.Object3D, box: THREE.Box3, center: THREE.Vector3 }
let roadSnapObstacleCache = []; // { name: string|null, obj: THREE.Object3D, box: THREE.Box3, center: THREE.Vector3 }
let roadGroundTargets = []; // Object3D[] (meshes/groups) used as the "base ground" height source for roads

function roadAngleStepRad() {
  return roadSnapEnabled ? (Math.PI / 2) : THREE.MathUtils.degToRad(ROAD_SNAP_FINE_DEG);
}

function quantizeAngleRad(angleRad, stepRad) {
  if (!Number.isFinite(angleRad) || !Number.isFinite(stepRad) || stepRad <= 0) return angleRad;
  return Math.round(angleRad / stepRad) * stepRad;
}

function getPointOnHorizontalPlaneFromEvent(event, y) {
  if (!event) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  _roadPlane.constant = -Number(y || 0);
  const hit = raycaster.ray.intersectPlane(_roadPlane, _roadPlaneHit);
  return hit ? _roadPlaneHit : null;
}

function getRoadSurfacePointFromEvent(event) {
  if (!event) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const targets = [];
  if (campusRoot) targets.push(campusRoot);
  targets.push(overlayRoot);

  const hits = raycaster.intersectObjects(targets, true);
  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh || obj.userData?.isRoadMeshSide) continue; // don't place onto existing road meshes
    if (!h.face) return h.point.clone();

    _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
    _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
    if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;

    return h.point.clone();
  }

  // Fallback: ground plane (y=0)
  return getGroundPointFromEvent(event);
}

function sampleRoadSurfaceYAtXZ(x, z, refY = null) {
  _roadDownOrigin.set(x, ROAD_SURFACE_RAY_Y, z);
  raycaster.set(_roadDownOrigin, _roadDownDir);

  const targets = [];
  if (campusRoot) targets.push(campusRoot);
  targets.push(overlayRoot);

  const hits = raycaster.intersectObjects(targets, true);

  if (!Number.isFinite(refY)) {
    // Old behavior: first upward-ish hit from above.
    for (const h of hits) {
      const obj = h?.object;
      if (!obj) continue;
      if (obj.userData?.isRoadMesh || obj.userData?.isRoadMeshSide) continue;
      if (!h.face) return h.point.y;

      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;

      return h.point.y;
    }
    return 0;
  }

  // Prefer the surface closest to the reference height (prevents snapping up to roofs when editing on ground).
  let bestY = null;
  let bestScore = Infinity;
  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh || obj.userData?.isRoadMeshSide) continue;
    if (h.face) {
      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;
    }

    const y = h.point.y;
    const score = Math.abs(y - refY);
    if (score < bestScore) {
      bestScore = score;
      bestY = y;
      if (bestScore < 1e-6) break;
    }
  }
  return bestY ?? 0;
}

function isGroundLikeName(name) {
  const n = String(name || "").trim();
  if (!n) return false;
  const lower = n.toLowerCase();
  if (lower === "ground" || lower === "cground") return true;
  // Matches tokens like "GROUND", "CGROUND", "C_GROUND", "campus_ground", "ground_mesh", etc (but not "PLAYGROUND").
  return /(^|[^a-z0-9])c?ground($|[^a-z0-9])/i.test(n);
}

function isGroundObjectOrChild(obj) {
  if (!obj) return false;
  let p = obj;
  while (p && p !== campusRoot) {
    if (isGroundLikeName(p.name)) return true;
    p = p.parent;
  }
  return false;
}

function rebuildRoadGroundTargets() {
  roadGroundTargets = [];
  if (!campusRoot) return;
  const seen = new Set();
  campusRoot.traverse((obj) => {
    if (!obj || !obj.name) return;
    if (!isGroundLikeName(obj.name)) return;
    // Prefer the highest ground-like object in the hierarchy (avoid duplicates).
    let p = obj.parent;
    while (p && p !== campusRoot) {
      if (isGroundLikeName(p.name)) return;
      p = p.parent;
    }
    if (seen.has(obj.uuid)) return;
    seen.add(obj.uuid);
    roadGroundTargets.push(obj);
  });
}

function sampleRoadBaseYAtXZ(x, z, refY = null) {
  if (!roadGroundTargets || !roadGroundTargets.length) {
    // Fallback for models where a dedicated ground node isn't detected by name.
    return sampleRoadSurfaceYAtXZ(x, z, refY);
  }
  _roadDownOrigin.set(x, ROAD_SURFACE_RAY_Y, z);
  raycaster.set(_roadDownOrigin, _roadDownDir);

  const hits = raycaster.intersectObjects(roadGroundTargets, true);
  if (!hits.length) {
    // Fallback if ground targets don't intersect at this XZ.
    return sampleRoadSurfaceYAtXZ(x, z, refY);
  }

  let bestY = null;
  let bestScore = Infinity;
  let firstY = null;

  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh || obj.userData?.isRoadMeshSide) continue;

    const y = h.point.y;
    if (firstY == null) firstY = y;

    let ok = true;
    if (h.face) {
      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) ok = false;
    }
    if (!ok) continue;

    if (!Number.isFinite(refY)) return y;
    const score = Math.abs(y - refY);
    if (score < bestScore) {
      bestScore = score;
      bestY = y;
      if (bestScore < 1e-6) break;
    }
  }

  // If normals are flipped or filtered out, fall back to a generic surface sample.
  return bestY ?? firstY ?? sampleRoadSurfaceYAtXZ(x, z, refY);
}

function newRoadPointId() {
  return `rp_${Date.now()}_${_roadPointIdSeq++}`;
}

function isKioskMeta(meta) {
  return !!(meta && (meta.type === "kiosk_start" || meta.kiosk === true));
}

function makeKioskMeta(pointId) {
  return {
    id: pointId || null,
    type: "kiosk_start",
    kiosk: true,
    label: "Kiosk_Start",
    building: null,
    linkedAt: Date.now(),
  };
}

function normalizeKioskMeta(meta, pointId) {
  return {
    ...(meta && typeof meta === "object" ? meta : {}),
    id: (meta && meta.id) ? meta.id : (pointId || null),
    type: "kiosk_start",
    kiosk: true,
    label: "Kiosk_Start",
    building: null,
    linkedAt: (meta && meta.linkedAt) ? meta.linkedAt : Date.now(),
  };
}

function cloneRoadPointMeta(metaArr) {
  if (!Array.isArray(metaArr)) return [];
  return metaArr.map((m) => {
    if (!m || typeof m !== "object") return null;
    const b = (m.building && typeof m.building === "object") ? { ...m.building } : null;
    return { ...m, building: b };
  });
}

function normalizeRoadPointMeta(meta, pointId = null) {
  if (!meta || typeof meta !== "object") return null;
  if (isKioskMeta(meta)) return normalizeKioskMeta(meta, pointId);

  const building = (meta.building && typeof meta.building === "object") ? meta.building : {};
  const resolved = resolveBuildingIdentity({
    buildingUid: building.uid || meta.buildingUid || meta.destinationUid || "",
    buildingName: building.name || meta.buildingName || meta.name || "",
    objectName: building.objectName || meta.objectName || meta.modelObjectName || ""
  });
  const normalizedBuilding = {};
  if (resolved.buildingName) normalizedBuilding.name = resolved.buildingName;
  if (resolved.objectName) normalizedBuilding.objectName = resolved.objectName;
  if (resolved.buildingUid) normalizedBuilding.uid = resolved.buildingUid;
  if (String(building.side || "").trim()) normalizedBuilding.side = String(building.side || "").trim();

  return {
    ...meta,
    id: meta.id || pointId || null,
    label: String(meta.label || "").trim() || (resolved.buildingName && normalizedBuilding.side ? `${resolved.buildingName}_${normalizedBuilding.side}` : (resolved.buildingName || null)),
    building: Object.keys(normalizedBuilding).length ? normalizedBuilding : null,
    linkedAt: meta.linkedAt || Date.now()
  };
}

function ensureRoadPointMetaArrays(roadObj) {
  const road = roadObj?.userData?.road;
  if (!road) return;
  const pts = Array.isArray(road.points) ? road.points : [];
  if (!Array.isArray(road.pointIds)) road.pointIds = [];
  if (!Array.isArray(road.pointMeta)) road.pointMeta = [];

  while (road.pointIds.length < pts.length) road.pointIds.push(newRoadPointId());
  while (road.pointMeta.length < pts.length) road.pointMeta.push(null);

  if (road.pointIds.length > pts.length) road.pointIds.length = pts.length;
  if (road.pointMeta.length > pts.length) road.pointMeta.length = pts.length;
}

function clearKioskMetaAll() {
  const changes = [];
  for (const obj of overlayRoot.children) {
    if (!isRoadObject(obj)) continue;
    ensureRoadPointMetaArrays(obj);
    const road = obj.userData.road;
    const beforeMeta = cloneRoadPointMeta(road.pointMeta || []);
    let changed = false;
    for (let i = 0; i < road.pointMeta.length; i++) {
      if (isKioskMeta(road.pointMeta[i])) {
        road.pointMeta[i] = null;
        changed = true;
      }
    }
    if (changed) {
      const afterMeta = cloneRoadPointMeta(road.pointMeta || []);
      const beforePoints = clonePointsArray(road.points || []);
      const beforeWidth = Number(road.width || ROAD_DEFAULT_WIDTH);
      const beforeIds = Array.isArray(road.pointIds) ? road.pointIds.slice() : [];
      const afterPoints = clonePointsArray(road.points || []);
      const afterIds = Array.isArray(road.pointIds) ? road.pointIds.slice() : [];
      changes.push({
        obj,
        beforePoints,
        afterPoints,
        beforeWidth,
        afterWidth: beforeWidth,
        beforeIds,
        afterIds,
        beforeMeta,
        afterMeta
      });
    }
  }
  if (changes.length) {
    for (const ch of changes) {
      pushUndo({ type: "road_edit", obj: ch.obj, beforePoints: ch.beforePoints, afterPoints: ch.afterPoints, beforeWidth: ch.beforeWidth, afterWidth: ch.afterWidth, beforeIds: ch.beforeIds, afterIds: ch.afterIds, beforeMeta: ch.beforeMeta, afterMeta: ch.afterMeta });
    }
    redoStack = [];
  }
  if (changes.length && currentTool === "road" && selected && isRoadObject(selected)) {
    buildRoadHandlesFor(selected);
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }
  return changes.length;
}

function distSqPointToBoxXZ(x, z, box) {
  const cx = Math.max(box.min.x, Math.min(box.max.x, x));
  const cz = Math.max(box.min.z, Math.min(box.max.z, z));
  const dx = x - cx;
  const dz = z - cz;
  return dx * dx + dz * dz;
}

function intersectRayAabbXZ(ox, oz, dx, dz, minX, maxX, minZ, maxZ) {
  const EPS = 1e-8;
  let tmin = -Infinity;
  let tmax = Infinity;

  if (Math.abs(dx) < EPS) {
    if (ox < minX || ox > maxX) return null;
  } else {
    const tx1 = (minX - ox) / dx;
    const tx2 = (maxX - ox) / dx;
    const txMin = Math.min(tx1, tx2);
    const txMax = Math.max(tx1, tx2);
    tmin = Math.max(tmin, txMin);
    tmax = Math.min(tmax, txMax);
  }

  if (Math.abs(dz) < EPS) {
    if (oz < minZ || oz > maxZ) return null;
  } else {
    const tz1 = (minZ - oz) / dz;
    const tz2 = (maxZ - oz) / dz;
    const tzMin = Math.min(tz1, tz2);
    const tzMax = Math.max(tz1, tz2);
    tmin = Math.max(tmin, tzMin);
    tmax = Math.min(tmax, tzMax);
  }

  if (tmax < tmin) return null;
  return { tEnter: tmin, tExit: tmax };
}

function determineApproachSide(anchorW, endX, endZ, buildingCenter) {
  if (!buildingCenter) return "front";
  let dx = (anchorW ? (anchorW.x - buildingCenter.x) : 0);
  let dz = (anchorW ? (anchorW.z - buildingCenter.z) : 0);
  if (!Number.isFinite(dx) || !Number.isFinite(dz) || (Math.abs(dx) < 1e-8 && Math.abs(dz) < 1e-8)) {
    dx = endX - buildingCenter.x;
    dz = endZ - buildingCenter.z;
  }
  if (Math.abs(dx) >= Math.abs(dz)) return dx < 0 ? "left" : "right";
  return dz < 0 ? "back" : "front";
}

function getTopLevelNamedAncestor(obj) {
  if (!obj || !campusRoot) return null;
  let p = obj;
  let top = null;
  while (p && p !== campusRoot) {
    if (p.name && !isGenericNodeName(p.name) && !isGroundLikeName(p.name)) {
      top = p;
    }
    p = p.parent;
  }
  return top;
}

function getBuildingLabelForObject(obj) {
  const root = getTopLevelNamedAncestor(obj);
  return root?.name || null;
}

function getBuildingRootFromObject(obj) {
  return getTopLevelNamedAncestor(obj);
}

function getRoadAttachTargetForObject(obj) {
  const root = getBuildingRootFromObject(obj);
  if (!root || !root.name) return null;
  const classified = classifyTopLevelObjectName(root.name);
  const catalogEntry = getModelBuildingCatalogEntry({
    buildingName: root.name,
    objectName: root.name
  });
  if (!catalogEntry && classified && classified.include === false) return null;
  const identity = resolveAttachTargetIdentity({
    buildingName: root.name,
    objectName: root.name
  });
  const policy = identity.attachPolicy || getRoadAttachPolicy(identity.entityType);
  if (!identity.key && !identity.objectName && !identity.buildingName) return null;
  return {
    root,
    identity,
    policy,
    name: String(identity.buildingName || identity.objectName || root.name || "").trim(),
    objectName: String(identity.objectName || root.name || "").trim(),
    entityType: normalizeDestinationEntityType(identity.entityType),
    key: String(identity.key || "").trim()
  };
}

function buildLiveRoadSnapSources() {
  const obstacles = [];
  const buildings = [];
  const meshes = [];
  if (!campusRoot) return { obstacles, buildings };

  campusRoot.updateMatrixWorld?.(true);
  const tmpSize = new THREE.Vector3();

  // Named buildings (for metadata + soft snapping)
  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;
    const target = getRoadAttachTargetForObject(obj);
    if (!target || !target.policy?.attachable) return;
    if (byName.has(target.key)) return;
    byName.set(target.key, target);
  });
  for (const target of byName.values()) {
    const obj = target.root;
    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) continue;
    box.getSize(tmpSize);
    if (isGroundObjectOrChild(obj)) continue;
    buildings.push({
      ...target,
      obj,
      box,
      center: box.getCenter(new THREE.Vector3())
    });
  }

  // Obstacles (for hard-stop collisions) Ã¢â‚¬â€ use collider meshes when available
  const seenObstacle = new Set();
  const addObstacle = (mesh) => {
    if (!mesh) return;
    if (seenObstacle.has(mesh.uuid)) return;
    const target = getRoadAttachTargetForObject(mesh);
    if (!target || !target.policy?.blocking) return;
    const box = new THREE.Box3().setFromObject(mesh);
    if (box.isEmpty()) return;
    box.getSize(tmpSize);
    if (isGroundLikeName(mesh.name)) return;
    if (isGroundObjectOrChild(mesh)) return;
    seenObstacle.add(mesh.uuid);
    meshes.push(mesh);
    obstacles.push({
      ...target,
      obj: mesh,
      box,
      center: box.getCenter(new THREE.Vector3())
    });
  };

  if (Array.isArray(baseColliderMeshes) && baseColliderMeshes.length) {
    for (const mesh of baseColliderMeshes) addObstacle(mesh);
  } else {
    campusRoot.traverse((obj) => {
      if (!obj || !obj.isMesh) return;
      addObstacle(obj);
    });
  }

  return { obstacles, buildings, meshes };
}

function rebuildRoadSnapBuildingCache() {
  roadSnapBuildingCache = [];
  roadSnapObstacleCache = [];
  if (!campusRoot) return;
  campusRoot.updateMatrixWorld?.(true);
  const tmpSize = new THREE.Vector3();

  // 1) Named buildings (for metadata + soft snapping)
  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;
    const target = getRoadAttachTargetForObject(obj);
    if (!target || !target.policy?.attachable) return;
    if (byName.has(target.key)) return;
    byName.set(target.key, target);
  });
  for (const target of byName.values()) {
    const obj = target.root;
    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) continue;
    box.getSize(tmpSize);
    if (isGroundObjectOrChild(obj)) continue;
    roadSnapBuildingCache.push({
      ...target,
      obj,
      box,
      center: box.getCenter(new THREE.Vector3())
    });
  }

  // 2) Obstacles (for hard-stop collisions) Ã¢â‚¬â€ use collider meshes when available
  const obstacleMeshes = (Array.isArray(baseColliderMeshes) && baseColliderMeshes.length)
    ? baseColliderMeshes
    : [];

  const seenObstacle = new Set();
  if (obstacleMeshes.length) {
    for (const mesh of obstacleMeshes) {
      if (!mesh) continue;
      if (seenObstacle.has(mesh.uuid)) continue;
      const target = getRoadAttachTargetForObject(mesh);
      if (!target || !target.policy?.blocking) continue;
      const box = new THREE.Box3().setFromObject(mesh);
      if (box.isEmpty()) continue;
      box.getSize(tmpSize);
      if (isGroundLikeName(mesh.name)) continue;
      if (isGroundObjectOrChild(mesh)) continue;
      seenObstacle.add(mesh.uuid);
      roadSnapObstacleCache.push({
        ...target,
        obj: mesh,
        box,
        center: box.getCenter(new THREE.Vector3())
      });
    }
  } else {
    // Fallback: traverse meshes if colliders aren't built yet
    campusRoot.traverse((obj) => {
      if (!obj || !obj.isMesh) return;
      if (seenObstacle.has(obj.uuid)) return;
      const target = getRoadAttachTargetForObject(obj);
      if (!target || !target.policy?.blocking) return;
      const box = new THREE.Box3().setFromObject(obj);
      if (box.isEmpty()) return;
      box.getSize(tmpSize);
      if (isGroundLikeName(obj.name)) return;
      if (isGroundObjectOrChild(obj)) return;
      seenObstacle.add(obj.uuid);
      roadSnapObstacleCache.push({
        ...target,
        obj,
        box,
        center: box.getCenter(new THREE.Vector3())
      });
    });
  }
}

function isBuildingLinkTaken(target, exceptPointId = null, opts = {}) {
  const identity = resolveAttachTargetIdentity(target);
  const key = String(identity.key || "").trim();
  if (!key) return false;
  const except = exceptPointId ? String(exceptPointId) : null;
  const maxLinks = Math.max(0, Number(
    opts?.maxLinks ?? identity?.attachPolicy?.maxLinks ?? getRoadAttachPolicy(identity.entityType).maxLinks
  ) || 0);
  if (maxLinks <= 0) return true;
  let linkCount = 0;

  const roads = overlayRoot?.children || [];
  for (const obj of roads) {
    if (!isRoadObject(obj)) continue;
    ensureRoadPointMetaArrays(obj);
    const metaArr = Array.isArray(obj.userData?.road?.pointMeta) ? obj.userData.road.pointMeta : [];
    const idArr = Array.isArray(obj.userData?.road?.pointIds) ? obj.userData.road.pointIds : [];

    for (let i = 0; i < metaArr.length; i++) {
      const meta = metaArr[i];
      const metaIdentity = getRoadMetaBuildingIdentity(meta);
      if (!metaIdentity || metaIdentity.key !== key) continue;
      const pid = (idArr[i] ?? meta.id ?? null);
      if (except && pid && String(pid) === except) continue;
      linkCount++;
      if (linkCount >= maxLinks) return true;
    }
  }
  return false;
}

function snapRoadEndToBuildingSide(anchorW, endX, endZ, width, opts = null) {
  const obstacles = [];
  const providedObstacles = Array.isArray(opts?.obstacles) ? opts.obstacles : null;
  const providedBuildings = Array.isArray(opts?.buildings) ? opts.buildings : null;
  const providedMeshes = Array.isArray(opts?.meshes) ? opts.meshes : null;
  const exceptPointId = opts?.exceptPointId ?? null;

  if (providedObstacles && providedObstacles.length) {
    obstacles.push(...providedObstacles);
    if (providedBuildings && providedBuildings.length) obstacles.push(...providedBuildings);
  } else {
    if (roadSnapObstacleCache && roadSnapObstacleCache.length) obstacles.push(...roadSnapObstacleCache);
    if (roadSnapBuildingCache && roadSnapBuildingCache.length) obstacles.push(...roadSnapBuildingCache);
  }
  if (!obstacles.length) return null;
  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const half = Math.max(0.1, w / 2);
  const gap = Math.max(0, Number(ROAD_BUILDING_GAP) || 0);
  const backOff = gap > 0 ? Math.min(0.02, gap * 0.5) : 1e-4;
  const pad = half + gap; // keep road edges out of the building volume

  // 1) Hard stop: if the segment ray crosses a building (expanded by road width),
  // clamp to the FIRST impact point (like hitting a wall).
  if (anchorW) {
    const vx = endX - anchorW.x;
    const vz = endZ - anchorW.z;
    const maxLen = Math.hypot(vx, vz);
    if (maxLen > 1e-6) {
      const dirX = vx / maxLen;
      const dirZ = vz / maxLen;

      // 1a) Prefer real mesh-surface raycast (true wall hit).
      if (providedMeshes && providedMeshes.length) {
        const prevFar = raycaster.far;
        _roadRayOrigin.set(anchorW.x, anchorW.y, anchorW.z);
        _roadRayDir.set(dirX, 0, dirZ).normalize();
        raycaster.set(_roadRayOrigin, _roadRayDir);
        raycaster.far = maxLen;

        const hits = raycaster.intersectObjects(providedMeshes, true);
        for (const h of hits) {
          const obj = h?.object;
          if (!obj) continue;
          if (obj.userData?.isRoadMesh || obj.userData?.isRoadMeshSide) continue;
          if (isGroundLikeName(obj.name)) continue;
          if (isGroundObjectOrChild(obj)) {
            // If it's ground-like, ignore it for road-wall collisions.
            continue;
          }
          const pt = h.point;
          if (!pt) continue;

          const root = getBuildingRootFromObject(obj);
          const target = getRoadAttachTargetForObject(root || obj);
          if (!target || !target.policy?.blocking) continue;
          let side = "front";
          if (root) {
            const rootBox = new THREE.Box3().setFromObject(root);
            const center = rootBox.getCenter(new THREE.Vector3());
            side = determineApproachSide(anchorW, pt.x, pt.z, center);
          }

          const outX = pt.x - _roadRayDir.x * backOff;
          const outZ = pt.z - _roadRayDir.z * backOff;
          const taken = isBuildingLinkTaken(target.identity, exceptPointId, {
            maxLinks: target.policy?.maxLinks
          });

          raycaster.far = prevFar;
          return {
            x: outX,
            z: outZ,
            buildingObj: root || obj,
            buildingName: taken ? null : (target.name || null),
            objectName: taken ? null : (target.objectName || null),
            buildingUid: taken ? null : (target.identity?.buildingUid || null),
            entityType: target.entityType || "building",
            side: taken ? null : side,
          };
        }
        raycaster.far = prevFar;
      }

      let bestHit = null; // { t, target, buildingObj, minX,maxX,minZ,maxZ }
      for (const b of obstacles) {
        if (!b?.box) continue;
        const box = b.box;
        const minX = box.min.x - pad;
        const maxX = box.max.x + pad;
        const minZ = box.min.z - pad;
        const maxZ = box.max.z + pad;

        const hit = intersectRayAabbXZ(anchorW.x, anchorW.z, dirX, dirZ, minX, maxX, minZ, maxZ);
        if (!hit) continue;
        if (hit.tExit < 0) continue;

        let t = hit.tEnter;
        if (t < 0) t = hit.tExit; // started inside the expanded box; clamp to leaving boundary
        if (t < 0 || t > maxLen) continue;

        if (!bestHit || t < bestHit.t) {
          bestHit = { t, target: b, buildingObj: b.obj, minX, maxX, minZ, maxZ };
        }
      }

      if (bestHit) {
        const hitX = anchorW.x + dirX * bestHit.t;
        const hitZ = anchorW.z + dirZ * bestHit.t;

        // Determine which wall we hit (closest boundary on the expanded box).
        const dL = Math.abs(hitX - bestHit.minX);
        const dR = Math.abs(hitX - bestHit.maxX);
        const dB = Math.abs(hitZ - bestHit.minZ);
        const dF = Math.abs(hitZ - bestHit.maxZ);
        const minD = Math.min(dL, dR, dB, dF);
        let side = "front";
        if (minD === dL) side = "left";
        else if (minD === dR) side = "right";
        else if (minD === dB) side = "back";
        else if (minD === dF) side = "front";

        // Pull slightly back along the ray so we stay OUTSIDE the expanded volume.
        const outX = hitX - dirX * backOff;
        const outZ = hitZ - dirZ * backOff;
        const taken = isBuildingLinkTaken(bestHit.target?.identity, exceptPointId, {
          maxLinks: bestHit.target?.policy?.maxLinks
        });

        return {
          x: outX,
          z: outZ,
          buildingObj: bestHit.buildingObj,
          buildingName: taken ? null : (bestHit.target?.name || null),
          objectName: taken ? null : (bestHit.target?.objectName || null),
          buildingUid: taken ? null : (bestHit.target?.identity?.buildingUid || null),
          entityType: bestHit.target?.entityType || "building",
          side: taken ? null : side,
        };
      }
    }
  }

  // 2) Soft snap: if the pointer is close to a building, snap to the nearest side.
  const buildingList = providedBuildings ?? roadSnapBuildingCache;
  if (!buildingList || !buildingList.length) return null;
  const snapDist = Math.max(ROAD_BUILDING_SNAP_DIST, w * 0.75);
  const maxSq = snapDist * snapDist;

  let best = null;
  for (const b of buildingList) {
    if (!b?.box) continue;
    if (!b?.policy?.attachable) continue;
    if (isBuildingLinkTaken(b.identity, exceptPointId, { maxLinks: b.policy?.maxLinks })) continue;
    const dSq = distSqPointToBoxXZ(endX, endZ, b.box);
    if (dSq > maxSq) continue;
    if (!best || dSq < best.dSq) best = { ...b, dSq };
  }
  if (!best) return null;

  const side = determineApproachSide(anchorW, endX, endZ, best.center);
  const box = best.box;
  const clamp = THREE.MathUtils.clamp;
  const offset = pad;

  let x = endX;
  let z = endZ;
  if (side === "left") {
    x = box.min.x - offset;
    z = clamp(endZ, box.min.z, box.max.z);
  } else if (side === "right") {
    x = box.max.x + offset;
    z = clamp(endZ, box.min.z, box.max.z);
  } else if (side === "front") {
    z = box.max.z + offset;
    x = clamp(endX, box.min.x, box.max.x);
  } else if (side === "back") {
    z = box.min.z - offset;
    x = clamp(endX, box.min.x, box.max.x);
  }

  return {
    x,
    z,
    buildingObj: best.obj,
    buildingName: best.name,
    objectName: best.objectName || null,
    buildingUid: best.identity?.buildingUid || null,
    entityType: best.entityType || "building",
    side,
  };
}

function makeBuildingLinkMeta(target, side, pointId) {
  const resolved = resolveAttachTargetIdentity(target);
  const name = String(resolved.buildingName || (typeof target === "string" ? target : target?.buildingName) || "").trim();
  const objectName = String(resolved.objectName || (typeof target === "string" ? target : target?.objectName) || "").trim();
  const entityType = normalizeDestinationEntityType(resolved.entityType);
  const s = String(side || "").trim();
  const building = {};
  if (name) building.name = name;
  if (objectName) building.objectName = objectName;
  if (resolved.buildingUid) building.uid = resolved.buildingUid;
  if (entityType) building.type = entityType;
  if (s) building.side = s;
  return {
    id: pointId || null,
    label: (name && s) ? `${name}_${s}` : (name || null),
    entityType,
    building: Object.keys(building).length ? building : null,
    linkedAt: Date.now(),
  };
}

function computeSnappedRoadEnd(anchorW, rawOnPlane, opts = {}) {
  const { width = null, out = null, snapSources = null, exceptPointId = null } = opts;
  if (!anchorW || !rawOnPlane) return null;
  const dx = rawOnPlane.x - anchorW.x;
  const dz = rawOnPlane.z - anchorW.z;
  const len = Math.hypot(dx, dz);
  if (len < ROAD_MIN_POINT_DIST) return null;

  const ang = Math.atan2(dz, dx);
  const step = roadAngleStepRad();
  const snapAng = quantizeAngleRad(ang, step);

  const ux = Math.cos(snapAng);
  const uz = Math.sin(snapAng);
  const proj = dx * ux + dz * uz; // closest point on snapped ray to the pointer (angle-quantized, not grid-based)
  if (proj < ROAD_MIN_POINT_DIST) return null;

  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  let x = anchorW.x + ux * proj;
  let z = anchorW.z + uz * proj;

  let snap = null;
  if (out && typeof out === "object") {
    out.snapped = false;
    out.buildingName = null;
    out.objectName = null;
    out.buildingUid = null;
    out.entityType = null;
    out.side = null;
  }

  if (roadBuildingSnapEnabled) {
    const snapOpts = (snapSources && typeof snapSources === "object") ? { ...snapSources } : {};
    if (exceptPointId) snapOpts.exceptPointId = exceptPointId;
    snap = snapRoadEndToBuildingSide(anchorW, x, z, w, snapOpts);
    if (snap) {
      x = snap.x;
      z = snap.z;
      if (out && typeof out === "object") {
        const hasLink = !!(snap.buildingName && snap.side);
        out.snapped = hasLink;
        out.buildingName = hasLink ? (snap.buildingName || null) : null;
        out.objectName = hasLink ? (snap.objectName || null) : null;
        out.buildingUid = hasLink ? (snap.buildingUid || null) : null;
        out.entityType = hasLink ? (snap.entityType || null) : null;
        out.side = hasLink ? (snap.side || null) : null;
      }
    }
  }

  const y = sampleRoadBaseYAtXZ(x, z, anchorW.y);
  return new THREE.Vector3(x, y, z);
}

function isRoadObject(obj) {
  return !!(obj && obj.userData?.isPlaced && obj.userData?.type === "road");
}

function setRoadWidthReadout(value) {
  if (!roadWidthReadoutEl) return;
  const v = Number(value);
  roadWidthReadoutEl.textContent = Number.isFinite(v) ? String(Math.round(v)) : String(value ?? "");
}

function updateRoadControls() {
  if (!roadControls) return;
  const isActive = currentTool === "road";
  roadControls.style.display = isActive ? "block" : "none";

  let hasEditableSelectedRoad = false;
  try {
    hasEditableSelectedRoad = !!(selected && isRoadObject(selected) && canEditObject(selected));
  } catch (_) {
    hasEditableSelectedRoad = false;
  }

  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  setRoadWidthReadout(width);

  if (roadWidthEl) {
    // Width is both the default for new road points and an editor for selected roads.
    roadWidthEl.disabled = !isActive;
    roadWidthEl.style.opacity = roadWidthEl.disabled ? "0.6" : "1";
  }

  if (roadFinishBtn) {
    const canFinish = !!(roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length >= 2);
    roadFinishBtn.disabled = !isActive || !canFinish;
    roadFinishBtn.style.opacity = roadFinishBtn.disabled ? "0.6" : "1";
  }
  if (roadCancelBtn) {
    const canCancel = !!(roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length);
    roadCancelBtn.disabled = !isActive || !canCancel;
    roadCancelBtn.style.opacity = roadCancelBtn.disabled ? "0.6" : "1";
  }
  if (roadNewBtn) {
    roadNewBtn.disabled = !isActive;
    roadNewBtn.style.opacity = roadNewBtn.disabled ? "0.6" : "1";
  }
  if (roadSnapBtn) {
    roadSnapBtn.textContent = `Snap: ${roadSnapEnabled ? "On" : "Off"}`;
  }
  if (roadBuildingSnapBtn) {
    roadBuildingSnapBtn.disabled = !isActive;
    roadBuildingSnapBtn.textContent = `Attach: ${roadBuildingSnapEnabled ? "On" : "Off"}`;
    roadBuildingSnapBtn.style.opacity = roadBuildingSnapBtn.disabled ? "0.6" : "1";
  }
  if (roadAutoIntersectBtn) {
    roadAutoIntersectBtn.disabled = !isActive;
    roadAutoIntersectBtn.textContent = `Auto-Intersect: ${roadAutoIntersectEnabled ? "On" : "Off"}`;
    roadAutoIntersectBtn.style.opacity = roadAutoIntersectBtn.disabled ? "0.6" : "1";
  }
  if (roadDragModeBtn) {
    roadDragModeBtn.disabled = !isActive;
    roadDragModeBtn.textContent = `Drag: ${roadDragMode === "move" ? "Move" : "Draw"}`;
    roadDragModeBtn.style.opacity = roadDragModeBtn.disabled ? "0.6" : "1";
  }
  if (roadKioskBtn) {
    roadKioskBtn.disabled = !isActive;
    roadKioskBtn.textContent = roadKioskPlaceMode ? "Kiosk Start: Place" : "Kiosk Start";
    roadKioskBtn.style.opacity = roadKioskBtn.disabled ? "0.6" : "1";
    roadKioskBtn.style.outline = roadKioskPlaceMode ? "2px solid #10b981" : "none";
    roadKioskBtn.style.outlineOffset = roadKioskPlaceMode ? "2px" : "0";
  }

  // Selected point + extend-from labels
  if (roadPointSelectedEl || roadExtendFromEl) {
    let pointLabel = "None";
    let extendLabel = "Auto";

    if (hasEditableSelectedRoad && selected && isRoadObject(selected)) {
      const ptsArr = Array.isArray(selected.userData?.road?.points) ? selected.userData.road.points : [];
      const lastIdx = ptsArr.length - 1;
      if (Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0 && roadSelectedPointIndex <= lastIdx) {
        if (roadSelectedPointIndex === 0) pointLabel = "Start";
        else if (roadSelectedPointIndex === lastIdx) pointLabel = "End";
        else pointLabel = String(roadSelectedPointIndex + 1);
      }

      if (roadExtendFromRoadId && roadExtendFromRoadId === selected.userData?.id && roadExtendFrom) {
        extendLabel = roadExtendFrom === "start" ? "Start" : "End";
      }
    }

    if (roadPointSelectedEl) roadPointSelectedEl.textContent = pointLabel;
    if (roadExtendFromEl) roadExtendFromEl.textContent = extendLabel;
  }

  updateSpecialPointsPanel();
}

function clearRoadPointSelection() {
  roadSelectedPointIndex = null;
  roadExtendFrom = null;
  roadExtendFromRoadId = null;
}

function selectRoadPoint(roadObj, index, opts = {}) {
  const { mode = "toggle", showStatus = true } = opts; // mode: "toggle" | "set"

  if (!roadObj || !isRoadObject(roadObj)) {
    clearRoadPointSelection();
    updateRoadControls();
    return;
  }

  const ptsArr = Array.isArray(roadObj.userData?.road?.points) ? roadObj.userData.road.points : [];
  const lastIdx = ptsArr.length - 1;

  const idx = Number.isInteger(index) ? index : null;
  roadSelectedPointIndex = (idx != null && idx >= 0 && idx <= lastIdx) ? idx : null;

  let endpoint = null;
  if (roadSelectedPointIndex === 0) endpoint = "start";
  else if (roadSelectedPointIndex === lastIdx) endpoint = "end";

  if (endpoint) {
    const isSame = (roadExtendFrom === endpoint && roadExtendFromRoadId === roadObj.userData?.id);
    const shouldToggleOff = (mode === "toggle" && isSame);
    if (shouldToggleOff) {
      roadExtendFrom = null;
      roadExtendFromRoadId = null;
      if (showStatus) setStatus("Extend-from cleared (auto)");
    } else {
      roadExtendFrom = endpoint;
      roadExtendFromRoadId = roadObj.userData?.id || null;
      if (showStatus) setStatus(`Extend from ${endpoint === "start" ? "Start" : "End"} selected Ã¢â‚¬â€ drag the point to draw a segment`);
    }
  } else {
    // Interior point selected; extension goes back to auto.
    if (roadExtendFromRoadId === roadObj.userData?.id) {
      roadExtendFrom = null;
      roadExtendFromRoadId = null;
    }
    if (showStatus) setStatus(roadDragMode === "draw"
      ? "Road point selected Ã¢â‚¬â€ drag to branch a new segment"
      : "Road point selected Ã¢â‚¬â€ drag to move (switch Drag mode to Draw to create segments)");
  }

  updateRoadControls();
}

function computeDraftPreviewPoint(ptWorld) {
  if (!roadDraft || !Array.isArray(roadDraft.points) || !roadDraft.points.length) return null;

  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;

  const last = roadDraft.points[roadDraft.points.length - 1];
  const delta = pt.clone().sub(last);
  delta.y = 0;
  if (delta.length() < ROAD_MIN_POINT_DIST) return null;

  if (roadSnapEnabled && roadDraft.points.length >= 2) {
    const prev = roadDraft.points[roadDraft.points.length - 2];
    const lastDir = last.clone().sub(prev);
    lastDir.y = 0;
    const snappedDir = snapRoadTurn(lastDir, delta);
    const len = Math.max(ROAD_MIN_POINT_DIST, delta.dot(snappedDir));
    pt.copy(last).addScaledVector(snappedDir, len);
    snapXZ(pt);
    pt.y = 0;
  }

  return pt;
}

function computeExtendPreviewSegment(roadObj, ptsLocal, ptWorld, extendEnd) {
  if (!roadObj || !isRoadObject(roadObj)) return null;
  if (!Array.isArray(ptsLocal) || ptsLocal.length < 1) return null;

  const ptW = snapXZ(ptWorld.clone());
  ptW.y = 0;

  const startW = roadObj.localToWorld(ptsLocal[0].clone());
  const endW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 1].clone());
  const anchorW = extendEnd ? endW : startW;

  let newW = ptW.clone();
  if (roadSnapEnabled && ptsLocal.length >= 2) {
    if (extendEnd) {
      const prevW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 2].clone());
      const lastDir = endW.clone().sub(prevW); lastDir.y = 0;
      const desired = ptW.clone().sub(endW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = endW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    } else {
      const nextW = roadObj.localToWorld(ptsLocal[1].clone());
      const lastDir = startW.clone().sub(nextW); lastDir.y = 0; // direction from next -> start
      const desired = ptW.clone().sub(startW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = startW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    }
  }

  if (newW.distanceTo(anchorW) < ROAD_MIN_POINT_DIST) return null;
  return { anchorW, newW };
}

function updateRoadPreview(event) {
  if (currentTool !== "road") return hideRoadHoverPreview();
  if (activeTouchPointerIds.size >= 2) return hideRoadHoverPreview();
  if (!event || !isEventOnStage(event)) return hideRoadHoverPreview();
  const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;

  // Preview for extending/branching from a selected road point.
  if (selected && isRoadObject(selected) && canEditObject(selected)) {
    const ptsLocal = getRoadLocalPoints(selected);
    const lastIdx = ptsLocal.length - 1;
    if (lastIdx >= 0) {
      let anchorIdx = null;

      if (Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0 && roadSelectedPointIndex <= lastIdx) {
        anchorIdx = roadSelectedPointIndex;
      } else if (roadExtendFromRoadId && roadExtendFromRoadId === selected.userData?.id && roadExtendFrom) {
        anchorIdx = roadExtendFrom === "start" ? 0 : lastIdx;
      } else if (lastIdx >= 1) {
        const hint = getRoadSurfacePointFromEvent(event);
        if (hint) {
          const startW = selected.localToWorld(ptsLocal[0].clone());
          const endW = selected.localToWorld(ptsLocal[lastIdx].clone());
          const dxs = hint.x - startW.x, dzs = hint.z - startW.z;
          const dxe = hint.x - endW.x,  dze = hint.z - endW.z;
          anchorIdx = (dxs * dxs + dzs * dzs) <= (dxe * dxe + dze * dze) ? 0 : lastIdx;
        }
      }

      if (anchorIdx == null) anchorIdx = 0;
      const anchorW = selected.localToWorld(ptsLocal[anchorIdx].clone());
      const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, anchorW.y);
      const width = Number(selected.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
      const endW = computeSnappedRoadEnd(anchorW, rawOnPlane, { width, snapSources: liveSources });
      if (endW) {
        showRoadHoverPreviewSegment(anchorW, endW, width);
        return;
      }
    }
  }

  // Preview for drafting a new road (legacy draft mode).
  if (roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length) {
    const last = roadDraft.points[roadDraft.points.length - 1];
    const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, last.y);
    const width = Number(roadDraft.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const cand = computeSnappedRoadEnd(last, rawOnPlane, { width, snapSources: liveSources });
    if (cand) {
      showRoadHoverPreviewSegment(last, cand, width);
      return;
    }
  }

  hideRoadHoverPreview();
}

function snapXZ(pt, step = ROAD_SNAP_STEP) {
  // Kept for compatibility with older code paths; road snapping is angle-based now.
  return pt;
}

function clonePointsArray(points) {
  return (points || []).map(p => [p[0], p[1], p[2]]);
}

function vec3FromArray(a) {
  return new THREE.Vector3(a[0], a[1], a[2]);
}

function arraysFromVec3(points) {
  return points.map(v => [v.x, v.y, v.z]);
}

function buildRoadGeometry(points, width) {
  const geom = new THREE.BufferGeometry();
  if (!Array.isArray(points) || points.length < 1) return geom;

  const half = Math.max(0.1, (Number(width) || ROAD_DEFAULT_WIDTH) / 2);

  // Clean consecutive duplicates (prevents zero-length segments creating spikes)
  const pts = [];
  const EPS_SQ = 1e-6;
  for (const p of points) {
    if (!p) continue;
    if (!pts.length) pts.push(p.clone());
    else {
      const last = pts[pts.length - 1];
      const dx = p.x - last.x;
      const dz = p.z - last.z;
      if ((dx * dx + dz * dz) > EPS_SQ) pts.push(p.clone());
    }
  }

  // Single node: render a simple square "cap" driven by road width.
  if (pts.length === 1) {
    const p = pts[0];
    const y = p.y + ROAD_Y_OFFSET;

    const posArr = new Float32Array([
      p.x - half, y, p.z - half,
      p.x + half, y, p.z - half,
      p.x + half, y, p.z + half,
      p.x - half, y, p.z + half,
    ]);
    const nArr = new Float32Array([
      0, 1, 0,
      0, 1, 0,
      0, 1, 0,
      0, 1, 0,
    ]);
    const uvArr = new Float32Array([
      0, 0,
      1, 0,
      1, 1,
      0, 1,
    ]);
    const idxArr = new Uint16Array([0, 2, 1, 0, 3, 2]);

    geom.setAttribute("position", new THREE.BufferAttribute(posArr, 3));
    geom.setAttribute("normal", new THREE.BufferAttribute(nArr, 3));
    geom.setAttribute("uv", new THREE.BufferAttribute(uvArr, 2));
    geom.setIndex(new THREE.BufferAttribute(idxArr, 1));
    return geom;
  }

  if (pts.length < 2) return geom;

  // UV distance along centerline
  let accLen = 0;
  const segLens = new Array(pts.length).fill(0);
  for (let i = 1; i < pts.length; i++) {
    const d = pts[i].clone().sub(pts[i - 1]);
    d.y = 0;
    accLen += d.length();
    segLens[i] = accLen;
  }
  const totalLen = Math.max(1e-6, accLen);

  const positions = [];
  const uvs = [];
  const normals = [];
  const indices = [];
  let vCount = 0;

  function addVertex(x, y, z, u, v) {
    positions.push(x, y, z);
    uvs.push(u, v);
    normals.push(0, 1, 0);
    return vCount++;
  }
  function crossXZ(ax, az, bx, bz) {
    return ax * bz - az * bx;
  }
  function intersectLinesXZ(pA, dA, pB, dB, out) {
    const denom = crossXZ(dA.x, dA.z, dB.x, dB.z);
    if (Math.abs(denom) < 1e-6) return null;
    const dx = pB.x - pA.x;
    const dz = pB.z - pA.z;
    const t = crossXZ(dx, dz, dB.x, dB.z) / denom;
    out.set(pA.x + dA.x * t, 0, pA.z + dA.z * t);
    return out;
  }

  const dir = new THREE.Vector3();
  const normal = new THREE.Vector3();
  const tmp = new THREE.Vector3();
  const pA = new THREE.Vector3();
  const pB = new THREE.Vector3();
  const miter = new THREE.Vector3();

  // 1) Segment quads (constant width per segment)
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i];
    const p1 = pts[i + 1];

    dir.copy(p1).sub(p0);
    dir.y = 0;
    const len = dir.length();
    if (len < 1e-6) continue;
    dir.multiplyScalar(1 / len);

    normal.set(-dir.z, 0, dir.x);

    const y0 = p0.y + ROAD_Y_OFFSET;
    const y1 = p1.y + ROAD_Y_OFFSET;
    const v0 = segLens[i] / totalLen;
    const v1 = segLens[i + 1] / totalLen;

    const l0 = addVertex(p0.x + normal.x * half, y0, p0.z + normal.z * half, 0, v0);
    const r0 = addVertex(p0.x - normal.x * half, y0, p0.z - normal.z * half, 1, v0);
    const l1 = addVertex(p1.x + normal.x * half, y1, p1.z + normal.z * half, 0, v1);
    const r1 = addVertex(p1.x - normal.x * half, y1, p1.z - normal.z * half, 1, v1);

    // Winding order so the road is visible from above (Y+)
    indices.push(l0, l1, r0);
    indices.push(r0, l1, r1);
  }

  // 2) Outer-corner join fill (round join fan)
  const TWO_PI = Math.PI * 2;
  for (let i = 1; i < pts.length - 1; i++) {
    const pPrev = pts[i - 1];
    const p = pts[i];
    const pNext = pts[i + 1];

    const dirPrev = p.clone().sub(pPrev);
    dirPrev.y = 0;
    const dirNext = pNext.clone().sub(p);
    dirNext.y = 0;
    if (dirPrev.lengthSq() < 1e-8 || dirNext.lengthSq() < 1e-8) continue;
    dirPrev.normalize();
    dirNext.normalize();

    const dot = dirPrev.dot(dirNext);
    if (dot > 0.9999) continue; // almost straight

    const turn = crossXZ(dirPrev.x, dirPrev.z, dirNext.x, dirNext.z);
    if (Math.abs(turn) < 1e-8) continue;

    const nPrev = new THREE.Vector3(-dirPrev.z, 0, dirPrev.x);
    const nNext = new THREE.Vector3(-dirNext.z, 0, dirNext.x);

    // Choose convex (outer) side based on turn direction.
    // For CCW (left) turns, convex side is RIGHT; for CW (right) turns, convex side is LEFT.
    if (turn > 0) {
      nPrev.multiplyScalar(-1);
      nNext.multiplyScalar(-1);
    }
    const outerPrev = nPrev;
    const outerNext = nNext;

    const y = p.y + ROAD_Y_OFFSET;
    const v = segLens[i] / totalLen;
    const iCenter = addVertex(p.x, y, p.z, 0.5, v);

    const startAng = Math.atan2(outerPrev.z, outerPrev.x);
    let endAng = Math.atan2(outerNext.z, outerNext.x);
    const ccw = turn > 0;

    if (ccw) {
      while (endAng <= startAng) endAng += TWO_PI;
    } else {
      while (endAng >= startAng) endAng -= TWO_PI;
    }

    const delta = endAng - startAng;
    const absDelta = Math.abs(delta);
    const steps = Math.max(1, Math.min(24, Math.ceil(absDelta / (Math.PI / 18)))); // ~10Ã‚Â° per step

    const arcIdx = [];
    for (let s = 0; s <= steps; s++) {
      const t = s / steps;
      const ang = startAng + delta * t;
      const x = p.x + Math.cos(ang) * half;
      const z = p.z + Math.sin(ang) * half;
      arcIdx.push(addVertex(x, y, z, 0.5, v));
    }

    for (let s = 0; s < steps; s++) {
      const aIdx = arcIdx[s];
      const bIdx = arcIdx[s + 1];
      // We want top-facing triangles (+Y). In XZ plane, CCW gives -Y, so flip for ccw arcs.
      if (ccw) indices.push(iCenter, bIdx, aIdx);
      else indices.push(iCenter, aIdx, bIdx);
    }
  }

  const posArr = new Float32Array(positions);
  const uvArr = new Float32Array(uvs);
  const nArr = new Float32Array(normals);
  const IndexArray = (vCount > 65535) ? Uint32Array : Uint16Array;
  const idxArr = new IndexArray(indices);

  geom.setAttribute("position", new THREE.BufferAttribute(posArr, 3));
  geom.setAttribute("normal", new THREE.BufferAttribute(nArr, 3));
  geom.setAttribute("uv", new THREE.BufferAttribute(uvArr, 2));
  geom.setIndex(new THREE.BufferAttribute(idxArr, 1));
  return geom;
}

function buildRoadSideGeometry(topGeom, thickness) {
  const geom = new THREE.BufferGeometry();
  if (!topGeom || !topGeom.attributes?.position) return geom;
  const t = Number(thickness || 0);
  if (!(t > 0)) return geom;

  const edges = new THREE.EdgesGeometry(topGeom);
  const pos = edges.attributes?.position?.array;
  if (!pos || pos.length < 6) return geom;

  const positions = [];
  const indices = [];
  let v = 0;

  for (let i = 0; i < pos.length; i += 6) {
    const ax = pos[i], ay = pos[i + 1], az = pos[i + 2];
    const bx = pos[i + 3], by = pos[i + 4], bz = pos[i + 5];

    const ayb = ay - t;
    const byb = by - t;

    positions.push(
      ax, ay, az,
      bx, by, bz,
      bx, byb, bz,
      ax, ayb, az
    );

    indices.push(v, v + 1, v + 2, v, v + 2, v + 3);
    v += 4;
  }

  geom.setAttribute("position", new THREE.BufferAttribute(new Float32Array(positions), 3));
  geom.setIndex(indices);
  geom.computeVertexNormals();
  return geom;
}

function replaceMeshGeometry(mesh, geom) {
  if (!mesh) return;
  const old = mesh.geometry;
  mesh.geometry = geom;
  old?.dispose?.();
}

function ensureRoadMeshFor(targetRoot, points, width, opts = {}) {
  const { preview = false } = opts;
  const existing = targetRoot?.children?.find?.(c => c && c.isMesh && c.userData?.isRoadMesh === true) || null;
  const existingSide = targetRoot?.children?.find?.(c => c && c.isMesh && c.userData?.isRoadMeshSide === true) || null;
  const geom = buildRoadGeometry(points, width);
  if (existing) {
    replaceMeshGeometry(existing, geom);
    existing.material = preview ? roadPreviewMaterial : roadMaterial;
  } else {
    const mesh = new THREE.Mesh(geom, preview ? roadPreviewMaterial : roadMaterial);
    mesh.userData.isRoadMesh = true;
    mesh.renderOrder = preview ? 15 : 1;
    targetRoot.add(mesh);
  }

  if (!preview && ROAD_THICKNESS > 0) {
    const sideGeom = buildRoadSideGeometry(geom, ROAD_THICKNESS);
    if (existingSide) {
      replaceMeshGeometry(existingSide, sideGeom);
      existingSide.material = roadSideMaterial;
    } else {
      const sideMesh = new THREE.Mesh(sideGeom, roadSideMaterial);
      sideMesh.userData.isRoadMeshSide = true;
      sideMesh.renderOrder = 0;
      targetRoot.add(sideMesh);
    }
  } else if (existingSide) {
    existingSide.geometry?.dispose?.();
    targetRoot.remove(existingSide);
  }

  return targetRoot?.children?.find?.(c => c && c.isMesh && c.userData?.isRoadMesh === true) || null;
}

function clearRoadDraft() {
  if (roadDraft && roadDraft.mesh) {
    roadDraft.mesh.geometry?.dispose?.();
  }
  roadDraft = null;
  hideRoadHoverPreview();
  while (roadDraftRoot.children.length) {
    const c = roadDraftRoot.children.pop();
    c.geometry?.dispose?.();
  }
  updateRoadControls();
}

function getRoadLocalPoints(obj) {
  const pts = obj?.userData?.road?.points;
  if (!Array.isArray(pts)) return [];
  return pts.map(vec3FromArray);
}

function setRoadLocalPoints(obj, points) {
  if (!obj.userData.road) obj.userData.road = {};
  obj.userData.road.points = arraysFromVec3(points);
}

function rebuildRoadObject(obj) {
  if (!isRoadObject(obj)) return;
  if (!obj.userData.road) obj.userData.road = {};
  ensureRoadPointMetaArrays(obj);
  const width = Number(obj.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const pts = getRoadLocalPoints(obj);
  ensureRoadMeshFor(obj, pts, width, { preview: false });
}

function createRoadFromWorldPoints(pointsWorld, width, opts = {}) {
  const {
    recordHistory = true,
    autoSelect = true,
    forcedId = null,
    forcedLocked = false,
    forcedName = "road",
  } = opts;

  if (!Array.isArray(pointsWorld) || !pointsWorld.length) return null;
  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

  const origin = pointsWorld[0].clone();
  const group = new THREE.Group();
  group.name = "road";
  group.position.copy(origin);

  const ptsLocal = pointsWorld.map(p => p.clone().sub(origin));
  group.userData.road = { width: w, points: arraysFromVec3(ptsLocal) };
  ensureRoadPointMetaArrays(group);
  ensureRoadMeshFor(group, ptsLocal, w, { preview: false });

  markPlaced(group, {
    id: forcedId ?? ("road_" + Date.now() + "_" + Math.floor(Math.random() * 1000)),
    asset: null,
    name: forcedName,
    locked: !!forcedLocked,
    type: "road"
  });

  overlayRoot.add(group);
  if (recordHistory) {
    pushUndo({ type: "add", obj: group, parent: overlayRoot });
    redoStack = [];
  }

  if (autoSelect) {
    selectObject(group);
    selectRoadPoint(group, 0, { mode: "set", showStatus: false });
  }

  updateRoadControls();
  return group;
}

function spawnRoadNodeAt(pointWorld, opts = {}) {
  const pt = pointWorld?.clone?.();
  if (!pt) return null;
  // Keep road nodes at ground/base height (avoid ramping up onto roofs).
  pt.y = sampleRoadBaseYAtXZ(pt.x, pt.z, Number.isFinite(pt.y) ? pt.y : null);
  const w = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const road = createRoadFromWorldPoints([pt], w, opts);
  if (road) setStatus("Road point placed Ã¢â‚¬â€ drag a point to create a segment");
  return road;
}

function placeKioskStartAt(pointWorld) {
  const pt = pointWorld?.clone?.();
  if (!pt) return null;
  pt.y = sampleRoadBaseYAtXZ(pt.x, pt.z, Number.isFinite(pt.y) ? pt.y : null);

  // Ensure only one kiosk start exists.
  clearKioskMetaAll();

  const w = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const road = createRoadFromWorldPoints([pt], w, { recordHistory: true, autoSelect: true });
  if (!road) return null;

  ensureRoadPointMetaArrays(road);
  const roadData = road.userData.road;
  const pid = Array.isArray(roadData.pointIds) ? roadData.pointIds[0] : null;

  const beforePoints = clonePointsArray(roadData.points || []);
  const beforeWidth = Number(roadData.width || ROAD_DEFAULT_WIDTH);
  const beforeIds = Array.isArray(roadData.pointIds) ? roadData.pointIds.slice() : [];
  const beforeMeta = cloneRoadPointMeta(roadData.pointMeta || []);

  roadData.pointMeta[0] = makeKioskMeta(pid);

  const afterPoints = clonePointsArray(roadData.points || []);
  const afterIds = Array.isArray(roadData.pointIds) ? roadData.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadData.pointMeta || []);

  pushUndo({
    type: "road_edit",
    obj: road,
    beforePoints,
    afterPoints,
    beforeWidth,
    afterWidth: beforeWidth,
    beforeIds,
    afterIds,
    beforeMeta,
    afterMeta,
  });
  redoStack = [];

  if (currentTool === "road" && selected === road) {
    buildRoadHandlesFor(road);
    syncRoadHandlesToRoad(road);
    updateRoadHandleScale();
  }

  setStatus("Kiosk start placed Ã¢â‚¬â€ drag it to create the first segment");
  updateRoadControls();
  return road;
}

function beginRoadDraft(ptWorld) {
  clearRoadDraft();
  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;
  roadDraft = { points: [pt], width, mesh: null };
  roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  updateRoadControls();
  setStatus("Road: start point set Ã¢â‚¬â€ tap to add more points, then Finish Road");
}

function snapDirToCardinal(dir) {
  if (!dir) return dir;
  dir.y = 0;
  if (dir.lengthSq() < 1e-8) {
    dir.set(1, 0, 0);
    return dir;
  }
  if (Math.abs(dir.x) >= Math.abs(dir.z)) dir.set(Math.sign(dir.x) || 1, 0, 0);
  else dir.set(0, 0, Math.sign(dir.z) || 1);
  return dir;
}

function snapRoadTurn(lastDir, desiredDir) {
  if (!lastDir || lastDir.lengthSq() < 1e-8) return desiredDir;
  if (!desiredDir || desiredDir.lengthSq() < 1e-8) return lastDir;

  const ld = lastDir.clone();
  ld.y = 0;
  if (ld.lengthSq() < 1e-8) return desiredDir;
  ld.normalize();

  const dd = desiredDir.clone();
  dd.y = 0;
  if (dd.lengthSq() < 1e-8) return ld;
  dd.normalize();

  const straight = ld;
  const left = new THREE.Vector3(-ld.z, 0, ld.x);
  const right = new THREE.Vector3(ld.z, 0, -ld.x);

  const dots = [
    { dir: straight, dot: straight.dot(dd) },
    { dir: left, dot: left.dot(dd) },
    { dir: right, dot: right.dot(dd) },
  ].sort((a, b) => b.dot - a.dot);

  return dots[0].dir.clone();
}

function addRoadDraftPoint(ptWorld) {
  if (!roadDraft) return beginRoadDraft(ptWorld);
  const width = Number(roadWidthEl?.value || roadDraft.width || ROAD_DEFAULT_WIDTH);
  roadDraft.width = width;

  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;

  const last = roadDraft.points[roadDraft.points.length - 1];
  const delta = pt.clone().sub(last);
  delta.y = 0;
  if (delta.length() < ROAD_MIN_POINT_DIST) return;

  if (roadSnapEnabled && roadDraft.points.length >= 2) {
    const prev = roadDraft.points[roadDraft.points.length - 2];
    const lastDir = last.clone().sub(prev);
    lastDir.y = 0;
    const snappedDir = snapRoadTurn(lastDir, delta);
    const len = Math.max(ROAD_MIN_POINT_DIST, delta.dot(snappedDir));
    pt.copy(last).addScaledVector(snappedDir, len);
    snapXZ(pt);
    pt.y = 0;
  }

  roadDraft.points.push(pt);
  roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  updateRoadControls();
  setStatus(`Road: ${roadDraft.points.length} points (Finish when ready)`);
}

function finishRoadDraft() {
  if (!roadDraft || roadDraft.points.length < 2) return;

  const group = new THREE.Group();
  group.name = "road";

  const pointsLocal = roadDraft.points.map(p => new THREE.Vector3(p.x, p.y, p.z));
  const width = Number(roadDraft.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

  group.userData.road = {
    width,
    points: arraysFromVec3(pointsLocal),
  };
  ensureRoadPointMetaArrays(group);

  ensureRoadMeshFor(group, pointsLocal, width, { preview: false });

  markPlaced(group, {
    id: "road_" + Date.now(),
    asset: null,
    name: "road",
    locked: false,
    type: "road"
  });

  overlayRoot.add(group);
  pushUndo({ type: "add", obj: group, parent: overlayRoot });
  redoStack = [];
  clearRoadDraft();
  selectObject(group);
  setStatus("Road placed (undo available)");
}

const _roadCenterNdc = new THREE.Vector2(0, 0);
function getGroundPointAtStageCenter() {
  raycaster.setFromCamera(_roadCenterNdc, camera);
  const pt = new THREE.Vector3();
  const hit = raycaster.ray.intersectPlane(groundPlane, pt);
  return hit ? pt : null;
}

function spawnDefaultRoadSegment() {
  const center = getGroundPointAtStageCenter() || controls?.target?.clone?.() || new THREE.Vector3(0, 0, 0);
  center.y = 0;
  snapXZ(center);

  const dir = new THREE.Vector3();
  camera.getWorldDirection(dir);
  dir.y = 0;
  if (dir.lengthSq() < 1e-8) dir.set(1, 0, 0);
  else dir.normalize();
  if (roadSnapEnabled) snapDirToCardinal(dir);

  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const len = Math.max(ROAD_SNAP_STEP * 10, 100);

  const p0World = center.clone().addScaledVector(dir, -len / 2);
  const p1World = center.clone().addScaledVector(dir, len / 2);
  p0World.y = 0;
  p1World.y = 0;
  snapXZ(p0World);
  snapXZ(p1World);

  const group = new THREE.Group();
  group.name = "road";
  group.position.copy(center);

  const p0Local = p0World.clone().sub(center);
  const p1Local = p1World.clone().sub(center);

  group.userData.road = {
    width,
    points: arraysFromVec3([p0Local, p1Local]),
  };
  ensureRoadPointMetaArrays(group);

  ensureRoadMeshFor(group, [p0Local, p1Local], width, { preview: false });

  markPlaced(group, {
    id: "road_" + Date.now() + "_" + Math.floor(Math.random() * 1000),
    asset: null,
    name: "road",
    locked: false,
    type: "road"
  });

  overlayRoot.add(group);
  pushUndo({ type: "add", obj: group, parent: overlayRoot });
  redoStack = [];

  selectObject(group);
  setStatus("Road spawned Ã¢â‚¬â€ drag a road point to draw a segment (Draw) or reposition it (Move)");
  updateRoadControls();
  return group;
}

function clearRoadHandles() {
  while (roadEditRoot.children.length) {
    roadEditRoot.children.pop();
  }
}

function updateRoadHandleScale() {
  if (!roadEditRoot.visible) return;
  const d = camera.position.distanceTo(controls.target);
  const s = Math.max(0.35, Math.min(1.8, d / 600)) * ROAD_HANDLE_BASE_SIZE;
  for (const h of roadEditRoot.children) {
    if (!h?.userData?.isRoadHandle) continue;
    h.scale.setScalar(s);
  }
}

function getRoadById(id) {
  if (!id) return null;
  for (const obj of overlayRoot.children) {
    if (isRoadObject(obj) && obj.userData?.id === id) return obj;
  }
  return null;
}

function roadsSharePointIds(a, b) {
  if (!a || !b) return false;
  ensureRoadPointMetaArrays(a);
  ensureRoadPointMetaArrays(b);
  const aIds = new Set(a.userData?.road?.pointIds || []);
  const bIds = b.userData?.road?.pointIds || [];
  for (const id of bIds) {
    if (aIds.has(id)) return true;
  }
  return false;
}

function getConnectedRoads(startRoad) {
  if (!startRoad || !isRoadObject(startRoad)) return [];
  const roads = overlayRoot.children.filter(obj => isRoadObject(obj));
  const connected = new Set([startRoad]);
  let changed = true;
  while (changed) {
    changed = false;
    for (const r of roads) {
      if (connected.has(r)) continue;
      for (const c of connected) {
        if (roadsSharePointIds(r, c)) {
          connected.add(r);
          changed = true;
          break;
        }
      }
    }
  }
  return Array.from(connected);
}

function getConnectedRoadsForTransform() {
  if (!selected || !isRoadObject(selected)) return [];
  const roads = getConnectedRoads(selected).filter(r => canEditObject(r));
  return roads.length > 1 ? roads : [];
}

function computeConnectedRoadsPivot(roads) {
  const box = new THREE.Box3();
  for (const r of roads) {
    if (!r) continue;
    box.expandByObject(r);
  }
  const center = new THREE.Vector3(0, 0, 0);
  if (!box.isEmpty()) box.getCenter(center);
  return center;
}

function applyConnectedTransformFromProxy() {
  if (!connectedTransform || !roadGroupProxy) return;
  const { roads, start, pivot, startProxy } = connectedTransform;
  if (!roads || !roads.length) return;

  const deltaPos = roadGroupProxy.position.clone().sub(startProxy.position);
  const invStartQuat = startProxy.quaternion.clone().invert();
  const deltaQuat = roadGroupProxy.quaternion.clone().multiply(invStartQuat);
  const scaleRatio = new THREE.Vector3(
    startProxy.scale.x ? (roadGroupProxy.scale.x / startProxy.scale.x) : 1,
    startProxy.scale.y ? (roadGroupProxy.scale.y / startProxy.scale.y) : 1,
    startProxy.scale.z ? (roadGroupProxy.scale.z / startProxy.scale.z) : 1
  );

  for (const r of roads) {
    const s = start.get(r);
    if (!s) continue;
    const offset = s.position.clone().sub(pivot);
    offset.multiply(scaleRatio);
    offset.applyQuaternion(deltaQuat);
    const newPos = pivot.clone().add(offset).add(deltaPos);
    r.position.copy(newPos);
    r.quaternion.copy(deltaQuat.clone().multiply(s.quaternion));
    r.scale.set(
      s.scale.x * scaleRatio.x,
      s.scale.y * scaleRatio.y,
      s.scale.z * scaleRatio.z
    );
    r.updateMatrixWorld(true);
  }

  // Keep handles in sync if visible
  syncRoadHandlesToRoad(null);

  return { deltaQuat };
}

function buildRoadHandlesFor(roadObj) {
  clearRoadHandles();
  roadEditRoot.visible = false;
  if (!roadObj || !isRoadObject(roadObj)) return;
  const roads = getConnectedRoads(roadObj);
  if (!roads.length) return;
  for (const r of roads) {
    const ptsLocal = getRoadLocalPoints(r);
    const metaArr = Array.isArray(r.userData?.road?.pointMeta) ? r.userData.road.pointMeta : [];
    for (let i = 0; i < ptsLocal.length; i++) {
      const meta = metaArr[i] || null;
      const isKiosk = isKioskMeta(meta);
      const handle = new THREE.Mesh(roadHandleGeometry, isKiosk ? roadKioskHandleMaterial : roadHandleMaterial);
      handle.userData.isRoadHandle = true;
      handle.userData.roadId = r.userData.id;
      handle.userData.pointIndex = i;

      const wp = r.localToWorld(ptsLocal[i].clone());
      handle.position.copy(wp);
      handle.renderOrder = 9998;
      roadEditRoot.add(handle);
    }
  }
  roadEditRoot.visible = true;
  updateRoadHandleScale();
}

function syncRoadHandlesToRoad(roadObj) {
  if (!roadEditRoot.visible) return;
  if (!roadObj || !isRoadObject(roadObj)) {
    for (const h of roadEditRoot.children) {
      if (!h?.userData?.isRoadHandle) continue;
      const r = getRoadById(h.userData.roadId);
      if (!r) continue;
      const ptsLocal = getRoadLocalPoints(r);
      const idx = h.userData.pointIndex;
      if (idx == null || !ptsLocal[idx]) continue;
      const wp = r.localToWorld(ptsLocal[idx].clone());
      h.position.copy(wp);
    }
    return;
  }
  const ptsLocal = getRoadLocalPoints(roadObj);
  for (const h of roadEditRoot.children) {
    if (!h?.userData?.isRoadHandle) continue;
    if (h.userData.roadId !== roadObj.userData?.id) continue;
    const idx = h.userData.pointIndex;
    if (idx == null || !ptsLocal[idx]) continue;
    const wp = roadObj.localToWorld(ptsLocal[idx].clone());
    h.position.copy(wp);
  }
}

function pickRoadHandle(event) {
  if (!roadEditRoot.visible || !roadEditRoot.children.length) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects(roadEditRoot.children, true);
  if (!hits.length) return null;
  const hit = hits[0].object;
  if (!hit?.userData?.isRoadHandle) return null;
  const idx = hit.userData.pointIndex;
  if (idx == null) return null;
  return { index: idx, roadId: hit.userData.roadId || null, handle: hit };
}

function getRoadTopMeshes(targetRoad = null) {
  const roads = targetRoad ? [targetRoad] : getRoadObjects();
  const meshes = [];
  for (const road of roads) {
    if (!road || !isRoadObject(road)) continue;
    road.traverse((obj) => {
      if (obj?.isMesh && obj.userData?.isRoadMesh === true) meshes.push(obj);
    });
  }
  return meshes;
}

function projectWorldPointToRoadSegment(roadObj, worldPoint, opts = {}) {
  if (!roadObj || !isRoadObject(roadObj) || !worldPoint) return null;
  roadObj.updateMatrixWorld(true);

  const pointsW = getRoadWorldPoints(roadObj);
  if (!Array.isArray(pointsW) || pointsW.length < 2) return null;

  const width = Number(roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const tol = Number.isFinite(opts?.tol)
    ? Number(opts.tol)
    : Math.max(1.5, (Number.isFinite(width) ? width : ROAD_DEFAULT_WIDTH) * 0.75);
  const seg = findSegmentIndexForPointXZ(pointsW, worldPoint, tol);
  if (!seg) return null;

  const a = pointsW[seg.index];
  const b = pointsW[seg.index + 1];
  if (!a || !b) return null;

  const t = Math.max(0, Math.min(1, Number(seg.t) || 0));
  const x = a.x + (b.x - a.x) * t;
  const y = a.y + (b.y - a.y) * t;
  const z = a.z + (b.z - a.z) * t;

  return {
    road: roadObj,
    segIndex: seg.index,
    t,
    dist: Number(seg.dist) || 0,
    point: new THREE.Vector3(x, y, z),
  };
}

function pickRoadSegmentHit(event, targetRoad = null) {
  if (!event) return null;
  const meshes = getRoadTopMeshes(targetRoad);
  if (!meshes.length) return null;

  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects(meshes, true);
  if (!hits.length) return null;

  for (const h of hits) {
    const mesh = h?.object;
    if (!mesh || mesh.userData?.isRoadMesh !== true) continue;

    let roadObj = mesh;
    while (roadObj && !isRoadObject(roadObj)) roadObj = roadObj.parent;
    if (!roadObj || (targetRoad && roadObj !== targetRoad)) continue;

    const projection = projectWorldPointToRoadSegment(roadObj, h.point);
    if (!projection) continue;

    return {
      ...projection,
      hitPoint: h.point.clone(),
      mesh,
    };
  }

  return null;
}

function captureRoadEditState(roadObj) {
  return {
    points: clonePointsArray(roadObj?.userData?.road?.points || []),
    width: Number(roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH),
    ids: Array.isArray(roadObj?.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [],
    meta: cloneRoadPointMeta(roadObj?.userData?.road?.pointMeta || []),
  };
}

function roadEditStateChanged(before, after) {
  if (!before || !after) return false;
  if (JSON.stringify(before.points || []) !== JSON.stringify(after.points || [])) return true;
  if (Math.abs(Number(before.width || 0) - Number(after.width || 0)) > 1e-6) return true;
  if (JSON.stringify(before.ids || []) !== JSON.stringify(after.ids || [])) return true;
  return JSON.stringify(before.meta || []) !== JSON.stringify(after.meta || []);
}

function getRoadPointRefsById(pointId, opts = {}) {
  const id = String(pointId || "").trim();
  if (!id || !overlayRoot) return [];

  const excludeRoad = opts?.excludeRoad || null;
  const excludeIndex = Number.isInteger(opts?.excludeIndex) ? opts.excludeIndex : null;
  const refs = [];

  for (const roadObj of overlayRoot.children || []) {
    if (!isRoadObject(roadObj)) continue;
    const ids = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds : [];
    for (let i = 0; i < ids.length; i++) {
      if (ids[i] !== id) continue;
      if (excludeRoad === roadObj && excludeIndex === i) continue;
      refs.push({ road: roadObj, index: i });
    }
  }

  return refs;
}

function groupRoadPointRefsByRoad(refs = []) {
  const grouped = new Map();
  for (const ref of refs) {
    if (!ref?.road || !isRoadObject(ref.road) || !Number.isInteger(ref.index)) continue;
    if (!grouped.has(ref.road)) grouped.set(ref.road, new Set());
    grouped.get(ref.road).add(ref.index);
  }
  return grouped;
}

function setRoadPointIndexFromWorld(roadObj, index, ptWorld) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[index]) return false;

  const w = ptWorld.clone();
  const local = roadObj.worldToLocal(w);

  ptsLocal[index].copy(local);
  setRoadLocalPoints(roadObj, ptsLocal);
  rebuildRoadObject(roadObj);
  syncRoadHandlesToRoad(roadObj);
  return true;
}

function isRoadEndpointIndex(roadObj, index) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  const ptsLocal = getRoadLocalPoints(roadObj);
  const lastIdx = ptsLocal.length - 1;
  if (!Number.isInteger(index)) return false;
  return index === 0 || index === lastIdx || ptsLocal.length === 1;
}

function maybeMergeRoadEndpoint(roadObj, index, opts = {}) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!isRoadEndpointIndex(roadObj, index)) return false;

  const allow = !!(roadAutoIntersectEnabled || opts.force || opts?.event?.shiftKey);
  if (!allow) return false;

  const threshold = Math.max(2, Number(ROAD_AUTO_INTERSECT_DIST) || 8);
  roadObj.updateMatrixWorld(true);
  ensureRoadPointMetaArrays(roadObj);

  const ptsLocal = getRoadLocalPoints(roadObj);
  const local = ptsLocal[index];
  if (!local) return false;
  const wpt = roadObj.localToWorld(local.clone());

  let best = null;
  for (const other of overlayRoot.children) {
    if (!other || other === roadObj) continue;
    if (!isRoadObject(other)) continue;

    other.updateMatrixWorld(true);
    ensureRoadPointMetaArrays(other);
    const oPts = getRoadLocalPoints(other);
    if (!oPts.length) continue;
    const oIndices = [0, oPts.length - 1];

    for (const oi of oIndices) {
      const op = oPts[oi];
      if (!op) continue;
      const ow = other.localToWorld(op.clone());
      const dx = ow.x - wpt.x;
      const dz = ow.z - wpt.z;
      const dist = Math.hypot(dx, dz);
      if (dist <= threshold && (!best || dist < best.dist)) {
        const oid = Array.isArray(other.userData?.road?.pointIds) ? other.userData.road.pointIds[oi] : null;
        best = { dist, world: ow, otherRoad: other, otherIndex: oi, otherId: oid };
      }
    }
  }

  if (!best) return false;

  const snapped = best.world.clone();
  snapped.y = sampleRoadBaseYAtXZ(snapped.x, snapped.z, wpt.y);
  if (!setRoadPointIndexFromWorld(roadObj, index, snapped)) return false;

  // Share pointId with the other road endpoint (makes intersection "unified").
  if (best.otherId && Array.isArray(roadObj.userData?.road?.pointIds)) {
    roadObj.userData.road.pointIds[index] = best.otherId;
  }

  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);
  setStatus("Road endpoints merged");
  updateRoadControls();
  return true;
}

function getRoadWorldPoints(roadObj) {
  const ptsLocal = getRoadLocalPoints(roadObj);
  return ptsLocal.map(p => roadObj.localToWorld(p.clone()));
}

function segmentIntersectXZ(a, b, c, d, eps = 1e-6) {
  const ax = a.x, az = a.z;
  const bx = b.x, bz = b.z;
  const cx = c.x, cz = c.z;
  const dx = d.x, dz = d.z;
  const rX = bx - ax;
  const rZ = bz - az;
  const sX = dx - cx;
  const sZ = dz - cz;
  const rxs = rX * sZ - rZ * sX;
  if (Math.abs(rxs) < eps) return null; // parallel/colinear

  const qpx = cx - ax;
  const qpz = cz - az;
  const t = (qpx * sZ - qpz * sX) / rxs;
  const u = (qpx * rZ - qpz * rX) / rxs;
  if (t < 0 || t > 1 || u < 0 || u > 1) return null;

  return { x: ax + t * rX, z: az + t * rZ, t, u };
}

function pointSegmentDistanceXZ(p, a, b) {
  const ax = a.x, az = a.z;
  const bx = b.x, bz = b.z;
  const px = p.x, pz = p.z;
  const vx = bx - ax;
  const vz = bz - az;
  const wx = px - ax;
  const wz = pz - az;
  const lenSq = vx * vx + vz * vz;
  let t = 0;
  if (lenSq > 1e-8) t = (wx * vx + wz * vz) / lenSq;
  if (t < 0) t = 0;
  else if (t > 1) t = 1;
  const qx = ax + vx * t;
  const qz = az + vz * t;
  const dx = px - qx;
  const dz = pz - qz;
  return { dist: Math.hypot(dx, dz), t };
}

function findSegmentIndexForPointXZ(pointsW, p, tol) {
  let best = null;
  for (let i = 0; i < pointsW.length - 1; i++) {
    const a = pointsW[i];
    const b = pointsW[i + 1];
    if (!a || !b) continue;
    const res = pointSegmentDistanceXZ(p, a, b);
    if (res.dist <= tol && (!best || res.dist < best.dist)) {
      best = { index: i, dist: res.dist, t: res.t };
    }
  }
  return best;
}

function findNearbyPointIndexWorld(pointsW, wpt, tol) {
  const tolSq = tol * tol;
  for (let i = 0; i < pointsW.length; i++) {
    const p = pointsW[i];
    if (!p) continue;
    const dx = p.x - wpt.x;
    const dz = p.z - wpt.z;
    if ((dx * dx + dz * dz) <= tolSq) return i;
  }
  return -1;
}

function insertRoadPointAtSegment(roadObj, segIndex, worldPoint, meta = null, pointId = null) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  ensureRoadPointMetaArrays(roadObj);

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[segIndex] || !ptsLocal[segIndex + 1]) return false;

  const local = roadObj.worldToLocal(worldPoint.clone());
  ptsLocal.splice(segIndex + 1, 0, local);
  const newId = pointId || newRoadPointId();
  roadObj.userData.road.pointIds.splice(segIndex + 1, 0, newId);
  roadObj.userData.road.pointMeta.splice(segIndex + 1, 0, meta);

  setRoadLocalPoints(roadObj, ptsLocal);
  rebuildRoadObject(roadObj);
  return true;
}

function insertRoadPointFromWorldHit(roadObj, worldPoint) {
  if (!roadObj || !isRoadObject(roadObj) || !worldPoint) return false;
  if (!canEditObject(roadObj)) return false;

  const projection = projectWorldPointToRoadSegment(roadObj, worldPoint);
  if (!projection) return false;

  const pointsW = getRoadWorldPoints(roadObj);
  const width = Number(roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const nearTol = Math.max(0.75, Math.min(ROAD_MIN_POINT_DIST, (Number.isFinite(width) ? width : ROAD_DEFAULT_WIDTH) * 0.35));
  const nearbyIndex = findNearbyPointIndexWorld(pointsW, projection.point, nearTol);
  if (nearbyIndex !== -1) {
    buildRoadHandlesFor(roadObj);
    syncRoadHandlesToRoad(roadObj);
    selectRoadPoint(roadObj, nearbyIndex, { mode: "set", showStatus: true });
    updateRoadControls();
    return true;
  }

  const before = captureRoadEditState(roadObj);
  if (!insertRoadPointAtSegment(roadObj, projection.segIndex, projection.point)) return false;

  const after = captureRoadEditState(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  const insertedIndex = projection.segIndex + 1;
  selectRoadPoint(roadObj, insertedIndex, { mode: "set", showStatus: false });
  pushUndo({
    type: "road_edit",
    obj: roadObj,
    beforePoints: before.points,
    afterPoints: after.points,
    beforeWidth: before.width,
    afterWidth: after.width,
    beforeIds: before.ids,
    afterIds: after.ids,
    beforeMeta: before.meta,
    afterMeta: after.meta,
  });
  redoStack = [];

  setStatus("Road point inserted - use Drag: Move to reposition or Drag: Draw to branch");
  updateRoadControls();
  return true;
}

function applyAutoIntersectionsForSegment(roadObj, segStartIndex, segEndIndex, opts = {}) {
  if (!roadObj || !isRoadObject(roadObj)) return { modifiedOthers: [] };
  if (!roadAutoIntersectEnabled && !opts?.event?.shiftKey) return { modifiedOthers: [] };

  ensureRoadPointMetaArrays(roadObj);
  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[segStartIndex] || !ptsLocal[segEndIndex]) return { modifiedOthers: [] };

  roadObj.updateMatrixWorld(true);
  const a = roadObj.localToWorld(ptsLocal[segStartIndex].clone());
  const b = roadObj.localToWorld(ptsLocal[segEndIndex].clone());
  const tol = Math.max(2, Number(ROAD_AUTO_INTERSECT_DIST) || 8) * 0.5;

  const intersections = [];
  for (const other of overlayRoot.children) {
    if (!other) continue;
    if (!isRoadObject(other)) continue;

    other.updateMatrixWorld(true);
    const oPtsW = getRoadWorldPoints(other);
    for (let i = 0; i < oPtsW.length - 1; i++) {
      if (other === roadObj) {
        if (i === segStartIndex) continue;
        if (i === segStartIndex - 1) continue;
        if (i === segStartIndex + 1) continue;
      }
      const c = oPtsW[i];
      const d = oPtsW[i + 1];
      const hit = segmentIntersectXZ(a, b, c, d);
      if (!hit) continue;

      const wpt = new THREE.Vector3(hit.x, sampleRoadBaseYAtXZ(hit.x, hit.z, a.y), hit.z);
      intersections.push({
        t: hit.t,
        u: hit.u,
        point: wpt,
        otherRoad: other,
        otherSegIndex: i,
      });
    }
  }

  if (!intersections.length) return { modifiedOthers: [] };

  // De-dupe intersections close to each other
  const deduped = [];
  for (const it of intersections) {
    const exists = deduped.find(d => d.point.distanceTo(it.point) <= tol);
    if (!exists) deduped.push(it);
  }

  // Assign shared pointId for each intersection (so roads are connected as one)
  for (const it of deduped) {
    try {
      const curPtsW = getRoadWorldPoints(roadObj);
      const curIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds : [];
      const curIdx = findNearbyPointIndexWorld(curPtsW, it.point, tol);

      ensureRoadPointMetaArrays(it.otherRoad);
      const othPtsW = getRoadWorldPoints(it.otherRoad);
      const othIds = Array.isArray(it.otherRoad.userData?.road?.pointIds) ? it.otherRoad.userData.road.pointIds : [];
      const othIdx = findNearbyPointIndexWorld(othPtsW, it.point, tol);

      let sharedId = null;
      if (curIdx !== -1) sharedId = curIds[curIdx];
      else if (othIdx !== -1) sharedId = othIds[othIdx];
      else sharedId = newRoadPointId();

      it.sharedId = sharedId;
      it.curIdx = curIdx;
      it.otherIdx = othIdx;
    } catch (_) {
      it.sharedId = newRoadPointId();
      it.curIdx = -1;
      it.otherIdx = -1;
    }
  }

  // Insert into current road (descending t so order is preserved)
  const currentInsert = deduped
    .slice()
    .sort((a, b) => b.t - a.t);

  const currentPointsW = getRoadWorldPoints(roadObj);
  for (const it of currentInsert) {
    const nearIdx = findNearbyPointIndexWorld(currentPointsW, it.point, tol);
    if (nearIdx !== -1) continue;
    insertRoadPointAtSegment(roadObj, segStartIndex, it.point, null, it.sharedId);
    // refresh world points after insertion
    roadObj.updateMatrixWorld(true);
    currentPointsW.length = 0;
    currentPointsW.push(...getRoadWorldPoints(roadObj));
  }

  // Insert into other roads (group by road/segment; descending order prevents index shifts)
  const modifiedOthers = new Map();
  const byRoad = new Map();
  for (const it of deduped) {
    if (!byRoad.has(it.otherRoad)) byRoad.set(it.otherRoad, []);
    byRoad.get(it.otherRoad).push(it);
  }

  for (const [other, list] of byRoad.entries()) {
    if (other === roadObj) {
      other.updateMatrixWorld(true);
      let oPtsW = getRoadWorldPoints(other);
      const grouped = list.slice().sort((a, b) => {
        if (a.otherSegIndex !== b.otherSegIndex) return b.otherSegIndex - a.otherSegIndex;
        return b.u - a.u;
      });

      for (const it of grouped) {
        const nearIdx = findNearbyPointIndexWorld(oPtsW, it.point, tol);
        if (nearIdx !== -1) {
          const ids = other.userData?.road?.pointIds || [];
          if (ids[nearIdx] && it.sharedId && ids[nearIdx] !== it.sharedId) {
            ids[nearIdx] = it.sharedId;
          }
          continue;
        }

        const seg = findSegmentIndexForPointXZ(oPtsW, it.point, tol);
        if (!seg) continue;
        insertRoadPointAtSegment(other, seg.index, it.point, null, it.sharedId);
        other.updateMatrixWorld(true);
        oPtsW = getRoadWorldPoints(other);
      }
      continue;
    }

    const beforePoints = clonePointsArray(other.userData?.road?.points || []);
    const beforeWidth = Number(other.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    const beforeIds = Array.isArray(other.userData?.road?.pointIds) ? other.userData.road.pointIds.slice() : [];
    const beforeMeta = cloneRoadPointMeta(other.userData?.road?.pointMeta || []);

    const grouped = list.slice().sort((a, b) => {
      if (a.otherSegIndex !== b.otherSegIndex) return b.otherSegIndex - a.otherSegIndex;
      return b.u - a.u;
    });

    other.updateMatrixWorld(true);
    let oPtsW = getRoadWorldPoints(other);
    for (const it of grouped) {
      const nearIdx = findNearbyPointIndexWorld(oPtsW, it.point, tol);
      if (nearIdx !== -1) {
        const ids = other.userData?.road?.pointIds || [];
        if (ids[nearIdx] && it.sharedId && ids[nearIdx] !== it.sharedId) {
          ids[nearIdx] = it.sharedId;
        }
        continue;
      }
      insertRoadPointAtSegment(other, it.otherSegIndex, it.point, null, it.sharedId);
      other.updateMatrixWorld(true);
      oPtsW = getRoadWorldPoints(other);
    }

    const afterPoints = clonePointsArray(other.userData?.road?.points || []);
    const afterWidth = Number(other.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    const afterIds = Array.isArray(other.userData?.road?.pointIds) ? other.userData.road.pointIds.slice() : [];
    const afterMeta = cloneRoadPointMeta(other.userData?.road?.pointMeta || []);

    const changed = JSON.stringify(beforePoints) !== JSON.stringify(afterPoints);
    if (changed) {
      modifiedOthers.set(other, {
        beforePoints,
        afterPoints,
        beforeWidth,
        afterWidth,
        beforeIds,
        afterIds,
        beforeMeta,
        afterMeta
      });
    }
  }

  return { modifiedOthers: Array.from(modifiedOthers.entries()) };
}

function beginRoadSegmentDrag(roadObj, startIndex, pointerId, clientX, clientY) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[startIndex]) return false;

  roadObj.updateMatrixWorld(true);
  const startWorld = roadObj.localToWorld(ptsLocal[startIndex].clone());
  ensureRoadPointMetaArrays(roadObj);

  isDraggingRoadSegment = true;
  roadSegmentDrag = {
    road: roadObj,
    startIndex,
    pointerId,
    startX: clientX,
    startY: clientY,
    startWorld,
    lastEndWorld: null,
    lastSnap: null, // { buildingName: string, side: string }|null
    beforePoints: clonePointsArray(roadObj.userData?.road?.points || []),
    beforeWidth: Number(roadObj.userData?.road?.width || ROAD_DEFAULT_WIDTH),
    beforeIds: Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [],
    beforeMeta: cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []),
  };

  controls.enabled = false;
  try { renderer.domElement.setPointerCapture(pointerId); } catch (_) {}
  setStatus("Road: drag to place next pointÃ¢â‚¬Â¦");
  return true;
}

function updateRoadSegmentDrag(event) {
  if (!isDraggingRoadSegment || !roadSegmentDrag) return;
  if (!event || roadSegmentDrag.pointerId !== event.pointerId) return;

  const anchorW = roadSegmentDrag.startWorld;
  const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, anchorW.y);
  const width = Number(roadSegmentDrag.road?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;
  const snapOut = {};
  const endW = computeSnappedRoadEnd(anchorW, rawOnPlane, { width, out: snapOut, snapSources: liveSources });
  roadSegmentDrag.lastEndWorld = endW;
  roadSegmentDrag.lastSnap = snapOut?.snapped ? {
    buildingName: snapOut.buildingName,
    objectName: snapOut.objectName,
    buildingUid: snapOut.buildingUid,
    entityType: snapOut.entityType,
    side: snapOut.side
  } : null;

  if (endW) {
    showRoadHoverPreviewSegment(anchorW, endW, width);
  } else {
    hideRoadHoverPreview();
  }
}

function endRoadSegmentDrag(pointerId = null, event = null) {
  if (!isDraggingRoadSegment || !roadSegmentDrag) return;
  if (pointerId != null && roadSegmentDrag.pointerId !== pointerId) return;

  const drag = roadSegmentDrag;
  const roadObj = drag.road;
  const startIndex = drag.startIndex;
  const startWorld = drag.startWorld;

  isDraggingRoadSegment = false;
  roadSegmentDrag = null;

  controls.enabled = true;
  syncControlsForPointers();
  hideRoadHoverPreview();

  const TAP_SLOP_PX = 8;
  const dx = event ? Math.abs(event.clientX - drag.startX) : TAP_SLOP_PX + 1;
  const dy = event ? Math.abs(event.clientY - drag.startY) : TAP_SLOP_PX + 1;
  const isTap = dx <= TAP_SLOP_PX && dy <= TAP_SLOP_PX;

  if (isTap) {
    selectRoadPoint(roadObj, startIndex, { mode: "toggle", showStatus: true });
    updateRoadControls();
    return;
  }

  // Compute final snapped end point.
  let endWorld = drag.lastEndWorld;
  let endSnap = drag.lastSnap || null;
  if (!endWorld && event) {
    const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, startWorld.y);
    const width = Number(roadObj?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;
    const snapOut = {};
    endWorld = computeSnappedRoadEnd(startWorld, rawOnPlane, { width, out: snapOut, snapSources: liveSources });
    endSnap = snapOut?.snapped ? {
      buildingName: snapOut.buildingName,
      objectName: snapOut.objectName,
      buildingUid: snapOut.buildingUid,
      entityType: snapOut.entityType,
      side: snapOut.side
    } : null;
  }
  if (!endWorld) {
    setStatus("Road: canceled");
    updateRoadControls();
    return;
  }

  const horizLen = Math.hypot(endWorld.x - startWorld.x, endWorld.z - startWorld.z);
  if (horizLen < ROAD_MIN_POINT_DIST) {
    updateRoadControls();
    return;
  }

  roadObj?.updateMatrixWorld?.(true);
  const ptsLocal = getRoadLocalPoints(roadObj);
  const lastIdx = ptsLocal.length - 1;

  // Endpoint extension edits the same road; interior-point drag branches into a new road.
  ensureRoadPointMetaArrays(roadObj);
  const startMeta = Array.isArray(roadObj.userData?.road?.pointMeta) ? roadObj.userData.road.pointMeta[startIndex] : null;
  const startPid = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds[startIndex] : null;
  const isKioskStart = isKioskMeta(startMeta);
  const isEndpoint = !isKioskStart && (startIndex === 0 || startIndex === lastIdx || ptsLocal.length === 1);
  if (!isEndpoint) {
    const width = Number(roadObj.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const branch = createRoadFromWorldPoints([startWorld, endWorld], width, { recordHistory: false, autoSelect: true });
    if (branch) {
      const batchCmds = [{ type: "add", obj: branch, parent: overlayRoot }];
      ensureRoadPointMetaArrays(branch);
      if (startPid && Array.isArray(branch.userData?.road?.pointIds)) {
        branch.userData.road.pointIds[0] = startPid;
      }
      // Copy start-point meta if present, and set end-point meta if snapped to a building.
      if (Array.isArray(branch.userData?.road?.pointMeta)) {
        if (startMeta) {
          const cloned = isKioskMeta(startMeta)
            ? normalizeKioskMeta(startMeta, startPid)
            : { ...startMeta, building: (startMeta.building ? { ...startMeta.building } : null) };
          if (startPid && cloned && typeof cloned === "object") cloned.id = startPid;
          branch.userData.road.pointMeta[0] = cloned;
        } else {
          branch.userData.road.pointMeta[0] = null;
        }
      }
      if (endSnap?.buildingName && endSnap?.side && Array.isArray(branch.userData?.road?.pointIds) && Array.isArray(branch.userData?.road?.pointMeta)) {
        const pid = branch.userData.road.pointIds[1];
        branch.userData.road.pointMeta[1] = makeBuildingLinkMeta(endSnap, endSnap.side, pid);
      }
      // Auto-intersect on newly created branch segment
      const res = applyAutoIntersectionsForSegment(branch, 0, 1, { event });
      if (Array.isArray(res.modifiedOthers)) {
        for (const [other, st] of res.modifiedOthers) {
          batchCmds.push({ type: "road_edit", obj: other, beforePoints: st.beforePoints, afterPoints: st.afterPoints, beforeWidth: st.beforeWidth, afterWidth: st.afterWidth, beforeIds: st.beforeIds, afterIds: st.afterIds, beforeMeta: st.beforeMeta, afterMeta: st.afterMeta });
        }
      }
      pushUndoBatch(batchCmds);
      redoStack = [];
      buildRoadHandlesFor(branch);
      syncRoadHandlesToRoad(branch);
      const branchPts = getRoadLocalPoints(branch);
      const branchIdx = Math.max(0, branchPts.length - 1);
      selectRoadPoint(branch, branchIdx, { mode: "set", showStatus: false });
      setStatus("Road branch created (undo available)");
    }
    updateRoadControls();
    return;
  }

  const beforePoints = drag.beforePoints;
  const beforeWidth = drag.beforeWidth;
  const beforeIds = drag.beforeIds || [];
  const beforeMeta = drag.beforeMeta || [];
  const newLocal = roadObj.worldToLocal(endWorld.clone());

  ensureRoadPointMetaArrays(roadObj);
  const roadData = roadObj.userData.road;

  const newId = newRoadPointId();
  const newMeta = (endSnap?.buildingName && endSnap?.side) ? makeBuildingLinkMeta(endSnap, endSnap.side, newId) : null;

  if (ptsLocal.length === 1) {
    ptsLocal.push(newLocal);
    roadData.pointIds.push(newId);
    roadData.pointMeta.push(newMeta);
  } else if (startIndex === 0) {
    ptsLocal.unshift(newLocal);
    roadData.pointIds.unshift(newId);
    roadData.pointMeta.unshift(newMeta);
  } else {
    ptsLocal.push(newLocal);
    roadData.pointIds.push(newId);
    roadData.pointMeta.push(newMeta);
  }

  setRoadLocalPoints(roadObj, ptsLocal);
  roadObj.userData.road.width = beforeWidth;
  rebuildRoadObject(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  // Auto-select the new endpoint so the user can keep drawing.
  const wasSinglePoint = Array.isArray(beforePoints) && beforePoints.length === 1;
  const extendedAtStart = !wasSinglePoint && startIndex === 0;
  const newIdx = extendedAtStart ? 0 : (ptsLocal.length - 1);

  const res = applyAutoIntersectionsForSegment(roadObj, extendedAtStart ? 0 : (ptsLocal.length - 2), extendedAtStart ? 1 : (ptsLocal.length - 1), { event });
  const batchCmds = [];
  if (Array.isArray(res.modifiedOthers)) {
    for (const [other, st] of res.modifiedOthers) {
      batchCmds.push({ type: "road_edit", obj: other, beforePoints: st.beforePoints, afterPoints: st.afterPoints, beforeWidth: st.beforeWidth, afterWidth: st.afterWidth, beforeIds: st.beforeIds, afterIds: st.afterIds, beforeMeta: st.beforeMeta, afterMeta: st.afterMeta });
    }
  }

  const ptsAfter = getRoadLocalPoints(roadObj);
  const mergedIdx = extendedAtStart ? 0 : (ptsAfter.length - 1);
  maybeMergeRoadEndpoint(roadObj, mergedIdx, { event });
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);
  const afterPoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const afterIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);
  batchCmds.push({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth: beforeWidth, beforeIds, afterIds, beforeMeta, afterMeta });
  pushUndoBatch(batchCmds);
  redoStack = [];

  const finalIdx = extendedAtStart ? 0 : (getRoadLocalPoints(roadObj).length - 1);
  selectRoadPoint(roadObj, finalIdx, { mode: "set", showStatus: false });
  setStatus("Road segment added (undo available)");
  updateRoadControls();
}

function beginRoadHandleDrag(roadObj, index, pointerId) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;
  if (!Array.isArray(roadObj.userData?.road?.points)) return false;

  ensureRoadPointMetaArrays(roadObj);
  const movedPointId = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds[index] : null;
  const pointRefs = movedPointId
    ? [{ road: roadObj, index }, ...getRoadPointRefsById(movedPointId, { excludeRoad: roadObj, excludeIndex: index })]
    : [{ road: roadObj, index }];
  const beforeStates = new Map();
  for (const ref of pointRefs) {
    if (!ref?.road || beforeStates.has(ref.road)) continue;
    beforeStates.set(ref.road, captureRoadEditState(ref.road));
  }

  isDraggingRoadHandle = true;
  roadHandleDrag = {
    road: roadObj,
    index,
    pointerId,
    beforePoints: clonePointsArray(roadObj.userData.road.points),
    beforeWidth: Number(roadObj.userData.road.width || ROAD_DEFAULT_WIDTH),
    beforeIds: Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [],
    beforeMeta: cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []),
    pointId: movedPointId || null,
    pointRefs,
    sharedRefs: pointRefs.filter((ref) => !(ref?.road === roadObj && ref?.index === index)),
    beforeStates,
  };
  controls.enabled = false;
  try { renderer.domElement.setPointerCapture(pointerId); } catch (_) {}
  setStatus("Road point: draggingÃ¢â‚¬Â¦");
  return true;
}

function endRoadHandleDrag(pointerId = null) {
  if (!isDraggingRoadHandle || !roadHandleDrag) return;
  if (pointerId != null && roadHandleDrag.pointerId !== pointerId) return;

  const drag = roadHandleDrag;
  const handleIndex = drag.index;
  const roadObj = drag.road;
  const beforePoints = drag.beforePoints;
  const beforeWidth = drag.beforeWidth;
  const beforeIds = drag.beforeIds || [];
  const beforeMeta = drag.beforeMeta || [];
  const trackedBeforeStates = drag.beforeStates instanceof Map
    ? new Map(drag.beforeStates)
    : new Map();
  if (!trackedBeforeStates.has(roadObj)) {
    trackedBeforeStates.set(roadObj, {
      points: beforePoints,
      width: beforeWidth,
      ids: beforeIds,
      meta: beforeMeta,
    });
  }
  const pointRefs = Array.isArray(drag.pointRefs) && drag.pointRefs.length
    ? drag.pointRefs
    : [{ road: roadObj, index: handleIndex }];

  isDraggingRoadHandle = false;
  roadHandleDrag = null;
  controls.enabled = true;

  if (roadObj && isRoadEndpointIndex(roadObj, handleIndex)) {
    maybeMergeRoadEndpoint(roadObj, handleIndex, { event: lastPointerEvent });
  }

  const movedPointId = roadObj?.userData?.road?.pointIds?.[handleIndex] ?? null;
  const refsByRoad = groupRoadPointRefsByRoad(pointRefs);
  const autoBeforeStates = new Map();

  for (const [refRoad, indexSet] of refsByRoad.entries()) {
    const beforeState = trackedBeforeStates.get(refRoad) || captureRoadEditState(refRoad);
    const afterStatePre = captureRoadEditState(refRoad);
    if (!roadEditStateChanged(beforeState, afterStatePre)) continue;

    const segKeys = new Set();
    const indices = Array.from(indexSet.values()).sort((a, b) => b - a);
    const ptsLocalNow = getRoadLocalPoints(refRoad);
    for (const idx of indices) {
      if (!Number.isInteger(idx)) continue;
      if (idx > 0) segKeys.add(`${idx - 1}:${idx}`);
      if (idx < ptsLocalNow.length - 1) segKeys.add(`${idx}:${idx + 1}`);
    }

    const segs = Array.from(segKeys)
      .map((key) => {
        const [startRaw, endRaw] = key.split(":");
        return { start: Number(startRaw), end: Number(endRaw) };
      })
      .filter((seg) => Number.isInteger(seg.start) && Number.isInteger(seg.end))
      .sort((a, b) => b.start - a.start);

    for (const seg of segs) {
      const res = applyAutoIntersectionsForSegment(refRoad, seg.start, seg.end, { event: lastPointerEvent });
      if (!Array.isArray(res.modifiedOthers)) continue;
      for (const [other, st] of res.modifiedOthers) {
        if (trackedBeforeStates.has(other) || autoBeforeStates.has(other)) continue;
        autoBeforeStates.set(other, {
          points: clonePointsArray(st.beforePoints || []),
          width: Number(st.beforeWidth || ROAD_DEFAULT_WIDTH),
          ids: Array.isArray(st.beforeIds) ? st.beforeIds.slice() : [],
          meta: cloneRoadPointMeta(st.beforeMeta || []),
        });
      }
    }
  }

  for (const [other, state] of autoBeforeStates.entries()) {
    trackedBeforeStates.set(other, state);
  }

  const batchCmds = [];
  for (const [editRoad, beforeState] of trackedBeforeStates.entries()) {
    const afterState = captureRoadEditState(editRoad);
    if (!roadEditStateChanged(beforeState, afterState)) continue;
    batchCmds.push({
      type: "road_edit",
      obj: editRoad,
      beforePoints: beforeState.points,
      afterPoints: afterState.points,
      beforeWidth: beforeState.width,
      afterWidth: afterState.width,
      beforeIds: beforeState.ids,
      afterIds: afterState.ids,
      beforeMeta: beforeState.meta,
      afterMeta: afterState.meta,
    });
  }

  if (batchCmds.length && roadObj) {
    pushUndoBatch(batchCmds);
    redoStack = [];

    const handleIndexAfter = (movedPointId && Array.isArray(roadObj.userData?.road?.pointIds))
      ? roadObj.userData.road.pointIds.indexOf(movedPointId)
      : handleIndex;
    const finalIndex = (handleIndexAfter != null && handleIndexAfter >= 0) ? handleIndexAfter : handleIndex;

    buildRoadHandlesFor(roadObj);
    syncRoadHandlesToRoad(roadObj);
    selectRoadPoint(roadObj, finalIndex, { mode: "set", showStatus: false });
    setStatus("Road edited - undo available");
  } else {
    // Treat a simple tap as point selection (and endpoint selection for extension).
    selectRoadPoint(roadObj, handleIndex, { mode: "toggle", showStatus: true });
  }
  updateRoadControls();
}

function extendRoadAtPoint(roadObj, ptWorld) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (ptsLocal.length < 1) return false;

  const beforePoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const beforeWidth = Number(roadObj.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  ensureRoadPointMetaArrays(roadObj);
  const beforeIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const beforeMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);

  const ptW = snapXZ(ptWorld.clone());
  ptW.y = 0;

  const startW = roadObj.localToWorld(ptsLocal[0].clone());
  const endW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 1].clone());
  const distStart = ptW.distanceTo(startW);
  const distEnd = ptW.distanceTo(endW);
  let extendEnd = distEnd <= distStart;

  // If user picked an endpoint handle, override nearest-end logic.
  if (roadExtendFromRoadId && roadExtendFromRoadId === roadObj.userData?.id && roadExtendFrom) {
    extendEnd = roadExtendFrom === "end";
  }

  let newW = ptW.clone();
  if (roadSnapEnabled && ptsLocal.length >= 2) {
    if (extendEnd) {
      const prevW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 2].clone());
      const lastDir = endW.clone().sub(prevW); lastDir.y = 0;
      const desired = ptW.clone().sub(endW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = endW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    } else {
      const nextW = roadObj.localToWorld(ptsLocal[1].clone());
      const lastDir = startW.clone().sub(nextW); lastDir.y = 0; // direction from next -> start
      const desired = ptW.clone().sub(startW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = startW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    }
  }

  const newLocal = roadObj.worldToLocal(newW.clone());
  newLocal.y = 0;
  snapXZ(newLocal);

  const endLocal = extendEnd ? ptsLocal[ptsLocal.length - 1] : ptsLocal[0];
  if (newLocal.distanceTo(endLocal) < ROAD_MIN_POINT_DIST) return false;

  const oldLastIdx = ptsLocal.length - 1;
  const newId = newRoadPointId();
  const newMeta = null;
  if (extendEnd) {
    ptsLocal.push(newLocal);
    roadObj.userData.road.pointIds.push(newId);
    roadObj.userData.road.pointMeta.push(newMeta);
  } else {
    ptsLocal.unshift(newLocal);
    roadObj.userData.road.pointIds.unshift(newId);
    roadObj.userData.road.pointMeta.unshift(newMeta);
  }

  // Keep point selection stable after inserting a new endpoint.
  if (selected === roadObj && Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0) {
    if (extendEnd) {
      if (roadSelectedPointIndex === oldLastIdx) roadSelectedPointIndex = ptsLocal.length - 1;
    } else {
      if (roadSelectedPointIndex !== 0) roadSelectedPointIndex = roadSelectedPointIndex + 1;
      else roadSelectedPointIndex = 0;
    }
  }

  setRoadLocalPoints(roadObj, ptsLocal);
  roadObj.userData.road.width = beforeWidth;
  rebuildRoadObject(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  const afterPoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const afterIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);
  pushUndo({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth: beforeWidth, beforeIds, afterIds, beforeMeta, afterMeta });
  redoStack = [];
  setStatus("Road extended Ã¢Å“â€œ (undo available)");
  updateRoadControls();
  return true;
}

// Collision (solid objects)
const COLLIDE_WITH_BASE_MODEL = true;
const MIN_BASE_COLLIDER_HEIGHT = 0.5;
const BASE_MIN_Y_OVERLAP = 0.15;
const COLLISION_EPS = 0.001;

const baseColliderMeshes = [];
const _boxA = new THREE.Box3();
const _boxB = new THREE.Box3();

// Ground plane
const groundPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

function setMouseFromEvent(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((event.clientY - rect.top) / rect.height) * 2 - 1);
}

function isEventOnStage(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  return event.clientX >= rect.left &&
    event.clientX <= rect.right &&
    event.clientY >= rect.top &&
    event.clientY <= rect.bottom;
}

function getGroundPointFromEvent(event) {
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const pt = new THREE.Vector3();
  const hit = raycaster.ray.intersectPlane(groundPlane, pt);
  return hit ? pt : null;
}

// -------------------- ROTATE PROXY (object center) --------------------
const rotateProxy = new THREE.Object3D();
rotateProxy.name = "rotateProxy";
scene.add(rotateProxy);

// -------------------- CONNECTED ROAD TRANSFORM PROXY --------------------
const roadGroupProxy = new THREE.Object3D();
roadGroupProxy.name = "roadGroupProxy";
scene.add(roadGroupProxy);

let connectedTransform = null; // { roads: [], start: Map, pivot: Vector3, startProxy: {position, quaternion, scale} }

function getWorldBBoxCenter(obj, out = new THREE.Vector3()) {
  const box = new THREE.Box3().setFromObject(obj);
  box.getCenter(out);
  return out;
}

function updateRotateProxyAtSelection() {
  if (!selected) return;
  getWorldBBoxCenter(selected, rotateProxy.position);
  if (!isTransformDragging) {
    rotateProxy.quaternion.copy(selected.quaternion);
  }
  rotateProxy.scale.set(1, 1, 1);
  rotateProxy.updateMatrixWorld(true);
}

// -------------------- Angle readout helpers --------------------
let lastPointerClientX = 0;
let lastPointerClientY = 0;

function showAngleReadout(text) {
  if (!angleReadoutEl) return;
  angleReadoutEl.style.display = "block";
  angleReadoutEl.textContent = text;
  angleReadoutEl.style.left = lastPointerClientX + "px";
  angleReadoutEl.style.top = lastPointerClientY + "px";
}
function hideAngleReadout() {
  if (!angleReadoutEl) return;
  angleReadoutEl.style.display = "none";
}

window.addEventListener("pointermove", (e) => {
  lastPointerClientX = e.clientX;
  lastPointerClientY = e.clientY;
}, { passive:true });

// -------------------- Transform Controls (FIXED) --------------------
const transform = new TransformControls(camera, renderer.domElement);

// Ã¢Å“â€¦ FIX: Add the helper (Object3D) to the scene, NOT transform itself
const transformHelper = transform.getHelper();
transformHelper.visible = false;
scene.add(transformHelper);

transform.setSize(1.0);
transform.setSpace("local");
transform.showX = true;
transform.showY = true;
transform.showZ = true;
transform.showE = false;

// Make gizmo ALWAYS visible & clickable (render on top)
function forceGizmoAlwaysVisible() {
  const root = transformHelper;
  root.renderOrder = 9999;
  root.traverse((n) => {
    if (n.isLine || n.isMesh) {
      n.renderOrder = 9999;
      if (n.material) {
        const mats = Array.isArray(n.material) ? n.material : [n.material];
        for (const m of mats) {
          m.depthTest = false;
          m.depthWrite = false;
          m.transparent = true;
        }
      }
    }
  });
}
forceGizmoAlwaysVisible();

function updateGizmoSize() {
  if (!transform || !transform.object) return;
  const d = camera.position.distanceTo(controls.target);
  let size = Math.max(0.7, Math.min(4.5, d / 220));
  if (currentTool === "rotate" && transform.object === rotateProxy) {
    size = Math.max(1.2, Math.min(3.2, d / 300));
  }
  transform.setSize(size);
}

// Rotate drag state
let isTransformDragging = false;
let gizmoLastSafePos = null;
let isGizmoPointerDown = false;

let rotateStartProxyQuat = new THREE.Quaternion();
let rotateStartSelQuat = new THREE.Quaternion();
let rotateStartSelPos = new THREE.Vector3();
let rotateCenterWorld = new THREE.Vector3();
let rotateAxis = null;

transform.addEventListener("dragging-changed", (e) => {
  isTransformDragging = !!e.value;
  controls.enabled = !e.value;

  if (!e.value) {
    isGizmoPointerDown = false;
    hideAngleReadout();

    if (currentTool === "rotate" && selected) {
      updateRotateProxyAtSelection();
    }
  }
});

// -------------------- Undo/Redo --------------------
let undoStack = [];
let redoStack = [];

function pushUndo(cmd) {
  undoStack.push(cmd);
  scheduleDirtyRefresh();
}

function pushUndoBatch(commands) {
  const list = Array.isArray(commands) ? commands.filter(Boolean) : [];
  if (!list.length) return;
  if (list.length === 1) {
    pushUndo(list[0]);
    return;
  }
  undoStack.push({ type: "batch", commands: list });
  scheduleDirtyRefresh();
}

function cloneTRS(obj) {
  return {
    position: obj.position.clone(),
    rotation: obj.rotation.clone(),
    scale: obj.scale.clone()
  };
}
function applyTRS(obj, trs) {
  if (!obj || !trs) return;
  obj.position.copy(trs.position);
  obj.rotation.copy(trs.rotation);
  obj.scale.copy(trs.scale);
  obj.updateMatrixWorld();
}
function removeObject(obj) {
  if (!obj) return;
  if (transform.object === obj) transform.detach();
  if (selected === obj) {
    selected = null;
    updateCommitButtons();
    clearRoadHandles();
    roadEditRoot.visible = false;
    updateRoadControls();
  }
  obj.parent?.remove(obj);
}
function insertObject(parent, obj) {
  if (!parent || !obj) return;
  parent.add(obj);
}

let activeBefore = null;

function historyTargetObject() {
  if (transform.object === roadGroupProxy) return roadGroupProxy;
  if (currentTool === "rotate" && selected) return selected;
  return transform.object;
}

transform.addEventListener("mouseDown", () => {
  const targetObj = historyTargetObject();
  if (!targetObj) return;

  isGizmoPointerDown = true;

  if (targetObj === roadGroupProxy) {
    const roads = getConnectedRoadsForTransform();
    if (roads.length) {
      const pivot = computeConnectedRoadsPivot(roads);
      roadGroupProxy.position.copy(pivot);
      roadGroupProxy.quaternion.identity();
      roadGroupProxy.scale.set(1, 1, 1);
      roadGroupProxy.updateMatrixWorld(true);

      const start = new Map();
      for (const r of roads) {
        start.set(r, {
          position: r.position.clone(),
          rotation: r.rotation.clone(),
          quaternion: r.quaternion.clone(),
          scale: r.scale.clone()
        });
      }
      connectedTransform = {
        roads,
        start,
        pivot,
        startProxy: {
          position: roadGroupProxy.position.clone(),
          quaternion: roadGroupProxy.quaternion.clone(),
          scale: roadGroupProxy.scale.clone()
        }
      };
    }
    activeBefore = null;
    gizmoLastSafePos = null;
    if (currentTool === "rotate") {
      rotateAxis = transform.axis || null;
      showAngleReadout("0.0Ã‚Â°");
    }
    return;
  }

  activeBefore = cloneTRS(targetObj);

  if (currentTool === "move") gizmoLastSafePos = targetObj.position.clone();
  else gizmoLastSafePos = null;

  if (currentTool === "rotate" && selected) {
    rotateAxis = transform.axis || null;
    rotateStartProxyQuat.copy(rotateProxy.quaternion);
    rotateStartSelQuat.copy(selected.quaternion);
    rotateStartSelPos.copy(selected.position);
    rotateCenterWorld.copy(getWorldBBoxCenter(selected));
    showAngleReadout("0.0Ã‚Â°");
  }
});

transform.addEventListener("objectChange", () => {
  if (connectedTransform && transform.object === roadGroupProxy) {
    const info = applyConnectedTransformFromProxy();
    if (currentTool === "rotate" && info?.deltaQuat) {
      const eul = new THREE.Euler().setFromQuaternion(info.deltaQuat, "XYZ");
      let deg = 0;
      if (rotateAxis === "X") deg = THREE.MathUtils.radToDeg(eul.x);
      else if (rotateAxis === "Y") deg = THREE.MathUtils.radToDeg(eul.y);
      else if (rotateAxis === "Z") deg = THREE.MathUtils.radToDeg(eul.z);
      else {
        const ang = 2 * Math.acos(Math.max(-1, Math.min(1, info.deltaQuat.w)));
        deg = THREE.MathUtils.radToDeg(ang);
      }
      showAngleReadout(`${deg.toFixed(1)}Ã‚Â°`);
    }
    return;
  }

  if (currentTool === "move") {
    const obj = transform.object;
    if (!obj) return;
    if (!gizmoLastSafePos) gizmoLastSafePos = obj.position.clone();

    if (isColliding(obj)) {
      obj.position.copy(gizmoLastSafePos);
      obj.updateMatrixWorld();
    } else {
      gizmoLastSafePos.copy(obj.position);
    }
    return;
  }

  if (currentTool === "rotate" && selected && transform.object === rotateProxy) {
    if (!isTransformDragging) return;

    const invStart = rotateStartProxyQuat.clone().invert();
    const delta = rotateProxy.quaternion.clone().multiply(invStart);

    selected.quaternion.copy(delta.clone().multiply(rotateStartSelQuat));

    const center = rotateCenterWorld.clone();
    const rel = rotateStartSelPos.clone().sub(center);
    rel.applyQuaternion(delta);
    selected.position.copy(center.add(rel));
    selected.updateMatrixWorld(true);

    const eul = new THREE.Euler().setFromQuaternion(delta, "XYZ");
    let deg = 0;
    if (rotateAxis === "X") deg = THREE.MathUtils.radToDeg(eul.x);
    else if (rotateAxis === "Y") deg = THREE.MathUtils.radToDeg(eul.y);
    else if (rotateAxis === "Z") deg = THREE.MathUtils.radToDeg(eul.z);
    else {
      const ang = 2 * Math.acos(Math.max(-1, Math.min(1, delta.w)));
      deg = THREE.MathUtils.radToDeg(ang);
    }
    showAngleReadout(`${deg.toFixed(1)}Ã‚Â°`);
  }
});

transform.addEventListener("mouseUp", () => {
  if (connectedTransform && transform.object === roadGroupProxy) {
    const items = [];
    for (const r of connectedTransform.roads) {
      const s = connectedTransform.start.get(r);
      if (!s) continue;
      items.push({
        obj: r,
        before: { position: s.position.clone(), rotation: s.rotation.clone(), scale: s.scale.clone() },
        after: cloneTRS(r)
      });
    }
    if (items.length) {
      pushUndo({ type: "transform_group", items });
      redoStack = [];
      setStatus("Changed (undo available)");
    }
    connectedTransform = null;
    isGizmoPointerDown = false;
    hideAngleReadout();
    return;
  }

  const targetObj = historyTargetObject();
  if (!targetObj || !activeBefore) return;

  const after = cloneTRS(targetObj);
  const before = activeBefore;
  activeBefore = null;
  gizmoLastSafePos = null;

  pushUndo({ type: "transform", obj: targetObj, before, after });
  redoStack = [];
  setStatus("Changed (undo available)");
  isGizmoPointerDown = false;

  if (currentTool === "rotate" && selected) updateRotateProxyAtSelection();
});

function doUndo() {
  const cmd = undoStack.pop();
  if (!cmd) return;

  const applyOneUndo = (c) => {
    if (!c) return;
    if (c.type === "transform") applyTRS(c.obj, c.before);
    else if (c.type === "transform_group") {
      for (const it of (c.items || [])) {
        applyTRS(it.obj, it.before);
      }
    }
    else if (c.type === "road_edit") applyRoadEdit(c.obj, c.beforePoints, c.beforeWidth, c.beforeIds, c.beforeMeta);
    else if (c.type === "add") removeObject(c.obj);
    else if (c.type === "remove") insertObject(c.parent, c.obj);
    else if (c.type === "batch") {
      const list = Array.isArray(c.commands) ? c.commands : [];
      for (let i = list.length - 1; i >= 0; i--) applyOneUndo(list[i]);
    }
  };
  applyOneUndo(cmd);

  redoStack.push(cmd);
  scheduleDirtyRefresh();
  if (commandTouchesBaseSceneGraph(cmd)) {
    refreshBaseModelDerivedData();
  }
  if (currentTool === "road" && selected && isRoadObject(selected)) {
    buildRoadHandlesFor(selected);
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }
  setStatus("Undo");
}
function doRedo() {
  const cmd = redoStack.pop();
  if (!cmd) return;

  const applyOneRedo = (c) => {
    if (!c) return;
    if (c.type === "transform") applyTRS(c.obj, c.after);
    else if (c.type === "transform_group") {
      for (const it of (c.items || [])) {
        applyTRS(it.obj, it.after);
      }
    }
    else if (c.type === "road_edit") applyRoadEdit(c.obj, c.afterPoints, c.afterWidth, c.afterIds, c.afterMeta);
    else if (c.type === "add") insertObject(c.parent, c.obj);
    else if (c.type === "remove") removeObject(c.obj);
    else if (c.type === "batch") {
      for (const sub of (c.commands || [])) applyOneRedo(sub);
    }
  };
  applyOneRedo(cmd);

  undoStack.push(cmd);
  scheduleDirtyRefresh();
  if (commandTouchesBaseSceneGraph(cmd)) {
    refreshBaseModelDerivedData();
  }
  if (currentTool === "road" && selected && isRoadObject(selected)) {
    buildRoadHandlesFor(selected);
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }
  setStatus("Redo");
}

function canDeleteObject(obj) {
  if (!obj) return { ok: false, message: "Select an object first", isBaseObject: false };
  if (obj.userData?.locked) return { ok: false, message: "Locked - press Unlock to delete", isBaseObject: false };
  if (obj.userData?.isPlaced) return { ok: true, message: "", isBaseObject: false };
  if (ALLOW_EDIT_BASE_MODEL === true) return { ok: true, message: "", isBaseObject: true };
  return { ok: false, message: "Delete only removes placed overlay objects", isBaseObject: false };
}

function refreshBaseModelDerivedData() {
  if (!campusRoot) return;
  rebuildBaseColliders();
  refreshBuildingList();
  rebuildRoadSnapBuildingCache();
  rebuildRoadGroundTargets();
}

function commandTouchesBaseSceneGraph(cmd) {
  if (!cmd || typeof cmd !== "object") return false;
  if (cmd.type === "batch") {
    return Array.isArray(cmd.commands) && cmd.commands.some((sub) => commandTouchesBaseSceneGraph(sub));
  }
  if (cmd.type === "add" || cmd.type === "remove") {
    return !!cmd.obj && !cmd.obj.userData?.isPlaced;
  }
  return false;
}

function performDelete(obj) {
  const rule = canDeleteObject(obj);
  if (!rule.ok) return setStatus(rule.message);

  const parent = obj.parent;

  clearSelected(obj);
  transform.detach();
  transformHelper.visible = false;
  selected = null;
  hideAngleReadout();
  updateCommitButtons();
  clearRoadHandles();
  roadEditRoot.visible = false;
  updateRoadControls();

  pushUndo({ type: "remove", obj, parent });
  redoStack = [];

  parent?.remove?.(obj);
  if (rule.isBaseObject) {
    refreshBaseModelDerivedData();
    setStatus("Base object deleted (undo available). Export GLB to persist.");
  } else {
    setStatus("Deleted (undo available)");
  }
}

function applyRoadEdit(obj, points, width, pointIds = null, pointMeta = null) {
  if (!obj || !isRoadObject(obj)) return;
  if (!obj.userData.road) obj.userData.road = {};
  obj.userData.road.points = clonePointsArray(Array.isArray(points) ? points : []);
  if (width != null) obj.userData.road.width = Number(width || ROAD_DEFAULT_WIDTH);
  if (Array.isArray(pointIds)) obj.userData.road.pointIds = pointIds.slice();
  if (Array.isArray(pointMeta)) obj.userData.road.pointMeta = cloneRoadPointMeta(pointMeta);
  ensureRoadPointMetaArrays(obj);
  rebuildRoadObject(obj);
  if (currentTool === "road" && selected && isRoadObject(selected)) {
    buildRoadHandlesFor(selected);
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }
  updateRoadControls();
}

btnUndo?.addEventListener("click", doUndo);
btnRedo?.addEventListener("click", doRedo);

// -------------------- Placed metadata --------------------
function markPlaced(obj, meta) {
  obj.userData.isPlaced = true;
  obj.userData.type = meta.type || "asset";
  obj.userData.asset = meta.asset;
  obj.userData.id = meta.id;
  obj.userData.locked = !!meta.locked;
  obj.userData.nameLabel = meta.name || "item";
}

// -------------------- Hover & Selection --------------------
let hovered = null;
let selected = null;
updateCommitButtons();

const hoverOriginal = new Map();
const selectOriginal = new Map();
const selectTransparencyBackup = new Map();

function ensureHighlightMaterials(map, mesh) {
  if (map.has(mesh.uuid)) return;
  map.set(mesh.uuid, { material: mesh.material });

  const mat = mesh.material;
  if (Array.isArray(mat)) {
    mesh.material = mat.map(m => (m && m.clone ? m.clone() : m));
  } else if (mat && mat.clone) {
    mesh.material = mat.clone();
  }
}
function getMaterialsArray(material) {
  if (!material) return [];
  return Array.isArray(material) ? material : [material];
}
function disposeReplacedMaterials(currentMaterial, originalMaterial) {
  const originals = new Set(getMaterialsArray(originalMaterial));
  const current = getMaterialsArray(currentMaterial);
  current.forEach((m) => {
    if (!m || originals.has(m) || !m.dispose) return;
    m.dispose();
  });
}
function applyTint(mesh, type) {
  const mats = getMaterialsArray(mesh.material);
  const isHover = type === "hover";
  mats.forEach((mat) => {
    if (!mat) return;
    if (mat.emissive) mat.emissive.setHex(isHover ? 0x3344ff : 0x22c55e);
    else if (mat.color) {
      if (isHover) mat.color.offsetHSL(0, 0, 0.15);
      else mat.color.offsetHSL(0.3, 0, 0.1);
    }
    mat.needsUpdate = true;
  });
}
function restoreOriginal(map, mesh) {
  const saved = map.get(mesh.uuid);
  if (!saved) return;
  const current = mesh.material;
  mesh.material = saved.material;
  disposeReplacedMaterials(current, saved.material);
  map.delete(mesh.uuid);
}
function releaseHighlightStateForObject(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    restoreOriginal(hoverOriginal, n);
    restoreOriginal(selectOriginal, n);
    selectTransparencyBackup.delete(n.uuid);
  });
}
function removeAndDisposeObject(obj) {
  if (!obj) return;
  releaseHighlightStateForObject(obj);
  if (obj.parent) obj.parent.remove(obj);
  disposeObject3D(obj);
}
function isMeshInsideObject(mesh, obj) {
  if (!mesh || !obj) return false;
  let p = mesh;
  while (p) {
    if (p === obj) return true;
    p = p.parent;
  }
  return false;
}
function applyHover(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    if (selected && isMeshInsideObject(n, selected)) return;
    ensureHighlightMaterials(hoverOriginal, n);
    applyTint(n, "hover");
  });
}
function clearHover(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    restoreOriginal(hoverOriginal, n);
  });
}

function applySelectedTransparency(obj, opacity = 0.55) {
  selectTransparencyBackup.clear();
  obj.traverse((n) => {
    if (!n.isMesh) return;
    const mats = getMaterialsArray(n.material);
    if (!mats.length) return;

    if (!selectTransparencyBackup.has(n.uuid)) {
      selectTransparencyBackup.set(n.uuid, mats.map(m => ({
        m,
        transparent: m.transparent,
        opacity: m.opacity
      })));
    }

    for (const m of mats) {
      m.transparent = true;
      const baseOp = (typeof m.opacity === "number") ? m.opacity : 1;
      m.opacity = Math.min(baseOp, opacity);
      m.needsUpdate = true;
    }
  });
}
function restoreSelectedTransparency(obj) {
  obj.traverse((n) => {
    if (!n.isMesh) return;
    const backup = selectTransparencyBackup.get(n.uuid);
    if (!backup) return;
    for (const b of backup) {
      b.m.transparent = b.transparent;
      b.m.opacity = b.opacity;
      b.m.needsUpdate = true;
    }
  });
  selectTransparencyBackup.clear();
}

function applySelected(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    ensureHighlightMaterials(selectOriginal, n);
    applyTint(n, "selected");
  });
  applySelectedTransparency(obj, 0.55);
}
function clearSelected(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    restoreOriginal(selectOriginal, n);
  });
  restoreSelectedTransparency(obj);
}

// -------------------- Collision helpers --------------------
function rebuildBaseColliders() {
  baseColliderMeshes.length = 0;
  if (!campusRoot || !COLLIDE_WITH_BASE_MODEL) return;
  const tmpBox = new THREE.Box3();
  const tmpSize = new THREE.Vector3();

  campusRoot.traverse((n) => {
    if (!n.isMesh) return;
    tmpBox.setFromObject(n);
    if (tmpBox.isEmpty()) return;
    tmpBox.getSize(tmpSize);
    if (tmpSize.y < MIN_BASE_COLLIDER_HEIGHT) return;
    baseColliderMeshes.push(n);
  });
}
function getOverlap(a, b) {
  return {
    x: Math.min(a.max.x, b.max.x) - Math.max(a.min.x, b.min.x),
    y: Math.min(a.max.y, b.max.y) - Math.max(a.min.y, b.min.y),
    z: Math.min(a.max.z, b.max.z) - Math.max(a.min.z, b.min.z),
  };
}
function boxesOverlap(a, b, eps, minYOverlap) {
  const o = getOverlap(a, b);
  if (o.x <= eps) return false;
  if (o.z <= eps) return false;
  if (o.y <= minYOverlap) return false;
  return true;
}
function isColliding(obj) {
  if (!obj) return false;
  if (obj.userData?.type === "road") return false;

  _boxA.setFromObject(obj);
  if (_boxA.isEmpty()) return false;

  for (const other of overlayRoot.children) {
    if (!other || other === obj) continue;
    if (!other.visible) continue;
    if (other.userData?.type === "road") continue;
    _boxB.setFromObject(other);
    if (boxesOverlap(_boxA, _boxB, COLLISION_EPS, COLLISION_EPS)) return true;
  }

  if (COLLIDE_WITH_BASE_MODEL) {
    for (const mesh of baseColliderMeshes) {
      if (!mesh || !mesh.visible) continue;
      if (isMeshInsideObject(mesh, obj)) continue;
      _boxB.setFromObject(mesh);
      if (boxesOverlap(_boxA, _boxB, COLLISION_EPS, BASE_MIN_Y_OVERLAP)) return true;
    }
  }

  return false;
}

// -------------------- Building list helpers --------------------
function isGenericNodeName(name) {
  if (!name) return true;
  const raw = String(name).trim().toLowerCase();
  if (!raw) return true;
  if (
    raw === "scene" ||
    raw === "auxscene" ||
    raw === "root" ||
    raw === "rootnode" ||
    raw === "gltf" ||
    raw === "model" ||
    raw === "group"
  ) {
    return true;
  }

  // Treat suffixed wrapper variants like Scene.001 / AuxScene_1 as generic too.
  const deSuffixed = raw.replace(/([._-]\d+)+$/g, "");
  return (
    deSuffixed === "scene" ||
    deSuffixed === "auxscene" ||
    deSuffixed === "root" ||
    deSuffixed === "rootnode" ||
    deSuffixed === "gltf" ||
    deSuffixed === "model" ||
    deSuffixed === "group"
  );
}

function isRenderableObject(obj) {
  return !!(obj && (obj.isMesh || obj.isLine || obj.isPoints));
}

function isEmptyGroup(obj) {
  if (!obj) return false;
  if (isRenderableObject(obj)) return false;
  return Array.isArray(obj.children) && obj.children.length > 0;
}

function updateBuildingSelected(obj) {
  if (!buildingSelectedEl) return;
  if (obj && obj.name) {
    const resolved = resolveBuildingIdentity({ buildingName: obj.name, objectName: obj.name });
    buildingSelectedEl.textContent = `Selected: ${resolved.buildingName || obj.name}`;
  }
  else buildingSelectedEl.textContent = "Selected: None";
}

function setGuideSelectionForBuilding(buildingName, roomName = "") {
  const resolved = resolveBuildingIdentity({
    buildingName,
    objectName: buildingName
  });
  const cleanBuilding = String(resolved.buildingName || buildingName || "").trim();
  if (!cleanBuilding && !resolved.buildingUid) return;
  const type = roomName ? "room" : "building";
  guideSelectionKey = buildGuideKey(type, {
    buildingName: cleanBuilding,
    buildingUid: resolved.buildingUid,
    roomName
  });
  renderGuideEditorPanel();
}

function refreshBuildingList() {
  if (!buildingListEl) return;
  buildingListEl.innerHTML = "";

  if (!campusRoot) {
    buildingListEl.innerHTML = "<div style=\"color:#6b7280;font-weight:700;\">No model loaded</div>";
    return;
  }

  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;
    const root = getTopLevelNamedAncestor(obj);
    if (!root || !root.name) return;
    if (byName.has(root.name)) return;
    byName.set(root.name, root);
  });

  const filter = (buildingFilterEl?.value || "").trim().toLowerCase();
  const items = Array.from(byName.entries())
    .map(([name, obj]) => {
      const resolved = resolveBuildingIdentity({ buildingName: name, objectName: name });
      return {
        objectName: name,
        displayName: String(resolved.buildingName || name).trim(),
        obj
      };
    })
    .filter(({ objectName, displayName }) => {
      const haystack = `${displayName} ${objectName}`.toLowerCase();
      return !filter || haystack.includes(filter);
    })
    .sort((a, b) => a.displayName.localeCompare(b.displayName) || a.objectName.localeCompare(b.objectName));

  if (!items.length) {
    buildingListEl.innerHTML = "<div style=\"color:#6b7280;font-weight:700;\">No matches</div>";
    return;
  }

  for (const item of items) {
    const name = item.objectName;
    const obj = item.obj;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = item.displayName || name;
    btn.title = item.displayName && item.displayName !== name
      ? `${item.displayName} (${name})`
      : (item.displayName || name);
    btn.style.padding = "8px 10px";
    btn.style.borderRadius = "10px";
    btn.style.border = "1px solid #e5e7eb";
    btn.style.background = "#fff";
    btn.style.fontWeight = "800";
    btn.style.textAlign = "left";
    btn.style.cursor = "pointer";

    btn.addEventListener("click", () => {
      selectObject(obj);
      setGuideSelectionForBuilding(name);
    });

    buildingListEl.appendChild(btn);
  }
}

buildingFilterEl?.addEventListener("input", () => {
  refreshBuildingList();
});
setupBuildingFilterKeyboard();

// -------------------- Picking (overlay + base) --------------------
function pickObject(event) {
  if (!campusRoot) return null;

  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const hits = raycaster.intersectObjects([overlayRoot, campusRoot], true);
  if (!hits.length) return null;

  const isGroundHit = (obj) => {
    if (!obj) return true;
    if (isGroundLikeName(obj.name)) return true;
    if (isGroundObjectOrChild(obj)) return true;
    return false;
  };

  // 1) Overlay objects first (roads/assets) Ã¢â‚¬â€ ignore ground meshes.
  for (const h of hits) {
    let obj = h.object;
    if (isGroundHit(obj)) continue;
    while (obj) {
      if (obj.userData?.isPlaced) return obj;
      if (obj.parent === overlayRoot) return obj;
      if (obj.parent === campusRoot) break;
      obj = obj.parent;
    }
  }

  // 2) Base model objects (buildings), excluding ground.
  for (const h of hits) {
    let obj = h.object;
    if (isGroundHit(obj)) continue;
    const namedRoot = getTopLevelNamedAncestor(obj);
    if (namedRoot && namedRoot !== campusRoot) return namedRoot;
    while (obj) {
      if (obj.parent === campusRoot) {
        // Never promote generic wrapper nodes (Scene/AuxScene/etc.) as selectable objects.
        if (!isGenericNodeName(obj.name) && !isGroundLikeName(obj.name)) return obj;
        break;
      }
      obj = obj.parent;
    }
  }

  // 3) Ground fallback: keep ground selectable, but never escalate to wrapper/root nodes.
  // Return the concrete ground hit object so only the ground surface is highlighted.
  for (const h of hits) {
    let obj = h.object;
    if (!isGroundHit(obj)) continue;
    if (obj && (obj.isMesh || obj.isLine || obj.isPoints)) return obj;

    // If the hit is not directly renderable, pick the nearest ground-like ancestor.
    let p = obj;
    while (p && p !== campusRoot) {
      if (isGroundLikeName(p.name) && !isGenericNodeName(p.name)) return p;
      p = p.parent;
    }
  }

  return null;
}

function pickRoadObject(event) {
  if (!overlayRoot) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const hits = raycaster.intersectObjects([overlayRoot], true);
  if (!hits.length) return null;

  for (const h of hits) {
    let obj = h.object;
    while (obj) {
      if (isRoadObject(obj)) return obj;
      if (obj.parent === overlayRoot) break;
      obj = obj.parent;
    }
  }
  return null;
}

// -------------------- Selection / edit rules --------------------
 function canEditObject(obj) {
   if (!obj) return false;
   if (obj.userData?.locked) return false;
   if (obj.userData?.isPlaced) return true;
   return ALLOW_EDIT_BASE_MODEL === true;
 }
function isTransformToolActive() {
  return currentTool === "move" || currentTool === "rotate" || currentTool === "scale";
}

// IMPORTANT: show/hide helper, not transform
function syncTransformToSelection() {
  if (!selected || !canEditObject(selected) || !isTransformToolActive()) {
    transform.detach();
    transformHelper.visible = false;
    hideAngleReadout();
    return;
  }

  transformHelper.visible = true;

  const connected = getConnectedRoadsForTransform();
  if (connected.length) {
    const pivot = computeConnectedRoadsPivot(connected);
    roadGroupProxy.position.copy(pivot);
    roadGroupProxy.quaternion.identity();
    roadGroupProxy.scale.set(1, 1, 1);
    roadGroupProxy.updateMatrixWorld(true);

    transform.detach();
    transform.attach(roadGroupProxy);

    if (currentTool === "move") {
      transform.setMode("translate");
    }
    if (currentTool === "scale") {
      transform.setMode("scale");
    }
    if (currentTool === "rotate") {
      transform.setMode("rotate");
      transform.setSpace("local");
    }

    updateGizmoSize();
    forceGizmoAlwaysVisible();
    return;
  }

  if (currentTool === "move") {
    transform.detach();
    transform.attach(selected);
    transform.setMode("translate");
  }

  if (currentTool === "scale") {
    transform.detach();
    transform.attach(selected);
    transform.setMode("scale");
  }

  if (currentTool === "rotate") {
    updateRotateProxyAtSelection();
    transform.detach();
    transform.attach(rotateProxy);
    transform.setMode("rotate");
    transform.setSpace("local");
  }

  updateGizmoSize();
  forceGizmoAlwaysVisible();
}

 function selectObject(obj) {
   if (selected) clearSelected(selected);

   // Clear road point/extend selection when changing selection
   clearRoadPointSelection();
   hideRoadHoverPreview();

   selected = obj || null;
   transform.detach();

   if (!selected) {
     transformHelper.visible = false;
     hideAngleReadout();
     updateBuildingSelected(null);
     clearRouteLine();
     setRouteBanner("");
     clearRoadHandles();
     roadEditRoot.visible = false;
     setStatus("No selection");
     updateCommitButtons();
     updateRoadControls();
     return;
   }

  if (hovered === selected) {
    clearHover(hovered);
    hovered = null;
  }

   applySelected(selected);

   syncTransformToSelection();
 
   const isPlaced = !!selected.userData?.isPlaced;
   const isLocked = !!selected.userData?.locked;
   const isRoad = isRoadObject(selected);
   if (isRoad) rebuildRoadObject(selected);
   
   if (isLocked) {
      if (isRoad) setStatus("Selected road (locked) Ã¢â‚¬â€ press Unlock to edit");
      else setStatus(isPlaced
        ? "Selected overlay (locked) Ã¢â‚¬â€ press Unlock to edit"
       : "Selected base object (locked) Ã¢â‚¬â€ press Unlock to edit");
    } else if (canEditObject(selected)) {
      if (isRoad && currentTool === "road") {
       setStatus("Selected road (editable) Ã¢â‚¬â€ drag a road point to draw a segment (Draw) or reposition it (Move)");
      } else if (isTransformToolActive()) {
        setStatus(isRoad
          ? "Selected road (editable)"
          : (isPlaced
            ? "Selected overlay (editable)"
            : withBaseEditExportHint("Selected base object (editable)", selected)));
      } else {
       setStatus(isRoad
         ? "Selected road (press Road tool to edit points)"
          : (isPlaced
            ? "Selected overlay (click Move/Rotate/Scale to edit)"
            : withBaseEditExportHint("Selected base object (click Move/Rotate/Scale to edit)", selected)));
     }
   } else {
     setStatus(isRoad ? "Selected road (view only)" : (isPlaced ? "Selected overlay (view only)" : "Selected base object (view only)"));
   }

   updateBuildingSelected(selected);
   updateCommitButtons();

   if (!selected.userData?.isPlaced && !isGroundLikeName(selected.name) && !isGroundObjectOrChild(selected)) {
     const routeRoot = getBuildingRootFromObject(selected);
     const routeName = routeRoot?.name && routeRoot.name !== "overlayRoot" ? routeRoot.name : null;
     if (routeName) {
       routeToBuilding(routeName);
     }
   }

   if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
   else {
     clearRoadHandles();
     roadEditRoot.visible = false;
   }

   updateRoadControls();
 }

function commitSelected() {
  if (!selected) return setStatus("Select an object first");
  if (selected.userData?.locked) return setStatus("Already locked");
 
 selected.userData.locked = true;
 
  // Prevent Undo/Redo from modifying a locked object
  undoStack = undoStack.filter(cmd => {
    if (cmd?.obj === selected) return false;
    if (cmd?.type === "transform_group" && Array.isArray(cmd.items)) {
      return !cmd.items.some(it => it?.obj === selected);
    }
    return true;
  });
  redoStack = redoStack.filter(cmd => {
    if (cmd?.obj === selected) return false;
    if (cmd?.type === "transform_group" && Array.isArray(cmd.items)) {
      return !cmd.items.some(it => it?.obj === selected);
    }
    return true;
  });
 
  syncTransformToSelection();
  updateCommitButtons();
  if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
  updateRoadControls();
  persistLockStateChangeFor(selected);
  scheduleDirtyRefresh();
  setStatus("Locked");
}

function persistLockStateChangeFor(obj) {
  if (!obj?.userData?.isPlaced) return;
  scheduleDirtyRefresh();
}

 function uncommitSelected() {
   if (!selected) return setStatus("Select an object first");
   if (!selected.userData?.locked) return setStatus("Already unlocked");
 
   selected.userData.locked = false;
   syncTransformToSelection();
   updateCommitButtons();
   if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
   updateRoadControls();
   persistLockStateChangeFor(selected);
   scheduleDirtyRefresh();
   setStatus("Unlocked");
  }
 
 btnCommit?.addEventListener("click", commitSelected);
 btnEdit?.addEventListener("click", uncommitSelected);

// -------------------- Tools --------------------
toolMove?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  roadKioskPlaceMode = false;
  currentTool = "move";
  transform.setMode("translate");
  setActiveToolButton(toolMove);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Move tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Move tool active (selection is locked Ã¢â‚¬â€ press Unlock)"
      : "Move tool active (selection is view only)");
  }
  setStatus(withBaseEditExportHint("Move tool active", selected));
});

toolRotate?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  roadKioskPlaceMode = false;
  currentTool = "rotate";

  transform.setMode("rotate");
  transform.setSpace("local");

  setActiveToolButton(toolRotate);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Rotate tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Rotate tool active (selection is locked Ã¢â‚¬â€ press Unlock)"
      : "Rotate tool active (selection is view only)");
  }
  setStatus(withBaseEditExportHint("Rotate tool active", selected));
});

toolScale?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  roadKioskPlaceMode = false;
  currentTool = "scale";

  transform.setMode("scale");
  setActiveToolButton(toolScale);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Scale tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Scale tool active (selection is locked Ã¢â‚¬â€ press Unlock)"
      : "Scale tool active (selection is view only)");
  }
  setStatus(withBaseEditExportHint("Scale tool active", selected));
});

toolRoad?.addEventListener("click", () => {
  currentTool = "road";
  setActiveToolButton(toolRoad);
  syncTransformToSelection();
  updateToolIndicator();

  if (selected && !isRoadObject(selected)) {
    selectObject(null);
  }

  // Leaving asset placement mode when switching to Road tool
  if (pendingAssetPath) {
    pendingAssetPath = null;
    clearPreview();
  }

  clearRoadDraft(); // legacy draft mode off by default (node/edge workflow places immediately)

  if (selected && isRoadObject(selected)) {
    if (canEditObject(selected)) {
      buildRoadHandlesFor(selected);
      syncRoadHandlesToRoad(selected);
      setStatus(roadDragMode === "draw"
        ? "Road tool active Ã¢â‚¬â€ drag a road point to draw a segment (Snap On=90Ã‚Â°, Off=5Ã‚Â°)"
        : "Road tool active Ã¢â‚¬â€ drag a road point to move it (switch Drag mode to Draw to create segments)");
    } else {
      clearRoadHandles();
      roadEditRoot.visible = false;
      setStatus("Road tool active Ã¢â‚¬â€ selected road is locked (press Unlock to edit)");
    }
  } else {
    clearRoadHandles();
    roadEditRoot.visible = false;
    setStatus("Road tool active Ã¢â‚¬â€ tap the map to place a road point");
  }
});

toolCancel?.addEventListener("click", () => {
  clearActiveTool(true);
});

roadFinishBtn?.addEventListener("click", () => {
  finishRoadDraft();
});
roadCancelBtn?.addEventListener("click", () => {
  clearRoadDraft();
  setStatus("Road draft canceled");
});
roadNewBtn?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  roadKioskPlaceMode = false;
  if (pendingAssetPath) {
    pendingAssetPath = null;
    clearPreview();
  }
  selectObject(null);
  setStatus("Road: tap the map to place the first point");
});
roadSnapBtn?.addEventListener("click", () => {
  roadSnapEnabled = !roadSnapEnabled;
  updateRoadControls();
  setStatus(roadSnapEnabled ? "Snap: On (90Ã‚Â° cardinal)" : `Snap: Off (${ROAD_SNAP_FINE_DEG}Ã‚Â° increments)`);
});
roadBuildingSnapBtn?.addEventListener("click", () => {
  roadBuildingSnapEnabled = !roadBuildingSnapEnabled;
  updateRoadControls();
  setStatus(roadBuildingSnapEnabled
    ? "Attach enabled Ã¢â‚¬â€ drag a road point to snap to a building"
    : "Attach disabled Ã¢â‚¬â€ free layout mode");
});
roadAutoIntersectBtn?.addEventListener("click", () => {
  roadAutoIntersectEnabled = !roadAutoIntersectEnabled;
  updateRoadControls();
  setStatus(roadAutoIntersectEnabled
    ? "Auto-Intersect enabled - endpoints can merge"
    : "Auto-Intersect disabled");
});
roadDragModeBtn?.addEventListener("click", () => {
  roadDragMode = (roadDragMode === "draw") ? "move" : "draw";
  updateRoadControls();
  setStatus(roadDragMode === "draw"
    ? "Road drag mode: Draw (drag a point to create a segment)"
    : "Road drag mode: Move (drag a point to reposition)");
});

roadKioskBtn?.addEventListener("click", () => {
  if (currentTool !== "road") {
    toolRoad?.click();
  }
  roadKioskPlaceMode = !roadKioskPlaceMode;
  updateRoadControls();
  setStatus(roadKioskPlaceMode
    ? "Kiosk Start: click the map to place the start point"
    : "Kiosk Start canceled");
});

roadWidthEl?.addEventListener("input", () => {
  setRoadWidthReadout(roadWidthEl.value);
  if (roadDraft) {
    roadDraft.width = Number(roadWidthEl.value || ROAD_DEFAULT_WIDTH);
    roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  }
  updateRoadControls();
});
roadWidthEl?.addEventListener("change", () => {
  const width = Number(roadWidthEl.value || ROAD_DEFAULT_WIDTH);
  if (selected && isRoadObject(selected) && canEditObject(selected)) {
    const beforeWidth = Number(selected.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    if (Math.abs(beforeWidth - width) > 1e-6) {
      ensureRoadPointMetaArrays(selected);
      const beforePoints = clonePointsArray(selected.userData?.road?.points || []);
      const beforeIds = Array.isArray(selected.userData?.road?.pointIds) ? selected.userData.road.pointIds.slice() : [];
      const beforeMeta = cloneRoadPointMeta(selected.userData?.road?.pointMeta || []);
      selected.userData.road.width = width;
      rebuildRoadObject(selected);
      syncRoadHandlesToRoad(selected);
      const afterPoints = clonePointsArray(selected.userData?.road?.points || []);
      const afterIds = Array.isArray(selected.userData?.road?.pointIds) ? selected.userData.road.pointIds.slice() : [];
      const afterMeta = cloneRoadPointMeta(selected.userData?.road?.pointMeta || []);
      pushUndo({
        type: "road_edit",
        obj: selected,
        beforePoints,
        afterPoints,
        beforeWidth,
        afterWidth: width,
        beforeIds,
        afterIds,
        beforeMeta,
        afterMeta,
      });
      redoStack = [];
      setStatus("Road width changed (undo available)");
    }
  }
  updateRoadControls();
});

toolDelete?.addEventListener("click", () => {
  const rule = canDeleteObject(selected);
  if (!rule.ok) return setStatus(rule.message);

  const opened = showDeleteConfirm(selected);
  if (!opened) {
    performDelete(selected);
  }
});

// -------------------- Asset palette --------------------
let pendingAssetPath = null;
let lastPointerEvent = null;

// Placement preview
let previewRoot = null;
let previewBox = null;
let previewBoxHelper = null;
let previewYOffset = 0;
let previewAssetPath = null;
let previewLoadToken = 0;
let previewLoadingPath = null;

function clearPreview() {
  if (previewBoxHelper) {
    scene.remove(previewBoxHelper);
    previewBoxHelper = null;
    previewBox = null;
  }
  if (previewRoot) {
    previewRoot.traverse((n) => {
      if (n.isMesh && n.material && n.material.dispose) {
        n.material.dispose();
      }
    });
    scene.remove(previewRoot);
    previewRoot = null;
  }
  previewAssetPath = null;
  previewYOffset = 0;
  previewLoadingPath = null;
}

function buildPreviewFor(assetPath) {
  if (!assetPath) return;
  if (previewAssetPath === assetPath && previewRoot) return;
  if (previewLoadingPath === assetPath) return;

  const token = ++previewLoadToken;
  clearPreview();
  previewLoadingPath = assetPath;

  loader.load(
    assetPath,
    (gltf) => {
      if (token !== previewLoadToken) return;
      previewLoadingPath = null;
      if (pendingAssetPath !== assetPath) return;

      const root = gltf.scene;

      root.traverse((n) => {
        if (!n.isMesh) return;
        const mat = new THREE.MeshBasicMaterial({
          color: 0x2563eb,
          wireframe: true,
          transparent: true,
          opacity: 0.35,
          depthTest: false
        });
        mat.depthWrite = false;
        n.material = mat;
        n.renderOrder = 10;
      });

      root.updateMatrixWorld(true);

      const box = new THREE.Box3().setFromObject(root);
      previewYOffset = -box.min.y;
      root.position.set(0, previewYOffset, 0);

      scene.add(root);
      previewRoot = root;
      previewAssetPath = assetPath;

      previewBox = new THREE.Box3();
      previewBoxHelper = new THREE.Box3Helper(previewBox, 0x2563eb);
      previewBoxHelper.material.transparent = true;
      previewBoxHelper.material.opacity = 0.4;
      previewBoxHelper.material.depthTest = false;
      previewBoxHelper.renderOrder = 11;
      scene.add(previewBoxHelper);

      if (lastPointerEvent) updatePreviewPosition(lastPointerEvent);
    },
    undefined,
    (err) => {
      if (token !== previewLoadToken) return;
      previewLoadingPath = null;
      console.error(err);
    }
  );
}

function updatePreviewPosition(event) {
  if (!previewRoot || !event) return;
  const pt = getGroundPointFromEvent(event);
  if (!pt) return;

  previewRoot.position.copy(pt);
  previewRoot.position.y += previewYOffset;
  previewRoot.updateMatrixWorld(true);

  if (previewBox && previewBoxHelper) {
    previewBox.setFromObject(previewRoot);
    previewBoxHelper.updateMatrixWorld(true);
  }
}

async function loadAssetList() {
  const res = await apiFetch("mapEditor.php?action=list_assets");
  const data = await res.json();
  if (!data.ok) throw new Error("Failed to list assets");

  assetListEl.innerHTML = "";
  data.assets.forEach(a => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = a.label;
    btn.style.padding = "10px";
    btn.style.borderRadius = "12px";
    btn.style.border = "1px solid #e5e7eb";
    btn.style.background = "#fff";
    btn.style.fontWeight = "900";
    btn.style.textAlign = "left";
    btn.style.cursor = "pointer";

    btn.addEventListener("click", () => {
      pendingAssetPath = a.path;
      buildPreviewFor(pendingAssetPath);
      setStatus(`Selected asset: ${a.label} Ã¢â‚¬â€ click on ground to place`);
    });

    assetListEl.appendChild(btn);
  });
}

async function spawnAssetAt(assetPath, point, opts = {}) {
  const {
    recordHistory = true,
    autoSelect = true,
    snapToGround = true,
    forcedId = null,
    forcedLocked = null,
    forcedName = null,
  } = opts;

  return new Promise((resolve, reject) => {
    loader.load(
      assetPath,
      (gltf) => {
        const root = gltf.scene;

        root.traverse((n) => {
          if (n.isMesh) {
            n.castShadow = false;
            n.receiveShadow = false;
          }
        });

        root.position.copy(point);

        if (snapToGround) {
          const box = new THREE.Box3().setFromObject(root);
          const minY = box.min.y;
          root.position.y -= minY;
        }

        root.updateMatrixWorld(true);

        markPlaced(root, {
          id: forcedId ?? ("obj_" + Date.now()),
          asset: assetPath,
          name: forcedName ?? assetPath.split("/").pop().replace(/\.(glb|gltf)$/i, ""),
          locked: forcedLocked ?? false
        });

        overlayRoot.add(root);

        if (recordHistory) {
          pushUndo({ type: "add", obj: root, parent: overlayRoot });
          redoStack = [];
        }

        if (autoSelect) selectObject(root);

        setStatus(recordHistory ? "Placed (undo available)" : "Loaded item");
        resolve(root);
      },
      undefined,
      (err) => reject(err)
    );
  });
}

// -------------------- Hover update --------------------
function updateHover(event) {
  lastPointerEvent = event;

  if (!pendingAssetPath && previewRoot) clearPreview();
  if (pendingAssetPath) {
    buildPreviewFor(pendingAssetPath);
    updatePreviewPosition(event);
  }

  if (isDraggingRoadSegment) return;

  if (isTransformDragging || isDraggingObject || isDraggingRoadHandle) {
    hideRoadHoverPreview();
    return;
  }

  const p = (currentTool === "road") ? pickRoadObject(event) : pickObject(event);
  const nextHover = (p && selected && p === selected) ? null : p;

  if (hovered && hovered !== nextHover) {
    clearHover(hovered);
    hovered = null;
  }

  if (nextHover && hovered !== nextHover) {
    hovered = nextHover;
    applyHover(hovered);
  }

  if (!nextHover && hovered) {
    clearHover(hovered);
    hovered = null;
  }

  updateRoadPreview(event);
}

renderer.domElement.addEventListener("pointermove", updateHover);
renderer.domElement.addEventListener("pointerleave", () => {
  if (hovered) {
    clearHover(hovered);
    hovered = null;
  }
  hideRoadHoverPreview();
});

// -------------------- Direct drag move (ONLY when Move tool is active) --------------------
let isDraggingObject = false;
let dragOffset = new THREE.Vector3();
let dragBeforeTRS = null;
let dragLastSafePos = new THREE.Vector3();
let dragConnected = null; // { roads: THREE.Object3D[], before: Map<road, TRS> }

renderer.domElement.addEventListener("pointerdown", async (e) => {
  if (e.button !== 0) return;
  if (isGizmoPointerDown) return;

  if (pendingAssetPath) {
    const pt = getGroundPointFromEvent(e);
    if (!pt) return;

    try {
      await spawnAssetAt(pendingAssetPath, pt, { recordHistory: true, autoSelect: true, snapToGround: true });
      pendingAssetPath = null;
      clearPreview();
    } catch (err) {
      showError("ASSET LOAD ERROR", err);
    }
    return;
  }

  if (currentTool === "road") {
    // Let OrbitControls handle multi-touch gestures (pan/zoom)
    if (activeTouchPointerIds.size >= 2) return;

    const h = pickRoadHandle(e);
    if (h) {
      const roadObj = getRoadById(h.roadId) || selected;
      if (roadObj && isRoadObject(roadObj)) {
        if (selected !== roadObj) selectObject(roadObj);
        if (!canEditObject(roadObj)) {
          setStatus("Road locked Ã¢â‚¬â€ press Unlock");
          e.preventDefault();
          return;
        }
        if (roadDragMode === "move") {
          if (beginRoadHandleDrag(roadObj, h.index, e.pointerId)) {
            e.preventDefault();
            return;
          }
        } else {
          if (beginRoadSegmentDrag(roadObj, h.index, e.pointerId, e.clientX, e.clientY)) {
            e.preventDefault();
            return;
          }
        }
      }
    }

    // Defer selection / placement to pointerup (helps avoid accidental taps during multi-touch gestures)
    roadTap = { pointerId: e.pointerId, startX: e.clientX, startY: e.clientY, hadMultiTouch: false };
    return;
  }

  const picked = pickObject(e);

  if (picked) {
    selectObject(picked);

    if (currentTool === "move" && selected && canEditObject(selected) && !isTransformDragging && !isGizmoPointerDown) {
      const pt = getGroundPointFromEvent(e);
      if (!pt) return;

      controls.enabled = false;
      isDraggingObject = true;

      dragBeforeTRS = cloneTRS(selected);
      dragConnected = null;
      if (selected && isRoadObject(selected)) {
        const connected = getConnectedRoadsForTransform();
        if (connected.length) {
          const before = new Map();
          for (const r of connected) {
            before.set(r, cloneTRS(r));
          }
          dragConnected = { roads: connected, before };
        }
      }
      dragOffset.copy(selected.position).sub(pt);
      dragLastSafePos.copy(selected.position);

      setStatus("DraggingÃ¢â‚¬Â¦ release to drop");
      e.preventDefault();
      return;
    }
  } else {
    selectObject(null);
  }
});

renderer.domElement.addEventListener("pointermove", (e) => {
  if (isDraggingRoadSegment && roadSegmentDrag && roadSegmentDrag.pointerId === e.pointerId) {
    updateRoadSegmentDrag(e);
    e.preventDefault();
    return;
  }

  if (isDraggingRoadHandle && roadHandleDrag && roadHandleDrag.pointerId === e.pointerId) {
    const pt = getRoadSurfacePointFromEvent(e);
    if (!pt) return;
    const roadObj = roadHandleDrag.road;
    const pointIndex = roadHandleDrag.index;
    const width = Number(roadObj?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

    let anchorW = null;
    let refY = 0;
    try {
      roadObj?.updateMatrixWorld?.(true);
      const beforeArr = roadHandleDrag.beforePoints?.[pointIndex];
      if (Array.isArray(beforeArr) && beforeArr.length >= 3) {
        const beforeLocal = vec3FromArray(beforeArr);
        anchorW = roadObj.localToWorld(beforeLocal.clone());
        refY = anchorW.y;
      }
    } catch (_) {}

    let x = pt.x;
    let z = pt.z;
    let snap = null;
    if (roadBuildingSnapEnabled) {
      const roadData = roadObj?.userData?.road;
      const exceptPid = Array.isArray(roadData?.pointIds) ? roadData.pointIds[pointIndex] : null;
      const liveSources = buildLiveRoadSnapSources();
      snap = snapRoadEndToBuildingSide(anchorW, x, z, width, { ...liveSources, exceptPointId: exceptPid });
      if (snap) {
        x = snap.x;
        z = snap.z;
      }
    }
    const y = sampleRoadBaseYAtXZ(x, z, Number.isFinite(refY) ? refY : 0);
    const wpt = new THREE.Vector3(x, y, z);

    setRoadPointIndexFromWorld(roadObj, pointIndex, wpt);
    if (roadHandleDrag?.pointId) {
      const sharedRefs = Array.isArray(roadHandleDrag.sharedRefs) ? roadHandleDrag.sharedRefs : [];
      for (const ref of sharedRefs) {
        if (!ref?.road || !Number.isInteger(ref.index)) continue;
        setRoadPointIndexFromWorld(ref.road, ref.index, wpt);
      }
    }

    // Auto-tag/clear a building link when a point snaps (so routing can later use it).
    if (roadBuildingSnapEnabled) {
      try {
        const roadData = roadObj?.userData?.road;
        if (roadData && Array.isArray(roadData.pointIds) && Array.isArray(roadData.pointMeta)) {
          const pid = roadData.pointIds[pointIndex];
          const existing = roadData.pointMeta[pointIndex];
          const keepKiosk = isKioskMeta(existing);
          if (snap?.buildingName && snap?.side) {
            roadData.pointMeta[pointIndex] = keepKiosk
              ? normalizeKioskMeta(existing, pid)
              : makeBuildingLinkMeta(snap, snap.side, pid);
          } else {
            if (keepKiosk) {
              roadData.pointMeta[pointIndex] = normalizeKioskMeta(existing, pid);
            } else if (existing && typeof existing === "object" && existing.building) {
              roadData.pointMeta[pointIndex] = null;
            }
          }
        }
      } catch (_) {}
    }
    e.preventDefault();
    return;
  }

  if (!isDraggingObject || !selected) return;
  const pt = getGroundPointFromEvent(e);
  if (!pt) return;

  if (dragConnected && dragConnected.roads && dragConnected.roads.length) {
    const baseBefore = dragConnected.before.get(selected) || dragBeforeTRS;
    const newPos = pt.clone().add(dragOffset);
    const delta = newPos.clone().sub(baseBefore.position);
    for (const r of dragConnected.roads) {
      const before = dragConnected.before.get(r);
      if (!before) continue;
      r.position.copy(before.position.clone().add(delta));
      r.updateMatrixWorld();
    }
    return;
  }

  selected.position.copy(pt.add(dragOffset));
  selected.updateMatrixWorld();

  if (isColliding(selected)) {
    selected.position.copy(dragLastSafePos);
    selected.updateMatrixWorld();
  } else {
    dragLastSafePos.copy(selected.position);
  }
});

window.addEventListener("pointerup", (e) => {
  if (isDraggingRoadSegment) {
    endRoadSegmentDrag(e.pointerId, e);
  }

  if (isDraggingRoadHandle) {
    endRoadHandleDrag(e.pointerId);
  }

  if (currentTool === "road" && roadTap && roadTap.pointerId === e.pointerId) {
    const tap = roadTap;
    roadTap = null;

    const TAP_SLOP_PX = 8;
    const dx = Math.abs(e.clientX - tap.startX);
    const dy = Math.abs(e.clientY - tap.startY);
    if (dx > TAP_SLOP_PX || dy > TAP_SLOP_PX) return;

    if (!tap.hadMultiTouch) {
      if (!isEventOnStage(e)) return;
      if (roadKioskPlaceMode) {
        const pt = getRoadSurfacePointFromEvent(e);
        if (pt) placeKioskStartAt(pt);
        roadKioskPlaceMode = false;
        updateRoadControls();
        hideRoadHoverPreview();
        e.preventDefault();
        return;
      }

      const picked = pickRoadObject(e);

      if (picked && isRoadObject(picked)) {
        const shouldInsertOnSelectedRoad = (
          picked === selected
          && canEditObject(picked)
          && !roadKioskPlaceMode
        );
        if (shouldInsertOnSelectedRoad) {
          const segmentHit = pickRoadSegmentHit(e, picked);
          if (segmentHit && insertRoadPointFromWorldHit(picked, segmentHit.point)) {
            hideRoadHoverPreview();
            e.preventDefault();
            return;
          }
        }
        selectObject(picked);
      } else {
        const pt = getRoadSurfacePointFromEvent(e);
        if (pt) spawnRoadNodeAt(pt);
      }
      hideRoadHoverPreview();
      e.preventDefault();
    }
  }

  if (!isDraggingObject || !selected) return;

  isDraggingObject = false;
  controls.enabled = true;

  if (dragConnected && dragConnected.roads && dragConnected.roads.length) {
    const items = [];
    for (const r of dragConnected.roads) {
      const before = dragConnected.before.get(r);
      if (!before) continue;
      items.push({ obj: r, before, after: cloneTRS(r) });
    }
    if (items.length) {
      pushUndo({ type: "transform_group", items });
      redoStack = [];
    }
    dragConnected = null;
    dragBeforeTRS = null;
    setStatus("Dropped Ã¢Å“â€œ (undo available)");
    return;
  }

  const after = cloneTRS(selected);
  const before = dragBeforeTRS;
  dragBeforeTRS = null;

  if (before) {
    pushUndo({ type: "transform", obj: selected, before, after });
    redoStack = [];
  }

  setStatus("Dropped Ã¢Å“â€œ (undo available)");
});

window.addEventListener("pointercancel", (e) => {
  if (isDraggingRoadSegment) endRoadSegmentDrag(e.pointerId, e);
  if (isDraggingRoadHandle) endRoadHandleDrag(e.pointerId);
  if (roadTap && roadTap.pointerId === e.pointerId) roadTap = null;
});

// -------------------- Save / Load overlay --------------------
function serializeOverlay() {
  const items = [];
  overlayRoot.children.forEach((obj) => {
    if (!obj.userData.isPlaced) return;

    const type = obj.userData?.type || "asset";

    if (type === "road") {
      ensureRoadPointMetaArrays(obj);
      const road = obj.userData?.road || {};
      items.push({
        type: "road",
        id: obj.userData.id,
        name: obj.userData.nameLabel,
        position: [obj.position.x, obj.position.y, obj.position.z],
        rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
        scale:    [obj.scale.x, obj.scale.y, obj.scale.z],
        locked: !!obj.userData.locked,
        width: Number(road.width || ROAD_DEFAULT_WIDTH),
        points: Array.isArray(road.points) ? road.points : [],
        pointIds: Array.isArray(road.pointIds) ? road.pointIds : [],
        pointMeta: Array.isArray(road.pointMeta)
          ? road.pointMeta.map((meta, index) => normalizeRoadPointMeta(meta, Array.isArray(road.pointIds) ? road.pointIds[index] : null))
          : []
      });
      return;
    }

    items.push({
      type: "asset",
      id: obj.userData.id,
      asset: obj.userData.asset,
      name: obj.userData.nameLabel,
      position: [obj.position.x, obj.position.y, obj.position.z],
      rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
      scale:    [obj.scale.x, obj.scale.y, obj.scale.z],
      locked: !!obj.userData.locked
    });
  });

  return { version: 2, items, routes: savedRoutes };
}

function serializeRoadnet() {
  const roads = [];
  for (const obj of (overlayRoot?.children || [])) {
    if (!isRoadObject(obj)) continue;
    ensureRoadPointMetaArrays(obj);
    const road = obj.userData?.road || {};
    roads.push({
      type: "road",
      id: obj.userData?.id || null,
      name: obj.userData?.nameLabel || "road",
      position: [obj.position.x, obj.position.y, obj.position.z],
      rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
      scale: [obj.scale.x, obj.scale.y, obj.scale.z],
      locked: !!obj.userData?.locked,
      width: Number(road.width || ROAD_DEFAULT_WIDTH),
      points: Array.isArray(road.points) ? clonePointsArray(road.points) : [],
      pointIds: Array.isArray(road.pointIds) ? road.pointIds.slice() : [],
      pointMeta: Array.isArray(road.pointMeta)
        ? cloneRoadPointMeta(road.pointMeta).map((meta, index) => normalizeRoadPointMeta(meta, Array.isArray(road.pointIds) ? road.pointIds[index] : null))
        : []
    });
  }
  return roads;
}

function buildDraftOverlayPayload() {
  const serialized = serializeOverlay();
  return {
    version: 2,
    items: (Array.isArray(serialized?.items) ? serialized.items : []).filter((item) => item && item.type !== "road")
  };
}

function buildWorkingRoutesSnapshot() {
  return hasLiveRoadData() ? computeSavedRoutes() : savedRoutes;
}

function isObjectIncludedInExportNodes(obj, nodes) {
  if (!obj || !Array.isArray(nodes) || !nodes.length) return false;
  const roots = new Set(nodes.filter(Boolean));
  let current = obj;
  while (current) {
    if (roots.has(current)) return true;
    current = current.parent || null;
  }
  return false;
}

async function withCleanExportVisualState(nodes, task) {
  const exportNodes = Array.isArray(nodes) ? nodes.filter(Boolean) : [];
  const selectedForExport = selected && isObjectIncludedInExportNodes(selected, exportNodes)
    ? selected
    : null;
  const hoveredForExport = hovered
    && hovered !== selectedForExport
    && isObjectIncludedInExportNodes(hovered, exportNodes)
      ? hovered
      : null;

  if (hoveredForExport) clearHover(hoveredForExport);
  if (selectedForExport) clearSelected(selectedForExport);

  try {
    return await task(exportNodes);
  } finally {
    if (selectedForExport && selected === selectedForExport) {
      applySelected(selectedForExport);
    }
    if (hoveredForExport && hovered === hoveredForExport && hovered !== selected) {
      applyHover(hoveredForExport);
    }
  }
}

function exportNodesToGlbBinary(nodes) {
  return withCleanExportVisualState(nodes, (exportNodes) => {
    const exporter = new GLTFExporter();
    return new Promise((resolve, reject) => {
      exporter.parse(
        exportNodes,
        (glb) => resolve(glb),
        (err) => reject(err),
        { binary: true }
      );
    });
  });
}

async function loadDraftStateForModel(modelName) {
  if (!modelName) {
    return {
      exists: false,
      model: "",
      savedAt: 0,
      hasBaseDraft: false,
      baseDraftFile: "",
      baseDraftUrl: "",
      overlay: { version: 2, items: [] },
      roads: [],
      routes: {},
      guides: { entries: {} }
    };
  }

  try {
    const res = await apiFetch(`mapEditor.php?action=load_draft_state&name=${encodeURIComponent(modelName)}`);
    const data = await res.json();
    if (!data?.ok || !data?.exists) {
      return {
        exists: false,
        model: modelName,
        savedAt: 0,
        hasBaseDraft: false,
        baseDraftFile: "",
        baseDraftUrl: "",
        overlay: { version: 2, items: [] },
        roads: [],
        routes: {},
        guides: { entries: {} }
      };
    }

    return {
      exists: true,
      model: String(data?.model || modelName || "").trim(),
      savedAt: normalizeDraftSavedAt(data?.savedAt),
      hasBaseDraft: !!data?.hasBaseDraft,
      baseDraftFile: String(data?.baseDraftFile || "").trim(),
      baseDraftUrl: String(data?.baseDraftUrl || "").trim(),
      overlay: (data?.overlay && typeof data.overlay === "object") ? data.overlay : { version: 2, items: [] },
      roads: Array.isArray(data?.roads) ? data.roads : [],
      routes: (data?.routes && typeof data.routes === "object") ? data.routes : {},
      guides: (data?.guides && typeof data.guides === "object") ? data.guides : { entries: {} }
    };
  } catch (_) {
    return {
      exists: false,
      model: modelName,
      savedAt: 0,
      hasBaseDraft: false,
      baseDraftFile: "",
      baseDraftUrl: "",
      overlay: { version: 2, items: [] },
      roads: [],
      routes: {},
      guides: { entries: {} }
    };
  }
}

async function saveDraftBaseModelForCurrentModel() {
  if (!campusRoot || !currentModelName) throw new Error("No base model loaded");
  campusRoot.updateMatrixWorld(true);
  const glb = await exportNodesToGlbBinary([...campusRoot.children]);
  const res = await apiFetch(`mapEditor.php?action=save_draft_glb&name=${encodeURIComponent(currentModelName)}`, {
    method: "POST",
    headers: { "Content-Type": "model/gltf-binary" },
    body: glb
  });
  const data = await res.json();
  if (!data?.ok) throw new Error(data?.error || "Failed to save draft base model");
  return {
    savedAt: normalizeDraftSavedAt(data?.savedAt),
    hasBaseDraft: true,
    baseDraftFile: String(data?.file || "").trim(),
    baseDraftUrl: String(data?.url || "").trim()
  };
}

async function saveCurrentDraftState() {
  if (!currentModelName) throw new Error("No current model selected");
  if (!campusRoot) throw new Error("Base model not loaded yet");

  const state = getUnsavedChangeState();
  if (!state.any) {
    if (isDraftSessionActive()) {
      setStatus("Draft already saved");
      return true;
    }
    setStatus("No draft changes to save");
    return false;
  }

  const routes = buildWorkingRoutesSnapshot();
  const roads = serializeRoadnet();
  const guides = buildGuidesPayloadForModel(currentModelName, {
    routes,
    includeOrphaned: true
  });
  let draftMeta = {
    savedAt: currentDraftSession?.savedAt || 0,
    hasBaseDraft: !!currentDraftSession?.hasBaseDraft,
    baseDraftFile: String(currentDraftSession?.baseDraftFile || "").trim(),
    baseDraftUrl: String(currentDraftSession?.baseDraftUrl || "").trim()
  };

  if (state.base) {
    setStatus("Saving draft base model...");
    draftMeta = {
      ...draftMeta,
      ...(await saveDraftBaseModelForCurrentModel())
    };
  }

  setStatus("Saving draft workspace...");
  const res = await apiFetch(`mapEditor.php?action=save_draft_state&name=${encodeURIComponent(currentModelName)}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      overlay: buildDraftOverlayPayload(),
      roads,
      routes,
      guides,
      hasBaseDraft: !!draftMeta.hasBaseDraft,
      baseDraftFile: draftMeta.baseDraftFile || ""
    })
  });
  const data = await res.json();
  if (!data?.ok) throw new Error(data?.error || "Draft save failed");

  setSavedRoutes(routes);
  setLoadedGuidesPayload({ model: currentModelName, entries: guides.entries || {} });
  renderGuideEditorPanel({ routes });
  setCurrentDraftSession({
    model: currentModelName,
    exists: true,
    active: true,
    savedAt: normalizeDraftSavedAt(data?.savedAt || draftMeta.savedAt),
    hasBaseDraft: !!data?.hasBaseDraft,
    baseDraftFile: String(data?.baseDraftFile || draftMeta.baseDraftFile || "").trim(),
    baseDraftUrl: String(data?.baseDraftUrl || draftMeta.baseDraftUrl || "").trim()
  });
  captureDirtyBaselines({ overlay: true, base: true });
  setStatus(`Draft saved (${currentModelName})`);
  return true;
}

async function saveOverlay(opts = {}) {
  const {
    silent = false,
    skipRoutingReview = false,
    routes: providedRoutes = null,
    roads: providedRoads = null
  } = opts;
  if (!isOverlaySaveAllowed()) {
    throw new Error("Save Overlay is only available for the original map.");
  }
  const routes = (providedRoutes && typeof providedRoutes === "object") ? providedRoutes : computeSavedRoutes();
  const roads = Array.isArray(providedRoads) ? providedRoads : serializeRoadnet();
  if (!skipRoutingReview) {
    const review = reviewRoutingPersist("Save", { routes, mode: "warn" });
    if (!review.proceed) {
      if (review.blocked) throw createRoutingReviewError(review, "Save");
      setStatus("Save canceled");
      return false;
    }
  }
  const payload = serializeOverlay();
  const res = await apiFetch("mapEditor.php?action=save_overlay", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || "Save failed");
  await saveRoadDataForModel(currentModelName, { roads, routes });
  captureDirtyBaselines({ overlay: true, base: false });
  if (silent) return true;
  setStatus("Saved overlay + roads");
  return true;
}

toolSave?.addEventListener("click", async () => {
  try {
    await saveCurrentDraftState();
  } catch (err) {
    showError("SAVE ERROR", err);
  }
});

function buildExportDefaultName() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  const ts = `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
  return `tnts_map_export_${ts}.glb`;
}

function exportSceneAsGlb() {
  if (!campusRoot) throw new Error("Base model not loaded yet");

  const defName = buildExportDefaultName();
  const nameInput = prompt("Save GLB as (new file):", defName);
  if (!nameInput) return Promise.resolve(false);
  const fileName = nameInput.trim();
  if (!fileName) return Promise.resolve(false);

  const routes = buildWorkingRoutesSnapshot();
  const review = reviewRoutingPersist("Export", { routes, mode: "warn" });
  if (!review.proceed) {
    if (review.blocked) return Promise.reject(createRoutingReviewError(review, "Export"));
    setStatus("Export canceled");
    return Promise.resolve(false);
  }

  campusRoot.updateMatrixWorld(true);
  overlayRoot.updateMatrixWorld(true);

  setStatus("Exporting GLB...");

  return new Promise((resolve, reject) => {
    exportNodesToGlbBinary([
      ...campusRoot.children,
      ...overlayRoot.children
    ]).then(async (glb) => {
      try {
        const res = await apiFetch(`mapEditor.php?action=export_glb&name=${encodeURIComponent(fileName)}&sourceModel=${encodeURIComponent(currentModelName || "")}`, {
          method: "POST",
          headers: { "Content-Type": "model/gltf-binary" },
          body: glb
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || "Export failed");
        await syncModelEntitiesToDatabase(data.file, campusRoot);
        const roads = serializeRoadnet();
        await saveRoadDataForModel(data.file, {
          roads,
          routes,
          includeOrphaned: false,
          updateLocalState: false
        });
        setStatus(`Exported child version: ${data.file}`);
        if (baseModelSelect) {
          await loadModelList();
          baseModelSelect.value = currentModelName;
        }
        resolve(true);
      } catch (err) {
        reject(err);
      }
    }).catch((err) => reject(err));
  });
}

toolExport?.addEventListener("click", () => {
  exportSceneAsGlb().catch(err => showError("EXPORT ERROR", err));
});

async function publishCurrentMap() {
  if (!currentModelName) throw new Error("No current model selected");

  const state = getUnsavedChangeState();
  const publishRoutes = buildWorkingRoutesSnapshot();
  if (isDraftSessionActive()) {
    const loadCommittedNow = confirm(
      `You are viewing a saved draft for ${currentModelName}.\n\nPublish only works on committed model versions.\n\nLoad the committed version now?`
    );
    if (loadCommittedNow) {
      await loadBaseModel(currentModelName, {
        loadOverlay: currentModelName === ORIGINAL_MODEL_NAME,
        forceCommitted: true
      });
      setStatus(`Committed version loaded for ${currentModelName}. Review it, then publish again.`);
      return false;
    }
    throw new Error(
      `Publish only works on committed model versions.\n\nLoad the committed version for ${currentModelName} or export the draft into a new child version first.`
    );
  }
  if (state.any) {
    throw new Error(
      `You have unsaved draft edits on ${currentModelName}.\n\nUse Save Draft to keep the workspace, then Export GLB to create a publishable child version, or reload the committed version before publishing.`
    );
  }

  const review = reviewRoutingPersist("Publish", {
    routes: publishRoutes,
    mode: "strict"
  });
  if (!review.proceed) {
    if (review.blocked) throw createRoutingReviewError(review, "Publish");
    setStatus("Publish canceled");
    return false;
  }

  await syncModelEntitiesToDatabase(currentModelName, null);

  const res = await apiFetch("mapEditor.php?action=publish_map", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ file: currentModelName })
  });
  const data = await res.json();
  if (!data?.ok) throw new Error(data?.error || "Publish failed");

  await loadModelList().catch(() => {});
  if (baseModelSelect) baseModelSelect.value = currentModelName;
  setStatus(`Published live: ${data?.published?.modelFile || currentModelName}`);
  return true;
}

toolPublish?.addEventListener("click", () => {
  publishCurrentMap().catch(err => showError("PUBLISH ERROR", err));
});

function clearRoadObjectsFromOverlay() {
  if (!overlayRoot) return;
  clearRoadHandles();
  roadEditRoot.visible = false;
  hideRoadHoverPreview();
  let clearedSelectedRoad = false;
  let clearedHoveredRoad = false;

  for (let i = overlayRoot.children.length - 1; i >= 0; i--) {
    const obj = overlayRoot.children[i];
    if (!isRoadObject(obj)) continue;
    if (selected === obj) {
      clearSelected(selected);
      selected = null;
      clearedSelectedRoad = true;
    }
    if (hovered === obj) {
      clearHover(hovered);
      hovered = null;
      clearedHoveredRoad = true;
    }
    removeAndDisposeObject(obj);
  }

  if (clearedSelectedRoad || clearedHoveredRoad) {
    transform.detach();
    transformHelper.visible = false;
    hideAngleReadout();
    updateBuildingSelected(null);
    updateCommitButtons();
  }
}

function spawnSerializedRoadObject(it) {
  if (!(it && (it.type === "road" || Array.isArray(it.points)))) return null;

  const group = new THREE.Group();
  group.name = "road";

  const width = Number(it.width || ROAD_DEFAULT_WIDTH);
  const pointsArr = Array.isArray(it.points) ? it.points : [];
  const safePoints = pointsArr.map((p) => Array.isArray(p) ? [
    Number(p[0] || 0),
    Number(p[1] || 0),
    Number(p[2] || 0),
  ] : [0, 0, 0]);

  group.userData.road = {
    width,
    points: safePoints,
    pointIds: Array.isArray(it.pointIds) ? it.pointIds.slice() : undefined,
    pointMeta: Array.isArray(it.pointMeta)
      ? it.pointMeta.map((meta, index) => normalizeRoadPointMeta(meta, Array.isArray(it.pointIds) ? it.pointIds[index] : null))
      : undefined,
  };
  ensureRoadPointMetaArrays(group);

  const ptsLocal = safePoints.map(vec3FromArray);
  ensureRoadMeshFor(group, ptsLocal, width, { preview: false });

  markPlaced(group, {
    id: it.id || ("road_" + Date.now()),
    asset: null,
    name: it.name || "road",
    locked: !!it.locked,
    type: "road"
  });

  if (Array.isArray(it.position) && it.position.length >= 3) {
    group.position.set(Number(it.position[0] || 0), Number(it.position[1] || 0), Number(it.position[2] || 0));
  }
  if (Array.isArray(it.rotation) && it.rotation.length >= 3) {
    group.rotation.set(Number(it.rotation[0] || 0), Number(it.rotation[1] || 0), Number(it.rotation[2] || 0));
  }
  if (Array.isArray(it.scale) && it.scale.length >= 3) {
    group.scale.set(Number(it.scale[0] || 1), Number(it.scale[1] || 1), Number(it.scale[2] || 1));
  }

  group.userData.locked = !!it.locked;
  overlayRoot.add(group);
  group.updateMatrixWorld(true);
  return group;
}

function toFiniteNumber(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function readVec3(value, fallback = [0, 0, 0]) {
  if (!Array.isArray(value)) return [fallback[0], fallback[1], fallback[2]];
  return [
    toFiniteNumber(value[0], fallback[0]),
    toFiniteNumber(value[1], fallback[1]),
    toFiniteNumber(value[2], fallback[2])
  ];
}

async function loadOverlay() {
  const res = await apiFetch("mapEditor.php?action=load_overlay");
  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    showError("OVERLAY LOAD ERROR", err);
    setStatus("Overlay load failed");
    return;
  }

  await applyOverlayPayload(data, {
    applyRoutes: true,
    statusMessage: "Overlay loaded"
  });
}

async function applyOverlayPayload(data = {}, opts = {}) {
  const {
    applyRoutes = true,
    statusMessage = ""
  } = opts;

  if (applyRoutes) {
    setSavedRoutes((data && typeof data.routes === "object") ? data.routes : {});
  }
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  clearRouteLine();
  setRouteBanner("");

  clearOverlayObjects();

  const items = Array.isArray(data?.items) ? data.items : [];
  for (const [idx, it] of items.entries()) {
    if (!it || typeof it !== "object") continue;

    if (it && (it.type === "road" || Array.isArray(it.points))) {
      spawnSerializedRoadObject(it);
      continue;
    }

    if (typeof it.asset !== "string" || !it.asset.trim()) {
      console.warn("Skipping overlay item with invalid asset at index", idx, it);
      continue;
    }

    const position = readVec3(it.position, [0, 0, 0]);
    const rotation = readVec3(it.rotation, [0, 0, 0]);
    const scale = readVec3(it.scale, [1, 1, 1]).map((v) => Math.max(0.0001, v));

    let obj = null;
    try {
      obj = await spawnAssetAt(
        it.asset,
        new THREE.Vector3(position[0], position[1], position[2]),
        {
          recordHistory: false,
          autoSelect: false,
          snapToGround: false,
          forcedId: it.id,
          forcedLocked: !!it.locked,
          forcedName: it.name
        }
      );
    } catch (err) {
      console.warn("Skipping overlay item that failed to spawn at index", idx, err);
      continue;
    }
    if (!obj) continue;

    obj.rotation.set(rotation[0], rotation[1], rotation[2]);
    obj.scale.set(scale[0], scale[1], scale[2]);
    obj.userData.locked = !!it.locked;
    obj.updateMatrixWorld(true);
  }

  if (hovered) { clearHover(hovered); hovered = null; }
  if (selected) { clearSelected(selected); selected = null; }
  updateBuildingSelected(null);
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
  updateCommitButtons();
  undoStack = [];
  redoStack = [];

  updateSpecialPointsPanel();
  if (statusMessage) {
    setStatus(statusMessage);
  }
}

async function applyDraftWorkspaceState(draftState = {}) {
  await applyOverlayPayload(draftState?.overlay || { version: 2, items: [] }, {
    applyRoutes: false,
    statusMessage: ""
  });

  clearRoadObjectsFromOverlay();
  const roads = Array.isArray(draftState?.roads) ? draftState.roads : [];
  for (const roadItem of roads) {
    spawnSerializedRoadObject(roadItem);
  }

  const routes = (draftState?.routes && typeof draftState.routes === "object") ? draftState.routes : {};
  const guides = (draftState?.guides && typeof draftState.guides === "object") ? draftState.guides : { entries: {} };
  setSavedRoutes(routes);
  setLoadedGuidesPayload({
    model: currentModelName,
    entries: guides.entries || {}
  });
  renderGuideEditorPanel({ routes });
  updateSpecialPointsPanel();
}

function updateBaseModelNote(name) {
  if (!baseModelNote) return;
  if (name === ORIGINAL_MODEL_NAME) {
    baseModelNote.textContent = "Original map. Overlay assets load from map_overlay.json; current edits stay in draft until you export a new version.";
  } else if (name) {
    baseModelNote.textContent = "Exported map. Overlay assets are skipped; current edits stay in draft until you export the next child version.";
  } else {
    baseModelNote.textContent = "No base models found.";
  }
}

function clearOverlayObjects() {
  while (overlayRoot.children.length) {
    removeAndDisposeObject(overlayRoot.children[0]);
  }
}

function resetEditorState() {
  clearPreview();
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadSnapBuildingCache = [];
  roadSnapObstacleCache = [];
  roadGroundTargets = [];
  if (hovered) { clearHover(hovered); hovered = null; }
  if (selected) { clearSelected(selected); selected = null; }
  updateBuildingSelected(null);
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
  clearRouteLine();
  setRouteBanner("");
  setSavedRoutes({});
  setLoadedGuidesPayload({ model: "", entries: {} });
  resetModelBuildingCatalog();
  guideSelectionKey = "";
  updateCommitButtons();
  updateRoadControls();
  undoStack = [];
  redoStack = [];
  updateSpecialPointsPanel();
  renderGuideEditorPanel();
}

function disposeObject3D(root) {
  if (!root) return;
  const disposedGeometries = new Set();
  const disposedMaterials = new Set();
  const disposedTextures = new Set();

  root.traverse((n) => {
    if (n.isMesh || n.isLine || n.isPoints) {
      if (n.geometry && n.geometry.dispose && !disposedGeometries.has(n.geometry)) {
        disposedGeometries.add(n.geometry);
        n.geometry.dispose();
      }

      const mats = getMaterialsArray(n.material);
      for (const m of mats) {
        if (!m || disposedMaterials.has(m)) continue;
        disposedMaterials.add(m);

        for (const key of Object.keys(m)) {
          const tex = m[key];
          if (tex && tex.isTexture && tex.dispose && !disposedTextures.has(tex)) {
            disposedTextures.add(tex);
            tex.dispose();
          }
        }

        if (m.dispose) m.dispose();
      }
    }
  });
}

async function loadModelList() {
  if (!baseModelSelect) return [];

  const res = await apiFetch("mapEditor.php?action=list_models");
  const data = await res.json();
  if (!data.ok) throw new Error("Failed to list models");

  const models = Array.isArray(data.models) ? data.models : [];
  baseModelSelect.innerHTML = "";

  if (!models.length) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "No .glb files found";
    baseModelSelect.appendChild(opt);
    baseModelSelect.disabled = true;
    updateBaseModelNote("");
    updateOverlaySaveAvailability();
    return models;
  }

  for (const m of models) {
    const opt = document.createElement("option");
    opt.value = m.file;
    if (m.isDefault && m.isOriginal) opt.textContent = `${m.file} (original, default)`;
    else if (m.isDefault) opt.textContent = `${m.file} (default)`;
    else if (m.isOriginal) opt.textContent = `${m.file} (original)`;
    else opt.textContent = m.file;
    baseModelSelect.appendChild(opt);
  }

  baseModelSelect.disabled = false;
  if (!models.some(m => m.file === currentModelName)) {
    currentModelName = models[0].file;
  }
  baseModelSelect.value = currentModelName;
  updateBaseModelNote(currentModelName);
  updateOverlaySaveAvailability();

  return models;
}

async function getDefaultModelName() {
  const res = await apiFetch("mapEditor.php?action=get_default_model");
  const data = await res.json();
  if (data && data.ok && data.file) return data.file;
  return ORIGINAL_MODEL_NAME;
}

async function setDefaultModelName(name) {
  const res = await apiFetch("mapEditor.php?action=set_default_model", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ file: name })
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || "Failed to set default");
  return data.file;
}

async function switchBaseModel(nextName) {
  if (isModelSwitching) return;
  if (!nextName || nextName === currentModelName) return;

  const warning = getUnsavedSwitchWarning();
  if (warning) {
    const ok = confirm(warning);
    if (!ok) {
      if (baseModelSelect) baseModelSelect.value = currentModelName;
      return;
    }
  }

  isModelSwitching = true;
  if (baseModelSelect) baseModelSelect.disabled = true;

  try {
    const shouldLoadOverlay = nextName === ORIGINAL_MODEL_NAME;
    await loadBaseModel(nextName, { loadOverlay: shouldLoadOverlay });
  } finally {
    if (baseModelSelect) baseModelSelect.disabled = false;
    isModelSwitching = false;
  }
}

async function loadBaseModel(modelName, opts = {}) {
  const shouldLoadOverlay = opts.loadOverlay !== false;
  const availableDraft = await loadDraftStateForModel(modelName);
  let resumeDraft = false;
  if (availableDraft.exists) {
    if (opts.resumeDraft === true) {
      resumeDraft = true;
    } else if (opts.forceCommitted === true) {
      resumeDraft = false;
    } else {
      const lines = [`A saved draft exists for ${modelName}.`];
      const savedAt = normalizeDraftSavedAt(availableDraft.savedAt);
      if (savedAt) {
        lines.push("", `Saved: ${new Date(savedAt).toLocaleString()}`);
      }
      if (availableDraft.hasBaseDraft) {
        lines.push("This draft includes base-model edits.");
      }
      lines.push("", "OK = resume the draft", "Cancel = load the committed model");
      resumeDraft = confirm(lines.join("\n"));
    }
  }
  const modelUrl = (resumeDraft && availableDraft.hasBaseDraft && availableDraft.baseDraftUrl)
    ? `${availableDraft.baseDraftUrl}${availableDraft.baseDraftUrl.includes("?") ? "&" : "?"}v=${Date.now()}`
    : `${MODEL_DIR}${modelName}`;

  setStatus(resumeDraft ? "Loading saved draft..." : "Loading base model...");
  resetEditorState();
  currentModelName = modelName;
  setCurrentDraftSession({
    model: modelName,
    exists: !!availableDraft.exists,
    active: false,
    savedAt: availableDraft.savedAt,
    hasBaseDraft: !!availableDraft.hasBaseDraft,
    baseDraftFile: availableDraft.baseDraftFile || "",
    baseDraftUrl: availableDraft.baseDraftUrl || ""
  });

  if (!shouldLoadOverlay && !resumeDraft) {
    clearOverlayObjects();
  }

  if (campusRoot) {
    scene.remove(campusRoot);
    disposeObject3D(campusRoot);
    campusRoot = null;
  }

  return new Promise((resolve, reject) => {
    loader.load(
      modelUrl,
      async (gltf) => {
        campusRoot = gltf.scene;
        scene.add(campusRoot);
        await loadModelBuildingCatalog(modelName);
        rebuildBaseColliders();
        refreshBuildingList();
        rebuildRoadSnapBuildingCache();
        rebuildRoadGroundTargets();

        const box = new THREE.Box3().setFromObject(campusRoot);
        const size = box.getSize(new THREE.Vector3());
        const center = box.getCenter(new THREE.Vector3());

        controls.target.copy(center);
        const maxDim = Math.max(size.x, size.y, size.z);
        const dist = maxDim * 1.2;

        camera.position.set(center.x, center.y + dist, center.z + dist);
        camera.near = Math.max(0.1, dist / 500);
        camera.far  = dist * 10;
        camera.updateProjectionMatrix();
        controls.update();

        defaultCameraState = {
          position: camera.position.clone(),
          target: controls.target.clone(),
        };

        resize();

        isTopView = true;
        if (btnTop) btnTop.textContent = "3D View";
        setTopView();

        if (!assetsLoaded) {
          await loadAssetList();
          assetsLoaded = true;
        }

        let roadnetInfo = { exists: false, loaded: 0 };
        if (resumeDraft) {
          await applyDraftWorkspaceState(availableDraft);
          roadnetInfo = {
            exists: Array.isArray(availableDraft.roads) && availableDraft.roads.length > 0,
            loaded: Array.isArray(availableDraft.roads) ? availableDraft.roads.length : 0
          };
        } else if (shouldLoadOverlay) {
          await loadOverlay();
          await loadRoutesForModel(modelName);
          roadnetInfo = await loadRoadnetForModel(modelName, {
            replaceExisting: true,
            keepExistingWhenMissing: true
          });
          await loadGuidesForModel(modelName);
        } else {
          resetEditorState();
          await loadRoutesForModel(modelName);
          roadnetInfo = await loadRoadnetForModel(modelName, {
            replaceExisting: true,
            keepExistingWhenMissing: false
          });
          await loadGuidesForModel(modelName);
        }

        currentTool = "none";
        setActiveToolButton(null);
        updateToolIndicator();

        updateBaseModelNote(currentModelName);
        if (baseModelSelect) baseModelSelect.value = currentModelName;

        setCurrentDraftSession({
          model: modelName,
          exists: !!availableDraft.exists,
          active: resumeDraft,
          savedAt: availableDraft.savedAt,
          hasBaseDraft: !!availableDraft.hasBaseDraft,
          baseDraftFile: availableDraft.baseDraftFile || "",
          baseDraftUrl: availableDraft.baseDraftUrl || ""
        });

        let readyStatus = "Ready (hover + click select; press Move/Rotate/Scale to edit)";
        if (resumeDraft) {
          readyStatus = availableDraft.hasBaseDraft
            ? "Draft resumed (saved base edits + navigation draft loaded)"
            : "Draft resumed (saved navigation draft loaded)";
        } else if (!shouldLoadOverlay) {
          readyStatus = roadnetInfo.exists
            ? "Ready (overlay assets not loaded; editable roads loaded)"
            : "Ready (overlay assets not loaded; no editable roadnet file yet)";
        } else if (availableDraft.exists) {
          readyStatus = "Ready (committed version loaded; saved draft still available)";
        }
        setStatus(readyStatus);

        updateOverlaySaveAvailability();
        renderGuideEditorPanel();
        captureDirtyBaselines({ overlay: true, base: true });
        resolve();
      },
      undefined,
      (err) => {
        showError(`GLB LOAD ERROR Ã¢â‚¬â€ (check ${modelName})`, err);
        reject(err);
      }
    );
  });
}

baseModelSelect?.addEventListener("change", () => {
  const nextName = baseModelSelect.value;
  switchBaseModel(nextName);
});

baseModelDefaultBtn?.addEventListener("click", async () => {
  if (!baseModelSelect) return;
  const name = baseModelSelect.value;
  if (!name) return;
  try {
    const saved = await setDefaultModelName(name);
    await loadModelList();
    if (baseModelSelect) baseModelSelect.value = currentModelName;
    setStatus(`Default model set: ${saved}`);
  } catch (err) {
    showError("DEFAULT MODEL ERROR", err);
  }
});

window.addEventListener("beforeunload", (e) => {
  if (!getUnsavedChangeState().any) return;
  e.preventDefault();
  e.returnValue = "";
});

// -------------------- View controls --------------------
function setTopView() {
  if (!campusRoot) return;

  const box = new THREE.Box3().setFromObject(campusRoot);
  const size = box.getSize(new THREE.Vector3());
  const center = box.getCenter(new THREE.Vector3());

  const height = Math.max(size.x, size.z) * 1.2;

  camera.position.set(center.x, height, center.z);
  camera.lookAt(center.x, 0, center.z);

  controls.target.copy(center);
  controls.enableRotate = false;
  controls.enablePan = true;
  controls.enableZoom = true;
  controls.update();
  setStatus("Top View (default)");
}

function set3DView() {
  if (!defaultCameraState) return;
  camera.position.copy(defaultCameraState.position);
  controls.target.copy(defaultCameraState.target);
  controls.enableRotate = true;
  controls.enablePan = true;
  controls.enableZoom = true;
  camera.updateProjectionMatrix();
  controls.update();
  setStatus("3D View");
}

btnTop?.addEventListener("click", () => {
  if (!campusRoot) return;
  if (!isTopView) {
    setTopView();
    btnTop.textContent = "3D View";
  } else {
    set3DView();
    btnTop.textContent = "Top View";
  }
  isTopView = !isTopView;
});

btnReset?.addEventListener("click", () => {
  if (!campusRoot || !defaultCameraState) return;
  isTopView = false;
  btnTop.textContent = "Top View";
  set3DView();
});

// -------------------- Load base model --------------------
(async () => {
  try {
    currentModelName = await getDefaultModelName();
  } catch (err) {
    currentModelName = ORIGINAL_MODEL_NAME;
  }

  try {
    await loadModelList();
  } catch (err) {
    console.warn("Model list failed:", err);
    updateBaseModelNote(currentModelName);
  }
  updateOverlaySaveAvailability();

  const shouldLoadOverlay = currentModelName === ORIGINAL_MODEL_NAME;
  loadBaseModel(currentModelName, { loadOverlay: shouldLoadOverlay })
    .catch(() => {});
})();

// -------------------- Animation loop --------------------
function animate() {
  requestAnimationFrame(animate);
  controls.update();
  updateGizmoSize();

  if (selected && currentTool === "rotate" && transform.object === rotateProxy && !isTransformDragging) {
    updateRotateProxyAtSelection();
  }

  if (currentTool === "road" && selected && isRoadObject(selected) && roadEditRoot.visible) {
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }

  renderer.render(scene, camera);
}
resize();
animate();
</script>

<?php admin_layout_end(); ?>




