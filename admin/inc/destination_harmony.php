<?php

require_once __DIR__ . "/building_identity.php";

function harmony_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException($conn->error);
  }
}

function harmony_table_exists(mysqli $conn, string $table): bool {
  $safeTable = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function harmony_index_exists(mysqli $conn, string $table, string $index): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeIndex = $conn->real_escape_string($index);
  $res = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function harmony_generate_uid(string $prefix): string {
  $safePrefix = preg_replace('/[^a-z0-9_]+/i', '', trim($prefix));
  $safePrefix = is_string($safePrefix) && $safePrefix !== "" ? strtolower($safePrefix) : "uid";
  try {
    return $safePrefix . "_" . bin2hex(random_bytes(8));
  } catch (Throwable $_) {
    return $safePrefix . "_" . dechex((int)(microtime(true) * 1000000)) . "_" . mt_rand(1000, 9999);
  }
}

function harmony_normalize_uid(?string $uid, string $prefix = ""): string {
  $value = map_identity_normalize_uid($uid);
  if ($value === "") return "";
  $safePrefix = preg_replace('/[^a-z0-9_]+/i', '', trim($prefix));
  $safePrefix = is_string($safePrefix) ? strtolower($safePrefix) : "";
  if ($safePrefix !== "" && strpos($value, $safePrefix . "_") !== 0) {
    return $safePrefix . "_" . $value;
  }
  return $value;
}

function harmony_normalize_guide_token(string $value): string {
  $value = trim((string)$value);
  if ($value === "") return "";
  $value = mb_strtolower($value);
  $value = preg_replace('/\s+/', '_', $value);
  return is_string($value) ? $value : "";
}

function harmony_build_guide_key(string $type, array $data = []): string {
  $safeType = harmony_normalize_guide_token($type);
  if ($safeType === "") $safeType = "building";
  $buildingUid = trim((string)($data["buildingUid"] ?? $data["uid"] ?? $data["destinationUid"] ?? ""));
  $buildingName = trim((string)($data["buildingName"] ?? $data["name"] ?? ""));
  $objectName = trim((string)($data["objectName"] ?? $data["modelObjectName"] ?? ""));
  $roomName = trim((string)($data["roomName"] ?? ""));
  $buildingToken = $buildingUid !== ""
    ? "uid_" . harmony_normalize_guide_token($buildingUid)
    : harmony_normalize_guide_token($buildingName !== "" ? $buildingName : $objectName);
  if ($buildingToken === "") return "";
  if ($safeType === "room") {
    return "room::" . $buildingToken . "::" . harmony_normalize_guide_token($roomName);
  }
  return $safeType . "::" . $buildingToken;
}

function harmony_room_identity_key(string $buildingUid, string $roomName, string $objectName = ""): string {
  $uid = harmony_normalize_uid($buildingUid, "bld");
  $token = map_identity_normalize_name($objectName !== "" ? $objectName : $roomName);
  return $uid !== "" && $token !== "" ? $uid . "::" . $token : "";
}

function harmony_facility_identity_key(string $facilityName, string $objectName = ""): string {
  $token = map_identity_normalize_name($objectName !== "" ? $objectName : $facilityName);
  return $token !== "" ? $token : "";
}

function harmony_try_add_unique_index_if_clean(mysqli $conn, string $table, string $indexName, array $columns): void {
  if (harmony_index_exists($conn, $table, $indexName)) return;
  foreach ($columns as $column) {
    if (!map_identity_has_column($conn, $table, $column)) return;
  }

  $safeTable = str_replace("`", "``", $table);
  $safeColumns = array_map(static function(string $column): string {
    return "`" . str_replace("`", "``", $column) . "`";
  }, $columns);
  $whereParts = array_map(static function(string $column): string {
    $safe = "`" . str_replace("`", "``", $column) . "`";
    return "COALESCE(TRIM(CAST({$safe} AS CHAR)), '') <> ''";
  }, $columns);
  $groupSql = implode(", ", $safeColumns);
  $whereSql = implode(" AND ", $whereParts);
  $dupSql = "SELECT 1 FROM `{$safeTable}` WHERE {$whereSql} GROUP BY {$groupSql} HAVING COUNT(*) > 1 LIMIT 1";
  $dupRes = $conn->query($dupSql);
  if (!($dupRes instanceof mysqli_result)) return;
  if ($dupRes->num_rows > 0) return;

  $safeIndex = str_replace("`", "``", $indexName);
  harmony_sql($conn, "ALTER TABLE `{$safeTable}` ADD UNIQUE KEY `{$safeIndex}` ({$groupSql})");
}

function harmony_ensure_room_facility_identity_columns(mysqli $conn): void {
  if (harmony_table_exists($conn, "rooms") && !map_identity_has_column($conn, "rooms", "room_uid")) {
    harmony_sql($conn, "ALTER TABLE rooms ADD COLUMN room_uid VARCHAR(64) NULL AFTER building_uid");
  }
  if (harmony_table_exists($conn, "facilities") && !map_identity_has_column($conn, "facilities", "facility_uid")) {
    harmony_sql($conn, "ALTER TABLE facilities ADD COLUMN facility_uid VARCHAR(64) NULL AFTER facility_id");
  }
}

function harmony_backfill_room_uids(mysqli $conn): void {
  if (!harmony_table_exists($conn, "rooms") || !map_identity_has_column($conn, "rooms", "room_uid")) return;
  $hasBuildingUid = map_identity_has_column($conn, "rooms", "building_uid");
  $hasObjectName = map_identity_has_column($conn, "rooms", "model_object_name");
  $res = $conn->query("
    SELECT room_id,
      room_uid,
      " . ($hasBuildingUid ? "building_uid" : "NULL AS building_uid") . ",
      room_name,
      " . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name") . "
    FROM rooms
    ORDER BY room_id ASC
  ");
  if (!($res instanceof mysqli_result)) {
    throw new RuntimeException("Failed to query rooms for UID backfill");
  }

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }

  $uidByKey = [];
  foreach ($rows as $row) {
    $key = harmony_room_identity_key(
      (string)($row["building_uid"] ?? ""),
      (string)($row["room_name"] ?? ""),
      (string)($row["model_object_name"] ?? "")
    );
    $uid = harmony_normalize_uid((string)($row["room_uid"] ?? ""), "room");
    if ($key === "" || $uid === "" || isset($uidByKey[$key])) continue;
    $uidByKey[$key] = $uid;
  }

  $update = $conn->prepare("UPDATE rooms SET room_uid = ? WHERE room_id = ?");
  if (!$update) throw new RuntimeException("Failed to prepare room UID backfill");
  foreach ($rows as $row) {
    $roomId = (int)($row["room_id"] ?? 0);
    if ($roomId <= 0) continue;
    $key = harmony_room_identity_key(
      (string)($row["building_uid"] ?? ""),
      (string)($row["room_name"] ?? ""),
      (string)($row["model_object_name"] ?? "")
    );
    if ($key === "") continue;
    $uid = harmony_normalize_uid((string)($row["room_uid"] ?? ""), "room");
    if ($uid === "" && isset($uidByKey[$key])) {
      $uid = $uidByKey[$key];
    }
    if ($uid === "") {
      $uid = harmony_generate_uid("room");
      $uidByKey[$key] = $uid;
    }
    $update->bind_param("si", $uid, $roomId);
    if (!$update->execute()) {
      $update->close();
      throw new RuntimeException("Failed to update room UID");
    }
  }
  $update->close();
}

