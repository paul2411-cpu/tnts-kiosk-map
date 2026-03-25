<?php
require_once __DIR__ . "/inc/auth.php";
require_admin_permission("manage_events", "You do not have access to manage events.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/events.php";
require_once __DIR__ . "/inc/layout.php";

$ROOT = dirname(__DIR__);
events_ensure_schema($conn);

$GLOBALS["admin_extra_head"] = <<<HTML
<script type="importmap">
{
  "imports": {
    "three": "../three.js-master/build/three.module.js",
    "three/addons/": "../three.js-master/examples/jsm/"
  }
}
</script>
HTML;

function events_admin_redirect(array $query = []): void {
  $url = "events.php";
  if ($query) $url .= "?" . http_build_query($query);
  header("Location: " . $url);
  exit;
}

function events_admin_alert(array $flash): string {
  $type = trim((string)($flash["type"] ?? "info"));
  return in_array($type, ["success", "error"], true) ? $type : "info";
}

if (empty($_SESSION["admin_events_csrf"])) {
  try {
    $_SESSION["admin_events_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["admin_events_csrf"] = sha1(uniqid((string)mt_rand(), true));
  }
}
$eventsCsrf = (string)$_SESSION["admin_events_csrf"];

$statusOptions = events_status_options();
$locationModeOptions = events_location_mode_options();
$healthLabels = events_health_labels();

$publicState = map_sync_resolve_public_model($ROOT);
$publicModel = trim((string)($publicState["modelFile"] ?? ""));
$publicModelUrl = $publicModel !== ""
  ? "../models/" . rawurlencode($publicModel)
  : "../models/tnts_navigation.glb";

$buildingOptions = events_load_building_options($conn, $publicModel);
$specificAreaAnchorOptions = events_load_building_options($conn, $publicModel, events_route_anchor_entity_types());
$roomOptions = events_load_room_options($conn, $publicModel);
$facilityOptions = events_load_facility_options($conn);

$flash = $_SESSION["admin_events_flash"] ?? null;
unset($_SESSION["admin_events_flash"]);

$form = [
  "event_id" => 0,
  "title" => "",
  "description" => "",
  "start_date" => "",
  "start_time" => "",
  "end_date" => "",
  "end_time" => "",
  "status" => "draft",
  "location_mode" => "text_only",
  "location" => "",
  "building_id" => "",
  "specific_area_anchor_building_id" => "",
  "room_id" => "",
  "facility_id" => "",
  "map_model_file" => $publicModel,
  "map_point_x" => "",
  "map_point_y" => "",
  "map_point_z" => "",
  "map_radius" => "8",
  "banner_path" => "",
];
$formErrors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postedCsrf = (string)($_POST["events_csrf"] ?? "");
  if ($postedCsrf === "" || !hash_equals($eventsCsrf, $postedCsrf)) {
    $_SESSION["admin_events_flash"] = [
      "type" => "error",
      "message" => "The event action could not be verified. Please refresh and try again.",
    ];
    events_admin_redirect();
  }

  $action = trim((string)($_POST["action"] ?? "save"));
  $eventId = (int)($_POST["event_id"] ?? 0);

  if ($action === "delete") {
    if ($eventId <= 0) {
      $_SESSION["admin_events_flash"] = ["type" => "error", "message" => "The selected event could not be deleted."];
      events_admin_redirect();
    }
    $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ? LIMIT 1");
    if (!$stmt) {
      $_SESSION["admin_events_flash"] = ["type" => "error", "message" => "The event could not be deleted right now."];
      events_admin_redirect();
    }
    $stmt->bind_param("i", $eventId);
    $ok = $stmt->execute();
    $stmt->close();
    $_SESSION["admin_events_flash"] = [
      "type" => $ok ? "success" : "error",
      "message" => $ok ? "Event #{$eventId} was deleted." : "The event could not be deleted right now.",
    ];
    events_admin_redirect();
  }

  $form = [
    "event_id" => $eventId,
    "title" => events_trimmed($_POST["title"] ?? "", 150),
    "description" => events_trimmed($_POST["description"] ?? "", 5000),
    "start_date" => trim((string)($_POST["start_date"] ?? "")),
    "start_time" => events_normalize_time_value($_POST["start_time"] ?? ""),
    "end_date" => trim((string)($_POST["end_date"] ?? "")),
    "end_time" => events_normalize_time_value($_POST["end_time"] ?? ""),
    "status" => events_normalize_status((string)($_POST["status"] ?? "draft")),
    "location_mode" => events_normalize_location_mode((string)($_POST["location_mode"] ?? "text_only")),
    "location" => events_trimmed($_POST["location"] ?? "", 255),
    "building_id" => trim((string)($_POST["building_id"] ?? "")),
    "specific_area_anchor_building_id" => trim((string)($_POST["specific_area_anchor_building_id"] ?? "")),
    "room_id" => trim((string)($_POST["room_id"] ?? "")),
    "facility_id" => trim((string)($_POST["facility_id"] ?? "")),
    "map_model_file" => trim((string)($_POST["map_model_file"] ?? $publicModel)),
    "map_point_x" => trim((string)($_POST["map_point_x"] ?? "")),
    "map_point_y" => trim((string)($_POST["map_point_y"] ?? "")),
    "map_point_z" => trim((string)($_POST["map_point_z"] ?? "")),
    "map_radius" => trim((string)($_POST["map_radius"] ?? "8")),
    "banner_path" => trim((string)($_POST["existing_banner_path"] ?? "")),
  ];

  if ($form["title"] === "") $formErrors[] = "A title is required.";
  if ($form["start_date"] === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form["start_date"])) {
    $formErrors[] = "A valid start date is required.";
  }
  if ($form["end_date"] !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form["end_date"])) {
    $formErrors[] = "The end date must be in YYYY-MM-DD format.";
  }
  if ($form["start_date"] !== "" && $form["end_date"] !== "" && $form["end_date"] < $form["start_date"]) {
    $formErrors[] = "The end date cannot be earlier than the start date.";
  }
  $postedStartTime = trim((string)($_POST["start_time"] ?? ""));
  $postedEndTime = trim((string)($_POST["end_time"] ?? ""));
  if ($postedStartTime !== "" && $form["start_time"] === "") {
    $formErrors[] = "The start time must be in HH:MM format.";
  }
  if ($postedEndTime !== "" && $form["end_time"] === "") {
    $formErrors[] = "The end time must be in HH:MM format.";
  }
  if (($form["start_time"] === "") xor ($form["end_time"] === "")) {
    $formErrors[] = "Provide both a start time and end time, or leave both blank for an all-day event.";
  }
  if (!$formErrors && $form["start_time"] !== "" && $form["end_time"] !== "") {
    $rangeCheck = events_resolve_interval([
      "start_date" => $form["start_date"],
      "start_time" => $form["start_time"],
      "end_date" => $form["end_date"],
      "end_time" => $form["end_time"],
    ]);
    if (!$rangeCheck || $rangeCheck["end"] <= $rangeCheck["start"]) {
      $formErrors[] = "The event end time must be later than the start time.";
    }
  }

  $resolvedBuildingId = 0;
  $resolvedRoomId = 0;
  $resolvedFacilityId = 0;
  $resolvedMapModelFile = "";
  $resolvedPointX = "";
  $resolvedPointY = "";
  $resolvedPointZ = "";
  $resolvedRadius = "";

  if ($form["location_mode"] === "text_only") {
    if ($form["location"] === "") $formErrors[] = "Text-only events need a display location.";
  } elseif ($form["location_mode"] === "building") {
    $resolvedBuildingId = (int)$form["building_id"];
    $building = $resolvedBuildingId > 0 ? events_fetch_building_by_id($conn, $resolvedBuildingId) : null;
    if (!$building) {
      $formErrors[] = "Please choose a valid building.";
    } elseif ($form["location"] === "") {
      $form["location"] = trim((string)($building["building_name"] ?? ""));
    }
  } elseif ($form["location_mode"] === "room") {
    $resolvedRoomId = (int)$form["room_id"];
    $room = $resolvedRoomId > 0 ? events_fetch_room_by_id($conn, $resolvedRoomId) : null;
    if (!$room) {
      $formErrors[] = "Please choose a valid room.";
    } else {
      $resolvedBuildingId = (int)($room["building_id"] ?? 0);
      if ($form["location"] === "") {
        $form["location"] = trim((string)($room["building_name"] ?? "") . " - " . (string)($room["room_name"] ?? ""));
      }
    }
  } elseif ($form["location_mode"] === "facility") {
    $resolvedFacilityId = (int)$form["facility_id"];
    $facility = $resolvedFacilityId > 0 ? events_fetch_facility_by_id($conn, $resolvedFacilityId) : null;
    if (!$facility) {
      $formErrors[] = "Please choose a valid facility.";
    } elseif ($form["location"] === "") {
      $facilityName = trim((string)($facility["facility_name"] ?? ""));
      $facilityLocation = trim((string)($facility["location"] ?? ""));
      $form["location"] = trim($facilityName . ($facilityLocation !== "" ? " - " . $facilityLocation : ""));
    }
  } elseif ($form["location_mode"] === "specific_area") {
    if ($publicModel === "") $formErrors[] = "A public map must be available before you can pin an event area.";
    if ($form["map_point_x"] === "" || $form["map_point_y"] === "" || $form["map_point_z"] === "") {
      $formErrors[] = "Please click the map to pick the event area.";
    }
    $resolvedBuildingId = (int)$form["specific_area_anchor_building_id"];
    if ($resolvedBuildingId > 0) {
      $anchor = events_fetch_building_by_id($conn, $resolvedBuildingId);
      if (!$anchor || !events_is_route_anchor_entity_type((string)($anchor["entity_type"] ?? "building"))) {
        $formErrors[] = "Please choose a valid route anchor destination.";
        $resolvedBuildingId = 0;
      } elseif ($form["location"] === "") {
        $form["location"] = trim((string)($anchor["building_name"] ?? "") . " - Event area");
      }
    }
    if ($form["location"] === "") $form["location"] = "Event area";
    $resolvedMapModelFile = $publicModel !== "" ? $publicModel : trim((string)$form["map_model_file"]);
    $resolvedPointX = $form["map_point_x"];
    $resolvedPointY = $form["map_point_y"];
    $resolvedPointZ = $form["map_point_z"];
    $radiusValue = is_numeric($form["map_radius"]) ? (float)$form["map_radius"] : 8.0;
    if ($radiusValue <= 0) $radiusValue = 8.0;
    $resolvedRadius = rtrim(rtrim(number_format($radiusValue, 2, ".", ""), "0"), ".");
    $form["map_radius"] = $resolvedRadius;
    $form["map_model_file"] = $resolvedMapModelFile;
  }

  if (!is_numeric($form["map_radius"]) || (float)$form["map_radius"] <= 0) $form["map_radius"] = "8";
  if (!empty($_POST["remove_banner"])) $form["banner_path"] = "";

  if (!$formErrors && in_array($form["status"], ["draft", "published"], true)) {
    $conflict = events_find_overlap_conflict($conn, [
      "location_mode" => $form["location_mode"],
      "building_id" => $resolvedBuildingId,
      "room_id" => $resolvedRoomId,
      "facility_id" => $resolvedFacilityId,
      "start_date" => $form["start_date"],
      "start_time" => $form["start_time"],
      "end_date" => $form["end_date"],
      "end_time" => $form["end_time"],
    ], $eventId);
    if (is_array($conflict)) {
      $conflictTitle = trim((string)($conflict["title"] ?? "Another event"));
      $conflictWhen = events_format_date_label($conflict);
      $formErrors[] = $conflictTitle . " already overlaps this destination on " . $conflictWhen . ". Choose a different time or location.";
    }
  }

  if (isset($_FILES["banner_file"]) && (int)($_FILES["banner_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $upload = events_store_banner_upload($_FILES["banner_file"], $ROOT);
    if (!$upload["ok"]) $formErrors[] = (string)$upload["error"];
    else $form["banner_path"] = (string)$upload["path"];
  }

  if (!$formErrors) {
    $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : 0;
    $buildingIdToken = $resolvedBuildingId > 0 ? (string)$resolvedBuildingId : "0";
    $roomIdToken = $resolvedRoomId > 0 ? (string)$resolvedRoomId : "0";
    $facilityIdToken = $resolvedFacilityId > 0 ? (string)$resolvedFacilityId : "0";
    $mapModelToken = $resolvedMapModelFile !== "" ? $resolvedMapModelFile : "";
    $pointXToken = $resolvedPointX !== "" ? $resolvedPointX : "";
    $pointYToken = $resolvedPointY !== "" ? $resolvedPointY : "";
    $pointZToken = $resolvedPointZ !== "" ? $resolvedPointZ : "";
    $radiusToken = $resolvedRadius !== "" ? $resolvedRadius : "";

    if ($eventId > 0) {
      $stmt = $conn->prepare(
        "UPDATE events SET
          title = ?, description = NULLIF(?, ''), start_date = ?, start_time = NULLIF(?, ''), end_date = NULLIF(?, ''), end_time = NULLIF(?, ''),
          location = NULLIF(?, ''), banner_path = NULLIF(?, ''), status = ?, location_mode = ?,
          building_id = NULLIF(?, '0'), room_id = NULLIF(?, '0'), facility_id = NULLIF(?, '0'),
          map_model_file = NULLIF(?, ''), map_point_x = NULLIF(?, ''), map_point_y = NULLIF(?, ''),
          map_point_z = NULLIF(?, ''), map_radius = NULLIF(?, ''), last_edited_at = NOW(),
          last_edited_by_admin_id = NULLIF(?, 0)
         WHERE event_id = ? LIMIT 1"
      );
      if ($stmt) {
        $stmt->bind_param("ssssssssssssssssssii", $form["title"], $form["description"], $form["start_date"], $form["start_time"], $form["end_date"], $form["end_time"], $form["location"], $form["banner_path"], $form["status"], $form["location_mode"], $buildingIdToken, $roomIdToken, $facilityIdToken, $mapModelToken, $pointXToken, $pointYToken, $pointZToken, $radiusToken, $adminId, $eventId);
      }
      $ok = $stmt ? $stmt->execute() : false;
      if ($stmt) $stmt->close();
      if ($ok) {
        $_SESSION["admin_events_flash"] = ["type" => "success", "message" => "Event #{$eventId} was updated."];
        events_admin_redirect(["edit" => $eventId]);
      }
      $formErrors[] = "The event could not be updated right now.";
    } else {
      $stmt = $conn->prepare(
        "INSERT INTO events (
          title, description, start_date, start_time, end_date, end_time, location, banner_path, status, location_mode,
          building_id, room_id, facility_id, map_model_file, map_point_x, map_point_y, map_point_z,
          map_radius, last_edited_at, last_edited_by_admin_id
         ) VALUES (
          ?, NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, '0'),
          NULLIF(?, '0'), NULLIF(?, '0'), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
          NULLIF(?, ''), NOW(), NULLIF(?, 0)
         )"
      );
      if ($stmt) {
        $stmt->bind_param("ssssssssssssssssssi", $form["title"], $form["description"], $form["start_date"], $form["start_time"], $form["end_date"], $form["end_time"], $form["location"], $form["banner_path"], $form["status"], $form["location_mode"], $buildingIdToken, $roomIdToken, $facilityIdToken, $mapModelToken, $pointXToken, $pointYToken, $pointZToken, $radiusToken, $adminId);
      }
      $ok = $stmt ? $stmt->execute() : false;
      $newEventId = $stmt ? (int)$stmt->insert_id : 0;
      if ($stmt) $stmt->close();
      if ($ok) {
        $_SESSION["admin_events_flash"] = ["type" => "success", "message" => "Event #{$newEventId} was created."];
        events_admin_redirect(["edit" => $newEventId]);
      }
      $formErrors[] = "The event could not be created right now.";
    }
  }
}

