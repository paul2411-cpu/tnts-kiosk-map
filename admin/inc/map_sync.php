<?php

function map_sync_sanitize_glb_name($name): ?string {
  $base = basename((string)$name);
  if ($base === "" || $base === "." || $base === "..") return null;
  if (!preg_match('/\.glb$/i', $base)) return null;
  return $base;
}

function map_sync_read_json_file(string $path): ?array {
  if (!file_exists($path)) return null;
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === "") return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function map_sync_first_glb_file(string $dir): ?string {
  if (!is_dir($dir)) return null;
  $files = [];
  foreach (scandir($dir) as $f) {
    if ($f === "." || $f === "..") continue;
    if (!preg_match('/\.glb$/i', $f)) continue;
    $files[] = $f;
  }
  sort($files, SORT_NATURAL | SORT_FLAG_CASE);
  return $files ? $files[0] : null;
}

function map_sync_paths(string $root): array {
  $root = rtrim(str_replace("\\", "/", $root), "/");
  $overlayDir = $root . "/admin/overlays";
  return [
    "root" => $root,
    "modelDir" => $root . "/models",
    "overlayDir" => $overlayDir,
    "defaultModelPath" => $overlayDir . "/default_model.json",
    "liveMapPath" => $overlayDir . "/map_live.json",
    "releasesPath" => $overlayDir . "/map_releases.json",
    "originalModelName" => "tnts_navigation.glb"
  ];
}

function map_sync_resolve_public_model(string $root): array {
  $paths = map_sync_paths($root);
  $modelDir = $paths["modelDir"];
  $liveJson = map_sync_read_json_file($paths["liveMapPath"]);
  $defaultJson = map_sync_read_json_file($paths["defaultModelPath"]);
  $hasLiveManifest = is_array($liveJson);

  $modelFile = null;
  $liveModel = map_sync_sanitize_glb_name($liveJson["modelFile"] ?? "");
  if ($liveModel && file_exists($modelDir . "/" . $liveModel)) {
    $modelFile = $liveModel;
  }
  if (!$modelFile) {
    $defaultModel = map_sync_sanitize_glb_name($defaultJson["file"] ?? "");
    if ($defaultModel && file_exists($modelDir . "/" . $defaultModel)) {
      $modelFile = $defaultModel;
    }
  }
  if (!$modelFile && file_exists($modelDir . "/" . $paths["originalModelName"])) {
    $modelFile = $paths["originalModelName"];
  }
  if (!$modelFile) {
    $modelFile = map_sync_first_glb_file($modelDir);
  }

  $routesFile = null;
  $routesPath = null;
  $guidesFile = null;
  $guidesPath = null;
  if ($modelFile) {
    $rawRoutesFile = str_replace("routes_", "", preg_replace('/\.json$/i', "", (string)($liveJson["routesFile"] ?? "")));
    $candidate = map_sync_sanitize_glb_name($rawRoutesFile);
    if ($candidate) {
      $routesFile = "routes_" . $candidate . ".json";
      $routesPath = $paths["overlayDir"] . "/" . $routesFile;
    }

    if (!$routesPath || !file_exists($routesPath)) {
      $fallbackRoutesFile = "routes_" . $modelFile . ".json";
      $fallbackRoutesPath = $paths["overlayDir"] . "/" . $fallbackRoutesFile;
      if (file_exists($fallbackRoutesPath)) {
        $routesFile = $fallbackRoutesFile;
        $routesPath = $fallbackRoutesPath;
      } else {
        $routesFile = null;
        $routesPath = null;
      }
    }

    $rawGuidesFile = str_replace("guides_", "", preg_replace('/\.json$/i', "", (string)($liveJson["guidesFile"] ?? "")));
    $guideCandidate = map_sync_sanitize_glb_name($rawGuidesFile);
    if ($guideCandidate) {
      $guidesFile = "guides_" . $guideCandidate . ".json";
      $guidesPath = $paths["overlayDir"] . "/" . $guidesFile;
    }

    if (!$guidesPath || !file_exists($guidesPath)) {
      $fallbackGuidesFile = "guides_" . $modelFile . ".json";
      $fallbackGuidesPath = $paths["overlayDir"] . "/" . $fallbackGuidesFile;
      if (file_exists($fallbackGuidesPath)) {
        $guidesFile = $fallbackGuidesFile;
        $guidesPath = $fallbackGuidesPath;
      } else {
        $guidesFile = null;
        $guidesPath = null;
      }
    }
  }

  return [
    "paths" => $paths,
    "hasLiveManifest" => $hasLiveManifest,
    "liveJson" => $liveJson,
    "defaultJson" => $defaultJson,
    "modelFile" => $modelFile,
    "modelPath" => $modelFile ? ($modelDir . "/" . $modelFile) : null,
    "routesFile" => $routesFile,
    "routesPath" => $routesPath,
    "guidesFile" => $guidesFile,
    "guidesPath" => $guidesPath
  ];
}

function map_sync_file_signature(?string $path): string {
  if (!is_string($path) || $path === "" || !file_exists($path)) return "missing";
  clearstatcache(true, $path);
  $mtime = @filemtime($path);
  $size = @filesize($path);
  return (string)(is_int($mtime) ? $mtime : 0) . ":" . (string)(is_int($size) ? $size : 0);
}

