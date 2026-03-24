<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/map_sync.php";
app_logger_set_default_subsystem("api.map_live");

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
  app_log("error", "No public model available", [
    "liveManifestExists" => $hasLiveManifest,
    "liveMapPath" => $LIVE_MAP_PATH,
  ], [
    "subsystem" => "api.map_live",
    "event" => "no_model",
  ]);
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "No model available"], JSON_PRETTY_PRINT);
  exit;
}

$routesFile = $state["routesFile"];
$routesPath = $state["routesPath"];
$roadnetFile = $state["roadnetFile"];
$roadnetPath = $state["roadnetPath"];
$guidesFile = $state["guidesFile"];
$guidesPath = $state["guidesPath"];

$roads = [];
if ($roadnetPath && file_exists($roadnetPath)) {
  $roadnetJson = json_decode(file_get_contents($roadnetPath), true);
  if (is_array($roadnetJson) && isset($roadnetJson["roads"]) && is_array($roadnetJson["roads"])) {
    $roads = $roadnetJson["roads"];
  }
}

$routes = new stdClass();
if ($routesPath && file_exists($routesPath)) {
  $routeJson = json_decode(file_get_contents($routesPath), true);
  if (is_array($routeJson) && isset($routeJson["routes"]) && is_array($routeJson["routes"])) {
    $routes = count($routeJson["routes"]) ? $routeJson["routes"] : new stdClass();
  }
}

$guides = new stdClass();
if ($guidesPath && file_exists($guidesPath)) {
  $guideJson = json_decode(file_get_contents($guidesPath), true);
  if (is_array($guideJson) && isset($guideJson["entries"]) && is_array($guideJson["entries"])) {
    $guides = count($guideJson["entries"]) ? $guideJson["entries"] : new stdClass();
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
  (string)($roadnetFile ?? ""),
  map_sync_file_signature($roadnetPath),
  (string)($routesFile ?? ""),
  map_sync_file_signature($routesPath),
  (string)($guidesFile ?? ""),
  map_sync_file_signature($guidesPath),
  map_sync_file_signature($LIVE_MAP_PATH)
])), 0, 12);
$version = $baseVersion . ":" . $stateVersion;

$webRoot = build_web_root();
$modelUrl = $webRoot . "/models/" . rawurlencode($modelFile);
$roadnetUrl = $roadnetFile ? ($webRoot . "/admin/overlays/" . rawurlencode($roadnetFile)) : null;
$routesUrl = $routesFile ? ($webRoot . "/admin/overlays/" . rawurlencode($routesFile)) : null;
$guidesUrl = $guidesFile ? ($webRoot . "/admin/overlays/" . rawurlencode($guidesFile)) : null;

echo json_encode([
  "ok" => true,
  "published" => $hasLiveManifest,
  "modelFile" => $modelFile,
  "modelUrl" => $modelUrl,
  "roadnetFile" => $roadnetFile,
  "roadnetUrl" => $roadnetUrl,
  "routesFile" => $routesFile,
  "routesUrl" => $routesUrl,
  "guidesFile" => $guidesFile,
  "guidesUrl" => $guidesUrl,
  "version" => $version,
  "publishedAt" => $publishedAt,
  "roads" => $roads,
  "routes" => $routes,
  "guides" => $guides
], JSON_PRETTY_PRINT);
