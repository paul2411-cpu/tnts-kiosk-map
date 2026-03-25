<?php
require_once __DIR__ . "/map_sync.php";
require_once __DIR__ . "/map_entities.php";

function events_trimmed($value, int $maxLen): string {
  $text = trim((string)$value);
  if ($text === "") return "";
  return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen) : $text;
}

function events_normalize_lookup(string $value): string {
  $value = mb_strtolower(trim($value));
  $value = preg_replace('/\s+/', ' ', $value);
  return is_string($value) ? $value : "";
}

function events_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function events_has_index(mysqli $conn, string $table, string $index): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeIndex = $conn->real_escape_string($index);
  $res = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function events_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException("SQL failed: " . $conn->error);
  }
}

function events_status_options(): array {
  return [
    "draft" => "Draft",
    "published" => "Published",
    "cancelled" => "Cancelled",
    "archived" => "Archived",
  ];
}

function events_location_mode_options(): array {
  return [
    "text_only" => "Text Only",
    "building" => "Building",
    "room" => "Room",
    "facility" => "Facility",
    "specific_area" => "Specific Area",
  ];
}

function events_health_labels(): array {
  return [
    "valid" => "Valid",
    "limited" => "Limited",
    "needs_review" => "Needs Review",
    "broken" => "Broken",
  ];
}

function events_normalize_status(string $status): string {
  $status = trim($status);
  $options = events_status_options();
  return isset($options[$status]) ? $status : "draft";
}

function events_normalize_location_mode(string $mode): string {
  $mode = trim($mode);
  $options = events_location_mode_options();
  return isset($options[$mode]) ? $mode : "text_only";
}

function events_normalize_time_value($value): string {
  $value = trim((string)$value);
  if ($value === "") return "";
  if (!preg_match('/^\d{2}:\d{2}$/', $value)) return "";
  $dt = DateTimeImmutable::createFromFormat("H:i", $value);
  return $dt ? $dt->format("H:i") : "";
}

function events_normalize_entity_type(string $type): string {
  $safe = trim(mb_strtolower($type));
  return in_array($safe, ["building", "venue", "area", "landmark"], true) ? $safe : "building";
}

function events_normalize_destination_type(string $type): string {
  $safe = trim(mb_strtolower($type));
  return in_array($safe, ["building", "venue", "area", "landmark", "facility"], true) ? $safe : "building";
}

function events_route_anchor_entity_types(): array {
  return ["building", "venue", "area"];
}

function events_is_route_anchor_entity_type(string $type): bool {
  return in_array(events_normalize_entity_type($type), events_route_anchor_entity_types(), true);
}

function events_route_lookup_variants($value): array {
  $variants = [];
  $raw = trim((string)$value);
  if ($raw !== "") {
    $variants[] = events_normalize_lookup($raw);
  }
  return array_values(array_unique(array_filter($variants, static fn($item) => $item !== "")));
}

function events_build_route_target_keys(array $target): array {
  $keys = [];
  foreach ([
    $target["buildingUid"] ?? "",
    $target["objectName"] ?? "",
    $target["buildingName"] ?? "",
    $target["name"] ?? "",
  ] as $value) {
    foreach (events_route_lookup_variants($value) as $variant) {
      $keys[$variant] = true;
    }
  }
  return array_keys($keys);
}

function events_has_route_for_target(array $routeKeys, array $target): bool {
  foreach (events_build_route_target_keys($target) as $candidate) {
    if (isset($routeKeys[$candidate])) return true;
  }
  return false;
}