function map_sync_atomic_write_json(string $path, $payload): bool {
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) return false;

  try {
    $suffix = bin2hex(random_bytes(6));
  } catch (Throwable $_) {
    $suffix = (string)mt_rand(100000, 999999);
  }

  $tmp = $path . ".tmp." . $suffix;
  $ok = @file_put_contents($tmp, $json, LOCK_EX);
  if ($ok === false) return false;
  if (@rename($tmp, $path)) return true;
  @unlink($path);
  $renamed = @rename($tmp, $path);
  if (!$renamed) @unlink($tmp);
  return $renamed;
}

function map_sync_route_key(string $name): string {
  return mb_strtolower(trim($name));
}

function map_sync_guide_token(string $value): string {
  $value = mb_strtolower(trim($value));
  return preg_replace('/\s+/', '_', $value);
}

function map_sync_build_guide_key(string $type, string $buildingName, string $roomName = ""): string {
  $safeType = map_sync_guide_token($type);
  if ($safeType === "") $safeType = "building";
  $buildingToken = map_sync_guide_token($buildingName);
  $roomToken = map_sync_guide_token($roomName);
  if ($safeType === "room") {
    return "room::" . $buildingToken . "::" . $roomToken;
  }
  return $safeType . "::" . $buildingToken;
}

function map_sync_rename_route_entry(string $root, string $modelFile, string $oldName, string $newName): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldName = trim($oldName);
  $newName = trim($newName);
  if ($oldName === "" || $newName === "") return false;
  if ($oldName === $newName) return true;

  $paths = map_sync_paths($root);
  $routesPath = $paths["overlayDir"] . "/routes_" . $safeModel . ".json";
  if (!file_exists($routesPath)) return true;

  $payload = map_sync_read_json_file($routesPath);
  if (!is_array($payload)) return false;
  $routes = isset($payload["routes"]) && is_array($payload["routes"]) ? $payload["routes"] : [];
  if (!$routes) return true;

  $oldKey = map_sync_route_key($oldName);
  $newKey = map_sync_route_key($newName);
  $foundKey = null;

  if (array_key_exists($oldKey, $routes)) {
    $foundKey = $oldKey;
  } elseif (array_key_exists($oldName, $routes)) {
    $foundKey = $oldName;
  } else {
    foreach ($routes as $key => $entry) {
      $entryName = is_array($entry) ? trim((string)($entry["name"] ?? "")) : "";
      if (map_sync_route_key((string)$key) === $oldKey || ($entryName !== "" && map_sync_route_key($entryName) === $oldKey)) {
        $foundKey = (string)$key;
        break;
      }
    }
  }

  if ($foundKey === null) return true;

  $entry = is_array($routes[$foundKey]) ? $routes[$foundKey] : [];
  unset($routes[$foundKey]);
  $entry["name"] = $newName;
  $routes[$newKey !== "" ? $newKey : $newName] = $entry;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["routes"] = $routes;
  return map_sync_atomic_write_json($routesPath, $payload);
}

function map_sync_rename_guide_entries(string $root, string $modelFile, string $oldName, string $newName): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldName = trim($oldName);
  $newName = trim($newName);
  if ($oldName === "" || $newName === "") return false;
  if ($oldName === $newName) return true;

  $paths = map_sync_paths($root);
  $guidesPath = $paths["overlayDir"] . "/guides_" . $safeModel . ".json";
  if (!file_exists($guidesPath)) return true;

  $payload = map_sync_read_json_file($guidesPath);
  if (!is_array($payload)) return false;
  $entries = isset($payload["entries"]) && is_array($payload["entries"]) ? $payload["entries"] : [];
  if (!$entries) return true;

  $oldRouteKey = map_sync_route_key($oldName);
  $updatedEntries = [];
  $changed = false;

  foreach ($entries as $rawKey => $rawEntry) {
    $entry = is_array($rawEntry) ? $rawEntry : [];
    $destinationType = trim((string)($entry["destinationType"] ?? $entry["type"] ?? "building"));
    if ($destinationType === "") $destinationType = "building";
    $buildingName = trim((string)($entry["buildingName"] ?? $entry["name"] ?? ""));
    $roomName = trim((string)($entry["roomName"] ?? ""));

    $matchesOldBuilding =
      $buildingName !== "" && map_sync_route_key($buildingName) === $oldRouteKey;

    if ($matchesOldBuilding) {
      $buildingName = $newName;
      $entry["buildingName"] = $newName;
      if (isset($entry["routeName"]) && map_sync_route_key((string)$entry["routeName"]) === $oldRouteKey) {
        $entry["routeName"] = $newName;
      }
      if (($destinationType === "building" || $destinationType === "site")
        && isset($entry["destinationName"])
        && map_sync_route_key((string)$entry["destinationName"]) === $oldRouteKey) {
        $entry["destinationName"] = $newName;
      }
      if (($destinationType === "building" || $destinationType === "site")
        && isset($entry["name"])
        && map_sync_route_key((string)$entry["name"]) === $oldRouteKey) {
        $entry["name"] = $newName;
      }
      $changed = true;
    }

    $targetKey = map_sync_build_guide_key($destinationType, $buildingName, $roomName);
    $entry["key"] = $targetKey;
    $updatedEntries[$targetKey !== "" ? $targetKey : (string)$rawKey] = $entry;
  }

  if (!$changed) return true;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["entries"] = $updatedEntries;
  return map_sync_atomic_write_json($guidesPath, $payload);
}
