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

$requestedModel = map_sync_sanitize_glb_name($_GET["model"] ?? "");
$state = map_sync_resolve_public_model($ROOT);
$currentModel = $requestedModel ?: (string)($state["modelFile"] ?? "");

$buildings = [];
$rooms = [];
$warnings = [];

try {
  $hasBuildingId = has_column($conn, "buildings", "building_id");
  $hasBuildingPresent = has_column($conn, "buildings", "is_present_in_latest");
  $hasBuildingSource = has_column($conn, "buildings", "source_model_file");
  $hasBuildingDescription = has_column($conn, "buildings", "description");
  $hasBuildingImage = has_column($conn, "buildings", "image_path");

  $buildingSql = "SELECT "
    . ($hasBuildingId ? "building_id" : "NULL AS building_id")
    . ", building_name, "
    . ($hasBuildingDescription ? "description" : "NULL AS description")
    . ", " . ($hasBuildingImage ? "image_path" : "NULL AS image_path")
    . ", " . ($hasBuildingSource ? "source_model_file" : "NULL AS source_model_file")
    . " FROM buildings WHERE building_name IS NOT NULL AND building_name <> ''";
  if ($hasBuildingPresent) {
    $buildingSql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }

  $buildingRows = [];
  if ($currentModel !== "" && $hasBuildingSource) {
    $stmt = $conn->prepare($buildingSql . " AND source_model_file = ? ORDER BY building_name ASC");
    if (!$stmt) throw new RuntimeException("Failed to prepare buildings query");
    $stmt->bind_param("s", $currentModel);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load buildings");
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $buildingRows[] = $row;
    }
    $stmt->close();
  } else {
    $res = $conn->query($buildingSql . " ORDER BY building_name ASC");
    if (!($res instanceof mysqli_result)) throw new RuntimeException("Failed to load buildings");
    while ($row = $res->fetch_assoc()) $buildingRows[] = $row;
  }

  $seenBuildings = [];
  foreach ($buildingRows as $row) {
    $name = trim((string)($row["building_name"] ?? ""));
    if ($name === "") continue;
    $key = mb_strtolower($name);
    if (isset($seenBuildings[$key])) continue;
    $seenBuildings[$key] = true;

    $buildings[] = [
      "id" => isset($row["building_id"]) ? (int)$row["building_id"] : null,
      "name" => $name,
      "description" => trim((string)($row["description"] ?? "")),
      "imagePath" => trim((string)($row["image_path"] ?? "")),
      "modelFile" => trim((string)($row["source_model_file"] ?? ""))
    ];
  }

  $hasRoomPresent = has_column($conn, "rooms", "is_present_in_latest");
  $hasRoomSource = has_column($conn, "rooms", "source_model_file");
  $hasRoomBuildingId = has_column($conn, "rooms", "building_id");
  $hasRoomBuildingName = has_column($conn, "rooms", "building_name");

  $roomBuildingExpr = $hasRoomBuildingId
    ? "COALESCE(NULLIF(TRIM(b.building_name), ''), " . ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL") . ")"
    : ($hasRoomBuildingName ? "NULLIF(TRIM(r.building_name), '')" : "NULL");

  $roomSql = "SELECT r.room_name, {$roomBuildingExpr} AS building_name"
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

  $roomRows = [];
  if ($currentModel !== "" && $hasRoomSource) {
    $stmt = $conn->prepare($roomSql . " AND r.source_model_file = ? ORDER BY building_name ASC, r.room_name ASC");
    if (!$stmt) throw new RuntimeException("Failed to prepare rooms query");
    $stmt->bind_param("s", $currentModel);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load rooms");
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $roomRows[] = $row;
    }
    $stmt->close();
  } else {
    $res = $conn->query($roomSql . " ORDER BY building_name ASC, r.room_name ASC");
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
      "roomName" => $roomName,
      "buildingName" => $buildingName,
      "modelFile" => trim((string)($row["source_model_file"] ?? ""))
    ];
  }
} catch (Throwable $e) {
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