function events_ensure_schema(mysqli $conn): void {
  events_sql($conn, "
    CREATE TABLE IF NOT EXISTS events (
      event_id INT(11) NOT NULL AUTO_INCREMENT,
      title VARCHAR(150) NOT NULL,
      description TEXT DEFAULT NULL,
      start_date DATE DEFAULT NULL,
      start_time TIME DEFAULT NULL,
      end_date DATE DEFAULT NULL,
      end_time TIME DEFAULT NULL,
      location VARCHAR(255) DEFAULT NULL,
      banner_path VARCHAR(255) DEFAULT NULL,
      date_added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      status ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft',
      location_mode ENUM('text_only','building','room','facility','specific_area') NOT NULL DEFAULT 'text_only',
      building_id INT(11) DEFAULT NULL,
      room_id INT(11) DEFAULT NULL,
      facility_id INT(11) DEFAULT NULL,
      map_model_file VARCHAR(255) DEFAULT NULL,
      map_point_x DOUBLE DEFAULT NULL,
      map_point_y DOUBLE DEFAULT NULL,
      map_point_z DOUBLE DEFAULT NULL,
      map_radius DOUBLE DEFAULT NULL,
      last_edited_at DATETIME DEFAULT NULL,
      last_edited_by_admin_id INT(11) DEFAULT NULL,
      PRIMARY KEY (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!events_has_column($conn, "events", "status")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN status ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft' AFTER date_added");
  }
  if (!events_has_column($conn, "events", "location_mode")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN location_mode ENUM('text_only','building','room','facility','specific_area') NOT NULL DEFAULT 'text_only' AFTER status");
  }
  if (!events_has_column($conn, "events", "start_time")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN start_time TIME DEFAULT NULL AFTER start_date");
  }
  if (!events_has_column($conn, "events", "end_time")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN end_time TIME DEFAULT NULL AFTER end_date");
  }
  if (!events_has_column($conn, "events", "building_id")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN building_id INT(11) DEFAULT NULL AFTER location_mode");
  }
  if (!events_has_column($conn, "events", "room_id")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN room_id INT(11) DEFAULT NULL AFTER building_id");
  }
  if (!events_has_column($conn, "events", "facility_id")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN facility_id INT(11) DEFAULT NULL AFTER room_id");
  }
  if (!events_has_column($conn, "events", "map_model_file")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN map_model_file VARCHAR(255) DEFAULT NULL AFTER facility_id");
  }
  if (!events_has_column($conn, "events", "map_point_x")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN map_point_x DOUBLE DEFAULT NULL AFTER map_model_file");
  }
  if (!events_has_column($conn, "events", "map_point_y")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN map_point_y DOUBLE DEFAULT NULL AFTER map_point_x");
  }
  if (!events_has_column($conn, "events", "map_point_z")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN map_point_z DOUBLE DEFAULT NULL AFTER map_point_y");
  }
  if (!events_has_column($conn, "events", "map_radius")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN map_radius DOUBLE DEFAULT NULL AFTER map_point_z");
  }
  if (!events_has_column($conn, "events", "last_edited_at")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN last_edited_at DATETIME DEFAULT NULL AFTER map_radius");
  }
  if (!events_has_column($conn, "events", "last_edited_by_admin_id")) {
    events_sql($conn, "ALTER TABLE events ADD COLUMN last_edited_by_admin_id INT(11) DEFAULT NULL AFTER last_edited_at");
  }

  events_sql($conn, "ALTER TABLE events MODIFY location VARCHAR(255) DEFAULT NULL");

  if (!events_has_index($conn, "events", "idx_events_status_dates")) {
    events_sql($conn, "ALTER TABLE events ADD INDEX idx_events_status_dates (status, start_date, end_date)");
  }
  if (!events_has_index($conn, "events", "idx_events_location_mode")) {
    events_sql($conn, "ALTER TABLE events ADD INDEX idx_events_location_mode (location_mode)");
  }
  if (!events_has_index($conn, "events", "idx_events_building_id")) {
    events_sql($conn, "ALTER TABLE events ADD INDEX idx_events_building_id (building_id)");
  }
  if (!events_has_index($conn, "events", "idx_events_room_id")) {
    events_sql($conn, "ALTER TABLE events ADD INDEX idx_events_room_id (room_id)");
  }
  if (!events_has_index($conn, "events", "idx_events_facility_id")) {
    events_sql($conn, "ALTER TABLE events ADD INDEX idx_events_facility_id (facility_id)");
  }
}

function events_public_model_file(string $root): string {
  $state = map_sync_resolve_public_model($root);
  return trim((string)($state["modelFile"] ?? ""));
}

function events_public_route_keys(string $root): array {
  static $cache = [];
  if (isset($cache[$root])) return $cache[$root];

  $state = map_sync_resolve_public_model($root);
  $routesPath = (string)($state["routesPath"] ?? "");
  $keys = [];
  if ($routesPath !== "" && is_file($routesPath)) {
    $payload = map_sync_read_json_file($routesPath);
    $routes = is_array($payload["routes"] ?? null) ? $payload["routes"] : [];
    foreach ($routes as $rawKey => $rawEntry) {
      $entry = is_array($rawEntry) ? $rawEntry : [];
      $candidates = [
        $rawKey,
        $entry["buildingUid"] ?? $entry["destinationUid"] ?? $entry["uid"] ?? "",
        $entry["objectName"] ?? $entry["modelObjectName"] ?? "",
        $entry["buildingName"] ?? $entry["name"] ?? $entry["routeName"] ?? $entry["destinationName"] ?? "",
      ];
      foreach ($candidates as $candidate) {
        foreach (events_route_lookup_variants($candidate) as $variant) {
          $keys[$variant] = true;
        }
      }
    }
  }

  $cache[$root] = $keys;
  return $keys;
}

function events_is_model_match(string $targetModel, string $publicModel): bool {
  $targetModel = trim($targetModel);
  $publicModel = trim($publicModel);
  if ($publicModel === "" || $targetModel === "") return true;
  return strcasecmp($targetModel, $publicModel) === 0;
}

function events_public_destination_catalog(mysqli $conn, string $publicModel): array {
  static $cache = [];
  $cacheKey = trim($publicModel);
  if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
  try {
    $cache[$cacheKey] = map_entities_fetch_model_destinations($conn, $publicModel, true);
  } catch (Throwable $_) {
    $cache[$cacheKey] = [];
  }
  return $cache[$cacheKey];
}

function events_public_room_catalog(mysqli $conn, string $publicModel): array {
  static $cache = [];
  $cacheKey = trim($publicModel);
  if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
  try {
    $cache[$cacheKey] = events_load_room_options($conn, $publicModel);
  } catch (Throwable $_) {
    $cache[$cacheKey] = [];
  }
  return $cache[$cacheKey];
}

function events_lookup_public_destination(
  mysqli $conn,
  string $publicModel,
  string $buildingName = "",
  string $objectName = "",
  string $buildingUid = "",
  array $entityTypes = []
): ?array {
  $safeUid = trim((string)$buildingUid);
  $safeName = events_normalize_lookup($buildingName);
  $safeObject = events_normalize_lookup($objectName);
  $allowedTypes = array_values(array_unique(array_filter(array_map("events_normalize_destination_type", $entityTypes))));

  $best = null;
  $bestScore = 0;
  foreach (events_public_destination_catalog($conn, $publicModel) as $row) {
    $type = events_normalize_destination_type((string)($row["entityType"] ?? "building"));
    if ($allowedTypes && !in_array($type, $allowedTypes, true)) continue;

    $rowUid = trim((string)($row["buildingUid"] ?? ""));
    $rowName = events_normalize_lookup((string)($row["name"] ?? ""));
    $rowObject = events_normalize_lookup((string)($row["objectName"] ?? ""));

    $score = 0;
    if ($safeUid !== "" && $rowUid !== "" && strcasecmp($rowUid, $safeUid) === 0) $score += 220;
    if ($safeObject !== "" && $rowObject === $safeObject) $score += 140;
    if ($safeName !== "" && $rowName === $safeName) $score += 110;
    if ($safeObject !== "" && $rowName === $safeObject) $score += 40;
    if ($safeName !== "" && $rowObject === $safeName) $score += 30;
    if ($score <= 0) continue;

    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $row;
    }
  }

  return is_array($best) ? $best : null;
}

function events_lookup_public_room(
  mysqli $conn,
  string $publicModel,
  string $buildingName = "",
  string $roomName = "",
  string $roomNumber = ""
): ?array {
  $safeBuilding = events_normalize_lookup($buildingName);
  $safeRoom = events_normalize_lookup($roomName);
  $safeNumber = events_normalize_lookup($roomNumber);
  if ($safeRoom === "" && $safeNumber === "") return null;

  $best = null;
  $bestScore = 0;
  foreach (events_public_room_catalog($conn, $publicModel) as $row) {
    $rowBuilding = events_normalize_lookup((string)($row["building_name"] ?? ""));
    $rowRoom = events_normalize_lookup((string)($row["room_name"] ?? ""));
    $rowNumber = events_normalize_lookup((string)($row["room_number"] ?? ""));

    $score = 0;
    if ($safeBuilding !== "" && $rowBuilding === $safeBuilding) $score += 90;
    if ($safeRoom !== "" && $rowRoom === $safeRoom) $score += 130;
    if ($safeNumber !== "" && $rowNumber === $safeNumber) $score += 120;
    if ($score <= 0) continue;

    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $row;
    }
  }

  return is_array($best) ? $best : null;
}

function events_banner_url(?string $storedPath): string {
  $path = trim((string)$storedPath);
  if ($path === "") return "";
  if (preg_match('#^(?:https?:)?//#i', $path)) return $path;
  $path = str_replace("\\", "/", $path);
  $path = preg_replace('#^\./#', '', $path);
  if (strpos($path, "../") === 0) return $path;
  return "../" . ltrim($path, "/");
}

function events_slugify_filename(string $name): string {
  $name = mb_strtolower(trim($name));
  $name = preg_replace('/[^a-z0-9]+/i', '-', $name);
  $name = trim((string)$name, "-");
  return $name !== "" ? $name : "event";
}

