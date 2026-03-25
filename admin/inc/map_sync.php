<?php

require_once __DIR__ . "/app_logger.php";
app_logger_bootstrap(["subsystem" => "map_sync"]);
require_once __DIR__ . "/building_identity.php";

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

  $roadnetFile = null;
  $roadnetPath = null;
  $routesFile = null;
  $routesPath = null;
  $guidesFile = null;
  $guidesPath = null;
  if ($modelFile) {
    $rawRoadnetFile = str_replace("roadnet_", "", preg_replace('/\.json$/i', "", (string)($liveJson["roadnetFile"] ?? "")));
    $roadCandidate = map_sync_sanitize_glb_name($rawRoadnetFile);
    if ($roadCandidate) {
      $roadnetFile = "roadnet_" . $roadCandidate . ".json";
      $roadnetPath = $paths["overlayDir"] . "/" . $roadnetFile;
    }

    if (!$roadnetPath || !file_exists($roadnetPath)) {
      $fallbackRoadnetFile = "roadnet_" . $modelFile . ".json";
      $fallbackRoadnetPath = $paths["overlayDir"] . "/" . $fallbackRoadnetFile;
      if (file_exists($fallbackRoadnetPath)) {
        $roadnetFile = $fallbackRoadnetFile;
        $roadnetPath = $fallbackRoadnetPath;
      } else {
        $roadnetFile = null;
        $roadnetPath = null;
      }
    }

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
    "roadnetFile" => $roadnetFile,
    "roadnetPath" => $roadnetPath,
    "routesFile" => $routesFile,
    "routesPath" => $routesPath,
    "guidesFile" => $guidesFile,
    "guidesPath" => $guidesPath
  ];
}

