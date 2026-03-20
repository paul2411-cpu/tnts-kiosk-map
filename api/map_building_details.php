<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/db.php";
require_once $ROOT . "/admin/inc/map_sync.php";

function has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function fetch_building(mysqli $conn, string $modelFile, ?int $buildingId, string $buildingName): ?array {
  $hasBuildingId = has_column($conn, "buildings", "building_id");
  $hasBuildingPresent = has_column($conn, "buildings", "is_present_in_latest");
  $hasBuildingSource = has_column($conn, "buildings", "source_model_file");
  $hasDescription = has_column($conn, "buildings", "description");
  $hasImage = has_column($conn, "buildings", "image_path");

  $sqlCols = ($hasBuildingId ? "building_id" : "NULL AS building_id")
    . ", building_name"
    . ", " . ($hasDescription ? "description" : "NULL AS description")
    . ", " . ($hasImage ? "image_path" : "NULL AS image_path")
    . ", " . ($hasBuildingSource ? "source_model_file" : "NULL AS source_model_file");

  $wherePresent = $hasBuildingPresent ? " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)" : "";

  if ($buildingId !== null && $hasBuildingId) {
    if ($modelFile !== "" && $hasBuildingSource) {
      $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE building_id = ? AND source_model_file = ?{$wherePresent} LIMIT 1");
      if (!$stmt) throw new RuntimeException("Failed to prepare building lookup");
      $stmt->bind_param("is", $buildingId, $modelFile);
      if (!$stmt->execute()) throw new RuntimeException("Failed to load building");
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row) return $row;
      return null;
    }

    $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE building_id = ?{$wherePresent} LIMIT 1");
    if (!$stmt) throw new RuntimeException("Failed to prepare building fallback lookup");
    $stmt->bind_param("i", $buildingId);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load building");
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return $row;
  }

  if ($buildingName !== "") {
    if ($modelFile !== "" && $hasBuildingSource) {
      $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE building_name = ? AND source_model_file = ?{$wherePresent} ORDER BY " . ($hasBuildingId ? "building_id DESC" : "building_name ASC") . " LIMIT 1");
      if (!$stmt) throw new RuntimeException("Failed to prepare building-name lookup");
      $stmt->bind_param("ss", $buildingName, $modelFile);
      if (!$stmt->execute()) throw new RuntimeException("Failed to load building");
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row) return $row;
      return null;
    }

    $stmt = $conn->prepare("SELECT {$sqlCols} FROM buildings WHERE building_name = ?{$wherePresent} ORDER BY " . ($hasBuildingId ? "building_id DESC" : "building_name ASC") . " LIMIT 1");
    if (!$stmt) throw new RuntimeException("Failed to prepare building-name fallback lookup");
    $stmt->bind_param("s", $buildingName);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load building");
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return $row;
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

  $resolvedBuildingId = isset($building["building_id"]) ? (int)$building["building_id"] : null;
  $resolvedBuildingName = trim((string)($building["building_name"] ?? $buildingName));
  $rooms = fetch_rooms_for_building($conn, $currentModel, $resolvedBuildingId, $resolvedBuildingName);

  echo json_encode([
    "ok" => true,
    "found" => true,
    "modelFile" => $currentModel,
    "building" => [
      "id" => $resolvedBuildingId,
      "name" => $resolvedBuildingName,
      "description" => trim((string)($building["description"] ?? "")),
      "imagePath" => trim((string)($building["image_path"] ?? "")),
      "modelFile" => trim((string)($building["source_model_file"] ?? ""))
    ],
    "rooms" => array_map(static function(array $row): array {
      return [
        "id" => isset($row["room_id"]) ? (int)$row["room_id"] : null,
        "name" => trim((string)($row["room_name"] ?? "")),
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
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "Building details failed: " . $e->getMessage()
  ], JSON_PRETTY_PRINT);
}