function events_store_banner_upload(array $file, string $root): array {
  if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ["ok" => true, "path" => ""];
  }

  if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return ["ok" => false, "error" => "The banner image upload failed."];
  }

  $tmpPath = (string)($file["tmp_name"] ?? "");
  if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
    return ["ok" => false, "error" => "The uploaded banner image could not be verified."];
  }

  $maxBytes = 6 * 1024 * 1024;
  $fileSize = (int)($file["size"] ?? 0);
  if ($fileSize <= 0 || $fileSize > $maxBytes) {
    return ["ok" => false, "error" => "Banner images must be smaller than 6 MB."];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($tmpPath);
  $allowed = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp",
    "image/gif" => "gif",
  ];
  if (!isset($allowed[$mime])) {
    return ["ok" => false, "error" => "Only JPG, PNG, WEBP, and GIF banner images are allowed."];
  }

  $dir = rtrim(str_replace("\\", "/", $root), "/") . "/assets/events";
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    return ["ok" => false, "error" => "The event banner folder could not be created."];
  }

  $original = (string)($file["name"] ?? "banner");
  $base = pathinfo($original, PATHINFO_FILENAME);
  $slug = events_slugify_filename($base);
  try {
    $suffix = bin2hex(random_bytes(5));
  } catch (Throwable $_) {
    $suffix = (string)mt_rand(10000, 99999);
  }
  $filename = $slug . "-" . $suffix . "." . $allowed[$mime];
  $destination = $dir . "/" . $filename;
  if (!move_uploaded_file($tmpPath, $destination)) {
    return ["ok" => false, "error" => "The banner image could not be saved."];
  }

  return ["ok" => true, "path" => "assets/events/" . $filename];
}

function events_fetch_building_by_id(mysqli $conn, int $buildingId): ?array {
  $hasSource = events_has_column($conn, "buildings", "source_model_file");
  $hasPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $hasEntityType = events_has_column($conn, "buildings", "entity_type");
  $hasUid = events_has_column($conn, "buildings", "building_uid");
  $hasObjectName = events_has_column($conn, "buildings", "model_object_name");
  $stmt = $conn->prepare(
    "SELECT building_id, "
    . ($hasUid ? "building_uid" : "NULL AS building_uid")
    . ", building_name, "
    . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name")
    . ", description, image_path, "
    . ($hasEntityType ? "entity_type" : "'building' AS entity_type")
    . ", "
    . ($hasSource ? "source_model_file" : "NULL AS source_model_file")
    . ", " . ($hasPresent ? "is_present_in_latest" : "1 AS is_present_in_latest")
    . " FROM buildings WHERE building_id = ? LIMIT 1"
  );
  if (!$stmt) return null;
  $stmt->bind_param("i", $buildingId);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}

function events_fetch_room_by_id(mysqli $conn, int $roomId): ?array {
  $hasRoomSource = events_has_column($conn, "rooms", "source_model_file");
  $hasRoomPresent = events_has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomBuildingName = events_has_column($conn, "rooms", "building_name");
  $hasBuildingSource = events_has_column($conn, "buildings", "source_model_file");
  $hasBuildingPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $hasBuildingUid = events_has_column($conn, "buildings", "building_uid");
  $hasBuildingObjectName = events_has_column($conn, "buildings", "model_object_name");
  $stmt = $conn->prepare(
    "SELECT
      r.room_id,
      r.room_name,
      r.room_number,
      r.room_type,
      r.floor_number,
      r.building_id,
      " . ($hasBuildingUid ? "b.building_uid" : "NULL AS building_uid") . ",
      " . ($hasBuildingObjectName ? "b.model_object_name" : "NULL AS building_object_name") . ",
      COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ") AS building_name,
      " . ($hasRoomSource ? "r.source_model_file" : ($hasBuildingSource ? "b.source_model_file" : "NULL")) . " AS source_model_file,
      " . ($hasRoomPresent ? "r.is_present_in_latest" : "1") . " AS room_present,
      " . ($hasBuildingPresent ? "b.is_present_in_latest" : "1") . " AS building_present
     FROM rooms r
     LEFT JOIN buildings b ON b.building_id = r.building_id
     WHERE r.room_id = ?
     LIMIT 1"
  );
  if (!$stmt) return null;
  $stmt->bind_param("i", $roomId);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}

function events_fetch_facility_by_id(mysqli $conn, int $facilityId): ?array {
  $stmt = $conn->prepare(
    "SELECT facility_id, facility_name, model_object_name, description, logo_path, location, contact_info,
            " . (events_has_column($conn, "facilities", "source_model_file") ? "source_model_file" : "NULL AS source_model_file") . ",
            " . (events_has_column($conn, "facilities", "is_present_in_latest") ? "is_present_in_latest" : "1 AS is_present_in_latest") . "
     FROM facilities
     WHERE facility_id = ?
     LIMIT 1"
  );
  if (!$stmt) return null;
  $stmt->bind_param("i", $facilityId);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}

