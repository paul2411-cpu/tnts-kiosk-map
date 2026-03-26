<?php
require_once __DIR__ . "/inc/auth.php";
require_admin_permission("manage_facilities", "You do not have access to manage facilities.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/map_entities.php";
require_once __DIR__ . "/inc/map_sync.php";
require_once __DIR__ . "/inc/destination_harmony.php";
app_logger_set_default_subsystem("facilities_admin");

$MODEL_DIR = __DIR__ . "/../models";
$ORIGINAL_MODEL_NAME = "tnts_navigation.glb";

if (empty($_SESSION["facility_editor_csrf"])) {
  try {
    $_SESSION["facility_editor_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["facility_editor_csrf"] = bin2hex(hash("sha256", uniqid((string)mt_rand(), true), true));
  }
}
$FACILITY_EDITOR_CSRF = (string)$_SESSION["facility_editor_csrf"];

function fac_fail(int $status, string $msg): void {
  app_log_http_problem($status, $msg, [
    "action" => trim((string)($_GET["action"] ?? "")),
    "modelFile" => trim((string)($_GET["model"] ?? $_POST["modelFile"] ?? "")),
  ], [
    "subsystem" => "facilities_admin",
    "event" => "http_error",
  ]);
  http_response_code($status);
  echo json_encode(["ok" => false, "error" => $msg], JSON_PRETTY_PRINT);
  exit;
}

function fac_post_csrf(): void {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") fac_fail(405, "POST required");
  $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
  $sessionToken = (string)($_SESSION["facility_editor_csrf"] ?? "");
  if (!is_string($token) || $token === "" || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    fac_fail(403, "CSRF validation failed");
  }
}

function fac_model_name(string $raw): ?string {
  $base = basename($raw);
  if ($base === "" || $base === "." || $base === "..") return null;
  if (!preg_match('/\.glb$/i', $base)) return null;
  return $base;
}

function fac_editor_model_file(): ?string {
  return map_sync_resolve_editor_model(dirname(__DIR__));
}

function fac_latest_version_id(mysqli $conn, string $modelFile): ?int {
  $stmt = $conn->prepare("SELECT version_id FROM map_versions WHERE model_file = ? ORDER BY version_id DESC LIMIT 1");
  if (!$stmt) throw new RuntimeException("Failed to prepare version lookup");
  $stmt->bind_param("s", $modelFile);
  if (!$stmt->execute()) throw new RuntimeException("Failed to query map_versions");
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row || !isset($row["version_id"])) return null;
  return (int)$row["version_id"];
}

function fac_fetch_rows(mysqli $conn, string $model = ""): array {
  map_entities_ensure_schema($conn);
  harmony_ensure_schema($conn);
  $hasSource = map_entities_has_column($conn, "facilities", "source_model_file");
  $hasPresent = map_entities_has_column($conn, "facilities", "is_present_in_latest");
  $hasVersion = map_entities_has_column($conn, "facilities", "last_seen_version_id");
  $hasEdited = map_entities_has_column($conn, "facilities", "last_edited_at");

  $sql = "SELECT facility_id, "
    . (map_entities_has_column($conn, "facilities", "facility_uid") ? "facility_uid" : "NULL AS facility_uid")
    . ", facility_name, model_object_name, description, logo_path, location, contact_info, "
    . ($hasSource ? "source_model_file" : "NULL AS source_model_file")
    . ", " . ($hasVersion ? "last_seen_version_id" : "NULL AS last_seen_version_id")
    . ", " . ($hasEdited ? "last_edited_at" : "NULL AS last_edited_at")
    . " FROM facilities WHERE facility_name IS NOT NULL AND facility_name <> ''";
  if ($hasPresent) $sql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";

  if ($model !== "" && $hasSource) {
    $sql .= " AND source_model_file = ? ORDER BY facility_name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Failed to prepare facility query");
    $stmt->bind_param("s", $model);
    if (!$stmt->execute()) throw new RuntimeException("Failed to query facilities");
    $res = $stmt->get_result();
  } else {
    $sql .= " ORDER BY facility_name ASC";
    $res = $conn->query($sql);
    if (!($res instanceof mysqli_result)) throw new RuntimeException("Failed to query facilities");
  }

  $rows = [];
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  return $rows;
}

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");
  $action = (string)$_GET["action"];
  try {
    map_entities_ensure_schema($conn);
    harmony_ensure_schema($conn);
  } catch (Throwable $e) {
    fac_fail(500, $e->getMessage());
  }

  if ($action === "list_models") {
    $editorModel = fac_editor_model_file();
    $publicState = map_sync_resolve_public_model(dirname(__DIR__));
    $publicModel = trim((string)($publicState["modelFile"] ?? ""));
    $rows = [];
    if (is_dir($MODEL_DIR)) {
      foreach (scandir($MODEL_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.glb$/i', $f)) continue;
        $rows[] = [
          "file" => $f,
          "isOriginal" => strcasecmp($f, $ORIGINAL_MODEL_NAME) === 0,
          "isDefault" => ($editorModel !== null && strcasecmp($f, $editorModel) === 0),
          "isLive" => ($publicModel !== "" && strcasecmp($f, $publicModel) === 0),
          "isEditable" => ($editorModel !== null && strcasecmp($f, $editorModel) === 0)
        ];
      }
    }
    usort($rows, static function(array $a, array $b): int {
      $editableCmp = ((int)!empty($a["isEditable"])) <=> ((int)!empty($b["isEditable"]));
      if ($editableCmp !== 0) return -$editableCmp;
      $liveCmp = ((int)!empty($a["isLive"])) <=> ((int)!empty($b["isLive"]));
      if ($liveCmp !== 0) return -$liveCmp;
      return strnatcasecmp((string)$a["file"], (string)$b["file"]);
    });
    echo json_encode(["ok" => true, "models" => $rows], JSON_PRETTY_PRINT);
    exit;
  }

  if ($action === "list_facilities") {
    try {
      $model = "";
      if (isset($_GET["model"]) && trim((string)$_GET["model"]) !== "") {
        $model = fac_model_name((string)$_GET["model"]);
        if ($model === null) fac_fail(400, "Invalid model");
      }
      $rows = fac_fetch_rows($conn, $model ?? "");
      echo json_encode(["ok" => true, "facilities" => $rows], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      fac_fail(500, $e->getMessage());
    }
  }

  if ($action === "get_facility") {
    try {
      map_entities_ensure_schema($conn);
      harmony_ensure_schema($conn);
      $id = isset($_GET["facilityId"]) && is_numeric($_GET["facilityId"]) ? (int)$_GET["facilityId"] : 0;
      if ($id <= 0) fac_fail(400, "Missing facility id");
      $stmt = $conn->prepare("
        SELECT facility_id, facility_uid, facility_name, model_object_name, description, logo_path, location, contact_info,
               source_model_file, last_seen_version_id, last_edited_at
        FROM facilities
        WHERE facility_id = ?
        LIMIT 1
      ");
      if (!$stmt) throw new RuntimeException("Failed to prepare facility lookup");
      $stmt->bind_param("i", $id);
      if (!$stmt->execute()) throw new RuntimeException("Failed to load facility");
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      echo json_encode(["ok" => true, "found" => (bool)$row, "facility" => $row ?: null], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      fac_fail(500, $e->getMessage());
    }
  }

  if ($action === "save_facility") {
    fac_post_csrf();
    try {
      map_entities_ensure_schema($conn);
      harmony_ensure_schema($conn);
      $facilityId = isset($_POST["facilityId"]) && is_numeric($_POST["facilityId"]) ? (int)$_POST["facilityId"] : 0;
      $modelFileRaw = trim((string)($_POST["modelFile"] ?? ""));
      $modelFile = $modelFileRaw === "" ? "" : fac_model_name($modelFileRaw);
      if ($modelFileRaw !== "" && $modelFile === null) fac_fail(400, "Invalid model file");

      $editableModel = fac_editor_model_file();
      if ($modelFile !== "" && $editableModel !== null && strcasecmp($editableModel, $modelFile) !== 0) {
        fac_fail(409, "Only the default admin model can be edited here. Switch the default model in Map Editor before editing this snapshot.");
      }

      $facilityName = trim((string)($_POST["facilityName"] ?? ""));
      $objectName = trim((string)($_POST["objectName"] ?? ""));
      $description = trim((string)($_POST["description"] ?? ""));
      $logoPath = trim((string)($_POST["logoPath"] ?? ""));
      $location = trim((string)($_POST["location"] ?? ""));
      $contactInfo = trim((string)($_POST["contactInfo"] ?? ""));

      if (mb_strlen($facilityName) > 100) fac_fail(400, "Facility name too long");
      if (mb_strlen($objectName) > 255) fac_fail(400, "Object name too long");
      if (mb_strlen($logoPath) > 255) fac_fail(400, "Logo path too long");
      if (mb_strlen($location) > 100) fac_fail(400, "Location too long");
      if (mb_strlen($contactInfo) > 100) fac_fail(400, "Contact info too long");

      if ($objectName !== "") {
        $classified = map_naming_classify_top_level($objectName);
        if (empty($classified["include"]) || (($classified["bucket"] ?? "") !== "facilities")) {
          fac_fail(400, "Object name must use a FAC_ top-level destination");
        }
        if ($facilityName === "") {
          $facilityName = trim((string)($classified["display_name"] ?? ""));
        }
      }
      if ($facilityName === "") fac_fail(400, "Facility name is required");
      if ($modelFile === "" && $facilityId <= 0) fac_fail(400, "Select a model before creating a facility");

      $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
      if (is_int($adminId) && $adminId <= 0) $adminId = null;
      $parentModel = $modelFile !== "" ? harmony_get_model_parent($conn, $modelFile) : "";

      $conn->begin_transaction();
      $existing = null;
      if ($facilityId > 0) {
        $stmt = $conn->prepare("
          SELECT facility_id, facility_uid, facility_name, model_object_name, source_model_file, first_seen_version_id, last_seen_version_id
          FROM facilities
          WHERE facility_id = ?
          LIMIT 1
        ");
        if (!$stmt) throw new RuntimeException("Failed to prepare facility lookup");
        $stmt->bind_param("i", $facilityId);
        if (!$stmt->execute()) throw new RuntimeException("Failed to load existing facility");
        $res = $stmt->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$existing) throw new RuntimeException("Facility record not found");
        if ($modelFile === "") $modelFile = trim((string)($existing["source_model_file"] ?? ""));
        if ($objectName === "") $objectName = trim((string)($existing["model_object_name"] ?? ""));
      }

      if ($modelFile === "") fac_fail(400, "Facility model file is required");
      $facilityUid = harmony_resolve_facility_uid($conn, $facilityName, $objectName, $modelFile, $parentModel);
      if ($existing) {
        $facilityUid = harmony_normalize_uid((string)($existing["facility_uid"] ?? ""), "fac") ?: $facilityUid;
      }

      $versionId = fac_latest_version_id($conn, $modelFile);
      $firstSeenVersion = $existing ? (int)($existing["first_seen_version_id"] ?? 0) : 0;
      $lastSeenVersion = $existing ? (int)($existing["last_seen_version_id"] ?? 0) : 0;
      if ($versionId !== null) {
        if ($firstSeenVersion <= 0) $firstSeenVersion = $versionId;
        $lastSeenVersion = $versionId;
      }

      if ($objectName !== "") {
        $dupStmt = $conn->prepare("SELECT facility_id FROM facilities WHERE source_model_file = ? AND model_object_name = ? LIMIT 1");
        if (!$dupStmt) throw new RuntimeException("Failed to prepare duplicate check");
        $dupStmt->bind_param("ss", $modelFile, $objectName);
        if (!$dupStmt->execute()) throw new RuntimeException("Failed to check duplicate facility object");
        $dupRes = $dupStmt->get_result();
        $dupRow = $dupRes ? $dupRes->fetch_assoc() : null;
        $dupStmt->close();
        $dupId = $dupRow ? (int)($dupRow["facility_id"] ?? 0) : 0;
        if ($dupId > 0 && $dupId !== $facilityId) {
          throw new RuntimeException("Another facility in this model already uses that object name.");
        }
      }

      if ($facilityId > 0) {
        if ($adminId === null) {
          $stmt = $conn->prepare("
            UPDATE facilities
            SET facility_uid = ?, facility_name = ?, model_object_name = ?, description = ?, logo_path = ?, location = ?, contact_info = ?,
                source_model_file = ?, first_seen_version_id = ?, last_seen_version_id = ?, is_present_in_latest = 1,
                last_edited_at = NOW(), last_edited_by_admin_id = NULL
            WHERE facility_id = ?
          ");
          if (!$stmt) throw new RuntimeException("Failed to prepare facility update");
          $stmt->bind_param("ssssssssiii", $facilityUid, $facilityName, $objectName, $description, $logoPath, $location, $contactInfo, $modelFile, $firstSeenVersion, $lastSeenVersion, $facilityId);
        } else {
          $stmt = $conn->prepare("
            UPDATE facilities
            SET facility_uid = ?, facility_name = ?, model_object_name = ?, description = ?, logo_path = ?, location = ?, contact_info = ?,
                source_model_file = ?, first_seen_version_id = ?, last_seen_version_id = ?, is_present_in_latest = 1,
                last_edited_at = NOW(), last_edited_by_admin_id = ?
            WHERE facility_id = ?
          ");
          if (!$stmt) throw new RuntimeException("Failed to prepare facility update");
          $stmt->bind_param("ssssssssiiii", $facilityUid, $facilityName, $objectName, $description, $logoPath, $location, $contactInfo, $modelFile, $firstSeenVersion, $lastSeenVersion, $adminId, $facilityId);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to update facility");
        $stmt->close();
      } else {
        if ($adminId === null) {
          $stmt = $conn->prepare("
            INSERT INTO facilities (
              facility_uid, facility_name, model_object_name, description, logo_path, location, contact_info,
              source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest,
              last_edited_at, last_edited_by_admin_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)
          ");
          if (!$stmt) throw new RuntimeException("Failed to prepare facility insert");
          $stmt->bind_param("ssssssssii", $facilityUid, $facilityName, $objectName, $description, $logoPath, $location, $contactInfo, $modelFile, $firstSeenVersion, $lastSeenVersion);
        } else {
          $stmt = $conn->prepare("
            INSERT INTO facilities (
              facility_uid, facility_name, model_object_name, description, logo_path, location, contact_info,
              source_model_file, first_seen_version_id, last_seen_version_id, is_present_in_latest,
              last_edited_at, last_edited_by_admin_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)
          ");
          if (!$stmt) throw new RuntimeException("Failed to prepare facility insert");
          $stmt->bind_param("ssssssssiii", $facilityUid, $facilityName, $objectName, $description, $logoPath, $location, $contactInfo, $modelFile, $firstSeenVersion, $lastSeenVersion, $adminId);
        }
        if (!$stmt->execute()) throw new RuntimeException("Failed to insert facility");
        $facilityId = (int)$stmt->insert_id;
        $stmt->close();
      }

      $metaStmt = $conn->prepare("SELECT DATE_FORMAT(last_edited_at, '%Y-%m-%d %H:%i:%s') AS edited_at FROM facilities WHERE facility_id = ? LIMIT 1");
      if (!$metaStmt) throw new RuntimeException("Failed to prepare metadata query");
      $metaStmt->bind_param("i", $facilityId);
      if (!$metaStmt->execute()) throw new RuntimeException("Failed to load edit metadata");
      $metaRes = $metaStmt->get_result();
      $metaRow = $metaRes ? $metaRes->fetch_assoc() : null;
      $metaStmt->close();

      $conn->commit();
      app_log("info", "Facility saved", [
        "facilityId" => $facilityId,
        "facilityName" => $facilityName,
        "objectName" => $objectName,
        "modelFile" => $modelFile,
      ], [
        "subsystem" => "facilities_admin",
        "event" => "save_facility",
      ]);
      echo json_encode([
        "ok" => true,
        "facilityId" => $facilityId,
        "editedAt" => (string)($metaRow["edited_at"] ?? ""),
      ], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      fac_fail(500, "Save failed: " . $e->getMessage());
    }
  }

  if ($action === "delete_facility") {
    fac_post_csrf();
    try {
      map_entities_ensure_schema($conn);
      $facilityId = isset($_POST["facilityId"]) && is_numeric($_POST["facilityId"]) ? (int)$_POST["facilityId"] : 0;
      if ($facilityId <= 0) fac_fail(400, "Missing facility id");
      $stmt = $conn->prepare("DELETE FROM facilities WHERE facility_id = ? LIMIT 1");
      if (!$stmt) throw new RuntimeException("Failed to prepare facility delete");
      $stmt->bind_param("i", $facilityId);
      if (!$stmt->execute()) throw new RuntimeException("Failed to delete facility");
      $stmt->close();
      app_log("info", "Facility deleted", [
        "facilityId" => $facilityId,
      ], [
        "subsystem" => "facilities_admin",
        "event" => "delete_facility",
      ]);
      echo json_encode(["ok" => true], JSON_PRETTY_PRINT);
      exit;
    } catch (Throwable $e) {
      fac_fail(500, "Delete failed: " . $e->getMessage());
    }
  }

  fac_fail(404, "Unknown action");
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Facilities", "facilities");
?>

<div class="card">
  <div class="section-title">Facilities Editor</div>
  <div style="color:#667085;font-weight:800;line-height:1.6;">
    Facilities are DB-backed destination labels tied to `FAC_` top-level objects in the GLB.<br>
    Use this page to refine the imported facility name, description, map object link, location, and contact info without rewriting the model.
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="row" style="grid-template-columns:140px 1fr 130px 130px;">
    <div class="label">Map Model</div>
    <select id="facility-model-select" class="select"></select>
    <button id="facility-reload-btn" type="button" class="btn primary">Reload</button>
    <button id="facility-clear-btn" type="button" class="btn gray">New</button>
  </div>
  <div id="facility-status" style="margin-top:10px;color:#334155;font-weight:800;">Ready.</div>
</div>

<div style="display:grid;grid-template-columns:minmax(420px,1fr) minmax(360px,0.95fr);gap:12px;margin-top:12px;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Facility</th><th>Object Name</th><th>Location</th><th>Contact</th><th>Model</th><th>Version</th><th>Last Edited</th>
        </tr>
      </thead>
      <tbody id="facility-table-body">
        <tr><td colspan="8">No data loaded yet.</td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="section-title">Selected Facility</div>
    <form class="form" onsubmit="return false;">
      <div class="row"><div class="label">Facility ID</div><input id="facility-id" class="input" readonly /></div>
      <div class="row"><div class="label">Source Model</div><input id="facility-model" class="input" readonly /></div>
      <div class="row"><div class="label">Display Name</div><input id="facility-name" class="input" /></div>
      <div class="row"><div class="label">Object Name</div><input id="facility-object-name" class="input" placeholder="FAC_CLINIC" /></div>
      <div class="row"><div class="label">Location</div><input id="facility-location" class="input" placeholder="Near BLD_ADMIN" /></div>
      <div class="row"><div class="label">Contact Info</div><input id="facility-contact" class="input" placeholder="0912 345 6789" /></div>
      <div class="row"><div class="label">Logo Path</div><input id="facility-logo" class="input" placeholder="/assets/facilities/clinic.jpg" /></div>
      <div class="row"><div class="label">Description</div><textarea id="facility-description" class="textarea"></textarea></div>
      <div class="row"><div class="label">Version ID</div><input id="facility-version" class="input" readonly /></div>
      <div class="row"><div class="label">Last Edited</div><input id="facility-edited-at" class="input" readonly /></div>
      <div class="actions">
        <button id="facility-save-btn" type="button" class="btn primary">Save Facility</button>
        <button id="facility-delete-btn" type="button" class="btn danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
const CSRF = <?= json_encode($FACILITY_EDITOR_CSRF) ?>;
const ORIGINAL = <?= json_encode($ORIGINAL_MODEL_NAME) ?>;

const modelSel = document.getElementById("facility-model-select");
const reloadBtn = document.getElementById("facility-reload-btn");
const clearBtn = document.getElementById("facility-clear-btn");
const statusEl = document.getElementById("facility-status");
const tableBody = document.getElementById("facility-table-body");

const facilityIdEl = document.getElementById("facility-id");
const facilityModelEl = document.getElementById("facility-model");
const facilityNameEl = document.getElementById("facility-name");
const objectNameEl = document.getElementById("facility-object-name");
const locationEl = document.getElementById("facility-location");
const contactEl = document.getElementById("facility-contact");
const logoEl = document.getElementById("facility-logo");
const descriptionEl = document.getElementById("facility-description");
const versionEl = document.getElementById("facility-version");
const editedAtEl = document.getElementById("facility-edited-at");
const saveBtn = document.getElementById("facility-save-btn");
const deleteBtn = document.getElementById("facility-delete-btn");

let modelRows = [];
let selectedFacilityId = 0;

function setStatus(msg, color = "#334155") {
  statusEl.textContent = String(msg || "");
  statusEl.style.color = color;
}

function esc(v) {
  return String(v ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll("\"", "&quot;")
    .replaceAll("'", "&#39;");
}

function currentModelMeta() {
  return modelRows.find((row) => String(row?.file || "") === String(modelSel.value || "")) || null;
}

function isCurrentModelEditable() {
  const meta = currentModelMeta();
  return !meta || !!meta.isEditable;
}

function updateEditorMode() {
  const editable = isCurrentModelEditable();
  saveBtn.disabled = !editable;
  deleteBtn.disabled = !editable || selectedFacilityId <= 0;
  saveBtn.title = editable ? "" : "Archive models are read-only here. Switch the default model in Map Editor to edit this snapshot.";
  if (!editable && modelSel.value) {
    setStatus(`Archive snapshot selected: ${modelSel.value}. Switch the default model in Map Editor to edit this snapshot.`, "#b54708");
  }
}

function clearForm() {
  selectedFacilityId = 0;
  facilityIdEl.value = "";
  facilityModelEl.value = modelSel.value || "";
  facilityNameEl.value = "";
  objectNameEl.value = "";
  locationEl.value = "";
  contactEl.value = "";
  logoEl.value = "";
  descriptionEl.value = "";
  versionEl.value = "";
  editedAtEl.value = "";
  deleteBtn.disabled = true;
}

function fillForm(row) {
  selectedFacilityId = Number(row?.facility_id || 0);
  facilityIdEl.value = selectedFacilityId > 0 ? String(selectedFacilityId) : "";
  facilityModelEl.value = String(row?.source_model_file || modelSel.value || "");
  facilityNameEl.value = String(row?.facility_name || "");
  objectNameEl.value = String(row?.model_object_name || "");
  locationEl.value = String(row?.location || "");
  contactEl.value = String(row?.contact_info || "");
  logoEl.value = String(row?.logo_path || "");
  descriptionEl.value = String(row?.description || "");
  versionEl.value = row?.last_seen_version_id != null ? String(row.last_seen_version_id) : "";
  editedAtEl.value = String(row?.last_edited_at || "");
  updateEditorMode();
}

async function loadModels() {
  const previousValue = modelSel.value || "";
  const res = await fetch("facilities.php?action=list_models", { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  const rows = Array.isArray(data.models) ? data.models : [];
  modelRows = rows;
  modelSel.innerHTML = "";
  if (!rows.length) {
    modelSel.disabled = true;
    modelSel.innerHTML = "<option value=\"\">No model</option>";
    clearForm();
    updateEditorMode();
    return;
  }
  rows.forEach((r) => {
    const o = document.createElement("option");
    o.value = r.file;
    const tags = [];
    if (r.isLive) tags.push("published");
    if (r.isEditable) tags.push("editable");
    if (r.isOriginal) tags.push("original");
    if (!r.isEditable && !r.isLive) tags.push("snapshot");
    else if (!r.isEditable && r.isLive) tags.push("read-only");
    o.textContent = tags.length ? `${r.file} (${tags.join(", ")})` : r.file;
    modelSel.appendChild(o);
  });
  modelSel.disabled = false;
  const names = rows.map((r) => r.file);
  const preferred = rows.find((r) => r.isEditable)?.file || rows.find((r) => r.isLive)?.file || (names.includes(ORIGINAL) ? ORIGINAL : names[0]);
  modelSel.value = names.includes(previousValue) ? previousValue : preferred;
  facilityModelEl.value = modelSel.value || "";
  updateEditorMode();
}

async function loadFacilities() {
  let url = "facilities.php?action=list_facilities";
  if (modelSel.value) url += `&model=${encodeURIComponent(modelSel.value)}`;
  const res = await fetch(url, { cache: "no-store" });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  const rows = Array.isArray(data.facilities) ? data.facilities : [];
  tableBody.innerHTML = "";
  if (!rows.length) {
    tableBody.innerHTML = "<tr><td colspan=\"8\">No facilities for this model.</td></tr>";
    return;
  }
  rows.forEach((row) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${esc(row.facility_id)}</td><td>${esc(row.facility_name)}</td><td>${esc(row.model_object_name || "-")}</td><td>${esc(row.location || "-")}</td><td>${esc(row.contact_info || "-")}</td><td>${esc(row.source_model_file || "-")}</td><td>${esc(row.last_seen_version_id || "-")}</td><td>${esc(row.last_edited_at || "-")}</td>`;
    tr.addEventListener("click", () => {
      fillForm(row);
      setStatus(`Loaded facility ${String(row.facility_name || "").trim() || row.facility_id}.`, "#0f766e");
    });
    tableBody.appendChild(tr);
  });
}

async function saveFacility() {
  if (!modelSel.value) {
    setStatus("Select model first.", "#b42318");
    return;
  }
  if (!isCurrentModelEditable()) {
    setStatus("This snapshot is archive-only here. Switch the default model in Map Editor before saving facility changes.", "#b54708");
    return;
  }
  const fd = new FormData();
  if (selectedFacilityId > 0) fd.append("facilityId", String(selectedFacilityId));
  fd.append("modelFile", modelSel.value || "");
  fd.append("facilityName", facilityNameEl.value || "");
  fd.append("objectName", objectNameEl.value || "");
  fd.append("location", locationEl.value || "");
  fd.append("contactInfo", contactEl.value || "");
  fd.append("logoPath", logoEl.value || "");
  fd.append("description", descriptionEl.value || "");

  setStatus(`Saving facility ${facilityNameEl.value || objectNameEl.value || "..." } ...`);
  const res = await fetch("facilities.php?action=save_facility", {
    method: "POST",
    headers: { "X-CSRF-Token": CSRF },
    body: fd
  });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  if (data.facilityId) {
    selectedFacilityId = Number(data.facilityId || 0);
    facilityIdEl.value = String(selectedFacilityId || "");
  }
  editedAtEl.value = String(data.editedAt || "");
  await loadFacilities();
  updateEditorMode();
  setStatus(`Saved facility ${facilityNameEl.value || objectNameEl.value || selectedFacilityId}.`, "#0f766e");
}

async function deleteFacility() {
  if (selectedFacilityId <= 0) {
    setStatus("Select a facility first.", "#b42318");
    return;
  }
  if (!isCurrentModelEditable()) {
    setStatus("This snapshot is archive-only here. Switch the default model in Map Editor before deleting facility metadata.", "#b54708");
    return;
  }
  if (!window.confirm(`Delete facility ${facilityNameEl.value || selectedFacilityId}?`)) return;

  const fd = new FormData();
  fd.append("facilityId", String(selectedFacilityId));
  const res = await fetch("facilities.php?action=delete_facility", {
    method: "POST",
    headers: { "X-CSRF-Token": CSRF },
    body: fd
  });
  const data = await res.json();
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  clearForm();
  await loadFacilities();
  setStatus("Facility deleted.", "#0f766e");
}

reloadBtn.addEventListener("click", () => {
  Promise.all([loadModels(), loadFacilities()])
    .then(() => setStatus("Facility data reloaded.", "#334155"))
    .catch((err) => setStatus(`Reload failed: ${err?.message || err}`, "#b42318"));
});

clearBtn.addEventListener("click", () => {
  clearForm();
  updateEditorMode();
  setStatus("Ready to create a new facility metadata entry.", "#334155");
});

modelSel.addEventListener("change", () => {
  clearForm();
  facilityModelEl.value = modelSel.value || "";
  updateEditorMode();
  loadFacilities().catch((err) => setStatus(`Failed to load facilities: ${err?.message || err}`, "#b42318"));
});

saveBtn.addEventListener("click", () => {
  saveFacility().catch((err) => setStatus(`Save failed: ${err?.message || err}`, "#b42318"));
});

deleteBtn.addEventListener("click", () => {
  deleteFacility().catch((err) => setStatus(`Delete failed: ${err?.message || err}`, "#b42318"));
});

(async () => {
  try {
    await loadModels();
    clearForm();
    await loadFacilities();
    if (modelSel.value) {
      setStatus(`Loaded facilities for ${modelSel.value}.`, "#0f766e");
    } else {
      setStatus("No GLB model available.", "#b42318");
    }
  } catch (err) {
    setStatus(`Init failed: ${err?.message || err}`, "#b42318");
  }
})();
</script>

<?php admin_layout_end(); ?>
