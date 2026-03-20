<?php

function announcements_trimmed($value, int $maxLen): string {
  $text = trim((string)$value);
  if ($text === "") return "";
  return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen) : $text;
}

function announcements_has_index(mysqli $conn, string $table, string $index): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeIndex = $conn->real_escape_string($index);
  $res = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function announcements_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function announcements_sql(mysqli $conn, string $sql): void {
  if (!$conn->query($sql)) {
    throw new RuntimeException("SQL failed: " . $conn->error);
  }
}

function announcements_ensure_schema(mysqli $conn): void {
  announcements_sql($conn, "
    CREATE TABLE IF NOT EXISTS announcements (
      announcement_id INT(11) NOT NULL AUTO_INCREMENT,
      title VARCHAR(150) NOT NULL,
      content TEXT DEFAULT NULL,
      date_posted TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expiry_date DATE DEFAULT NULL,
      banner_path VARCHAR(255) DEFAULT NULL,
      importance_level ENUM('normal','important','headline') NOT NULL DEFAULT 'normal',
      schedule_mode ENUM('date_only','timed') NOT NULL DEFAULT 'date_only',
      start_date DATE DEFAULT NULL,
      end_date DATE DEFAULT NULL,
      start_at DATETIME DEFAULT NULL,
      end_at DATETIME DEFAULT NULL,
      PRIMARY KEY (announcement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  if (!announcements_has_column($conn, "announcements", "banner_path")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN banner_path VARCHAR(255) DEFAULT NULL AFTER expiry_date");
  }
  if (!announcements_has_column($conn, "announcements", "importance_level")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN importance_level ENUM('normal','important','headline') NOT NULL DEFAULT 'normal' AFTER banner_path");
  }
  if (!announcements_has_column($conn, "announcements", "schedule_mode")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN schedule_mode ENUM('date_only','timed') NOT NULL DEFAULT 'date_only' AFTER importance_level");
  }
  if (!announcements_has_column($conn, "announcements", "start_date")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN start_date DATE DEFAULT NULL AFTER schedule_mode");
  }
  if (!announcements_has_column($conn, "announcements", "end_date")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN end_date DATE DEFAULT NULL AFTER start_date");
  }
  if (!announcements_has_column($conn, "announcements", "start_at")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN start_at DATETIME DEFAULT NULL AFTER end_date");
  }
  if (!announcements_has_column($conn, "announcements", "end_at")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD COLUMN end_at DATETIME DEFAULT NULL AFTER start_at");
  }

  if (!announcements_has_index($conn, "announcements", "idx_announcements_date_posted")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_date_posted (date_posted)");
  }
  if (!announcements_has_index($conn, "announcements", "idx_announcements_expiry_date")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_expiry_date (expiry_date)");
  }
  if (!announcements_has_index($conn, "announcements", "idx_announcements_start_date")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_start_date (start_date)");
  }
  if (!announcements_has_index($conn, "announcements", "idx_announcements_end_date")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_end_date (end_date)");
  }
  if (!announcements_has_index($conn, "announcements", "idx_announcements_start_at")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_start_at (start_at)");
  }
  if (!announcements_has_index($conn, "announcements", "idx_announcements_end_at")) {
    announcements_sql($conn, "ALTER TABLE announcements ADD INDEX idx_announcements_end_at (end_at)");
  }
}

function announcements_importance_options(): array {
  return [
    "normal" => "Standard",
    "important" => "Important",
    "headline" => "Headline",
  ];
}

function announcements_importance_normalize($value): string {
  $importance = strtolower(trim((string)$value));
  $options = announcements_importance_options();
  return isset($options[$importance]) ? $importance : "normal";
}

function announcements_importance_label($value): string {
  $importance = announcements_importance_normalize($value);
  $options = announcements_importance_options();
  return (string)($options[$importance] ?? "Standard");
}

function announcements_importance_rank($value): int {
  $importance = announcements_importance_normalize($value);
  $ranks = [
    "normal" => 0,
    "important" => 1,
    "headline" => 2,
  ];
  return (int)($ranks[$importance] ?? 0);
}

function announcements_importance_class($value): string {
  return "importance-" . announcements_importance_normalize($value);
}

function announcements_is_headline(array $row): bool {
  return announcements_importance_normalize($row["importance_level"] ?? "") === "headline";
}

function announcements_schedule_mode_options(): array {
  return [
    "date_only" => "Date Only",
    "timed" => "Specific Time",
  ];
}

function announcements_schedule_mode_normalize($value): string {
  $mode = strtolower(trim((string)$value));
  $options = announcements_schedule_mode_options();
  return isset($options[$mode]) ? $mode : "date_only";
}

function announcements_schedule_mode_label($value): string {
  $mode = announcements_schedule_mode_normalize($value);
  $options = announcements_schedule_mode_options();
  return (string)($options[$mode] ?? "Date Only");
}

function announcements_parse_date_value($value): ?DateTimeImmutable {
  $raw = trim((string)$value);
  if ($raw === "") return null;
  $dt = DateTimeImmutable::createFromFormat("!Y-m-d", $raw);
  if (!$dt || $dt->format("Y-m-d") !== $raw) return null;
  return $dt;
}

function announcements_parse_datetime_value($value): ?DateTimeImmutable {
  $raw = trim((string)$value);
  if ($raw === "") return null;
  $normalized = str_replace("T", " ", $raw);

  $withSeconds = DateTimeImmutable::createFromFormat("!Y-m-d H:i:s", $normalized);
  if ($withSeconds && $withSeconds->format("Y-m-d H:i:s") === $normalized) return $withSeconds;

  $withoutSeconds = DateTimeImmutable::createFromFormat("!Y-m-d H:i", $normalized);
  if ($withoutSeconds && $withoutSeconds->format("Y-m-d H:i") === $normalized) return $withoutSeconds;

  return null;
}

function announcements_date_input_value($value): string {
  $dt = announcements_parse_date_value($value);
  return $dt ? $dt->format("Y-m-d") : "";
}

function announcements_datetime_storage_value($value): string {
  $dt = announcements_parse_datetime_value($value);
  return $dt ? $dt->format("Y-m-d H:i:s") : "";
}

function announcements_datetime_input_value($value): string {
  $dt = announcements_parse_datetime_value($value);
  return $dt ? $dt->format("Y-m-d\\TH:i") : "";
}

function announcements_effective_schedule(array $row): array {
  $mode = announcements_schedule_mode_normalize($row["schedule_mode"] ?? "");

  $startDateRaw = trim((string)($row["start_date"] ?? ""));
  $endDateRaw = trim((string)($row["end_date"] ?? ""));
  $legacyExpiry = trim((string)($row["expiry_date"] ?? ""));
  if ($mode === "date_only" && $endDateRaw === "" && $legacyExpiry !== "") {
    $endDateRaw = $legacyExpiry;
  }

  return [
    "mode" => $mode,
    "start_date" => announcements_parse_date_value($startDateRaw),
    "end_date" => announcements_parse_date_value($endDateRaw),
    "start_at" => announcements_parse_datetime_value($row["start_at"] ?? ""),
    "end_at" => announcements_parse_datetime_value($row["end_at"] ?? ""),
  ];
}

function announcements_status(array $row, ?DateTimeImmutable $now = null): string {
  $now = $now ?: new DateTimeImmutable("now");
  $schedule = announcements_effective_schedule($row);

  if ($schedule["mode"] === "timed") {
    if ($schedule["start_at"] instanceof DateTimeImmutable && $now < $schedule["start_at"]) return "upcoming";
    if ($schedule["end_at"] instanceof DateTimeImmutable && $now > $schedule["end_at"]) return "expired";
    return "active";
  }

  $today = $now->setTime(0, 0, 0);
  if ($schedule["start_date"] instanceof DateTimeImmutable && $today < $schedule["start_date"]) return "upcoming";
  if ($schedule["end_date"] instanceof DateTimeImmutable && $today > $schedule["end_date"]) return "expired";
  return "active";
}

function announcements_is_active(array $row, ?DateTimeImmutable $now = null): bool {
  return announcements_status($row, $now) === "active";
}

function announcements_is_upcoming(array $row, ?DateTimeImmutable $now = null): bool {
  return announcements_status($row, $now) === "upcoming";
}

function announcements_status_label(array $row, ?DateTimeImmutable $now = null): string {
  $status = announcements_status($row, $now);
  if ($status === "upcoming") return "Upcoming";
  if ($status === "expired") return "Expired";
  return "Active";
}

function announcements_format_date(?string $value, bool $withTime = false): string {
  $raw = trim((string)$value);
  if ($raw === "") return $withTime ? "Unknown date" : "No date";

  $timestamp = strtotime($raw);
  if ($timestamp === false) return $withTime ? "Unknown date" : "No date";

  return $withTime ? date("M d, Y h:i A", $timestamp) : date("M d, Y", $timestamp);
}

function announcements_format_schedule(array $row): string {
  $schedule = announcements_effective_schedule($row);

  if ($schedule["mode"] === "timed") {
    $start = $schedule["start_at"];
    $end = $schedule["end_at"];
    if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable) {
      if ($start->format("Y-m-d") === $end->format("Y-m-d")) {
        return $start->format("M d, Y h:i A") . " - " . $end->format("h:i A");
      }
      return $start->format("M d, Y h:i A") . " - " . $end->format("M d, Y h:i A");
    }
    if ($start instanceof DateTimeImmutable) return "Starts " . $start->format("M d, Y h:i A");
    if ($end instanceof DateTimeImmutable) return "Until " . $end->format("M d, Y h:i A");
    return "Always visible";
  }

  $start = $schedule["start_date"];
  $end = $schedule["end_date"];
  if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable) {
    if ($start->format("Y-m-d") === $end->format("Y-m-d")) {
      return $start->format("M d, Y");
    }
    return $start->format("M d, Y") . " - " . $end->format("M d, Y");
  }
  if ($start instanceof DateTimeImmutable) return "Starting " . $start->format("M d, Y");
  if ($end instanceof DateTimeImmutable) return "Until " . $end->format("M d, Y");
  return "Always visible";
}

function announcements_content_preview(?string $content, int $maxLen = 220): string {
  $text = trim((string)$content);
  if ($text === "") return "";
  $text = preg_replace('/\s+/', ' ', $text);
  $text = is_string($text) ? $text : "";
  if ($text === "") return "";
  $trimLen = max(0, $maxLen - 3);
  return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $trimLen) . "..." : $text;
}