function events_resolve_facility_target(mysqli $conn, int $facilityId, string $publicModel = ""): array {
  $facility = events_fetch_facility_by_id($conn, $facilityId);
  if (!$facility) {
    return [
      "facility" => null,
      "health" => "broken",
      "canMap" => false,
      "canRoute" => false,
      "message" => "The selected facility no longer exists.",
      "resolvedTarget" => null,
    ];
  }

  $facilityName = trim((string)($facility["facility_name"] ?? ""));
  $facilityObjectName = trim((string)($facility["model_object_name"] ?? ""));
  $facilitySourceModel = trim((string)($facility["source_model_file"] ?? ""));
  $facilityPresent = (string)($facility["is_present_in_latest"] ?? "1") !== "0";
  if ($facilityName !== "" && $facilityObjectName !== "" && $facilityPresent && events_is_model_match($facilitySourceModel, $publicModel)) {
    return [
      "facility" => $facility,
      "health" => "valid",
      "canMap" => true,
      "canRoute" => false,
      "message" => "This facility resolves directly to a mapped facility destination.",
      "resolvedTarget" => [
        "type" => "facility",
        "buildingName" => $facilityName,
        "objectName" => $facilityObjectName,
      ],
    ];
  }

  $publicFacilityMatch = events_lookup_public_destination(
    $conn,
    $publicModel,
    $facilityName,
    $facilityObjectName,
    "",
    ["facility"]
  );
  $fallbackFacilityMatch = is_array($publicFacilityMatch) ? [
    "facility" => $facility,
    "health" => "limited",
    "canMap" => true,
    "canRoute" => false,
    "message" => "This facility was matched to the current public map.",
    "resolvedTarget" => [
      "type" => "facility",
      "buildingId" => isset($publicFacilityMatch["id"]) ? (int)$publicFacilityMatch["id"] : 0,
      "buildingUid" => trim((string)($publicFacilityMatch["buildingUid"] ?? "")),
      "buildingName" => trim((string)($publicFacilityMatch["name"] ?? $facilityName)),
      "objectName" => trim((string)($publicFacilityMatch["objectName"] ?? $facilityObjectName)),
    ],
  ] : null;

  $hasRoomSource = events_has_column($conn, "rooms", "source_model_file");
  $hasRoomPresent = events_has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomBuildingName = events_has_column($conn, "rooms", "building_name");
  $hasBuildingSource = events_has_column($conn, "buildings", "source_model_file");
  $hasBuildingPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $hasBuildingUid = events_has_column($conn, "buildings", "building_uid");
  $hasBuildingObjectName = events_has_column($conn, "buildings", "model_object_name");
  $sql = "
    SELECT
      r.room_id,
      r.room_name,
      r.room_number,
      r.building_id,
      " . ($hasBuildingUid ? "b.building_uid" : "NULL AS building_uid") . ",
      " . ($hasBuildingObjectName ? "b.model_object_name" : "NULL AS building_object_name") . ",
      COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ") AS building_name,
      " . ($hasRoomSource ? "r.source_model_file" : ($hasBuildingSource ? "b.source_model_file" : "NULL")) . " AS source_model_file,
      " . ($hasRoomPresent ? "r.is_present_in_latest" : "1") . " AS room_present,
      " . ($hasBuildingPresent ? "b.is_present_in_latest" : "1") . " AS building_present
    FROM facility_room fr
    INNER JOIN rooms r ON r.room_id = fr.room_id
    LEFT JOIN buildings b ON b.building_id = r.building_id
    WHERE fr.facility_id = ?
    ORDER BY building_name ASC, r.room_name ASC";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    if (is_array($fallbackFacilityMatch)) return $fallbackFacilityMatch;
    return [
      "facility" => $facility,
      "health" => "limited",
      "canMap" => false,
      "canRoute" => false,
      "message" => "The facility could not be matched to a room right now.",
      "resolvedTarget" => null,
    ];
  }

  $stmt->bind_param("i", $facilityId);
  if (!$stmt->execute()) {
    $stmt->close();
    if (is_array($fallbackFacilityMatch)) return $fallbackFacilityMatch;
    return [
      "facility" => $facility,
      "health" => "limited",
      "canMap" => false,
      "canRoute" => false,
      "message" => "The facility could not be matched to a room right now.",
      "resolvedTarget" => null,
    ];
  }

  $res = $stmt->get_result();
  $rows = [];
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      $rows[] = $row;
    }
  }
  $stmt->close();

  if (!$rows) {
    if (is_array($fallbackFacilityMatch)) return $fallbackFacilityMatch;
    return [
      "facility" => $facility,
      "health" => "limited",
      "canMap" => false,
      "canRoute" => false,
      "message" => "This facility has no linked room yet, so it cannot open on the map.",
      "resolvedTarget" => null,
    ];
  }

  $validRows = [];
  foreach ($rows as $row) {
    $sourceModel = trim((string)($row["source_model_file"] ?? ""));
    $roomPresent = (string)($row["room_present"] ?? "1") !== "0";
    $buildingPresent = (string)($row["building_present"] ?? "1") !== "0";
    if (!$roomPresent || !$buildingPresent) continue;
    if (!events_is_model_match($sourceModel, $publicModel)) continue;
    $validRows[] = $row;
  }

  if (!$validRows) {
    if (is_array($fallbackFacilityMatch)) return $fallbackFacilityMatch;
    return [
      "facility" => $facility,
      "health" => "needs_review",
      "canMap" => false,
      "canRoute" => false,
      "message" => "This facility is linked to rooms from a different map version and needs review.",
      "resolvedTarget" => null,
    ];
  }

  $roomRows = [];
  $buildingRows = [];
  foreach ($validRows as $row) {
    $roomId = (int)($row["room_id"] ?? 0);
    if ($roomId > 0) $roomRows[$roomId] = $row;
    $buildingId = (int)($row["building_id"] ?? 0);
    if ($buildingId > 0) $buildingRows[$buildingId] = $row;
  }

  if (count($roomRows) === 1) {
    $row = array_values($roomRows)[0];
    return [
      "facility" => $facility,
      "health" => "valid",
      "canMap" => true,
      "canRoute" => true,
      "message" => "This facility resolves to one room.",
      "resolvedTarget" => [
        "type" => "room",
        "buildingId" => (int)($row["building_id"] ?? 0),
        "buildingUid" => trim((string)($row["building_uid"] ?? "")),
        "buildingName" => trim((string)($row["building_name"] ?? "")),
        "objectName" => trim((string)($row["building_object_name"] ?? "")),
        "roomId" => (int)($row["room_id"] ?? 0),
        "roomName" => trim((string)($row["room_name"] ?? "")),
        "roomNumber" => trim((string)($row["room_number"] ?? "")),
      ],
    ];
  }

  if (count($buildingRows) === 1) {
    $row = array_values($buildingRows)[0];
    return [
      "facility" => $facility,
      "health" => "valid",
      "canMap" => true,
      "canRoute" => true,
      "message" => "This facility spans one building, so directions will route to that building.",
      "resolvedTarget" => [
        "type" => "building",
        "buildingId" => (int)($row["building_id"] ?? 0),
        "buildingUid" => trim((string)($row["building_uid"] ?? "")),
        "buildingName" => trim((string)($row["building_name"] ?? "")),
        "objectName" => trim((string)($row["building_object_name"] ?? "")),
      ],
    ];
  }

  return [
    "facility" => $facility,
    "health" => "limited",
    "canMap" => false,
    "canRoute" => false,
    "message" => "This facility spans multiple map targets, so it cannot route to one exact destination yet.",
    "resolvedTarget" => null,
  ];
}

function events_resolve_interval(array $event): ?array {
  $startDate = trim((string)($event["start_date"] ?? ""));
  $endDate = trim((string)($event["end_date"] ?? "")) ?: $startDate;
  if ($startDate === "" || $endDate === "") return null;

  $startTime = events_normalize_time_value($event["start_time"] ?? "");
  $endTime = events_normalize_time_value($event["end_time"] ?? "");
  $timed = ($startTime !== "" && $endTime !== "");

  $startStamp = $startDate . " " . ($timed ? $startTime . ":00" : "00:00:00");
  $endStamp = $endDate . " " . ($timed ? $endTime . ":59" : "23:59:59");
  $startAt = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $startStamp) ?: null;
  $endAt = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $endStamp) ?: null;
  if (!$startAt || !$endAt) return null;

  return [
    "start" => $startAt,
    "end" => $endAt,
    "timed" => $timed,
    "startDate" => $startDate,
    "endDate" => $endDate,
    "startTime" => $startTime,
    "endTime" => $endTime,
  ];
}

function events_ranges_overlap(array $left, array $right): bool {
  if (empty($left["start"]) || empty($left["end"]) || empty($right["start"]) || empty($right["end"])) return false;
  return $left["start"] <= $right["end"] && $right["start"] <= $left["end"];
}