function map_sync_resolve_editor_model(string $root): ?string {
  $paths = map_sync_paths($root);
  $modelDir = $paths["modelDir"];

  $defaultJson = map_sync_read_json_file($paths["defaultModelPath"]);
  $defaultModel = map_sync_sanitize_glb_name($defaultJson["file"] ?? "");
  if ($defaultModel && file_exists($modelDir . "/" . $defaultModel)) {
    return $defaultModel;
  }

  $publicState = map_sync_resolve_public_model($root);
  $publicModel = map_sync_sanitize_glb_name($publicState["modelFile"] ?? "");
  if ($publicModel && file_exists($modelDir . "/" . $publicModel)) {
    return $publicModel;
  }

  $originalModel = $paths["originalModelName"] ?? "";
  if ($originalModel !== "" && file_exists($modelDir . "/" . $originalModel)) {
    return $originalModel;
  }

  return map_sync_first_glb_file($modelDir);
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

function map_sync_normalize_building_uid(?string $uid): string {
  return map_identity_normalize_uid($uid);
}

function map_sync_building_identity_key(?string $uid, string $name = ""): string {
  $safeUid = map_sync_normalize_building_uid($uid);
  if ($safeUid !== "") return "uid:" . $safeUid;
  $safeName = map_sync_route_key($name);
  return $safeName !== "" ? "name:" . $safeName : "";
}

function map_sync_guide_token(string $value): string {
  $value = mb_strtolower(trim($value));
  return preg_replace('/\s+/', '_', $value);
}

function map_sync_build_guide_key(string $type, string $buildingName, string $roomName = "", string $buildingUid = "", string $objectName = ""): string {
  $safeType = map_sync_guide_token($type);
  if ($safeType === "") $safeType = "building";
  $safeUid = map_sync_normalize_building_uid($buildingUid);
  $buildingToken = $safeUid !== ""
    ? ("uid_" . map_sync_guide_token($safeUid))
    : map_sync_guide_token($buildingName !== "" ? $buildingName : $objectName);
  $roomToken = map_sync_guide_token($roomName);
  if ($safeType === "room") {
    return "room::" . $buildingToken . "::" . $roomToken;
  }
  return $safeType . "::" . $buildingToken;
}

function map_sync_route_entry_identity_key($entry, string $fallbackKey = ""): string {
  $uid = "";
  if (is_array($entry)) {
    $uid = map_sync_normalize_building_uid((string)($entry["buildingUid"] ?? $entry["destinationUid"] ?? $entry["uid"] ?? ""));
  }
  $name = is_array($entry) ? trim((string)($entry["name"] ?? $fallbackKey)) : trim($fallbackKey);
  return map_sync_building_identity_key($uid, $name);
}

function map_sync_rename_route_entry(string $root, string $modelFile, string $oldName, string $newName, ?string $buildingUid = null): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldName = trim($oldName);
  $newName = trim($newName);
  if ($oldName === "" || $newName === "") return false;
  $safeUid = map_sync_normalize_building_uid($buildingUid);
  if ($oldName === $newName && $safeUid === "") return true;

  $paths = map_sync_paths($root);
  $routesPath = $paths["overlayDir"] . "/routes_" . $safeModel . ".json";
  if (!file_exists($routesPath)) return true;

  $payload = map_sync_read_json_file($routesPath);
  if (!is_array($payload)) return false;
  $routes = isset($payload["routes"]) && is_array($payload["routes"]) ? $payload["routes"] : [];
  if (!$routes) return true;

  $oldKey = map_sync_route_key($oldName);
  $targetKey = $safeUid !== "" ? $safeUid : map_sync_route_key($newName);
  $expectedIdentity = map_sync_building_identity_key($safeUid, $oldName);
  $foundKey = null;

  if ($safeUid !== "" && array_key_exists($safeUid, $routes)) {
    $foundKey = $safeUid;
  } elseif (array_key_exists($oldKey, $routes)) {
    $foundKey = $oldKey;
  } elseif (array_key_exists($oldName, $routes)) {
    $foundKey = $oldName;
  } else {
    foreach ($routes as $key => $entry) {
      if ($expectedIdentity !== "" && map_sync_route_entry_identity_key($entry, (string)$key) === $expectedIdentity) {
        $foundKey = (string)$key;
        break;
      }
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
  if ($safeUid !== "") $entry["buildingUid"] = $safeUid;
  $routes[$targetKey !== "" ? $targetKey : $newName] = $entry;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["routes"] = $routes;
  return map_sync_atomic_write_json($routesPath, $payload);
}

function map_sync_rename_guide_entries(string $root, string $modelFile, string $oldName, string $newName, ?string $buildingUid = null): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldName = trim($oldName);
  $newName = trim($newName);
  if ($oldName === "" || $newName === "") return false;
  $safeUid = map_sync_normalize_building_uid($buildingUid);
  if ($oldName === $newName && $safeUid === "") return true;

  $paths = map_sync_paths($root);
  $guidesPath = $paths["overlayDir"] . "/guides_" . $safeModel . ".json";
  if (!file_exists($guidesPath)) return true;

  $payload = map_sync_read_json_file($guidesPath);
  if (!is_array($payload)) return false;
  $entries = isset($payload["entries"]) && is_array($payload["entries"]) ? $payload["entries"] : [];
  if (!$entries) return true;

  $oldRouteKey = map_sync_route_key($oldName);
  $oldIdentity = map_sync_building_identity_key($safeUid, $oldName);
  $updatedEntries = [];
  $changed = false;

  foreach ($entries as $rawKey => $rawEntry) {
    $entry = is_array($rawEntry) ? $rawEntry : [];
    $destinationType = trim((string)($entry["destinationType"] ?? $entry["type"] ?? "building"));
    if ($destinationType === "") $destinationType = "building";
    $buildingName = trim((string)($entry["buildingName"] ?? $entry["name"] ?? ""));
    $entryUid = map_sync_normalize_building_uid((string)($entry["buildingUid"] ?? $entry["destinationUid"] ?? ""));
    $objectName = trim((string)($entry["objectName"] ?? $entry["modelObjectName"] ?? ""));
    $roomName = trim((string)($entry["roomName"] ?? ""));

    $matchesOldBuilding =
      ($oldIdentity !== "" && map_sync_building_identity_key($entryUid, $buildingName) === $oldIdentity)
      || ($buildingName !== "" && map_sync_route_key($buildingName) === $oldRouteKey);

    if ($matchesOldBuilding) {
      $buildingName = $newName;
      $entry["buildingName"] = $newName;
       if ($safeUid !== "") {
        $entry["buildingUid"] = $safeUid;
      }
      if (isset($entry["routeName"]) && map_sync_route_key((string)$entry["routeName"]) === $oldRouteKey) {
        $entry["routeName"] = $newName;
      }
      if ($destinationType !== "room"
        && isset($entry["destinationName"])
        && map_sync_route_key((string)$entry["destinationName"]) === $oldRouteKey) {
        $entry["destinationName"] = $newName;
      }
      if ($destinationType !== "room"
        && isset($entry["name"])
        && map_sync_route_key((string)$entry["name"]) === $oldRouteKey) {
        $entry["name"] = $newName;
      }
      $changed = true;
    }

    $targetKey = map_sync_build_guide_key($destinationType, $buildingName, $roomName, (string)($entry["buildingUid"] ?? $entryUid), $objectName);
    $entry["key"] = $targetKey;
    $updatedEntries[$targetKey !== "" ? $targetKey : (string)$rawKey] = $entry;
  }

  if (!$changed) return true;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["entries"] = $updatedEntries;
  return map_sync_atomic_write_json($guidesPath, $payload);
}

function map_sync_rename_roadnet_links(string $root, string $modelFile, string $oldName, string $newName, ?string $buildingUid = null): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldName = trim($oldName);
  $newName = trim($newName);
  if ($oldName === "" || $newName === "") return false;
  $safeUid = map_sync_normalize_building_uid($buildingUid);

  $paths = map_sync_paths($root);
  $roadnetPath = $paths["overlayDir"] . "/roadnet_" . $safeModel . ".json";
  if (!file_exists($roadnetPath)) return true;

  $payload = map_sync_read_json_file($roadnetPath);
  if (!is_array($payload)) return false;
  $roads = isset($payload["roads"]) && is_array($payload["roads"]) ? $payload["roads"] : [];
  if (!$roads) return true;

  $oldKey = map_sync_route_key($oldName);
  $oldIdentity = map_sync_building_identity_key($safeUid, $oldName);
  $changed = false;

  foreach ($roads as $roadIndex => $road) {
    if (!is_array($road)) continue;
    $metaArr = isset($road["pointMeta"]) && is_array($road["pointMeta"]) ? $road["pointMeta"] : null;
    if ($metaArr === null) continue;

    foreach ($metaArr as $metaIndex => $meta) {
      if (!is_array($meta)) continue;
      $building = isset($meta["building"]) && is_array($meta["building"]) ? $meta["building"] : null;
      if (!$building) continue;

      $buildingName = trim((string)($building["name"] ?? ""));
      $entryUid = map_sync_normalize_building_uid((string)($building["uid"] ?? ""));
      $matches = ($oldIdentity !== "" && map_sync_building_identity_key($entryUid, $buildingName) === $oldIdentity)
        || ($buildingName !== "" && map_sync_route_key($buildingName) === $oldKey);
      if (!$matches) continue;

      $side = trim((string)($building["side"] ?? ""));
      $building["name"] = $newName;
      if ($safeUid !== "") $building["uid"] = $safeUid;
      $meta["building"] = $building;
      if ($side !== "") {
        $meta["label"] = $newName . "_" . $side;
      } elseif (isset($meta["label"]) && map_sync_route_key((string)$meta["label"]) === $oldKey) {
        $meta["label"] = $newName;
      }
      $roads[$roadIndex]["pointMeta"][$metaIndex] = $meta;
      $changed = true;
    }
  }

  if (!$changed) return true;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["roads"] = $roads;
  return map_sync_atomic_write_json($roadnetPath, $payload);
}