function announcements_banner_url(?string $storedPath): string {
  $path = trim((string)$storedPath);
  if ($path === "") return "";
  if (preg_match('#^(?:https?:)?//#i', $path)) return $path;
  $path = str_replace("\\", "/", $path);
  $path = preg_replace('#^\./#', '', $path);
  if (strpos($path, "../") === 0) return $path;
  return "../" . ltrim($path, "/");
}

function announcements_slugify_filename(string $name): string {
  $name = mb_strtolower(trim($name));
  $name = preg_replace('/[^a-z0-9]+/i', '-', $name);
  $name = trim((string)$name, "-");
  return $name !== "" ? $name : "announcement";
}

function announcements_store_banner_upload(array $file, string $root): array {
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

  $dir = rtrim(str_replace("\\", "/", $root), "/") . "/assets/announcements";
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    return ["ok" => false, "error" => "The announcement banner folder could not be created."];
  }

  $original = (string)($file["name"] ?? "banner");
  $base = pathinfo($original, PATHINFO_FILENAME);
  $slug = announcements_slugify_filename($base);
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

  return ["ok" => true, "path" => "assets/announcements/" . $filename];
}

function announcements_banner_filesystem_path(string $root, ?string $storedPath): string {
  $path = trim((string)$storedPath);
  if ($path === "") return "";
  $path = str_replace("\\", "/", $path);
  $path = ltrim($path, "/");
  if (strpos($path, "../") !== false) return "";
  $prefix = "assets/announcements/";
  if (strpos($path, $prefix) !== 0) return "";
  return rtrim(str_replace("\\", "/", $root), "/") . "/" . ltrim($path, "/");
}