function events_conflict_scope(array $event): ?array {
  $mode = events_normalize_location_mode((string)($event["location_mode"] ?? "text_only"));
  if ($mode === "building" || $mode === "specific_area") {
    $buildingId = (int)($event["building_id"] ?? 0);
    if ($buildingId > 0) {
      return ["kind" => "building", "id" => $buildingId];
    }
    return null;
  }
  if ($mode === "room") {
    $roomId = (int)($event["room_id"] ?? 0);
    return $roomId > 0 ? ["kind" => "room", "id" => $roomId] : null;
  }
  if ($mode === "facility") {
    $facilityId = (int)($event["facility_id"] ?? 0);
    return $facilityId > 0 ? ["kind" => "facility", "id" => $facilityId] : null;
  }
  return null;
}

function events_find_overlap_conflict(mysqli $conn, array $candidate, int $ignoreEventId = 0): ?array {
  $interval = events_resolve_interval($candidate);
  $scope = events_conflict_scope($candidate);
  if (!$interval || !$scope) return null;

  if ($scope["kind"] === "building") {
    $stmt = $conn->prepare(
      "SELECT * FROM events
       WHERE event_id <> ?
         AND status IN ('draft','published')
         AND (
           (location_mode = 'building' AND building_id = ?)
           OR
           (location_mode = 'specific_area' AND building_id = ?)
         )
       ORDER BY start_date ASC, event_id ASC"
    );
    if (!$stmt) return null;
    $stmt->bind_param("iii", $ignoreEventId, $scope["id"], $scope["id"]);
  } elseif ($scope["kind"] === "room") {
    $stmt = $conn->prepare(
      "SELECT * FROM events
       WHERE event_id <> ?
         AND status IN ('draft','published')
         AND location_mode = 'room'
         AND room_id = ?
       ORDER BY start_date ASC, event_id ASC"
    );
    if (!$stmt) return null;
    $stmt->bind_param("ii", $ignoreEventId, $scope["id"]);
  } elseif ($scope["kind"] === "facility") {
    $stmt = $conn->prepare(
      "SELECT * FROM events
       WHERE event_id <> ?
         AND status IN ('draft','published')
         AND location_mode = 'facility'
         AND facility_id = ?
       ORDER BY start_date ASC, event_id ASC"
    );
    if (!$stmt) return null;
    $stmt->bind_param("ii", $ignoreEventId, $scope["id"]);
  } else {
    return null;
  }

  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $conflict = null;
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      $rowInterval = events_resolve_interval($row);
      if (!$rowInterval) continue;
      if (events_ranges_overlap($interval, $rowInterval)) {
        $conflict = $row;
        break;
      }
    }
  }
  $stmt->close();
  return $conflict;
}

function events_classify_schedule(array $event): string {
  $status = events_normalize_status((string)($event["status"] ?? "draft"));
  $interval = events_resolve_interval($event);
  if (!$interval) return $status === "published" ? "unscheduled" : "draft";

  if ($status !== "published") return $status;
  $now = new DateTimeImmutable("now");
  $today = new DateTimeImmutable("today");
  if ($interval["end"] < $now) return "past";
  if ($interval["start"] > $now) {
    return $interval["start"]->format("Y-m-d") === $today->format("Y-m-d") ? "today" : "upcoming";
  }
  if ($interval["start"]->format("Y-m-d") === $today->format("Y-m-d") && $interval["end"]->format("Y-m-d") === $today->format("Y-m-d")) {
    return "today";
  }
  return "ongoing";
}

function events_format_date_label(array $event): string {
  $interval = events_resolve_interval($event);
  if (!$interval) return "Date TBA";

  $start = $interval["start"];
  $end = $interval["end"];
  if (!$interval["timed"]) {
    $label = $start->format("M d, Y");
    if ($interval["endDate"] !== $interval["startDate"]) {
      $label .= " to " . $end->format("M d, Y");
    }
    return $label;
  }

  if ($interval["endDate"] === $interval["startDate"]) {
    return $start->format("M d, Y g:i A") . " to " . $end->format("g:i A");
  }
  return $start->format("M d, Y g:i A") . " to " . $end->format("M d, Y g:i A");
}

function events_is_publicly_visible(array $event): bool {
  if (events_normalize_status((string)($event["status"] ?? "")) !== "published") return false;
  $schedule = events_classify_schedule($event);
  return in_array($schedule, ["today", "ongoing", "upcoming", "unscheduled"], true);
}