$editId = isset($_GET["edit"]) && is_numeric($_GET["edit"]) ? (int)$_GET["edit"] : 0;
if (!$formErrors && $editId > 0) {
  $editingRow = events_load_row_by_id($conn, $editId);
  if ($editingRow) {
    $form = array_merge($form, [
      "event_id" => (int)($editingRow["event_id"] ?? 0),
      "title" => (string)($editingRow["title"] ?? ""),
      "description" => (string)($editingRow["description"] ?? ""),
      "start_date" => (string)($editingRow["start_date"] ?? ""),
      "start_time" => (string)($editingRow["start_time"] ?? ""),
      "end_date" => (string)($editingRow["end_date"] ?? ""),
      "end_time" => (string)($editingRow["end_time"] ?? ""),
      "status" => events_normalize_status((string)($editingRow["status"] ?? "draft")),
      "location_mode" => events_normalize_location_mode((string)($editingRow["location_mode"] ?? "text_only")),
      "location" => (string)($editingRow["location"] ?? ""),
      "building_id" => (string)($editingRow["building_id"] ?? ""),
      "specific_area_anchor_building_id" => (string)($editingRow["building_id"] ?? ""),
      "room_id" => (string)($editingRow["room_id"] ?? ""),
      "facility_id" => (string)($editingRow["facility_id"] ?? ""),
      "map_model_file" => (string)($editingRow["map_model_file"] ?? $publicModel),
      "map_point_x" => (string)($editingRow["map_point_x"] ?? ""),
      "map_point_y" => (string)($editingRow["map_point_y"] ?? ""),
      "map_point_z" => (string)($editingRow["map_point_z"] ?? ""),
      "map_radius" => (string)($editingRow["map_radius"] ?? "8"),
      "banner_path" => (string)($editingRow["banner_path"] ?? ""),
    ]);
  } else {
    $flash = ["type" => "error", "message" => "The selected event could not be found."];
  }
}

