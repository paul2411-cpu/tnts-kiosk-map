<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/db.php";
require_once $ROOT . "/admin/inc/events.php";
app_logger_set_default_subsystem("api.event_details");

function event_details_response(array $payload, int $statusCode = 200): void {
  http_response_code($statusCode);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function event_details_resolved_target($target): ?array {
  if (!is_array($target)) return null;

  $type = trim((string)($target["type"] ?? ""));
  if ($type === "") return null;

  $out = ["type" => $type];
  if (isset($target["buildingId"])) $out["buildingId"] = (int)$target["buildingId"];
  if (isset($target["buildingName"])) $out["buildingName"] = trim((string)$target["buildingName"]);
  if (isset($target["roomId"])) $out["roomId"] = (int)$target["roomId"];
  if (isset($target["roomName"])) $out["roomName"] = trim((string)$target["roomName"]);
  if (isset($target["roomNumber"])) $out["roomNumber"] = trim((string)$target["roomNumber"]);
  if (isset($target["x"])) $out["x"] = (float)$target["x"];
  if (isset($target["y"])) $out["y"] = (float)$target["y"];
  if (isset($target["z"])) $out["z"] = (float)$target["z"];
  if (isset($target["radius"])) $out["radius"] = (float)$target["radius"];
  if (isset($target["modelFile"])) $out["modelFile"] = trim((string)$target["modelFile"]);

  return $out;
}

$eventId = 0;
if (isset($_GET["event"]) && is_numeric($_GET["event"])) {
  $eventId = (int)$_GET["event"];
} elseif (isset($_GET["eventId"]) && is_numeric($_GET["eventId"])) {
  $eventId = (int)$_GET["eventId"];
}

if ($eventId <= 0) {
  event_details_response([
    "ok" => false,
    "error" => "A valid event id is required.",
  ], 400);
}

try {
  events_ensure_schema($conn);

  $row = events_load_row_by_id($conn, $eventId);
  if (!$row || !events_is_publicly_visible($row)) {
    event_details_response([
      "ok" => true,
      "found" => false,
      "event" => null,
      "message" => "This event is unavailable or no longer public.",
    ]);
  }

  $publicModel = events_public_model_file($ROOT);
  $resolution = events_resolve_location($conn, $row, $publicModel, $ROOT);
  $schedule = events_classify_schedule($row);

  event_details_response([
    "ok" => true,
    "found" => true,
    "modelFile" => $publicModel,
    "event" => [
      "id" => (int)($row["event_id"] ?? 0),
      "title" => trim((string)($row["title"] ?? "")),
      "description" => trim((string)($row["description"] ?? "")),
      "status" => trim((string)($row["status"] ?? "published")),
      "schedule" => $schedule,
      "scheduleLabel" => ucwords(str_replace("_", " ", $schedule)),
      "dateLabel" => events_format_date_label($row),
      "startDate" => trim((string)($row["start_date"] ?? "")),
      "endDate" => trim((string)($row["end_date"] ?? "")),
      "locationLabel" => trim((string)($resolution["displayLocation"] ?? $row["location"] ?? "")),
      "locationMode" => trim((string)($resolution["mode"] ?? "text_only")),
      "health" => trim((string)($resolution["health"] ?? "limited")),
      "healthLabel" => trim((string)($resolution["healthLabel"] ?? "Limited")),
      "healthMessage" => trim((string)($resolution["message"] ?? "")),
      "canMap" => !empty($resolution["canMap"]),
      "canRoute" => !empty($resolution["canRoute"]),
      "bannerUrl" => events_banner_url((string)($row["banner_path"] ?? "")),
      "resolvedTarget" => event_details_resolved_target($resolution["resolvedTarget"] ?? null),
    ],
  ]);
} catch (Throwable $e) {
  app_log_exception($e, [
    "eventId" => $eventId,
  ], [
    "subsystem" => "api.event_details",
    "event" => "event_lookup_failed",
    "message" => "Event lookup failed",
  ]);
  event_details_response([
    "ok" => false,
    "error" => "Event lookup failed: " . $e->getMessage(),
  ], 500);
}