function events_resolve_location(mysqli $conn, array $event, string $publicModel, string $root = ""): array {
  $mode = events_normalize_location_mode((string)($event["location_mode"] ?? "text_only"));
  $displayLocation = trim((string)($event["location"] ?? ""));
  $routeKeys = $root !== "" ? events_public_route_keys($root) : [];

  $resolved = [
    "mode" => $mode,
    "displayLocation" => $displayLocation,
    "health" => "valid",
    "healthLabel" => events_health_labels()["valid"],
    "canMap" => false,
    "canRoute" => false,
    "message" => "",
    "resolvedTarget" => null,
  ];

  if ($mode === "text_only") {
    $resolved["health"] = "limited";
    $resolved["healthLabel"] = events_health_labels()["limited"];
    $resolved["message"] = "This event is text-only and does not open on the map.";
    return $resolved;
  }

  if ($mode === "building") {
    $buildingId = (int)($event["building_id"] ?? 0);
    $building = $buildingId > 0 ? events_fetch_building_by_id($conn, $buildingId) : null;
    if (!$building) {
      $resolved["health"] = "broken";
      $resolved["healthLabel"] = events_health_labels()["broken"];
      $resolved["message"] = "The linked building no longer exists.";
      return $resolved;
    }

    $buildingName = trim((string)($building["building_name"] ?? ""));
    $buildingType = trim((string)($building["entity_type"] ?? "building")) ?: "building";
    $buildingUid = trim((string)($building["building_uid"] ?? ""));
    $buildingObjectName = trim((string)($building["model_object_name"] ?? ""));
    if ($displayLocation === "") $resolved["displayLocation"] = $buildingName;

    $present = (string)($building["is_present_in_latest"] ?? "1") !== "0";
    $sourceModel = trim((string)($building["source_model_file"] ?? ""));
    if (!$present || !events_is_model_match($sourceModel, $publicModel)) {
      $fallbackBuilding = events_lookup_public_destination(
        $conn,
        $publicModel,
        $buildingName,
        $buildingObjectName,
        $buildingUid,
        [$buildingType]
      );
      if (is_array($fallbackBuilding)) {
        $fallbackName = trim((string)($fallbackBuilding["name"] ?? $buildingName));
        $fallbackUid = trim((string)($fallbackBuilding["buildingUid"] ?? ""));
        $fallbackObjectName = trim((string)($fallbackBuilding["objectName"] ?? $buildingObjectName));
        if ($displayLocation === "") $resolved["displayLocation"] = $fallbackName;
        $resolved["health"] = "limited";
        $resolved["healthLabel"] = events_health_labels()["limited"];
        $resolved["canMap"] = true;
        $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
          "buildingUid" => $fallbackUid,
          "buildingName" => $fallbackName,
          "objectName" => $fallbackObjectName,
        ]);
        $resolved["message"] = $resolved["canRoute"]
          ? "This event was matched to the current public map. Directions are available."
          : "This event was matched to the current public map, but no published route is available right now.";
        $resolved["resolvedTarget"] = [
          "type" => trim((string)($fallbackBuilding["entityType"] ?? $buildingType)) ?: $buildingType,
          "buildingId" => isset($fallbackBuilding["id"]) ? (int)$fallbackBuilding["id"] : 0,
          "buildingName" => $fallbackName,
          "buildingUid" => $fallbackUid,
          "objectName" => $fallbackObjectName,
        ];
        return $resolved;
      }
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "The linked building is not available in the current public map.";
      return $resolved;
    }

    $resolved["canMap"] = true;
    $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
      "buildingUid" => $buildingUid,
      "buildingName" => $buildingName,
      "objectName" => $buildingObjectName,
    ]);
    $resolved["message"] = $resolved["canRoute"]
      ? "Directions are available for this building."
      : "This building can open on the map, but no published route is available right now.";
    if (!$resolved["canRoute"]) {
      $resolved["health"] = "limited";
      $resolved["healthLabel"] = events_health_labels()["limited"];
    }
    $resolved["resolvedTarget"] = [
      "type" => $buildingType,
      "buildingId" => (int)$building["building_id"],
      "buildingName" => $buildingName,
      "buildingUid" => $buildingUid,
      "objectName" => $buildingObjectName,
    ];
    return $resolved;
  }

  if ($mode === "room") {
    $roomId = (int)($event["room_id"] ?? 0);
    $room = $roomId > 0 ? events_fetch_room_by_id($conn, $roomId) : null;
    if (!$room) {
      $resolved["health"] = "broken";
      $resolved["healthLabel"] = events_health_labels()["broken"];
      $resolved["message"] = "The linked room no longer exists.";
      return $resolved;
    }

    $buildingName = trim((string)($room["building_name"] ?? ""));
    $roomName = trim((string)($room["room_name"] ?? ""));
    $roomNumber = trim((string)($room["room_number"] ?? ""));
    $buildingUid = trim((string)($room["building_uid"] ?? ""));
    $buildingObjectName = trim((string)($room["building_object_name"] ?? ""));
    if ($displayLocation === "") {
      $resolved["displayLocation"] = trim($buildingName . ($roomName !== "" ? " - " . $roomName : ""));
    }

    $roomPresent = (string)($room["room_present"] ?? "1") !== "0";
    $buildingPresent = (string)($room["building_present"] ?? "1") !== "0";
    $sourceModel = trim((string)($room["source_model_file"] ?? ""));
    if (!$roomPresent || !$buildingPresent || !events_is_model_match($sourceModel, $publicModel)) {
      $fallbackRoom = events_lookup_public_room($conn, $publicModel, $buildingName, $roomName, $roomNumber);
      if (is_array($fallbackRoom)) {
        $fallbackBuildingName = trim((string)($fallbackRoom["building_name"] ?? $buildingName));
        $fallbackRoomName = trim((string)($fallbackRoom["room_name"] ?? $roomName));
        $fallbackRoomNumber = trim((string)($fallbackRoom["room_number"] ?? $roomNumber));
        $fallbackBuilding = events_lookup_public_destination(
          $conn,
          $publicModel,
          $fallbackBuildingName,
          "",
          "",
          events_route_anchor_entity_types()
        );
        $fallbackUid = trim((string)($fallbackBuilding["buildingUid"] ?? ""));
        $fallbackObjectName = trim((string)($fallbackBuilding["objectName"] ?? ""));
        if ($displayLocation === "") {
          $resolved["displayLocation"] = trim($fallbackBuildingName . ($fallbackRoomName !== "" ? " - " . $fallbackRoomName : ""));
        }
        $resolved["health"] = "limited";
        $resolved["healthLabel"] = events_health_labels()["limited"];
        $resolved["canMap"] = true;
        $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
          "buildingUid" => $fallbackUid,
          "buildingName" => $fallbackBuildingName,
          "objectName" => $fallbackObjectName,
        ]);
        $resolved["message"] = $resolved["canRoute"]
          ? "This event room was matched to the current public map. Directions are available through its parent building."
          : "This event room was matched to the current public map, but no published route is available for its building right now.";
        $resolved["resolvedTarget"] = [
          "type" => "room",
          "buildingId" => (int)($fallbackRoom["building_id"] ?? 0),
          "buildingUid" => $fallbackUid,
          "buildingName" => $fallbackBuildingName,
          "objectName" => $fallbackObjectName,
          "roomId" => (int)($fallbackRoom["room_id"] ?? 0),
          "roomName" => $fallbackRoomName,
          "roomNumber" => $fallbackRoomNumber,
        ];
        return $resolved;
      }
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "The linked room is not available in the current public map.";
      return $resolved;
    }

    $resolved["canMap"] = true;
    $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
      "buildingUid" => $buildingUid,
      "buildingName" => $buildingName,
      "objectName" => $buildingObjectName,
    ]);
    $resolved["message"] = $resolved["canRoute"]
      ? "Directions are available through the room's parent building."
      : "The room can open on the map, but no published route is available for its building right now.";
    if (!$resolved["canRoute"]) {
      $resolved["health"] = "limited";
      $resolved["healthLabel"] = events_health_labels()["limited"];
    }
    $resolved["resolvedTarget"] = [
      "type" => "room",
      "buildingId" => (int)($room["building_id"] ?? 0),
      "buildingUid" => $buildingUid,
      "buildingName" => $buildingName,
      "objectName" => $buildingObjectName,
      "roomId" => (int)($room["room_id"] ?? 0),
      "roomName" => $roomName,
      "roomNumber" => $roomNumber,
    ];
    return $resolved;
  }

  if ($mode === "facility") {
    $facilityId = (int)($event["facility_id"] ?? 0);
    $facilityResolution = $facilityId > 0 ? events_resolve_facility_target($conn, $facilityId, $publicModel) : [
      "facility" => null,
      "health" => "broken",
      "canMap" => false,
      "canRoute" => false,
      "message" => "The linked facility is missing.",
      "resolvedTarget" => null,
    ];

    $facility = $facilityResolution["facility"] ?? null;
    if ($displayLocation === "" && $facility) {
      $facilityName = trim((string)($facility["facility_name"] ?? ""));
      $facilityLocation = trim((string)($facility["location"] ?? ""));
      $resolved["displayLocation"] = trim($facilityName . ($facilityLocation !== "" ? " - " . $facilityLocation : ""));
    }

    $resolved["health"] = (string)($facilityResolution["health"] ?? "limited");
    $resolved["healthLabel"] = events_health_labels()[$resolved["health"]] ?? events_health_labels()["limited"];
    $resolved["canMap"] = !empty($facilityResolution["canMap"]);
    $resolved["canRoute"] = false;
    $resolved["message"] = (string)($facilityResolution["message"] ?? "");
    $resolved["resolvedTarget"] = $facilityResolution["resolvedTarget"] ?? null;
    if ($resolved["canMap"] && is_array($resolved["resolvedTarget"])) {
      $targetType = trim((string)($resolved["resolvedTarget"]["type"] ?? ""));
      $targetBuildingName = "";
      if ($targetType === "building") {
        $targetBuildingName = trim((string)($resolved["resolvedTarget"]["buildingName"] ?? ""));
      } elseif ($targetType === "room") {
        $targetBuildingName = trim((string)($resolved["resolvedTarget"]["buildingName"] ?? ""));
      } elseif ($targetType === "facility") {
        $targetBuildingName = trim((string)($resolved["resolvedTarget"]["buildingName"] ?? ""));
      }

      $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
        "buildingUid" => trim((string)($resolved["resolvedTarget"]["buildingUid"] ?? "")),
        "buildingName" => $targetBuildingName,
        "objectName" => trim((string)($resolved["resolvedTarget"]["objectName"] ?? "")),
      ]);
      if ($resolved["canRoute"]) {
        $resolved["message"] = $targetType === "room"
          ? "Directions are available through the facility's linked room."
          : ($targetType === "facility"
            ? "Directions are available for this facility."
            : "Directions are available through the facility's linked building.");
      } else {
        $resolved["message"] = "This facility can open on the map, but no published route is available for its linked destination right now.";
        if ($resolved["health"] === "valid") {
          $resolved["health"] = "limited";
          $resolved["healthLabel"] = events_health_labels()["limited"];
        }
      }
    }
    return $resolved;
  }

  if ($mode === "specific_area") {
    $x = $event["map_point_x"] ?? null;
    $y = $event["map_point_y"] ?? null;
    $z = $event["map_point_z"] ?? null;
    $radius = $event["map_radius"] ?? null;
    $modelFile = trim((string)($event["map_model_file"] ?? ""));
    $differentAreaModel = $modelFile !== "" && !events_is_model_match($modelFile, $publicModel);
    if (!is_numeric($x) || !is_numeric($y) || !is_numeric($z)) {
      $resolved["health"] = "broken";
      $resolved["healthLabel"] = events_health_labels()["broken"];
      $resolved["message"] = "The saved map area is incomplete.";
      return $resolved;
    }
    if ($displayLocation === "") {
      $resolved["displayLocation"] = "Event area";
    }
    if ($differentAreaModel) {
      $resolved["health"] = "limited";
      $resolved["healthLabel"] = events_health_labels()["limited"];
      $resolved["message"] = "This event area was pinned on an older map version.";
    }

    $resolved["canMap"] = true;
    $resolved["canRoute"] = false;
    if (!$differentAreaModel) {
      $resolved["health"] = "valid";
      $resolved["healthLabel"] = events_health_labels()["valid"];
      $resolved["message"] = "This event will open as a map highlight only.";
    }
    $resolved["resolvedTarget"] = [
      "type" => "specific_area",
      "x" => (float)$x,
      "y" => (float)$y,
      "z" => (float)$z,
      "radius" => (is_numeric($radius) && (float)$radius > 0) ? (float)$radius : 8.0,
      "modelFile" => $modelFile,
    ];

    $anchorBuildingId = (int)($event["building_id"] ?? 0);
    if ($anchorBuildingId > 0) {
      $anchor = events_fetch_building_by_id($conn, $anchorBuildingId);
      if (!$anchor) {
        $resolved["health"] = "limited";
        $resolved["healthLabel"] = events_health_labels()["limited"];
        $resolved["message"] = "This event area highlight is valid, but its linked route anchor no longer exists.";
        return $resolved;
      }

      $anchorType = events_normalize_entity_type((string)($anchor["entity_type"] ?? "building"));
      if (!events_is_route_anchor_entity_type($anchorType)) {
        $resolved["health"] = "limited";
        $resolved["healthLabel"] = events_health_labels()["limited"];
        $resolved["message"] = "This event area highlight is valid, but its linked route anchor cannot provide directions.";
        return $resolved;
      }

      $anchorPresent = (string)($anchor["is_present_in_latest"] ?? "1") !== "0";
      $anchorSourceModel = trim((string)($anchor["source_model_file"] ?? ""));
      if ($differentAreaModel) {
        $anchorMatch = ($anchorPresent && events_is_model_match($anchorSourceModel, $publicModel))
          ? [
              "id" => (int)($anchor["building_id"] ?? 0),
              "buildingUid" => trim((string)($anchor["building_uid"] ?? "")),
              "name" => trim((string)($anchor["building_name"] ?? "")),
              "objectName" => trim((string)($anchor["model_object_name"] ?? "")),
              "entityType" => $anchorType,
            ]
          : events_lookup_public_destination(
              $conn,
              $publicModel,
              trim((string)($anchor["building_name"] ?? "")),
              trim((string)($anchor["model_object_name"] ?? "")),
              trim((string)($anchor["building_uid"] ?? "")),
              [$anchorType]
            );
        if (!is_array($anchorMatch)) {
          $resolved["health"] = "needs_review";
          $resolved["healthLabel"] = events_health_labels()["needs_review"];
          $resolved["message"] = "This event area was pinned on a different map version and its linked destination is not available in the current public map.";
          return $resolved;
        }

        $anchorName = trim((string)($anchorMatch["name"] ?? $anchor["building_name"] ?? ""));
        if ($displayLocation === "") {
          $resolved["displayLocation"] = trim($anchorName . " - Event area");
        }
        $resolved["resolvedTarget"] = [
          "type" => $anchorType,
          "buildingId" => isset($anchorMatch["id"]) ? (int)$anchorMatch["id"] : (int)($anchor["building_id"] ?? 0),
          "buildingUid" => trim((string)($anchorMatch["buildingUid"] ?? $anchor["building_uid"] ?? "")),
          "buildingName" => $anchorName,
          "objectName" => trim((string)($anchorMatch["objectName"] ?? $anchor["model_object_name"] ?? "")),
        ];
        $resolved["canMap"] = true;
        $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
          "buildingUid" => trim((string)($resolved["resolvedTarget"]["buildingUid"] ?? "")),
          "buildingName" => $anchorName,
          "objectName" => trim((string)($resolved["resolvedTarget"]["objectName"] ?? "")),
        ]);
        $resolved["message"] = $resolved["canRoute"]
          ? "This event area was pinned on an older map version. The exact highlight is unavailable, but directions are available through the current linked destination."
          : "This event area was pinned on an older map version. The exact highlight is unavailable, but the linked destination can still open on the map.";
        return $resolved;
      }
      if (!$anchorPresent || !events_is_model_match($anchorSourceModel, $publicModel)) {
        $resolved["health"] = "needs_review";
        $resolved["healthLabel"] = events_health_labels()["needs_review"];
        $resolved["message"] = "This event area highlight is valid, but its linked route anchor is not available in the current public map.";
        return $resolved;
      }

      $anchorName = trim((string)($anchor["building_name"] ?? ""));
      $resolved["resolvedTarget"]["buildingId"] = (int)($anchor["building_id"] ?? 0);
      $resolved["resolvedTarget"]["buildingUid"] = trim((string)($anchor["building_uid"] ?? ""));
      $resolved["resolvedTarget"]["buildingName"] = $anchorName;
      $resolved["resolvedTarget"]["objectName"] = trim((string)($anchor["model_object_name"] ?? ""));
      $resolved["resolvedTarget"]["anchorType"] = $anchorType;

      if ($displayLocation === "") {
        $resolved["displayLocation"] = trim($anchorName . " - Event area");
      }

      $resolved["canRoute"] = events_has_route_for_target($routeKeys, [
        "buildingUid" => trim((string)($anchor["building_uid"] ?? "")),
        "buildingName" => $anchorName,
        "objectName" => trim((string)($anchor["model_object_name"] ?? "")),
      ]);
      if ($resolved["canRoute"]) {
        $resolved["message"] = "Directions are available through the linked destination. The exact event spot will still open as a highlight.";
      } else {
        $resolved["health"] = "limited";
        $resolved["healthLabel"] = events_health_labels()["limited"];
        $resolved["message"] = "This event area highlight is valid, but no published route is available for its linked destination right now.";
      }
    }
    if ($differentAreaModel) {
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "This event area was pinned on a different map version and needs review.";
      return $resolved;
    }
    return $resolved;
  }

  $resolved["health"] = "broken";
  $resolved["healthLabel"] = events_health_labels()["broken"];
  $resolved["message"] = "The location mode is invalid.";
  return $resolved;
}

