<?php
require_once __DIR__ . "/map_sync.php";

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

function events_ensure_schema(mysqli $conn): void {
  events_sql($conn, "
    CREATE TABLE IF NOT EXISTS events (
      event_id INT(11) NOT NULL AUTO_INCREMENT,
      title VARCHAR(150) NOT NULL,
      description TEXT DEFAULT NULL,
      start_date DATE DEFAULT NULL,
      end_date DATE DEFAULT NULL,
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
      $name = trim((string)($entry["buildingName"] ?? $entry["name"] ?? $entry["routeName"] ?? $entry["destinationName"] ?? $rawKey));
      $normalized = events_normalize_lookup($name);
      if ($normalized === "") continue;
      $keys[$normalized] = $name;
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
  $stmt = $conn->prepare(
    "SELECT building_id, building_name, description, image_path, "
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
  $stmt = $conn->prepare(
    "SELECT
      r.room_id,
      r.room_name,
      r.room_number,
      r.room_type,
      r.floor_number,
      r.building_id,
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
    "SELECT facility_id, facility_name, description, logo_path, location, contact_info
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

  $hasRoomSource = events_has_column($conn, "rooms", "source_model_file");
  $hasRoomPresent = events_has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomBuildingName = events_has_column($conn, "rooms", "building_name");
  $hasBuildingSource = events_has_column($conn, "buildings", "source_model_file");
  $hasBuildingPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $sql = "
    SELECT
      r.room_id,
      r.room_name,
      r.room_number,
      r.building_id,
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
        "buildingName" => trim((string)($row["building_name"] ?? "")),
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
        "buildingName" => trim((string)($row["building_name"] ?? "")),
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

function events_classify_schedule(array $event): string {
  $status = events_normalize_status((string)($event["status"] ?? "draft"));
  $start = trim((string)($event["start_date"] ?? ""));
  $end = trim((string)($event["end_date"] ?? ""));
  if ($start === "") return $status === "published" ? "unscheduled" : "draft";

  $today = new DateTimeImmutable("today");
  $startDate = DateTimeImmutable::createFromFormat("Y-m-d", $start) ?: null;
  $endDate = $end !== "" ? (DateTimeImmutable::createFromFormat("Y-m-d", $end) ?: null) : null;
  $finalEnd = $endDate ?: $startDate;
  if (!$startDate || !$finalEnd) return $status === "published" ? "unscheduled" : "draft";

  if ($status !== "published") return $status;
  if ($finalEnd < $today) return "past";
  if ($startDate > $today) return "upcoming";
  if ($startDate == $today && $finalEnd == $today) return "today";
  return "ongoing";
}

function events_format_date_label(array $event): string {
  $start = trim((string)($event["start_date"] ?? ""));
  $end = trim((string)($event["end_date"] ?? ""));
  if ($start === "") return "Date TBA";

  $startTs = strtotime($start);
  if ($startTs === false) return "Date TBA";
  $label = date("M d, Y", $startTs);

  if ($end !== "" && $end !== $start) {
    $endTs = strtotime($end);
    if ($endTs !== false) {
      $label .= " to " . date("M d, Y", $endTs);
    }
  }

  return $label;
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
    if ($displayLocation === "") $resolved["displayLocation"] = $buildingName;

    $present = (string)($building["is_present_in_latest"] ?? "1") !== "0";
    $sourceModel = trim((string)($building["source_model_file"] ?? ""));
    if (!$present || !events_is_model_match($sourceModel, $publicModel)) {
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "The linked building is not available in the current public map.";
      return $resolved;
    }

    $routeKey = events_normalize_lookup($buildingName);
    $resolved["canMap"] = true;
    $resolved["canRoute"] = $routeKey !== "" && isset($routeKeys[$routeKey]);
    $resolved["message"] = $resolved["canRoute"]
      ? "Directions are available for this building."
      : "This building can open on the map, but no published route is available right now.";
    if (!$resolved["canRoute"]) {
      $resolved["health"] = "limited";
      $resolved["healthLabel"] = events_health_labels()["limited"];
    }
    $resolved["resolvedTarget"] = [
      "type" => "building",
      "buildingId" => (int)$building["building_id"],
      "buildingName" => $buildingName,
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
    if ($displayLocation === "") {
      $resolved["displayLocation"] = trim($buildingName . ($roomName !== "" ? " - " . $roomName : ""));
    }

    $roomPresent = (string)($room["room_present"] ?? "1") !== "0";
    $buildingPresent = (string)($room["building_present"] ?? "1") !== "0";
    $sourceModel = trim((string)($room["source_model_file"] ?? ""));
    if (!$roomPresent || !$buildingPresent || !events_is_model_match($sourceModel, $publicModel)) {
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "The linked room is not available in the current public map.";
      return $resolved;
    }

    $routeKey = events_normalize_lookup($buildingName);
    $resolved["canMap"] = true;
    $resolved["canRoute"] = $routeKey !== "" && isset($routeKeys[$routeKey]);
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
      "buildingName" => $buildingName,
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
      }

      $routeKey = events_normalize_lookup($targetBuildingName);
      $resolved["canRoute"] = $routeKey !== "" && isset($routeKeys[$routeKey]);
      if ($resolved["canRoute"]) {
        $resolved["message"] = $targetType === "room"
          ? "Directions are available through the facility's linked room."
          : "Directions are available through the facility's linked building.";
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
    if (!is_numeric($x) || !is_numeric($y) || !is_numeric($z)) {
      $resolved["health"] = "broken";
      $resolved["healthLabel"] = events_health_labels()["broken"];
      $resolved["message"] = "The saved map area is incomplete.";
      return $resolved;
    }
    if ($displayLocation === "") {
      $resolved["displayLocation"] = "Event area";
    }
    if ($modelFile !== "" && !events_is_model_match($modelFile, $publicModel)) {
      $resolved["health"] = "needs_review";
      $resolved["healthLabel"] = events_health_labels()["needs_review"];
      $resolved["message"] = "This event area was pinned on a different map version and needs review.";
      return $resolved;
    }

    $resolved["canMap"] = true;
    $resolved["canRoute"] = false;
    $resolved["health"] = "valid";
    $resolved["healthLabel"] = events_health_labels()["valid"];
    $resolved["message"] = "This event will open as a map highlight only.";
    $resolved["resolvedTarget"] = [
      "type" => "specific_area",
      "x" => (float)$x,
      "y" => (float)$y,
      "z" => (float)$z,
      "radius" => (is_numeric($radius) && (float)$radius > 0) ? (float)$radius : 8.0,
      "modelFile" => $modelFile,
    ];
    return $resolved;
  }

  $resolved["health"] = "broken";
  $resolved["healthLabel"] = events_health_labels()["broken"];
  $resolved["message"] = "The location mode is invalid.";
  return $resolved;
}

function events_load_building_options(mysqli $conn, string $publicModel): array {
  $hasPresent = events_has_column($conn, "buildings", "is_present_in_latest");
  $hasSource = events_has_column($conn, "buildings", "source_model_file");
  $sql = "SELECT building_id, building_name, description, image_path, "
    . ($hasSource ? "source_model_file" : "NULL AS source_model_file")
    . " FROM buildings WHERE building_name IS NOT NULL AND building_name <> ''";
  if ($hasPresent) {
    $sql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
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
    $sql .= " WHERE status = 'published' AND (COALESCE(end_date, start_date) IS NULL OR COALESCE(end_date, start_date) >= CURDATE())";
  }
  $sql .= " ORDER BY
    CASE
      WHEN start_date IS NULL THEN 3
      WHEN start_date = CURDATE() THEN 0
      WHEN start_date > CURDATE() THEN 1
      ELSE 2
    END ASC,
    start_date ASC,
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
