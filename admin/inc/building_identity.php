<?php

function map_identity_normalize_name(string $name): string {
  $value = preg_replace('/\s+/', ' ', trim($name));
  return mb_strtolower((string)$value);
}

function map_identity_normalize_uid(?string $uid): string {
  $value = mb_strtolower(trim((string)$uid));
  $value = preg_replace('/[^a-z0-9_-]+/', '', $value);
  return is_string($value) ? $value : "";
}

function map_identity_generate_uid(): string {
  try {
    return "bld_" . bin2hex(random_bytes(8));
  } catch (Throwable $_) {
    return "bld_" . dechex((int)(microtime(true) * 1000000)) . "_" . mt_rand(1000, 9999);
  }
}

function map_identity_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function map_identity_exec_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException($conn->error);
  }
}

function map_identity_ensure_columns(mysqli $conn): void {
  if (!map_identity_has_column($conn, "buildings", "building_uid")) {
    map_identity_exec_sql($conn, "ALTER TABLE buildings ADD COLUMN building_uid VARCHAR(64) NULL AFTER building_id");
  }
  if (!map_identity_has_column($conn, "buildings", "model_object_name")) {
    map_identity_exec_sql($conn, "ALTER TABLE buildings ADD COLUMN model_object_name VARCHAR(255) NULL AFTER building_name");
  }
  if (!map_identity_has_column($conn, "rooms", "building_uid")) {
    map_identity_exec_sql($conn, "ALTER TABLE rooms ADD COLUMN building_uid VARCHAR(64) NULL AFTER building_id");
  }
}

function map_identity_backfill_buildings(mysqli $conn): void {
  if (!map_identity_has_column($conn, "buildings", "building_uid")) return;

  $rows = [];
  $res = $conn->query("SELECT building_id, building_name, building_uid FROM buildings ORDER BY building_id ASC");
  if (!($res instanceof mysqli_result)) {
    throw new RuntimeException("Failed to query buildings for UID backfill");
  }
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }

  $groupUidByName = [];
  foreach ($rows as $row) {
    $nameKey = map_identity_normalize_name((string)($row["building_name"] ?? ""));
    $uid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
    if ($nameKey === "" || $uid === "") continue;
    if (!isset($groupUidByName[$nameKey])) {
      $groupUidByName[$nameKey] = $uid;
    }
  }

  $update = $conn->prepare("UPDATE buildings SET building_uid = ? WHERE building_id = ?");
  if (!$update) {
    throw new RuntimeException("Failed to prepare building UID backfill");
  }

  foreach ($rows as $row) {
    $buildingId = (int)($row["building_id"] ?? 0);
    if ($buildingId <= 0) continue;

    $nameKey = map_identity_normalize_name((string)($row["building_name"] ?? ""));
    $uid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
    if ($uid === "" && $nameKey !== "" && isset($groupUidByName[$nameKey])) {
      $uid = $groupUidByName[$nameKey];
    }
    if ($uid === "") {
      $uid = map_identity_generate_uid();
      if ($nameKey !== "" && !isset($groupUidByName[$nameKey])) {
        $groupUidByName[$nameKey] = $uid;
      }
    }
    $update->bind_param("si", $uid, $buildingId);
    if (!$update->execute()) {
      $update->close();
      throw new RuntimeException("Failed to update building UID");
    }
  }

  $update->close();

  if (map_identity_has_column($conn, "buildings", "model_object_name")) {
    map_identity_exec_sql($conn, "
      UPDATE buildings
      SET model_object_name = building_name
      WHERE (model_object_name IS NULL OR TRIM(model_object_name) = '')
        AND building_name IS NOT NULL
        AND TRIM(building_name) <> ''
    ");
  }
}