function events_load_building_options(mysqli $conn, string $publicModel, array $entityTypes = []): array {
  $hasPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $hasSource = events_has_column($conn, "buildings", "source_model_file");
  $hasEntityType = events_has_column($conn, "buildings", "entity_type");
  $hasUid = events_has_column($conn, "buildings", "building_uid");
  $hasObjectName = events_has_column($conn, "buildings", "model_object_name");
  $sql = "SELECT building_id, building_name, description, image_path, "
    . ($hasUid ? "building_uid" : "NULL AS building_uid")
    . ", "
    . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name")
    . ", "
    . ($hasEntityType ? "entity_type" : "'building' AS entity_type")
    . ", "
    . ($hasSource ? "source_model_file" : "NULL AS source_model_file")
    . " FROM buildings WHERE building_name IS NOT NULL AND building_name <> ''";
  if ($hasPresent) {
    $sql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }

  $allowedTypes = array_values(array_unique(array_filter(array_map("events_normalize_entity_type", $entityTypes))));
  if ($allowedTypes && $hasEntityType) {
    $quotedTypes = array_map(static function (string $type) use ($conn): string {
      return "'" . $conn->real_escape_string($type) . "'";
    }, $allowedTypes);
    $sql .= " AND entity_type IN (" . implode(", ", $quotedTypes) . ")";
  }

  $rows = [];
  if ($publicModel !== "" && $hasSource) {
    $stmt = $conn->prepare($sql . " AND source_model_file = ? ORDER BY building_name ASC");
    if ($stmt) {
      $stmt->bind_param("s", $publicModel);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
          while ($row = $res->fetch_assoc()) $rows[] = $row;
        }
      }
      $stmt->close();
    }
  }

  if (!$rows) {
    $res = $conn->query($sql . " ORDER BY building_name ASC");
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
  }

  return $rows;
}

