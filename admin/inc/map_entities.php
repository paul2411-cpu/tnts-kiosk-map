<?php

require_once __DIR__ . "/map_naming.php";
require_once __DIR__ . "/building_identity.php";

function map_entities_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace('`', '``', $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function map_entities_exec_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException($conn->error);
  }
}

function map_entities_ensure_schema(mysqli $conn): void {
  map_identity_ensure_schema($conn);

  if (!map_entities_has_column($conn, 'buildings', 'entity_type')) {
    map_entities_exec_sql($conn, "ALTER TABLE buildings ADD COLUMN entity_type ENUM('building','venue','area','landmark') NOT NULL DEFAULT 'building' AFTER model_object_name");
  }

  if (!map_entities_has_column($conn, 'rooms', 'model_object_name')) {
    map_entities_exec_sql($conn, "ALTER TABLE rooms ADD COLUMN model_object_name VARCHAR(255) NULL AFTER building_uid");
  }

  map_entities_exec_sql($conn, "
    CREATE TABLE IF NOT EXISTS facilities (
      facility_id INT AUTO_INCREMENT PRIMARY KEY,
      facility_name VARCHAR(100) NOT NULL,
      description TEXT NULL,
      logo_path VARCHAR(255) NULL,
      location VARCHAR(100) NULL,
      contact_info VARCHAR(100) NULL,
      date_added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  if (!map_entities_has_column($conn, 'facilities', 'model_object_name')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN model_object_name VARCHAR(255) NULL AFTER facility_name");
  }
  if (!map_entities_has_column($conn, 'facilities', 'source_model_file')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN source_model_file VARCHAR(255) NULL AFTER logo_path");
  }
  if (!map_entities_has_column($conn, 'facilities', 'first_seen_version_id')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN first_seen_version_id INT NULL AFTER source_model_file");
  }
  if (!map_entities_has_column($conn, 'facilities', 'last_seen_version_id')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN last_seen_version_id INT NULL AFTER first_seen_version_id");
  }
  if (!map_entities_has_column($conn, 'facilities', 'is_present_in_latest')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN is_present_in_latest TINYINT(1) NOT NULL DEFAULT 1 AFTER last_seen_version_id");
  }
  if (!map_entities_has_column($conn, 'facilities', 'last_edited_at')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN last_edited_at DATETIME NULL AFTER is_present_in_latest");
  }
  if (!map_entities_has_column($conn, 'facilities', 'last_edited_by_admin_id')) {
    map_entities_exec_sql($conn, "ALTER TABLE facilities ADD COLUMN last_edited_by_admin_id INT NULL AFTER last_edited_at");
  }

  if (map_entities_has_column($conn, 'rooms', 'model_object_name')) {
    map_entities_exec_sql($conn, "
      UPDATE rooms
      SET model_object_name = room_name
      WHERE (model_object_name IS NULL OR TRIM(model_object_name) = '')
        AND room_name IS NOT NULL
        AND TRIM(room_name) <> ''
    ");
  }
  if (map_entities_has_column($conn, 'buildings', 'entity_type')) {
    map_entities_exec_sql($conn, "
      UPDATE buildings
      SET entity_type = 'building'
      WHERE entity_type IS NULL OR TRIM(entity_type) = ''
    ");
  }
  if (map_entities_has_column($conn, 'facilities', 'model_object_name')) {
    map_entities_exec_sql($conn, "
      UPDATE facilities
      SET model_object_name = facility_name
      WHERE (model_object_name IS NULL OR TRIM(model_object_name) = '')
        AND facility_name IS NOT NULL
        AND TRIM(facility_name) <> ''
    ");
  }
}

function map_entities_fetch_model_destinations(mysqli $conn, string $modelFile, bool $presentOnly = true): array {
  map_entities_ensure_schema($conn);

  $rows = [];
  $hasBuildingSource = map_entities_has_column($conn, 'buildings', 'source_model_file');
  $hasBuildingPresent = map_entities_has_column($conn, 'buildings', 'is_present_in_latest');
  $hasBuildingUid = map_entities_has_column($conn, 'buildings', 'building_uid');
  $hasBuildingType = map_entities_has_column($conn, 'buildings', 'entity_type');

  $buildingSql = "SELECT "
    . "building_id, "
    . ($hasBuildingUid ? "building_uid" : "NULL AS building_uid")
    . ", building_name, model_object_name, "
    . ($hasBuildingType ? "entity_type" : "'building' AS entity_type")
    . ", description, image_path, "
    . ($hasBuildingSource ? "source_model_file" : "NULL AS source_model_file")
    . " FROM buildings WHERE building_name IS NOT NULL AND building_name <> ''";
  if ($presentOnly && $hasBuildingPresent) {
    $buildingSql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }
  if ($modelFile !== '' && $hasBuildingSource) {
    $stmt = $conn->prepare($buildingSql . " AND source_model_file = ? ORDER BY building_name ASC");
    if (!$stmt) throw new RuntimeException("Failed to prepare destination lookup");
    $stmt->bind_param('s', $modelFile);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load building destinations");
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        $rows[] = [
          'id' => (int)($row['building_id'] ?? 0),
          'buildingUid' => trim((string)($row['building_uid'] ?? '')),
          'name' => trim((string)($row['building_name'] ?? '')),
          'objectName' => trim((string)($row['model_object_name'] ?? '')),
          'entityType' => trim((string)($row['entity_type'] ?? 'building')) ?: 'building',
          'description' => trim((string)($row['description'] ?? '')),
          'imagePath' => trim((string)($row['image_path'] ?? '')),
          'modelFile' => trim((string)($row['source_model_file'] ?? '')),
          'location' => '',
          'contactInfo' => '',
          'sourceTable' => 'buildings',
        ];
      }
    }
    $stmt->close();
  } else {
    $res = $conn->query($buildingSql . " ORDER BY building_name ASC");
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        $rows[] = [
          'id' => (int)($row['building_id'] ?? 0),
          'buildingUid' => trim((string)($row['building_uid'] ?? '')),
          'name' => trim((string)($row['building_name'] ?? '')),
          'objectName' => trim((string)($row['model_object_name'] ?? '')),
          'entityType' => trim((string)($row['entity_type'] ?? 'building')) ?: 'building',
          'description' => trim((string)($row['description'] ?? '')),
          'imagePath' => trim((string)($row['image_path'] ?? '')),
          'modelFile' => trim((string)($row['source_model_file'] ?? '')),
          'location' => '',
          'contactInfo' => '',
          'sourceTable' => 'buildings',
        ];
      }
    }
  }

  $hasFacilitySource = map_entities_has_column($conn, 'facilities', 'source_model_file');
  $hasFacilityPresent = map_entities_has_column($conn, 'facilities', 'is_present_in_latest');
  $facilitySql = "SELECT facility_id, facility_name, model_object_name, description, logo_path, location, contact_info, "
    . ($hasFacilitySource ? "source_model_file" : "NULL AS source_model_file")
    . " FROM facilities WHERE facility_name IS NOT NULL AND facility_name <> ''";
  if ($presentOnly && $hasFacilityPresent) {
    $facilitySql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }
  if ($modelFile !== '' && $hasFacilitySource) {
    $stmt = $conn->prepare($facilitySql . " AND source_model_file = ? ORDER BY facility_name ASC");
    if (!$stmt) throw new RuntimeException("Failed to prepare facility lookup");
    $stmt->bind_param('s', $modelFile);
    if (!$stmt->execute()) throw new RuntimeException("Failed to load facilities");
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        $rows[] = [
          'id' => (int)($row['facility_id'] ?? 0),
          'buildingUid' => '',
          'name' => trim((string)($row['facility_name'] ?? '')),
          'objectName' => trim((string)($row['model_object_name'] ?? '')),
          'entityType' => 'facility',
          'description' => trim((string)($row['description'] ?? '')),
          'imagePath' => trim((string)($row['logo_path'] ?? '')),
          'modelFile' => trim((string)($row['source_model_file'] ?? '')),
          'location' => trim((string)($row['location'] ?? '')),
          'contactInfo' => trim((string)($row['contact_info'] ?? '')),
          'sourceTable' => 'facilities',
        ];
      }
    }
    $stmt->close();
  } else {
    $res = $conn->query($facilitySql . " ORDER BY facility_name ASC");
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        $rows[] = [
          'id' => (int)($row['facility_id'] ?? 0),
          'buildingUid' => '',
          'name' => trim((string)($row['facility_name'] ?? '')),
          'objectName' => trim((string)($row['model_object_name'] ?? '')),
          'entityType' => 'facility',
          'description' => trim((string)($row['description'] ?? '')),
          'imagePath' => trim((string)($row['logo_path'] ?? '')),
          'modelFile' => trim((string)($row['source_model_file'] ?? '')),
          'location' => trim((string)($row['location'] ?? '')),
          'contactInfo' => trim((string)($row['contact_info'] ?? '')),
          'sourceTable' => 'facilities',
        ];
      }
    }
  }

  $deduped = [];
  $seen = [];
  foreach ($rows as $row) {
    $key = mb_strtolower(trim((string)($row['entityType'] ?? '')) . '|' . trim((string)($row['objectName'] ?? '')) . '|' . trim((string)($row['name'] ?? '')));
    if ($key === '' || isset($seen[$key])) continue;
    $seen[$key] = true;
    $deduped[] = $row;
  }
  usort($deduped, static function(array $a, array $b): int {
    return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
  return $deduped;
}