function harmony_backfill_facility_uids(mysqli $conn): void {
  if (!harmony_table_exists($conn, "facilities") || !map_identity_has_column($conn, "facilities", "facility_uid")) return;
  $hasObjectName = map_identity_has_column($conn, "facilities", "model_object_name");
  $res = $conn->query("
    SELECT facility_id,
      facility_uid,
      facility_name,
      " . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name") . "
    FROM facilities
    ORDER BY facility_id ASC
  ");
  if (!($res instanceof mysqli_result)) {
    throw new RuntimeException("Failed to query facilities for UID backfill");
  }

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }

  $uidByKey = [];
  foreach ($rows as $row) {
    $key = harmony_facility_identity_key((string)($row["facility_name"] ?? ""), (string)($row["model_object_name"] ?? ""));
    $uid = harmony_normalize_uid((string)($row["facility_uid"] ?? ""), "fac");
    if ($key === "" || $uid === "" || isset($uidByKey[$key])) continue;
    $uidByKey[$key] = $uid;
  }

  $update = $conn->prepare("UPDATE facilities SET facility_uid = ? WHERE facility_id = ?");
  if (!$update) throw new RuntimeException("Failed to prepare facility UID backfill");
  foreach ($rows as $row) {
    $facilityId = (int)($row["facility_id"] ?? 0);
    if ($facilityId <= 0) continue;
    $key = harmony_facility_identity_key((string)($row["facility_name"] ?? ""), (string)($row["model_object_name"] ?? ""));
    if ($key === "") continue;
    $uid = harmony_normalize_uid((string)($row["facility_uid"] ?? ""), "fac");
    if ($uid === "" && isset($uidByKey[$key])) {
      $uid = $uidByKey[$key];
    }
    if ($uid === "") {
      $uid = harmony_generate_uid("fac");
      $uidByKey[$key] = $uid;
    }
    $update->bind_param("si", $uid, $facilityId);
    if (!$update->execute()) {
      $update->close();
      throw new RuntimeException("Failed to update facility UID");
    }
  }
  $update->close();
}