function map_sync_retarget_room_guide_entries(
  string $root,
  string $modelFile,
  string $oldBuildingName,
  string $oldRoomName,
  string $newBuildingName,
  string $newRoomName
): bool {
  $safeModel = map_sync_sanitize_glb_name($modelFile);
  if ($safeModel === null) return false;

  $oldBuildingName = trim($oldBuildingName);
  $oldRoomName = trim($oldRoomName);
  $newBuildingName = trim($newBuildingName);
  $newRoomName = trim($newRoomName);
  if ($oldRoomName === "" || $newRoomName === "") return false;
  if ($oldBuildingName === "" || $newBuildingName === "") return true;

  $oldBuildingKey = map_sync_guide_token($oldBuildingName);
  $oldRoomKey = map_sync_guide_token($oldRoomName);
  $newBuildingKey = map_sync_guide_token($newBuildingName);
  $newRoomKey = map_sync_guide_token($newRoomName);
  if ($oldBuildingKey === $newBuildingKey && $oldRoomKey === $newRoomKey) return true;

  $paths = map_sync_paths($root);
  $guidesPath = $paths["overlayDir"] . "/guides_" . $safeModel . ".json";
  if (!file_exists($guidesPath)) return true;

  $payload = map_sync_read_json_file($guidesPath);
  if (!is_array($payload)) return false;
  $entries = isset($payload["entries"]) && is_array($payload["entries"]) ? $payload["entries"] : [];
  if (!$entries) return true;

  $updatedEntries = [];
  $changed = false;

  foreach ($entries as $rawKey => $rawEntry) {
    $entry = is_array($rawEntry) ? $rawEntry : [];
    $destinationType = trim((string)($entry["destinationType"] ?? $entry["type"] ?? "building"));
    if ($destinationType === "") $destinationType = "building";
    $buildingName = trim((string)($entry["buildingName"] ?? $entry["name"] ?? ""));
    $roomName = trim((string)($entry["roomName"] ?? ""));

    $matchesRoom = (
      $destinationType === "room"
      && $buildingName !== ""
      && $roomName !== ""
      && map_sync_guide_token($buildingName) === $oldBuildingKey
      && map_sync_guide_token($roomName) === $oldRoomKey
    );

    if ($matchesRoom) {
      $buildingName = $newBuildingName;
      $roomName = $newRoomName;
      $entry["buildingName"] = $newBuildingName;
      $entry["roomName"] = $newRoomName;
      $entry["routeName"] = $newBuildingName;
      $entry["destinationName"] = $newBuildingName . " / " . $newRoomName;
      $entry["manualText"] = "";
      $entry["autoSteps"] = [];
      $entry["finalSteps"] = [];
      $entry["routeSignature"] = "";
      $entry["sourceRouteSignature"] = "";
      $entry["guideMode"] = "auto";
      $entry["status"] = "stale";
      $existingNotes = is_array($entry["notes"]) ? $entry["notes"] : [];
      $resetNote = "Room guide text was cleared after the room name or building changed. Review before publishing.";
      $filteredNotes = array_values(array_filter(array_map("strval", $existingNotes), static function($note) use ($resetNote) {
        return trim($note) !== "" && trim($note) !== $resetNote;
      }));
      array_unshift($filteredNotes, $resetNote);
      $entry["notes"] = $filteredNotes;
      $changed = true;
    }

    $targetKey = map_sync_build_guide_key(
      $destinationType,
      $buildingName,
      $roomName,
      (string)($entry["buildingUid"] ?? ""),
      trim((string)($entry["objectName"] ?? $entry["modelObjectName"] ?? ""))
    );
    $entry["key"] = $targetKey;
    $updatedEntries[$targetKey !== "" ? $targetKey : (string)$rawKey] = $entry;
  }

  if (!$changed) return true;

  $payload["model"] = $safeModel;
  $payload["updated"] = time();
  $payload["entries"] = $updatedEntries;
  return map_sync_atomic_write_json($guidesPath, $payload);
}