function announcements_delete_local_banner(string $root, ?string $storedPath): void {
  $fullPath = announcements_banner_filesystem_path($root, $storedPath);
  if ($fullPath === "" || !is_file($fullPath)) return;
  @unlink($fullPath);
}

function announcements_sort_rows_by_priority(array $rows): array {
  usort($rows, static function (array $a, array $b): int {
    $importanceCompare = announcements_importance_rank($b["importance_level"] ?? "")
      <=> announcements_importance_rank($a["importance_level"] ?? "");
    if ($importanceCompare !== 0) return $importanceCompare;

    $timeA = strtotime((string)($a["date_posted"] ?? "")) ?: 0;
    $timeB = strtotime((string)($b["date_posted"] ?? "")) ?: 0;
    if ($timeA !== $timeB) return $timeB <=> $timeA;

    return (int)($b["announcement_id"] ?? 0) <=> (int)($a["announcement_id"] ?? 0);
  });

  return $rows;
}

function announcements_load_rows(mysqli $conn, bool $activeOnly = false): array {
  announcements_ensure_schema($conn);

  $sql = "SELECT
    announcement_id,
    title,
    content,
    date_posted,
    expiry_date,
    banner_path,
    importance_level,
    schedule_mode,
    start_date,
    end_date,
    start_at,
    end_at
    FROM announcements
    ORDER BY date_posted DESC, announcement_id DESC";

  $rows = [];
  $res = $conn->query($sql);
  $now = new DateTimeImmutable("now");
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      if ($activeOnly && !announcements_is_active($row, $now)) continue;
      $rows[] = $row;
    }
  }
  return $rows;
}

function announcements_load_row_by_id(mysqli $conn, int $announcementId): ?array {
  announcements_ensure_schema($conn);

  $stmt = $conn->prepare(
    "SELECT
      announcement_id,
      title,
      content,
      date_posted,
      expiry_date,
      banner_path,
      importance_level,
      schedule_mode,
      start_date,
      end_date,
      start_at,
      end_at
     FROM announcements
     WHERE announcement_id = ?
     LIMIT 1"
  );
  if (!$stmt) return null;

  $stmt->bind_param("i", $announcementId);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }

  $res = $stmt->get_result();
  $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
  $stmt->close();
  return is_array($row) ? $row : null;
}