function map_identity_backfill_rooms(mysqli $conn): void {
  if (!map_identity_has_column($conn, "rooms", "building_uid")) return;

  if (map_identity_has_column($conn, "rooms", "building_id") && map_identity_has_column($conn, "buildings", "building_uid")) {
    map_identity_exec_sql($conn, "
      UPDATE rooms r
      INNER JOIN buildings b ON b.building_id = r.building_id
      SET r.building_uid = b.building_uid
      WHERE (r.building_uid IS NULL OR TRIM(r.building_uid) = '')
        AND b.building_uid IS NOT NULL
        AND TRIM(b.building_uid) <> ''
    ");
  }

  if (map_identity_has_column($conn, "rooms", "source_model_file")
    && map_identity_has_column($conn, "rooms", "building_name")
    && map_identity_has_column($conn, "buildings", "source_model_file")
    && map_identity_has_column($conn, "buildings", "building_name")
    && map_identity_has_column($conn, "buildings", "building_uid")) {
    map_identity_exec_sql($conn, "
      UPDATE rooms r
      INNER JOIN buildings b
        ON b.source_model_file = r.source_model_file
       AND b.building_name = r.building_name
      SET r.building_uid = b.building_uid
      WHERE (r.building_uid IS NULL OR TRIM(r.building_uid) = '')
        AND b.building_uid IS NOT NULL
        AND TRIM(b.building_uid) <> ''
    ");
  }
}

function map_identity_ensure_schema(mysqli $conn): void {
  map_identity_ensure_columns($conn);
  map_identity_backfill_buildings($conn);
  map_identity_backfill_rooms($conn);
}

function map_identity_resolve_uid(mysqli $conn, string $buildingName, string $modelFile = ""): string {
  $buildingName = trim($buildingName);
  $safeModel = trim($modelFile);
  if ($buildingName === "") return "";
  $hasObjectName = map_identity_has_column($conn, "buildings", "model_object_name");

  if ($safeModel !== "" && map_identity_has_column($conn, "buildings", "source_model_file")) {
    $whereName = $hasObjectName
      ? "(building_name = ? OR model_object_name = ?)"
      : "building_name = ?";
    $stmt = $conn->prepare("
      SELECT building_uid
      FROM buildings
      WHERE source_model_file = ?
        AND {$whereName}
        AND building_uid IS NOT NULL
        AND TRIM(building_uid) <> ''
      ORDER BY building_id DESC
      LIMIT 1
    ");
    if ($stmt) {
      if ($hasObjectName) $stmt->bind_param("sss", $safeModel, $buildingName, $buildingName);
      else $stmt->bind_param("ss", $safeModel, $buildingName);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $uid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
        if ($uid !== "") return $uid;
      } else {
        $stmt->close();
      }
    }
  }

  $whereName = $hasObjectName
    ? "(building_name = ? OR model_object_name = ?)"
    : "building_name = ?";
  $stmt = $conn->prepare("
    SELECT building_uid
    FROM buildings
    WHERE {$whereName}
      AND building_uid IS NOT NULL
      AND TRIM(building_uid) <> ''
    ORDER BY building_id DESC
    LIMIT 1
  ");
  if ($stmt) {
    if ($hasObjectName) $stmt->bind_param("ss", $buildingName, $buildingName);
    else $stmt->bind_param("s", $buildingName);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      $uid = map_identity_normalize_uid((string)($row["building_uid"] ?? ""));
      if ($uid !== "") return $uid;
    } else {
      $stmt->close();
    }
  }

  return map_identity_generate_uid();
}

function map_identity_fetch_model_buildings(mysqli $conn, string $modelFile, bool $presentOnly = true): array {
  if (!map_identity_has_column($conn, "buildings", "source_model_file")) return [];
  $hasObjectName = map_identity_has_column($conn, "buildings", "model_object_name");

  $wherePresent = "";
  if ($presentOnly && map_identity_has_column($conn, "buildings", "is_present_in_latest")) {
    $wherePresent = " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }

  $sql = "
    SELECT building_id, building_uid, building_name,
      " . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name") . ",
      description, image_path, source_model_file
    FROM buildings
    WHERE source_model_file = ?{$wherePresent}
    ORDER BY building_name ASC, building_id ASC
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException("Failed to prepare model building catalog query");
  }
  $stmt->bind_param("s", $modelFile);
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException("Failed to load model building catalog");
  }
  $res = $stmt->get_result();
  $rows = [];
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  $stmt->close();
  return $rows;
}
