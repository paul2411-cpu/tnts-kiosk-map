<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/map_sync.php";

function build_web_root() {
  $scriptDir = str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? ""));
  $scriptDir = rtrim($scriptDir, "/");
  $root = preg_replace('#(^|/)api$#', '', $scriptDir);
  if ($root === null || $root === "") return "";
  return "/" . ltrim($root, "/");
}

$state = map_sync_resolve_public_model($ROOT);
$paths = $state["paths"];
$MODEL_DIR = $paths["modelDir"];
$LIVE_MAP_PATH = $paths["liveMapPath"];
$liveJson = $state["liveJson"];
$hasLiveManifest = (bool)$state["hasLiveManifest"];
$modelFile = $state["modelFile"];

if (!$modelFile) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "No model available"], JSON_PRETTY_PRINT);
  exit;
}

$routesFile = $state["routesFile"];
$routesPath = $state["routesPath"];

$routes = new stdClass();
if ($routesPath && file_exists($routesPath)) {
  $routeJson = json_decode(file_get_contents($routesPath), true);
  if (is_array($routeJson) && isset($routeJson["routes"]) && is_array($routeJson["routes"])) {
    $routes = count($routeJson["routes"]) ? $routeJson["routes"] : new stdClass();
  }
}

$modelPath = $MODEL_DIR . "/" . $modelFile;
$publishedAt = isset($liveJson["publishedAt"]) ? (int)$liveJson["publishedAt"] : (file_exists($modelPath) ? filemtime($modelPath) : time());
$baseVersion = isset($liveJson["version"]) && $liveJson["version"] !== ""
  ? (string)$liveJson["version"]
  : (string)$publishedAt;
$stateVersion = substr(sha1(implode("|", [
  $modelFile,
  map_sync_file_signature($modelPath),
  (string)($routesFile ?? ""),
  map_sync_file_signature($routesPath),
  map_sync_file_signature($LIVE_MAP_PATH)
])), 0, 12);
$version = $baseVersion . ":" . $stateVersion;

$webRoot = build_web_root();
$modelUrl = $webRoot . "/models/" . rawurlencode($modelFile);
$routesUrl = $routesFile ? ($webRoot . "/admin/overlays/" . rawurlencode($routesFile)) : null;

echo json_encode([
  "ok" => true,
  "published" => $hasLiveManifest,
  "modelFile" => $modelFile,
  "modelUrl" => $modelUrl,
  "routesFile" => $routesFile,
  "routesUrl" => $routesUrl,
  "version" => $version,
  "publishedAt" => $publishedAt,
  "routes" => $routes
], JSON_PRETTY_PRINT);
