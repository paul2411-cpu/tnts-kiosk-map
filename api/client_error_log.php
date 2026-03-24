<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/app_logger.php";
app_logger_bootstrap(["subsystem" => "client_error_api"]);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "POST required"], JSON_PRETTY_PRINT);
  exit;
}

if (!app_logger_is_same_origin_request()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Cross-origin request denied"], JSON_PRETTY_PRINT);
  exit;
}

$raw = file_get_contents("php://input");
if (!is_string($raw) || trim($raw) === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Empty payload"], JSON_PRETTY_PRINT);
  exit;
}

if (strlen($raw) > 65535) {
  http_response_code(413);
  echo json_encode(["ok" => false, "error" => "Payload too large"], JSON_PRETTY_PRINT);
  exit;
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Invalid JSON"], JSON_PRETTY_PRINT);
  exit;
}

$payload = is_array($decoded["payload"] ?? null) ? $decoded["payload"] : [];
$message = app_logger_trim_text((string)($payload["message"] ?? "Client error"), 2000);
$kind = app_logger_trim_text((string)($payload["kind"] ?? "client_error"), 120);
$subsystem = app_logger_trim_text((string)($decoded["subsystem"] ?? "client"), 120);

app_log("error", "Client runtime issue", [
  "clientKind" => $kind,
  "clientPage" => app_logger_trim_text((string)($decoded["page"] ?? ""), 160),
  "clientRequestId" => app_logger_trim_text((string)($decoded["requestId"] ?? ""), 120),
  "message" => $message,
  "href" => app_logger_trim_text((string)($decoded["href"] ?? ""), 1000),
  "payload" => app_logger_safe_value($payload["extra"] ?? []),
], [
  "subsystem" => $subsystem !== "" ? ("client." . strtolower(preg_replace('/[^a-z0-9_.-]+/i', "_", $subsystem))) : "client",
  "event" => "client_error",
]);

echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