function events_load_room_options(mysqli $conn, string $publicModel): array {
  $hasRoomSource = events_has_column($conn, "rooms", "source_model_file");
  $hasRoomPresent = events_has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomBuildingName = events_has_column($conn, "rooms", "building_name");
  $hasBuildingPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $sql = "SELECT
      r.room_id,
      r.room_name,
      r.room_number,
      r.room_type,
      r.floor_number,
      r.building_id,
      COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ") AS building_name,
      " . ($hasRoomSource ? "r.source_model_file" : "NULL AS source_model_file") . "
    FROM rooms r
    LEFT JOIN buildings b ON b.building_id = r.building_id
    WHERE r.room_name IS NOT NULL AND r.room_name <> ''";
  if ($hasRoomPresent) {
    $sql .= " AND (r.is_present_in_latest = 1 OR r.is_present_in_latest IS NULL)";
  }
  if ($hasBuildingPresent) {
    $sql .= " AND (b.building_id IS NULL OR b.is_present_in_latest = 1 OR b.is_present_in_latest IS NULL)";
  }

  $rows = [];
  if ($publicModel !== "" && $hasRoomSource) {
    $stmt = $conn->prepare($sql . " AND r.source_model_file = ? ORDER BY building_name ASC, r.room_name ASC");
    if ($stmt) {
      $stmt->bind_param("s", $publicModel);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
          while ($row = $res->fetch_assoc()) $rows[] = $row;
        }
      }
      $stmt->close();
    }
  }

  if (!$rows) {
    $res = $conn->query($sql . " ORDER BY building_name ASC, r.room_name ASC");
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
  }

  return $rows;
}

function events_load_facility_options(mysqli $conn): array {
  $rows = [];
  $res = $conn->query("SELECT facility_id, facility_name, description, logo_path, location, contact_info FROM facilities ORDER BY facility_name ASC");
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  return $rows;
}

function events_load_rows(mysqli $conn, bool $publicOnly = false): array {
  events_ensure_schema($conn);
  $sql = "SELECT * FROM events";
  if ($publicOnly) {
    $sql .= " WHERE status = 'published'";
  }
  $sql .= " ORDER BY
    CASE
      WHEN start_date IS NULL THEN 3
      WHEN start_date = CURDATE() THEN 0
      WHEN start_date > CURDATE() THEN 1
      ELSE 2
    END ASC,
    start_date ASC,
    COALESCE(start_time, '23:59:59') ASC,
    date_added DESC,
    event_id DESC";

  $rows = [];
  $res = $conn->query($sql);
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      $rows[] = $row;
    }
  }
  return $rows;
}

function events_load_row_by_id(mysqli $conn, int $eventId): ?array {
  events_ensure_schema($conn);
  $stmt = $conn->prepare("SELECT * FROM events WHERE event_id = ? LIMIT 1");
  if (!$stmt) return null;
  $stmt->bind_param("i", $eventId);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}