function harmony_ensure_schema(mysqli $conn): void {
  map_identity_ensure_schema($conn);
  harmony_ensure_room_facility_identity_columns($conn);
  harmony_sql($conn, "
    CREATE TABLE IF NOT EXISTS model_lineage (
      model_file VARCHAR(255) NOT NULL PRIMARY KEY,
      parent_model_file VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  harmony_sql($conn, "
    CREATE TABLE IF NOT EXISTS guide_source_entries (
      source_id INT AUTO_INCREMENT PRIMARY KEY,
      model_file VARCHAR(255) NOT NULL,
      guide_key VARCHAR(255) NOT NULL,
      destination_type VARCHAR(32) NOT NULL,
      guide_kind ENUM('main','supplement') NOT NULL DEFAULT 'main',
      building_uid VARCHAR(64) NULL,
      room_uid VARCHAR(64) NULL,
      facility_uid VARCHAR(64) NULL,
      building_name VARCHAR(255) NOT NULL DEFAULT '',
      room_name VARCHAR(255) NOT NULL DEFAULT '',
      object_name VARCHAR(255) NOT NULL DEFAULT '',
      manual_text TEXT NULL,
      source_route_signature VARCHAR(255) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_by_admin_id INT NULL,
      UNIQUE KEY uniq_guide_source (model_file, guide_key, guide_kind),
      KEY idx_guide_source_model (model_file),
      KEY idx_guide_source_building_uid (building_uid),
      KEY idx_guide_source_room_uid (room_uid),
      KEY idx_guide_source_facility_uid (facility_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  harmony_backfill_room_uids($conn);
  harmony_backfill_facility_uids($conn);
  harmony_try_add_unique_index_if_clean($conn, "buildings", "uniq_buildings_model_uid", ["source_model_file", "building_uid"]);
  harmony_try_add_unique_index_if_clean($conn, "rooms", "uniq_rooms_model_uid", ["source_model_file", "room_uid"]);
  harmony_try_add_unique_index_if_clean($conn, "facilities", "uniq_facilities_model_uid", ["source_model_file", "facility_uid"]);
}

function harmony_get_model_parent(mysqli $conn, string $modelFile): string {
  $safeModel = trim($modelFile);
  if ($safeModel === "") return "";
  $stmt = $conn->prepare("SELECT parent_model_file FROM model_lineage WHERE model_file = ? LIMIT 1");
  if (!$stmt) throw new RuntimeException("Failed to prepare model lineage lookup");
  $stmt->bind_param("s", $safeModel);
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException("Failed to query model lineage");
  }
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return trim((string)($row["parent_model_file"] ?? ""));
}

function harmony_set_model_parent(mysqli $conn, string $modelFile, string $parentModel = ""): void {
  $safeModel = trim($modelFile);
  if ($safeModel === "") return;
  $safeParent = trim($parentModel);
  $stmt = $conn->prepare("
    INSERT INTO model_lineage (model_file, parent_model_file)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE parent_model_file = VALUES(parent_model_file)
  ");
  if (!$stmt) throw new RuntimeException("Failed to prepare model lineage write");
  $stmt->bind_param("ss", $safeModel, $safeParent);
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException("Failed to save model lineage");
  }
  $stmt->close();
}

function harmony_resolve_room_uid(
  mysqli $conn,
  string $buildingUid,
  string $roomName,
  string $objectName = "",
  string $modelFile = "",
  string $parentModel = ""
): string {
  $safeBuildingUid = harmony_normalize_uid($buildingUid, "bld");
  $safeRoomName = trim($roomName);
  $safeObjectName = trim($objectName);
  if ($safeBuildingUid === "" || ($safeRoomName === "" && $safeObjectName === "")) {
    return harmony_generate_uid("room");
  }

  $queries = [];
  if ($modelFile !== "") {
    $queries[] = ["model", $modelFile];
  }
  if ($parentModel !== "" && strcasecmp($parentModel, $modelFile) !== 0) {
    $queries[] = ["model", $parentModel];
  }
  $queries[] = ["global", ""];

  foreach ($queries as [$scope, $scopeModel]) {
    $whereScope = $scope === "model" ? "source_model_file = ? AND " : "";
    $sql = "
      SELECT room_uid
      FROM rooms
      WHERE {$whereScope}building_uid = ?
        AND ((model_object_name = ? AND TRIM(model_object_name) <> '') OR room_name = ?)
        AND room_uid IS NOT NULL
        AND TRIM(room_uid) <> ''
      ORDER BY room_id DESC
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare room UID lookup");
    if ($scope === "model") $stmt->bind_param("ssss", $scopeModel, $safeBuildingUid, $safeObjectName, $safeRoomName);
    else $stmt->bind_param("sss", $safeBuildingUid, $safeObjectName, $safeRoomName);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to query room UID");
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $uid = harmony_normalize_uid((string)($row["room_uid"] ?? ""), "room");
    if ($uid !== "") return $uid;
  }

  return harmony_generate_uid("room");
}

function harmony_resolve_facility_uid(
  mysqli $conn,
  string $facilityName,
  string $objectName = "",
  string $modelFile = "",
  string $parentModel = ""
): string {
  $safeFacilityName = trim($facilityName);
  $safeObjectName = trim($objectName);
  if ($safeFacilityName === "" && $safeObjectName === "") {
    return harmony_generate_uid("fac");
  }

  $queries = [];
  if ($modelFile !== "") $queries[] = ["model", $modelFile];
  if ($parentModel !== "" && strcasecmp($parentModel, $modelFile) !== 0) $queries[] = ["model", $parentModel];
  $queries[] = ["global", ""];

  foreach ($queries as [$scope, $scopeModel]) {
    $whereScope = $scope === "model" ? "source_model_file = ? AND " : "";
    $sql = "
      SELECT facility_uid
      FROM facilities
      WHERE {$whereScope}((model_object_name = ? AND TRIM(model_object_name) <> '') OR facility_name = ?)
        AND facility_uid IS NOT NULL
        AND TRIM(facility_uid) <> ''
      ORDER BY facility_id DESC
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare facility UID lookup");
    if ($scope === "model") $stmt->bind_param("sss", $scopeModel, $safeObjectName, $safeFacilityName);
    else $stmt->bind_param("ss", $safeObjectName, $safeFacilityName);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to query facility UID");
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $uid = harmony_normalize_uid((string)($row["facility_uid"] ?? ""), "fac");
    if ($uid !== "") return $uid;
  }

  return harmony_generate_uid("fac");
}

function harmony_fetch_building_template(
  mysqli $conn,
  string $parentModel,
  string $buildingUid,
  string $objectName,
  string $buildingName
): ?array {
  $safeParent = trim($parentModel);
  $safeUid = harmony_normalize_uid($buildingUid, "bld");
  $safeObject = trim($objectName);
  $safeName = trim($buildingName);
  $attempts = [];
  if ($safeParent !== "") {
    $attempts[] = [
      "SELECT building_uid, building_name, model_object_name, entity_type, description, image_path, last_edited_at, last_edited_by_admin_id
       FROM buildings
       WHERE source_model_file = ?
         AND (
           (building_uid = ? AND TRIM(building_uid) <> '')
           OR (model_object_name = ? AND TRIM(model_object_name) <> '')
           OR building_name = ?
         )
       ORDER BY CASE WHEN building_uid = ? AND TRIM(building_uid) <> '' THEN 0 ELSE 1 END, building_id DESC
       LIMIT 1",
      "sssss",
      [$safeParent, $safeUid, $safeObject, $safeName, $safeUid]
    ];
  }
  if ($safeUid !== "") {
    $attempts[] = [
      "SELECT building_uid, building_name, model_object_name, entity_type, description, image_path, last_edited_at, last_edited_by_admin_id
       FROM buildings
       WHERE building_uid = ?
         AND TRIM(building_uid) <> ''
       ORDER BY building_id DESC
       LIMIT 1",
      "s",
      [$safeUid]
    ];
  }

  foreach ($attempts as [$sql, $types, $params]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare building template lookup");
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to load building template");
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return $row;
  }

  return null;
}

function harmony_fetch_facility_template(
  mysqli $conn,
  string $parentModel,
  string $facilityUid,
  string $objectName,
  string $facilityName
): ?array {
  $safeParent = trim($parentModel);
  $safeUid = harmony_normalize_uid($facilityUid, "fac");
  $safeObject = trim($objectName);
  $safeName = trim($facilityName);
  $attempts = [];
  if ($safeParent !== "") {
    $attempts[] = [
      "SELECT facility_uid, facility_name, model_object_name, description, logo_path, location, contact_info, last_edited_at, last_edited_by_admin_id
       FROM facilities
       WHERE source_model_file = ?
         AND (
           (facility_uid = ? AND TRIM(facility_uid) <> '')
           OR (model_object_name = ? AND TRIM(model_object_name) <> '')
           OR facility_name = ?
         )
       ORDER BY CASE WHEN facility_uid = ? AND TRIM(facility_uid) <> '' THEN 0 ELSE 1 END, facility_id DESC
       LIMIT 1",
      "sssss",
      [$safeParent, $safeUid, $safeObject, $safeName, $safeUid]
    ];
  }
  if ($safeUid !== "") {
    $attempts[] = [
      "SELECT facility_uid, facility_name, model_object_name, description, logo_path, location, contact_info, last_edited_at, last_edited_by_admin_id
       FROM facilities
       WHERE facility_uid = ?
         AND TRIM(facility_uid) <> ''
       ORDER BY facility_id DESC
       LIMIT 1",
      "s",
      [$safeUid]
    ];
  }

  foreach ($attempts as [$sql, $types, $params]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare facility template lookup");
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to load facility template");
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return $row;
  }

  return null;
}

function harmony_fetch_room_template(
  mysqli $conn,
  string $parentModel,
  string $roomUid,
  string $buildingUid,
  string $roomName,
  string $objectName
): ?array {
  $safeParent = trim($parentModel);
  $safeRoomUid = harmony_normalize_uid($roomUid, "room");
  $safeBuildingUid = harmony_normalize_uid($buildingUid, "bld");
  $safeRoomName = trim($roomName);
  $safeObject = trim($objectName);
  $attempts = [];
  if ($safeParent !== "") {
    $attempts[] = [
      "SELECT room_uid, room_name, room_number, room_type, floor_number, description, indoor_guide_text, image_path, model_object_name, last_edited_at, last_edited_by_admin_id
       FROM rooms
       WHERE source_model_file = ?
         AND (
           (room_uid = ? AND TRIM(room_uid) <> '')
           OR (
             building_uid = ?
             AND (
               (model_object_name = ? AND TRIM(model_object_name) <> '')
               OR room_name = ?
             )
           )
         )
       ORDER BY CASE WHEN room_uid = ? AND TRIM(room_uid) <> '' THEN 0 ELSE 1 END, room_id DESC
       LIMIT 1",
      "ssssss",
      [$safeParent, $safeRoomUid, $safeBuildingUid, $safeObject, $safeRoomName, $safeRoomUid]
    ];
  }
  if ($safeRoomUid !== "") {
    $attempts[] = [
      "SELECT room_uid, room_name, room_number, room_type, floor_number, description, indoor_guide_text, image_path, model_object_name, last_edited_at, last_edited_by_admin_id
       FROM rooms
       WHERE room_uid = ?
         AND TRIM(room_uid) <> ''
       ORDER BY room_id DESC
       LIMIT 1",
      "s",
      [$safeRoomUid]
    ];
  }

  foreach ($attempts as [$sql, $types, $params]) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare room template lookup");
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to load room template");
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return $row;
  }

  return null;
}

function harmony_load_guide_source_rows(mysqli $conn, string $modelFile): array {
  $safeModel = trim($modelFile);
  if ($safeModel === "") return [];
  $models = [];
  $visited = [];
  $cursor = $safeModel;
  while ($cursor !== "" && !isset($visited[$cursor]) && count($models) < 10) {
    $visited[$cursor] = true;
    array_unshift($models, $cursor);
    $cursor = harmony_get_model_parent($conn, $cursor);
  }

  $stmt = $conn->prepare("
    SELECT guide_key, destination_type, guide_kind, building_uid, room_uid, facility_uid,
      building_name, room_name, object_name, manual_text, source_route_signature
    FROM guide_source_entries
    WHERE model_file = ?
    ORDER BY guide_key ASC, guide_kind ASC
  ");
  if (!$stmt) throw new RuntimeException("Failed to prepare guide source lookup");

  $merged = [];
  foreach ($models as $model) {
    $stmt->bind_param("s", $model);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException("Failed to query guide source entries");
    }
    $res = $stmt->get_result();
    if (!($res instanceof mysqli_result)) continue;
    while ($row = $res->fetch_assoc()) {
      $identity = trim((string)($row["guide_key"] ?? "")) . "::" . trim((string)($row["guide_kind"] ?? "main"));
      if ($identity === "::") continue;
      $merged[$identity] = $row;
    }
  }
  $stmt->close();
  return array_values($merged);
}

function harmony_upsert_guide_source_row(
  mysqli $conn,
  string $modelFile,
  string $guideKey,
  string $destinationType,
  string $guideKind,
  string $buildingUid,
  string $roomUid,
  string $facilityUid,
  string $buildingName,
  string $roomName,
  string $objectName,
  string $manualText,
  string $sourceRouteSignature,
  ?int $adminId
): void {
  $stmt = $conn->prepare("
    INSERT INTO guide_source_entries (
      model_file, guide_key, destination_type, guide_kind,
      building_uid, room_uid, facility_uid,
      building_name, room_name, object_name,
      manual_text, source_route_signature, updated_at, updated_by_admin_id
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE
      destination_type = VALUES(destination_type),
      building_uid = VALUES(building_uid),
      room_uid = VALUES(room_uid),
      facility_uid = VALUES(facility_uid),
      building_name = VALUES(building_name),
      room_name = VALUES(room_name),
      object_name = VALUES(object_name),
      manual_text = VALUES(manual_text),
      source_route_signature = VALUES(source_route_signature),
      updated_at = NOW(),
      updated_by_admin_id = VALUES(updated_by_admin_id)
  ");
  if (!$stmt) throw new RuntimeException("Failed to prepare guide source write");
  $stmt->bind_param(
    "ssssssssssssi",
    $modelFile,
    $guideKey,
    $destinationType,
    $guideKind,
    $buildingUid,
    $roomUid,
    $facilityUid,
    $buildingName,
    $roomName,
    $objectName,
    $manualText,
    $sourceRouteSignature,
    $adminId
  );
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException("Failed to save guide source entry");
  }
  $stmt->close();
}

function harmony_delete_guide_source_row(mysqli $conn, string $modelFile, string $guideKey, string $guideKind): void {
  $stmt = $conn->prepare("DELETE FROM guide_source_entries WHERE model_file = ? AND guide_key = ? AND guide_kind = ?");
  if (!$stmt) throw new RuntimeException("Failed to prepare guide source delete");
  $stmt->bind_param("sss", $modelFile, $guideKey, $guideKind);
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException("Failed to delete guide source entry");
  }
  $stmt->close();
}

function harmony_persist_guide_source_entries(mysqli $conn, string $modelFile, array $entries, ?int $adminId): void {
  $safeModel = trim($modelFile);
  if ($safeModel === "") return;
  foreach ($entries as $entry) {
    if (!is_array($entry)) continue;
    $guideKey = trim((string)($entry["key"] ?? ""));
    $destinationType = trim((string)($entry["destinationType"] ?? $entry["type"] ?? "building"));
    if ($guideKey === "" || $destinationType === "") continue;

    $buildingUid = harmony_normalize_uid((string)($entry["buildingUid"] ?? ""), "bld");
    $roomUid = harmony_normalize_uid((string)($entry["roomUid"] ?? ""), "room");
    $facilityUid = harmony_normalize_uid((string)($entry["facilityUid"] ?? ""), "fac");
    $buildingName = trim((string)($entry["buildingName"] ?? $entry["name"] ?? ""));
    $roomName = trim((string)($entry["roomName"] ?? ""));
    $objectName = trim((string)($entry["objectName"] ?? $entry["modelObjectName"] ?? ""));
    $manualText = trim((string)($entry["manualText"] ?? ""));
    $sourceRouteSignature = trim((string)($entry["sourceRouteSignature"] ?? ""));

    if ($manualText !== "") {
      harmony_upsert_guide_source_row(
        $conn,
        $safeModel,
        $guideKey,
        $destinationType,
        "main",
        $buildingUid,
        $roomUid,
        $facilityUid,
        $buildingName,
        $roomName,
        $objectName,
        $manualText,
        $sourceRouteSignature,
        $adminId
      );
    } else {
      harmony_delete_guide_source_row($conn, $safeModel, $guideKey, "main");
    }

    if ($destinationType === "room" && array_key_exists("roomSupplementText", $entry)) {
      $supplementText = trim((string)($entry["roomSupplementText"] ?? ""));
      if ($supplementText !== "") {
        harmony_upsert_guide_source_row(
          $conn,
          $safeModel,
          $guideKey,
          $destinationType,
          "supplement",
          $buildingUid,
          $roomUid,
          "",
          $buildingName,
          $roomName,
          $objectName,
          $supplementText,
          "",
          $adminId
        );
      } else {
        harmony_delete_guide_source_row($conn, $safeModel, $guideKey, "supplement");
      }
    }
  }
}

function harmony_merge_guide_source_rows(array $entries, array $sourceRows): array {
  foreach ($sourceRows as $row) {
    if (!is_array($row)) continue;
    $guideKey = trim((string)($row["guide_key"] ?? ""));
    $guideKind = trim((string)($row["guide_kind"] ?? "main"));
    if ($guideKey === "") continue;
    if (!isset($entries[$guideKey]) || !is_array($entries[$guideKey])) {
      $entries[$guideKey] = [
        "key" => $guideKey,
        "destinationType" => trim((string)($row["destination_type"] ?? "building")) ?: "building",
        "buildingName" => trim((string)($row["building_name"] ?? "")),
        "buildingUid" => trim((string)($row["building_uid"] ?? "")),
        "roomName" => trim((string)($row["room_name"] ?? "")),
        "objectName" => trim((string)($row["object_name"] ?? "")),
      ];
      if (!empty($row["room_uid"])) $entries[$guideKey]["roomUid"] = trim((string)$row["room_uid"]);
      if (!empty($row["facility_uid"])) $entries[$guideKey]["facilityUid"] = trim((string)$row["facility_uid"]);
    }
    if ($guideKind === "supplement") {
      $entries[$guideKey]["roomSupplementText"] = trim((string)($row["manual_text"] ?? ""));
      continue;
    }
    $entries[$guideKey]["manualText"] = trim((string)($row["manual_text"] ?? ""));
    $entries[$guideKey]["sourceRouteSignature"] = trim((string)($row["source_route_signature"] ?? ""));
  }
  return $entries;
}

function harmony_sync_room_supplement_guide(
  mysqli $conn,
  string $modelFile,
  string $buildingUid,
  string $roomUid,
  string $buildingName,
  string $roomName,
  string $objectName,
  string $manualText,
  ?int $adminId
): void {
  $guideKey = harmony_build_guide_key("room", [
    "buildingUid" => $buildingUid,
    "buildingName" => $buildingName,
    "objectName" => $objectName,
    "roomName" => $roomName
  ]);
  if ($guideKey === "") return;
  $safeModel = trim($modelFile);
  if ($safeModel === "") return;
  $safeText = trim($manualText);
  if ($safeText === "") {
    harmony_delete_guide_source_row($conn, $safeModel, $guideKey, "supplement");
    return;
  }
  harmony_upsert_guide_source_row(
    $conn,
    $safeModel,
    $guideKey,
    "room",
    "supplement",
    harmony_normalize_uid($buildingUid, "bld"),
    harmony_normalize_uid($roomUid, "room"),
    "",
    trim($buildingName),
    trim($roomName),
    trim($objectName),
    $safeText,
    "",
    $adminId
  );
}

function harmony_diag_count(mysqli $conn, string $sql): int {
  $res = $conn->query($sql);
  if (!($res instanceof mysqli_result)) {
    throw new RuntimeException("Failed to run diagnostics query");
  }
  $row = $res->fetch_assoc();
  return $row ? (int)($row["cnt"] ?? 0) : 0;
}

function harmony_diag_uid_duplicates(
  mysqli $conn,
  string $table,
  string $uidColumn,
  string $modelFile = "",
  int $limit = 20
): array {
  if (!harmony_table_exists($conn, $table)) return [];
  if (!map_identity_has_column($conn, $table, $uidColumn)) return [];
  $hasSourceModel = map_identity_has_column($conn, $table, "source_model_file");

  $safeTable = "`" . str_replace("`", "``", $table) . "`";
  $safeUid = "`" . str_replace("`", "``", $uidColumn) . "`";
  $safeLimit = max(1, min(100, (int)$limit));
  $safeModel = trim($modelFile);
  $escapedModel = $safeModel !== "" ? $conn->real_escape_string($safeModel) : "";

  $where = "COALESCE(TRIM(CAST({$safeUid} AS CHAR)), '') <> ''";
  if ($safeModel !== "" && $hasSourceModel) {
    $where .= " AND source_model_file = '{$escapedModel}'";
  }

  if ($safeModel === "" && $hasSourceModel) {
    $sql = "
      SELECT source_model_file AS model_file, {$safeUid} AS uid_value, COUNT(*) AS duplicate_count
      FROM {$safeTable}
      WHERE {$where}
      GROUP BY source_model_file, {$safeUid}
      HAVING COUNT(*) > 1
      ORDER BY duplicate_count DESC, model_file ASC, uid_value ASC
      LIMIT {$safeLimit}
    ";
  } else {
    $sql = "
      SELECT {$safeUid} AS uid_value, COUNT(*) AS duplicate_count
      FROM {$safeTable}
      WHERE {$where}
      GROUP BY {$safeUid}
      HAVING COUNT(*) > 1
      ORDER BY duplicate_count DESC, uid_value ASC
      LIMIT {$safeLimit}
    ";
  }

  $res = $conn->query($sql);
  if (!($res instanceof mysqli_result)) {
    throw new RuntimeException("Failed to query duplicate UID diagnostics");
  }
  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $item = [
      "uid" => trim((string)($row["uid_value"] ?? "")),
      "count" => (int)($row["duplicate_count"] ?? 0),
    ];
    if (isset($row["model_file"])) {
      $item["modelFile"] = trim((string)($row["model_file"] ?? ""));
    }
    $rows[] = $item;
  }
  return $rows;
}

function harmony_diag_table_count(mysqli $conn, string $table, string $modelFile = ""): int {
  if (!harmony_table_exists($conn, $table)) return 0;
  $safeTable = "`" . str_replace("`", "``", $table) . "`";
  $hasSourceModel = map_identity_has_column($conn, $table, "source_model_file");
  $safeModel = trim($modelFile);
  if ($safeModel !== "" && $hasSourceModel) {
    $escapedModel = $conn->real_escape_string($safeModel);
    return harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM {$safeTable} WHERE source_model_file = '{$escapedModel}'");
  }
  return harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM {$safeTable}");
}

function harmony_diag_missing_uid_count(
  mysqli $conn,
  string $table,
  string $uidColumn,
  string $modelFile = ""
): int {
  if (!harmony_table_exists($conn, $table)) return 0;
  if (!map_identity_has_column($conn, $table, $uidColumn)) return 0;
  $safeTable = "`" . str_replace("`", "``", $table) . "`";
  $safeUid = "`" . str_replace("`", "``", $uidColumn) . "`";
  $hasSourceModel = map_identity_has_column($conn, $table, "source_model_file");
  $safeModel = trim($modelFile);
  $where = "COALESCE(TRIM(CAST({$safeUid} AS CHAR)), '') = ''";
  if ($safeModel !== "" && $hasSourceModel) {
    $escapedModel = $conn->real_escape_string($safeModel);
    $where .= " AND source_model_file = '{$escapedModel}'";
  }
  return harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM {$safeTable} WHERE {$where}");
}

function harmony_collect_health_report(mysqli $conn, string $modelFile = ""): array {
  $safeModel = trim($modelFile);
  $scope = $safeModel !== "" ? "model" : "all_models";
  $lineage = null;
  if ($safeModel !== "") {
    $parentModel = harmony_get_model_parent($conn, $safeModel);
    $safeParent = $conn->real_escape_string($safeModel);
    $childCount = harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM model_lineage WHERE parent_model_file = '{$safeParent}'");
    $lineage = [
      "modelFile" => $safeModel,
      "parentModelFile" => $parentModel,
      "childCount" => $childCount,
    ];
  }

  $buildingsByUid = harmony_diag_uid_duplicates($conn, "buildings", "building_uid", $safeModel, 20);
  $roomsByUid = harmony_diag_uid_duplicates($conn, "rooms", "room_uid", $safeModel, 20);
  $facilitiesByUid = harmony_diag_uid_duplicates($conn, "facilities", "facility_uid", $safeModel, 20);

  $guideWhere = "1=1";
  if ($safeModel !== "") {
    $escapedModel = $conn->real_escape_string($safeModel);
    $guideWhere .= " AND model_file = '{$escapedModel}'";
  }
  $guideMainCount = harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM guide_source_entries WHERE {$guideWhere} AND guide_kind = 'main'");
  $guideSupplementCount = harmony_diag_count($conn, "SELECT COUNT(*) AS cnt FROM guide_source_entries WHERE {$guideWhere} AND guide_kind = 'supplement'");

  return [
    "scope" => $scope,
    "modelFile" => $safeModel,
    "generatedAt" => gmdate("c"),
    "lineage" => $lineage,
    "indexes" => [
      "uniq_buildings_model_uid" => harmony_index_exists($conn, "buildings", "uniq_buildings_model_uid"),
      "uniq_rooms_model_uid" => harmony_index_exists($conn, "rooms", "uniq_rooms_model_uid"),
      "uniq_facilities_model_uid" => harmony_index_exists($conn, "facilities", "uniq_facilities_model_uid"),
      "uniq_guide_source" => harmony_index_exists($conn, "guide_source_entries", "uniq_guide_source"),
    ],
    "counts" => [
      "buildings" => harmony_diag_table_count($conn, "buildings", $safeModel),
      "rooms" => harmony_diag_table_count($conn, "rooms", $safeModel),
      "facilities" => harmony_diag_table_count($conn, "facilities", $safeModel),
      "guideSourceMain" => $guideMainCount,
      "guideSourceSupplement" => $guideSupplementCount,
      "missingBuildingUid" => harmony_diag_missing_uid_count($conn, "buildings", "building_uid", $safeModel),
      "missingRoomUid" => harmony_diag_missing_uid_count($conn, "rooms", "room_uid", $safeModel),
      "missingFacilityUid" => harmony_diag_missing_uid_count($conn, "facilities", "facility_uid", $safeModel),
      "duplicateBuildingUidGroups" => count($buildingsByUid),
      "duplicateRoomUidGroups" => count($roomsByUid),
      "duplicateFacilityUidGroups" => count($facilitiesByUid),
    ],
    "duplicates" => [
      "buildingsByUid" => $buildingsByUid,
      "roomsByUid" => $roomsByUid,
      "facilitiesByUid" => $facilitiesByUid,
    ],
  ];
}
