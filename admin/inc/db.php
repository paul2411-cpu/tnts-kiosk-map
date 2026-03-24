<?php
require_once __DIR__ . "/app_logger.php";
app_logger_bootstrap(["subsystem" => "database"]);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "tnts_kiosk";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  app_log("critical", "Database connection failed", [
    "dbHost" => $DB_HOST,
    "dbName" => $DB_NAME,
    "error" => $conn->connect_error,
  ], [
    "subsystem" => "database",
    "event" => "db_connect_failed",
  ]);
  http_response_code(500);
  die("Database connection failed.");
}
$conn->set_charset("utf8mb4");
