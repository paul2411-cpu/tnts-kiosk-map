<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/db.php";
require_once $ROOT . "/admin/inc/map_sync.php";
require_once $ROOT . "/admin/inc/map_entities.php";
app_logger_set_default_subsystem("api.map_search");

function has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

$requestedModel = map_sync_sanitize_glb_name($_GET["model"] ?? "");
$state = map_sync_resolve_public_model($ROOT);
$currentModel = $requestedModel ?: (string)($state["modelFile"] ?? "");

$buildings = [];
$rooms = [];
$warnings = [];

try {
  $seenBuildings = [];
  foreach (map_entities_fetch_model_destinations($conn, $currentModel, true) as $row) {
    $name = trim((string)($row["name"] ?? ""));
    if ($name === "") continue;
    $objectName = trim((string)($row["objectName"] ?? ""));
    $entityType = trim((string)($row["entityType"] ?? "building")) ?: "building";
    $key = mb_strtolower($entityType . "|" . ($objectName !== "" ? $objectName : $name));
    if (isset($seenBuildings[$key])) continue;
    $seenBuildings[$key] = true;

    $buildings[] = [
      "id" => isset($row["id"]) ? (int)$row["id"] : null,
      "buildingUid" => trim((string)($row["buildingUid"] ?? "")),
      "name" => $name,
      "objectName" => $objectName !== "" ? $objectName : $name,
      "entityType" => $entityType,
      "description" => trim((string)($row["description"] ?? "")),
      "imagePath" => trim((string)($row["imagePath"] ?? "")),
      "modelFile" => trim((string)($row["modelFile"] ?? "")),
      "location" => trim((string)($row["location"] ?? "")),
      "contactInfo" => trim((string)($row["contactInfo"] ?? ""))
    ];
  }

  $hasRoomPresent = has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomSource = has_column($conn, "rooms", "source_model_file");
  $hasRoomBuildingId = has_column($conn, "rooms", "building_id");
  $hasRoomBuildingName = has_column($conn, "rooms", "building_name");
  $hasRoomId = has_column($conn, "rooms", "room_id");
  $hasRoomNumber = has_column($conn, "rooms", "room_number");
  $hasRoomType = has_column($conn, "rooms", "room_type");
  $hasFloor = has_column($conn, "rooms", "floor_number");
  $hasDescription = has_column($conn, "rooms", "description");
  $hasIndoorGuideText = has_column($conn, "rooms", "indoor_guide_text");
  $hasRoomEdited = has_column($conn, "rooms", "last_edited_at");

  $roomBuildingExpr = $hasRoomBuildingId
    ? "COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ")"
    : ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL");

  $roomSql = "SELECT "
    . ($hasRoomId ? "r.room_id" : "NULL AS room_id")
    . ", r.room_name, {$roomBuildingExpr} AS building_name"
    . ", " . ($hasRoomNumber ? "r.room_number" : "NULL AS room_number")
    . ", " . ($hasRoomType ? "r.room_type" : "NULL AS room_type")
    . ", " . ($hasFloor ? "r.floor_number" : "NULL AS floor_number")
    . ", " . ($hasDescription ? "r.description" : "NULL AS description")
    . ", " . ($hasIndoorGuideText ? "r.indoor_guide_text" : "NULL AS indoor_guide_text")
    . ($hasRoomSource ? ", r.source_model_file" : ", NULL AS source_model_file")
    . " FROM rooms r "
    . ($hasRoomBuildingId ? "LEFT JOIN buildings b ON b.building_id = r.building_id " : "")
    . "WHERE r.room_name IS NOT NULL AND r.room_name <> ''";
  if ($hasRoomPresent) {
    $roomSql .= " AND (r.is_present_in_latest = 1 OR r.is_present_in_latest IS NULL)";
  }
  if ($hasRoomBuildingId && $hasBuildingPresent) {
    $roomSql .= " AND (b.building_id IS NULL OR b.is_present_in_latest = 1 OR b.is_present_in_latest IS NULL)";
  }

  $roomOrderParts = ["building_name ASC", "r.room_name ASC"];
  if ($hasIndoorGuideText) $roomOrderParts[] = "(CASE WHEN r.indoor_guide_text IS NULL OR TRIM(r.indoor_guide_text) = '' THEN 1 ELSE 0 END) ASC";
  if ($hasRoomNumber) $roomOrderParts[] = "(CASE WHEN r.room_number IS NULL OR TRIM(r.room_number) = '' THEN 1 ELSE 0 END) ASC";
  if ($hasFloor) $roomOrderParts[] = "(CASE WHEN r.floor_number IS NULL OR TRIM(r.floor_number) = '' THEN 1 ELSE 0 END) ASC";
  if ($hasRoomEdited) $roomOrderParts[] = "r.last_edited_at DESC";
  if ($hasRoomId) $roomOrderParts[] = "r.room_id DESC";
  $roomOrderSql = " ORDER BY " . implode(", ", $roomOrderParts);

  $roomRows = [];
  if ($currentModel !== "" && $hasRoomSource) {
    $stmt = $conn->prepare($roomSql . " AND r.source_model_file = ?" . $roomOrderSql);
    if (!$stmt) throw new RuntimeException("Failed to prepare rooms query");
    $stmt->bind_param("s", $currentModel);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load rooms");
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $roomRows[] = $row;
    }
    $stmt->close();
  } else {
    $res = $conn->query($roomSql . $roomOrderSql);
    if (!($res instanceof mysqli_result)) throw new RuntimeException("Failed to load rooms");
    while ($row = $res->fetch_assoc()) $roomRows[] = $row;
  }

  $seenRooms = [];
  foreach ($roomRows as $row) {
    $roomName = trim((string)($row["room_name"] ?? ""));
    $buildingName = trim((string)($row["building_name"] ?? ""));
    if ($roomName === "" || $buildingName === "") continue;

    $dedupeKey = strtolower($buildingName . "|" . $roomName);
    if (isset($seenRooms[$dedupeKey])) continue;
    $seenRooms[$dedupeKey] = true;

    $rooms[] = [
      "id" => isset($row["room_id"]) ? (int)$row["room_id"] : null,
      "roomName" => $roomName,
      "buildingName" => $buildingName,
      "roomNumber" => trim((string)($row["room_number"] ?? "")),
      "roomType" => trim((string)($row["room_type"] ?? "")),
      "floorNumber" => trim((string)($row["floor_number"] ?? "")),
      "description" => trim((string)($row["description"] ?? "")),
      "indoorGuideText" => trim((string)($row["indoor_guide_text"] ?? "")),
      "modelFile" => trim((string)($row["source_model_file"] ?? ""))
    ];
  }
} catch (Throwable $e) {
  app_log_exception($e, [
    "requestedModel" => $requestedModel,
    "resolvedModel" => $currentModel,
  ], [
    "subsystem" => "api.map_search",
    "event" => "search_failed",
    "message" => "Search index failed",
  ]);
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "Search index failed: " . $e->getMessage()
  ], JSON_PRETTY_PRINT);
  exit;
}

$payload = [
  "ok" => true,
  "modelFile" => $currentModel,
  "buildings" => $buildings,
  "rooms" => $rooms
];
if ($warnings) {
  $payload["warnings"] = $warnings;
}

echo json_encode($payload, JSON_PRETTY_PRINT);
