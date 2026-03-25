<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/db.php";
require_once $ROOT . "/admin/inc/map_sync.php";
require_once $ROOT . "/admin/inc/map_entities.php";
require_once $ROOT . "/admin/inc/events.php";
app_logger_set_default_subsystem("api.map_building_details");

function has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function natural_room_field_compare($left, $right): int {
  $a = trim((string)$left);
  $b = trim((string)$right);
  if ($a === $b) return 0;
  if ($a === "") return 1;
  if ($b === "") return -1;

  if (is_numeric($a) && is_numeric($b)) {
    $cmp = (float)$a <=> (float)$b;
    if ($cmp !== 0) return $cmp;
  }

  return strnatcasecmp($a, $b);
}

function map_building_details_route_target($target): ?array {
  if (!is_array($target)) return null;
  $buildingName = trim((string)($target["buildingName"] ?? ""));
  if ($buildingName === "") return null;
  return [
    "type" => trim((string)($target["type"] ?? "building")) ?: "building",
    "buildingId" => isset($target["buildingId"]) ? (int)$target["buildingId"] : null,
    "buildingUid" => trim((string)($target["buildingUid"] ?? "")),
    "buildingName" => $buildingName,
    "objectName" => trim((string)($target["objectName"] ?? "")),
    "roomId" => isset($target["roomId"]) ? (int)$target["roomId"] : null,
    "roomName" => trim((string)($target["roomName"] ?? "")),
    "roomNumber" => trim((string)($target["roomNumber"] ?? "")),
  ];
}

function sort_room_rows(array &$rows): void {
  usort($rows, static function(array $a, array $b): int {
    $cmp = natural_room_field_compare($a["floor_number"] ?? "", $b["floor_number"] ?? "");
    if ($cmp !== 0) return $cmp;

    $cmp = natural_room_field_compare($a["room_number"] ?? "", $b["room_number"] ?? "");
    if ($cmp !== 0) return $cmp;

    $cmp = natural_room_field_compare($a["room_name"] ?? "", $b["room_name"] ?? "");
    if ($cmp !== 0) return $cmp;

    $aGuideEmpty = trim((string)($a["indoor_guide_text"] ?? "")) === "" ? 1 : 0;
    $bGuideEmpty = trim((string)($b["indoor_guide_text"] ?? "")) === "" ? 1 : 0;
    if ($aGuideEmpty !== $bGuideEmpty) return $aGuideEmpty <=> $bGuideEmpty;

    $editedA = trim((string)($a["last_edited_at"] ?? ""));
    $editedB = trim((string)($b["last_edited_at"] ?? ""));
    if ($editedA !== $editedB) return $editedB <=> $editedA;

    return (int)($b["room_id"] ?? 0) <=> (int)($a["room_id"] ?? 0);
  });
}

function fetch_building(mysqli $conn, string $modelFile, ?int $buildingId, string $buildingName): ?array {
  $destinations = map_entities_fetch_model_destinations($conn, $modelFile, true);
  foreach ($destinations as $row) {
    $rowId = isset($row["id"]) ? (int)$row["id"] : null;
    $name = trim((string)($row["name"] ?? ""));
    $objectName = trim((string)($row["objectName"] ?? ""));
    if ($buildingId !== null && $rowId !== null && $rowId === $buildingId) {
      return $row;
    }
    if ($buildingName !== "" && ($buildingName === $name || $buildingName === $objectName)) {
      return $row;
    }
  }
  return null;
}