$eventRows = events_load_rows($conn, false);
$enrichedRows = [];
$counts = ["total" => 0, "published" => 0, "draft" => 0, "attention" => 0];
foreach ($eventRows as $row) {
  $resolution = events_resolve_location($conn, $row, $publicModel, $ROOT);
  $schedule = events_classify_schedule($row);
  $enrichedRows[] = ["row" => $row, "resolution" => $resolution, "schedule" => $schedule];
  $counts["total"]++;
  if (($row["status"] ?? "") === "published") $counts["published"]++;
  if (($row["status"] ?? "") === "draft") $counts["draft"]++;
  if (in_array($resolution["health"], ["needs_review", "broken"], true)) $counts["attention"]++;
}

admin_layout_start("Events", "events");
?>

<style>
  .events-alert {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid #d0d5dd;
    font-size: 13px;
    font-weight: 800;
  }
  .events-alert.success { background: #ecfdf3; border-color: #abefc6; color: #067647; }
  .events-alert.error { background: #fef3f2; border-color: #fecdca; color: #b42318; }
  .events-alert.info { background: #eff8ff; border-color: #b2ddff; color: #175cd3; }

  .events-layout { display: grid; gap: 16px; }
  .events-form-grid { display: grid; gap: 12px; }
  .events-row-stack { display: grid; gap: 8px; }
  .events-note { color: #475467; font-size: 12px; line-height: 1.55; }
  .events-inline-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }

  .events-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.02em;
    white-space: nowrap;
  }
  .events-chip.status-draft { background: #f2f4f7; color: #344054; }
  .events-chip.status-published { background: #ecfdf3; color: #067647; }
  .events-chip.status-cancelled { background: #fef3f2; color: #b42318; }
  .events-chip.status-archived { background: #f9f5ff; color: #6941c6; }
  .events-chip.health-valid { background: #ecfdf3; color: #067647; }
  .events-chip.health-limited { background: #fffaeb; color: #b54708; }
  .events-chip.health-needs_review { background: #fff7ed; color: #c2410c; }
  .events-chip.health-broken { background: #fef3f2; color: #b42318; }
  .events-chip.schedule-upcoming,
  .events-chip.schedule-today,
  .events-chip.schedule-ongoing { background: #eff8ff; color: #175cd3; }
  .events-chip.schedule-past,
  .events-chip.schedule-draft,
  .events-chip.schedule-cancelled,
  .events-chip.schedule-archived { background: #f2f4f7; color: #475467; }

  .events-target-block {
    display: none;
    gap: 12px;
    padding: 14px;
    border-radius: 14px;
    border: 1px solid #e4e7ec;
    background: #fcfcfd;
  }
  .events-target-block.is-visible { display: grid; }

  .events-banner-preview {
    display: grid;
    gap: 8px;
    padding: 10px;
    border-radius: 12px;
    border: 1px dashed #d0d5dd;
    background: #fff;
  }
  .events-banner-preview img {
    max-width: 260px;
    border-radius: 12px;
    border: 1px solid #eaecf0;
    display: block;
  }

  .events-picker-shell { display: grid; gap: 10px; }
  .events-picker-stage {
    height: 360px;
    border-radius: 14px;
    border: 1px solid #d0d5dd;
    overflow: hidden;
    background: linear-gradient(180deg, #f8fafc, #eef2f6);
    position: relative;
  }
  .events-picker-stage canvas {
    width: 100% !important;
    height: 100% !important;
    display: block;
  }
  .events-picker-meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .events-picker-coords {
    font-size: 12px;
    font-weight: 800;
    color: #344054;
    background: #fff;
    border: 1px solid #e4e7ec;
    border-radius: 999px;
    padding: 8px 10px;
  }

  .events-form-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; }
  .events-table-title { display: grid; gap: 4px; }
  .events-table-title strong { font-size: 14px; color: #101828; }
  .events-table-meta,
  .events-table-copy { font-size: 12px; color: #475467; line-height: 1.55; }
  .events-table-copy { white-space: pre-wrap; }
  .events-actions { display: flex; flex-wrap: wrap; gap: 8px; }
  .events-empty { color: #667085; font-weight: 800; font-size: 13px; }

  @media (max-width: 980px) {
    .events-inline-grid { grid-template-columns: 1fr; }
    .events-picker-stage { height: 300px; }
  }
</style>

<?php if (is_array($flash)): ?>
  <div class="events-alert <?= htmlspecialchars(events_admin_alert($flash), ENT_QUOTES, "UTF-8") ?>">
    <?= htmlspecialchars((string)($flash["message"] ?? ""), ENT_QUOTES, "UTF-8") ?>
  </div>
<?php endif; ?>

<?php if ($formErrors): ?>
  <div class="events-alert error">
    <?= htmlspecialchars(implode(" ", $formErrors), ENT_QUOTES, "UTF-8") ?>
  </div>
<?php endif; ?>

<div class="grid cols-4">
  <div class="card">
    <div class="kpi-title">TOTAL EVENTS</div>
    <div class="kpi-value"><?= number_format($counts["total"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">PUBLISHED</div>
    <div class="kpi-value"><?= number_format($counts["published"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">DRAFTS</div>
    <div class="kpi-value"><?= number_format($counts["draft"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">NEEDS ATTENTION</div>
    <div class="kpi-value"><?= number_format($counts["attention"]) ?></div>
  </div>
</div>

<div class="events-layout" style="margin-top:14px;">
  <div class="card">
    <div class="section-title"><?= (int)$form["event_id"] > 0 ? "Edit Event" : "Create Event" ?></div>
    <div class="events-note" style="margin-bottom:12px;">
      Directions are derived from the selected location mode. Buildings and rooms can route when the current live map has a published route. Specific-area events can also route when they are pinned to an exact spot and linked to a route-ready area or destination.
    </div>

    <form method="post" class="events-form-grid" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="events_csrf" value="<?= htmlspecialchars($eventsCsrf, ENT_QUOTES, "UTF-8") ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="event_id" value="<?= (int)$form["event_id"] ?>">
      <input type="hidden" name="existing_banner_path" value="<?= htmlspecialchars((string)$form["banner_path"], ENT_QUOTES, "UTF-8") ?>">

      <div class="events-inline-grid">
        <div class="events-row-stack">
          <div class="label">Title</div>
          <input class="input" type="text" name="title" maxlength="150" placeholder="Example: Research Colloquium" value="<?= htmlspecialchars((string)$form["title"], ENT_QUOTES, "UTF-8") ?>">
        </div>
        <div class="events-row-stack">
          <div class="label">Display Location</div>
          <input class="input" type="text" name="location" maxlength="255" placeholder="Shown on cards and details. Leave blank to auto-fill from linked targets." value="<?= htmlspecialchars((string)$form["location"], ENT_QUOTES, "UTF-8") ?>">
        </div>
      </div>

      <div class="events-row-stack">
        <div class="label">Description</div>
        <textarea class="textarea" name="description" placeholder="Add event details, reminders, or visitor notes."><?= htmlspecialchars((string)$form["description"], ENT_QUOTES, "UTF-8") ?></textarea>
      </div>

      <div class="events-inline-grid">
        <div class="events-row-stack">
          <div class="label">Start Date</div>
          <input class="input" type="date" name="start_date" value="<?= htmlspecialchars((string)$form["start_date"], ENT_QUOTES, "UTF-8") ?>">
        </div>
        <div class="events-row-stack">
          <div class="label">End Date</div>
          <input class="input" type="date" name="end_date" value="<?= htmlspecialchars((string)$form["end_date"], ENT_QUOTES, "UTF-8") ?>">
        </div>
      </div>

      <div class="events-inline-grid">
        <div class="events-row-stack">
          <div class="label">Start Time</div>
          <input class="input" type="time" name="start_time" value="<?= htmlspecialchars((string)$form["start_time"], ENT_QUOTES, "UTF-8") ?>">
          <div class="events-note">Optional. Leave both time fields blank to treat the event as all-day.</div>
        </div>
        <div class="events-row-stack">
          <div class="label">End Time</div>
          <input class="input" type="time" name="end_time" value="<?= htmlspecialchars((string)$form["end_time"], ENT_QUOTES, "UTF-8") ?>">
          <div class="events-note">Timed events need both a start and end time.</div>
        </div>
      </div>

      <div class="events-inline-grid">
        <div class="events-row-stack">
          <div class="label">Status</div>
          <select class="select" name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, "UTF-8") ?>" <?= $form["status"] === $value ? "selected" : "" ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="events-row-stack">
          <div class="label">Location Mode</div>
          <select class="select" name="location_mode" id="location_mode">
            <?php foreach ($locationModeOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, "UTF-8") ?>" <?= $form["location_mode"] === $value ? "selected" : "" ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="events-target-block<?= $form["location_mode"] === "building" ? " is-visible" : "" ?>" data-location-block="building">
        <div class="events-row-stack">
          <div class="label">Linked Building</div>
          <select class="select" name="building_id">
            <option value="">Choose a building</option>
            <?php foreach ($buildingOptions as $building): ?>
              <?php $buildingId = (string)((int)($building["building_id"] ?? 0)); ?>
              <option value="<?= htmlspecialchars($buildingId, ENT_QUOTES, "UTF-8") ?>" <?= (string)$form["building_id"] === $buildingId ? "selected" : "" ?>>
                <?= htmlspecialchars((string)($building["building_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="events-target-block<?= $form["location_mode"] === "room" ? " is-visible" : "" ?>" data-location-block="room">
        <div class="events-row-stack">
          <div class="label">Linked Room</div>
          <select class="select" name="room_id">
            <option value="">Choose a room</option>
            <?php foreach ($roomOptions as $room): ?>
              <?php
                $roomId = (string)((int)($room["room_id"] ?? 0));
                $roomLabel = trim((string)($room["building_name"] ?? ""));
                $roomName = trim((string)($room["room_name"] ?? ""));
                $roomNumber = trim((string)($room["room_number"] ?? ""));
                if ($roomName !== "") $roomLabel .= ($roomLabel !== "" ? " - " : "") . $roomName;
                if ($roomNumber !== "") $roomLabel .= " (" . $roomNumber . ")";
              ?>
              <option value="<?= htmlspecialchars($roomId, ENT_QUOTES, "UTF-8") ?>" <?= (string)$form["room_id"] === $roomId ? "selected" : "" ?>>
                <?= htmlspecialchars($roomLabel, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="events-target-block<?= $form["location_mode"] === "facility" ? " is-visible" : "" ?>" data-location-block="facility">
        <div class="events-row-stack">
          <div class="label">Linked Facility</div>
          <select class="select" name="facility_id">
            <option value="">Choose a facility</option>
            <?php foreach ($facilityOptions as $facility): ?>
              <?php
                $facilityId = (string)((int)($facility["facility_id"] ?? 0));
                $facilityLabel = trim((string)($facility["facility_name"] ?? ""));
                $facilityLocation = trim((string)($facility["location"] ?? ""));
                if ($facilityLocation !== "") $facilityLabel .= " - " . $facilityLocation;
              ?>
              <option value="<?= htmlspecialchars($facilityId, ENT_QUOTES, "UTF-8") ?>" <?= (string)$form["facility_id"] === $facilityId ? "selected" : "" ?>>
                <?= htmlspecialchars($facilityLabel, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="events-note">Facilities route only when they resolve cleanly to one room or one building through the facility-room links.</div>
        </div>
      </div>

      <div class="events-target-block<?= $form["location_mode"] === "specific_area" ? " is-visible" : "" ?>" data-location-block="specific_area">
        <div class="events-picker-shell">
          <div class="label">Specific Event Area</div>
          <div class="events-note">
            Click the public map preview to place the exact event spot. You can optionally link that spot to a route-ready area or destination such as Quadrangle so the public map can still offer directions. The saved point is tied to the current public model: <strong><?= htmlspecialchars($publicModel !== "" ? $publicModel : "No public model detected", ENT_QUOTES, "UTF-8") ?></strong>.
          </div>
          <div class="events-row-stack">
            <div class="label">Route Anchor</div>
            <select class="select" name="specific_area_anchor_building_id" id="specific_area_anchor_building_id">
              <option value="">Highlight only (no route anchor)</option>
              <?php foreach ($specificAreaAnchorOptions as $anchor): ?>
                <?php
                  $anchorId = (string)((int)($anchor["building_id"] ?? 0));
                  $anchorName = trim((string)($anchor["building_name"] ?? ""));
                  $anchorType = trim((string)($anchor["entity_type"] ?? "building"));
                  $anchorLabel = $anchorName !== "" ? $anchorName : ("Destination #" . $anchorId);
                  $anchorLabel .= " (" . ucfirst(str_replace("_", " ", $anchorType)) . ")";
                ?>
                <option value="<?= htmlspecialchars($anchorId, ENT_QUOTES, "UTF-8") ?>" <?= (string)$form["specific_area_anchor_building_id"] === $anchorId ? "selected" : "" ?>>
                  <?= htmlspecialchars($anchorLabel, ENT_QUOTES, "UTF-8") ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="events-note">Choose the destination that should supply the route and guide text. The event pin will still show the exact final spot.</div>
          </div>
          <div class="events-picker-stage" id="event-area-picker"></div>
          <div class="events-picker-meta">
            <div class="events-picker-coords" id="event-area-coords">No event area selected yet.</div>
            <button class="btn gray" type="button" id="event-area-clear">Clear Pin</button>
          </div>
          <div class="events-inline-grid">
            <div class="events-row-stack">
              <div class="label">Highlight Radius</div>
              <input class="input" type="number" min="2" max="80" step="0.5" name="map_radius" id="map_radius" value="<?= htmlspecialchars((string)$form["map_radius"], ENT_QUOTES, "UTF-8") ?>">
            </div>
            <div class="events-row-stack">
              <div class="label">Picker Status</div>
              <div class="events-note" id="event-area-status"><?= htmlspecialchars($publicModel !== "" ? "Map preview ready." : "Map preview is unavailable until a public model is published.", ENT_QUOTES, "UTF-8") ?></div>
            </div>
          </div>
        </div>
        <input type="hidden" name="map_model_file" id="map_model_file" value="<?= htmlspecialchars((string)$form["map_model_file"], ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="map_point_x" id="map_point_x" value="<?= htmlspecialchars((string)$form["map_point_x"], ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="map_point_y" id="map_point_y" value="<?= htmlspecialchars((string)$form["map_point_y"], ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="map_point_z" id="map_point_z" value="<?= htmlspecialchars((string)$form["map_point_z"], ENT_QUOTES, "UTF-8") ?>">
      </div>

      <div class="events-row-stack">
        <div class="label">Banner Image</div>
        <input class="input" type="file" name="banner_file" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="events-note">Optional. Supported formats: JPG, PNG, WEBP, GIF up to 6 MB.</div>
        <?php if (trim((string)$form["banner_path"]) !== ""): ?>
          <div class="events-banner-preview">
            <img src="<?= htmlspecialchars(events_banner_url((string)$form["banner_path"]), ENT_QUOTES, "UTF-8") ?>" alt="Current event banner">
            <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:800; color:#344054;">
              <input type="checkbox" name="remove_banner" value="1">
              Remove current banner
            </label>
          </div>
        <?php endif; ?>
      </div>

      <div class="events-form-actions">
        <div class="actions">
          <button class="btn primary" type="submit"><?= (int)$form["event_id"] > 0 ? "Save Event" : "Create Event" ?></button>
          <a class="btn gray" href="events.php">Reset Form</a>
        </div>
      </div>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Event</th>
          <th>Schedule</th>
          <th>Status</th>
          <th>Map Capability</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$enrichedRows): ?>
          <tr>
            <td colspan="5" class="events-empty">No events have been created yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($enrichedRows as $item): ?>
            <?php
              $row = $item["row"];
              $resolution = $item["resolution"];
              $schedule = $item["schedule"];
              $eventId = (int)($row["event_id"] ?? 0);
              $mapHref = "../pages/map.php?event=" . rawurlencode((string)$eventId);
              $routeHref = $mapHref . "&autoroute=1";
              $scheduleLabel = ucwords(str_replace("_", " ", $schedule));
              $displayLocation = trim((string)($resolution["displayLocation"] ?? $row["location"] ?? ""));
              $summary = trim((string)($resolution["message"] ?? ""));
              $dateLabel = events_format_date_label($row);
            ?>
            <tr>
              <td>
                <div class="events-table-title">
                  <strong><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></strong>
                  <?php if ($displayLocation !== ""): ?>
                    <div class="events-table-meta"><?= htmlspecialchars($displayLocation, ENT_QUOTES, "UTF-8") ?></div>
                  <?php endif; ?>
                  <?php if (trim((string)($row["description"] ?? "")) !== ""): ?>
                    <div class="events-table-copy"><?= htmlspecialchars(events_trimmed((string)$row["description"], 180), ENT_QUOTES, "UTF-8") ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="events-row-stack">
                  <span class="events-chip schedule-<?= htmlspecialchars($schedule, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></span>
                  <div class="events-table-meta">
                    <?= htmlspecialchars($dateLabel, ENT_QUOTES, "UTF-8") ?>
                  </div>
                </div>
              </td>
              <td>
                <div class="events-row-stack">
                  <span class="events-chip status-<?= htmlspecialchars((string)($row["status"] ?? "draft"), ENT_QUOTES, "UTF-8") ?>">
                    <?= htmlspecialchars($statusOptions[(string)($row["status"] ?? "draft")] ?? "Draft", ENT_QUOTES, "UTF-8") ?>
                  </span>
                  <span class="events-chip health-<?= htmlspecialchars((string)$resolution["health"], ENT_QUOTES, "UTF-8") ?>">
                    <?= htmlspecialchars($healthLabels[(string)$resolution["health"]] ?? "Limited", ENT_QUOTES, "UTF-8") ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="events-row-stack">
                  <div class="events-table-meta"><?= htmlspecialchars($locationModeOptions[(string)($resolution["mode"] ?? "text_only")] ?? "Text Only", ENT_QUOTES, "UTF-8") ?></div>
                  <div class="events-table-meta"><?= htmlspecialchars($summary !== "" ? $summary : "No map action available.", ENT_QUOTES, "UTF-8") ?></div>
                </div>
              </td>
              <td>
                <div class="events-actions">
                  <a class="btn blue" href="events.php?edit=<?= $eventId ?>">Edit</a>
                  <?php if (!empty($resolution["canMap"])): ?>
                    <a class="btn gray" href="<?= htmlspecialchars($mapHref, ENT_QUOTES, "UTF-8") ?>">Open Map</a>
                  <?php endif; ?>
                  <?php if (!empty($resolution["canRoute"])): ?>
                    <a class="btn gray" href="<?= htmlspecialchars($routeHref, ENT_QUOTES, "UTF-8") ?>">Directions</a>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Delete this event?');">
                    <input type="hidden" name="events_csrf" value="<?= htmlspecialchars($eventsCsrf, ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <button class="btn danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script id="events-admin-picker-config" type="application/json">
<?= json_encode([
  "modelUrl" => $publicModelUrl,
  "modelFile" => $publicModel,
  "enabled" => $publicModel !== "",
  "routeAnchors" => array_map(static function (array $anchor): array {
    return [
      "id" => (int)($anchor["building_id"] ?? 0),
      "name" => trim((string)($anchor["building_name"] ?? "")),
      "buildingUid" => trim((string)($anchor["building_uid"] ?? "")),
      "objectName" => trim((string)($anchor["model_object_name"] ?? "")),
      "entityType" => trim((string)($anchor["entity_type"] ?? "building")),
    ];
  }, $specificAreaAnchorOptions),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script>
  (function() {
    const modeSelect = document.getElementById("location_mode");
    const blocks = Array.from(document.querySelectorAll("[data-location-block]"));
    function syncBlocks() {
      const mode = modeSelect ? modeSelect.value : "text_only";
      blocks.forEach((block) => {
        block.classList.toggle("is-visible", block.getAttribute("data-location-block") === mode);
      });
      window.dispatchEvent(new CustomEvent("events:location-mode-change", {
        detail: { mode }
      }));
    }
    modeSelect?.addEventListener("change", syncBlocks);
    syncBlocks();
  })();
</script>
<script type="module" src="../js/admin-events-map-picker.js"></script>

<?php admin_layout_end(); ?>