function fetch_rooms_for_building(mysqli $conn, string $modelFile, ?int $buildingId, string $buildingName): array {
  $hasRoomId = has_column($conn, "rooms", "room_id");
  $hasRoomPresent = has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomSource = has_column($conn, "rooms", "source_model_file");
  $hasRoomBuildingId = has_column($conn, "rooms", "building_id");
  $hasRoomBuildingName = has_column($conn, "rooms", "building_name");
  $hasRoomNumber = has_column($conn, "rooms", "room_number");
  $hasRoomType = has_column($conn, "rooms", "room_type");
  $hasRoomObjectName = has_column($conn, "rooms", "model_object_name");
  $hasFloor = has_column($conn, "rooms", "floor_number");
  $hasDescription = has_column($conn, "rooms", "description");
  $hasIndoorGuideText = has_column($conn, "rooms", "indoor_guide_text");
  $hasEdited = has_column($conn, "rooms", "last_edited_at");

  $where = " WHERE r.room_name IS NOT NULL AND r.room_name <> ''";
  if ($hasRoomPresent) $where .= " AND (r.is_present_in_latest = 1 OR r.is_present_in_latest IS NULL)";

  $params = [];
  $types = "";
  if ($buildingId !== null && $hasRoomBuildingId) {
    $where .= " AND r.building_id = ?";
    $params[] = $buildingId;
    $types .= "i";
  } elseif ($buildingName !== "" && $hasRoomBuildingName) {
    $where .= " AND r.building_name = ?";
    $params[] = $buildingName;
    $types .= "s";
  } else {
    return [];
  }

  if ($modelFile !== "" && $hasRoomSource) {
    $where .= " AND r.source_model_file = ?";
    $params[] = $modelFile;
    $types .= "s";
  }

  $sql = "SELECT "
    . ($hasRoomId ? "r.room_id" : "NULL AS room_id")
    . ", r.room_name"
    . ", " . ($hasRoomObjectName ? "r.model_object_name" : "NULL AS model_object_name")
    . ", " . ($hasRoomNumber ? "r.room_number" : "NULL AS room_number")
    . ", " . ($hasRoomType ? "r.room_type" : "NULL AS room_type")
    . ", " . ($hasFloor ? "r.floor_number" : "NULL AS floor_number")
    . ", " . ($hasDescription ? "r.description" : "NULL AS description")
    . ", " . ($hasIndoorGuideText ? "r.indoor_guide_text" : "NULL AS indoor_guide_text")
    . ", " . ($hasRoomSource ? "r.source_model_file" : "NULL AS source_model_file")
    . " FROM rooms r"
    . $where;

  $orderParts = [];
  if ($hasFloor) $orderParts[] = "r.floor_number ASC";
  if ($hasRoomNumber) $orderParts[] = "r.room_number ASC";
  $orderParts[] = "r.room_name ASC";
  if ($hasIndoorGuideText) $orderParts[] = "(CASE WHEN r.indoor_guide_text IS NULL OR TRIM(r.indoor_guide_text) = '' THEN 1 ELSE 0 END) ASC";
  if ($hasEdited) $orderParts[] = "r.last_edited_at DESC";
  if ($hasRoomId) $orderParts[] = "r.room_id DESC";
  $sql .= " ORDER BY " . implode(", ", $orderParts);

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new RuntimeException("Failed to prepare rooms lookup");

  if ($types !== "") {
    $stmt->bind_param($types, ...$params);
  }
  if (!$stmt->execute()) throw new RuntimeException("Failed to load rooms");
  $res = $stmt->get_result();
  $rows = [];
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  $stmt->close();
  $deduped = [];
  $seen = [];
  foreach ($rows as $row) {
    $roomName = trim((string)($row["room_name"] ?? ""));
    if ($roomName === "") continue;
    $dedupeKey = mb_strtolower($roomName . "|" . trim((string)($row["room_number"] ?? "")) . "|" . trim((string)($row["floor_number"] ?? "")));
    if (isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;
    $deduped[] = $row;
  }
  sort_room_rows($deduped);
  return $deduped;
}

$requestedModel = map_sync_sanitize_glb_name($_GET["model"] ?? "");
$state = map_sync_resolve_public_model($ROOT);
$currentModel = $requestedModel ?: (string)($state["modelFile"] ?? "");
$buildingId = isset($_GET["buildingId"]) && is_numeric($_GET["buildingId"]) ? (int)$_GET["buildingId"] : null;
$buildingName = trim((string)($_GET["name"] ?? ""));

if ($buildingId === null && $buildingName === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing building identifier"], JSON_PRETTY_PRINT);
  exit;
}

try {
  $building = fetch_building($conn, $currentModel, $buildingId, $buildingName);
  if (!$building) {
    echo json_encode([
      "ok" => true,
      "found" => false,
      "modelFile" => $currentModel,
      "building" => null,
      "rooms" => []
    ], JSON_PRETTY_PRINT);
    exit;
  }

  $resolvedBuildingId = isset($building["id"]) ? (int)$building["id"] : (isset($building["building_id"]) ? (int)$building["building_id"] : null);
  $resolvedBuildingName = trim((string)($building["name"] ?? $building["building_name"] ?? $buildingName));
  $entityType = trim((string)($building["entityType"] ?? "building")) ?: "building";
  $rooms = [];
  $routeTarget = null;
  if ($entityType === "facility" && $resolvedBuildingId !== null && $resolvedBuildingId > 0) {
    $facilityResolution = events_resolve_facility_target($conn, $resolvedBuildingId, $currentModel);
    $routeTarget = map_building_details_route_target($facilityResolution["resolvedTarget"] ?? null);
    if (($routeTarget["type"] ?? "") === "room" && trim((string)($routeTarget["roomName"] ?? "")) !== "") {
      $rooms[] = [
        "room_id" => isset($routeTarget["roomId"]) ? (int)$routeTarget["roomId"] : null,
        "room_name" => trim((string)($routeTarget["roomName"] ?? "")),
        "model_object_name" => "",
        "room_number" => trim((string)($routeTarget["roomNumber"] ?? "")),
        "room_type" => "Linked room",
        "floor_number" => "",
        "description" => "Directions for this facility will continue through this linked room.",
        "indoor_guide_text" => "",
        "source_model_file" => $currentModel,
        "building_name" => trim((string)($routeTarget["buildingName"] ?? "")),
      ];
    }
  } else {
    $rooms = fetch_rooms_for_building($conn, $currentModel, $resolvedBuildingId, $resolvedBuildingName);
  }

  echo json_encode([
    "ok" => true,
    "found" => true,
    "modelFile" => $currentModel,
      "building" => [
        "id" => $resolvedBuildingId,
      "buildingUid" => trim((string)($building["buildingUid"] ?? $building["building_uid"] ?? "")),
        "name" => $resolvedBuildingName,
      "objectName" => trim((string)($building["objectName"] ?? $building["model_object_name"] ?? $resolvedBuildingName)),
      "entityType" => $entityType,
      "description" => trim((string)($building["description"] ?? "")),
      "imagePath" => trim((string)($building["imagePath"] ?? $building["image_path"] ?? "")),
      "modelFile" => trim((string)($building["modelFile"] ?? $building["source_model_file"] ?? "")),
      "location" => trim((string)($building["location"] ?? "")),
      "contactInfo" => trim((string)($building["contactInfo"] ?? "")),
      "routeTarget" => $routeTarget,
    ],
    "rooms" => array_map(static function(array $row): array {
      return [
        "id" => isset($row["room_id"]) ? (int)$row["room_id"] : null,
        "name" => trim((string)($row["room_name"] ?? "")),
        "buildingName" => trim((string)($row["building_name"] ?? "")),
        "objectName" => trim((string)($row["model_object_name"] ?? "")),
        "roomNumber" => trim((string)($row["room_number"] ?? "")),
        "roomType" => trim((string)($row["room_type"] ?? "")),
        "floorNumber" => trim((string)($row["floor_number"] ?? "")),
        "description" => trim((string)($row["description"] ?? "")),
        "indoorGuideText" => trim((string)($row["indoor_guide_text"] ?? "")),
        "modelFile" => trim((string)($row["source_model_file"] ?? ""))
      ];
    }, $rooms)
  ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
  app_log_exception($e, [
    "requestedModel" => $requestedModel,
    "resolvedModel" => $currentModel,
    "buildingId" => $buildingId,
    "buildingName" => $buildingName,
  ], [
    "subsystem" => "api.map_building_details",
    "event" => "building_details_failed",
    "message" => "Building details failed",
  ]);
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "Building details failed: " . $e->getMessage()
  ], JSON_PRETTY_PRINT);
}
