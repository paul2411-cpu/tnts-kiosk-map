<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();

$ASSET_DIR = __DIR__ . "/assets_map";
$OVERLAY_PATH = __DIR__ . "/overlays/map_overlay.json";
$MODEL_DIR = __DIR__ . "/../models";
$DEFAULT_MODEL_PATH = __DIR__ . "/overlays/default_model.json";
$ORIGINAL_MODEL_NAME = "tnts_map.glb";

if (isset($_GET["action"])) {
  header("Content-Type: application/json; charset=utf-8");

  if ($_GET["action"] === "list_assets") {
    $items = [];
    if (is_dir($ASSET_DIR)) {
      foreach (scandir($ASSET_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.(glb|gltf)$/i', $f)) continue;
        $items[] = [
          "label" => pathinfo($f, PATHINFO_FILENAME),
          "path"  => "assets_map/" . $f
        ];
      }
    }
    echo json_encode(["ok" => true, "assets" => $items], JSON_PRETTY_PRINT);
    exit;
  }

  if ($_GET["action"] === "list_models") {
    $defaultModel = $ORIGINAL_MODEL_NAME;
    if (file_exists($DEFAULT_MODEL_PATH)) {
      $raw = file_get_contents($DEFAULT_MODEL_PATH);
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json["file"])) {
        $candidate = basename($json["file"]);
        if (preg_match('/\.glb$/i', $candidate) && file_exists($MODEL_DIR . "/" . $candidate)) {
          $defaultModel = $candidate;
        }
      }
    }

    $items = [];
    if (is_dir($MODEL_DIR)) {
      foreach (scandir($MODEL_DIR) as $f) {
        if ($f === "." || $f === "..") continue;
        if (!preg_match('/\.glb$/i', $f)) continue;
        $items[] = [
          "file" => $f,
          "label" => pathinfo($f, PATHINFO_FILENAME),
          "url" => "../models/" . $f,
          "isOriginal" => ($f === $ORIGINAL_MODEL_NAME),
          "isDefault" => ($f === $defaultModel),
        ];
      }
    }
    echo json_encode(["ok" => true, "models" => $items], JSON_PRETTY_PRINT);
    exit;
  }

  if ($_GET["action"] === "get_default_model") {
    $defaultModel = $ORIGINAL_MODEL_NAME;
    if (file_exists($DEFAULT_MODEL_PATH)) {
      $raw = file_get_contents($DEFAULT_MODEL_PATH);
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json["file"])) {
        $candidate = basename($json["file"]);
        if (preg_match('/\.glb$/i', $candidate) && file_exists($MODEL_DIR . "/" . $candidate)) {
          $defaultModel = $candidate;
        }
      }
    }
    echo json_encode(["ok" => true, "file" => $defaultModel], JSON_PRETTY_PRINT);
    exit;
  }

  if ($_GET["action"] === "set_default_model") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
      http_response_code(405);
      echo json_encode(["ok" => false, "error" => "POST required"]);
      exit;
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $file = is_array($data) && !empty($data["file"]) ? basename($data["file"]) : "";

    if ($file === "" || !preg_match('/\.glb$/i', $file)) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid file"]);
      exit;
    }

    $path = $MODEL_DIR . "/" . $file;
    if (!file_exists($path)) {
      http_response_code(404);
      echo json_encode(["ok" => false, "error" => "File not found"]);
      exit;
    }

    $overlayDir = dirname($DEFAULT_MODEL_PATH);
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    $payload = ["file" => $file, "updated" => time()];
    file_put_contents($DEFAULT_MODEL_PATH, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(["ok" => true, "file" => $file], JSON_PRETTY_PRINT);
    exit;
  }

  if ($_GET["action"] === "load_overlay") {
    if (file_exists($OVERLAY_PATH)) {
      echo file_get_contents($OVERLAY_PATH);
    } else {
      echo json_encode(["version" => 1, "items" => []], JSON_PRETTY_PRINT);
    }
    exit;
  }

  if ($_GET["action"] === "save_overlay") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
      http_response_code(405);
      echo json_encode(["ok" => false, "error" => "POST required"]);
      exit;
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data["items"]) || !is_array($data["items"])) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
      exit;
    }

    $overlayDir = dirname($OVERLAY_PATH);
    if (!is_dir($overlayDir)) mkdir($overlayDir, 0775, true);

    file_put_contents($OVERLAY_PATH, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(["ok" => true]);
    exit;
  }

  if ($_GET["action"] === "export_glb") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
      http_response_code(405);
      echo json_encode(["ok" => false, "error" => "POST required"]);
      exit;
    }

    $name = isset($_GET["name"]) ? trim($_GET["name"]) : "";
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($safe === "" || $safe === "." || $safe === "..") {
      $safe = "tnts_map_export_" . date("Ymd_His") . ".glb";
    }
    if (!preg_match('/\.glb$/i', $safe)) {
      $safe .= ".glb";
    }

    $exportDir = __DIR__ . "/../models";
    if (!is_dir($exportDir)) mkdir($exportDir, 0775, true);

    $base = preg_replace('/\.glb$/i', '', $safe);
    $finalName = $safe;
    $path = $exportDir . "/" . $finalName;
    $i = 1;
    while (file_exists($path)) {
      $finalName = $base . "_" . $i . ".glb";
      $path = $exportDir . "/" . $finalName;
      $i++;
    }

    $raw = file_get_contents("php://input");
    if ($raw === false || strlen($raw) < 12) {
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Empty or invalid GLB"]);
      exit;
    }

    file_put_contents($path, $raw, LOCK_EX);
    echo json_encode(["ok" => true, "file" => $finalName, "path" => "models/" . $finalName], JSON_PRETTY_PRINT);
    exit;
  }

  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Unknown action"]);
  exit;
}

require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Map Editor", "mapeditor");
?>

<div class="card">
  <div class="section-title">Map Editor</div>
  <div style="color:#667085;font-weight:800; line-height:1.6;">
    Hover highlights ONE object at a time. Click selects and keeps highlight until another click. Move only works when Move tool is active.
  </div>
</div>

<div class="map-box">
  <div style="--stage-h: clamp(620px, 78vh, 1100px); display:grid; grid-template-columns: 1fr 360px; gap:12px; padding:12px; align-items:stretch;">
    <div style="position:relative;">
      <div id="map-stage" style="width:100%; height: var(--stage-h); background:#f3f4f6; border-radius:14px; overflow:hidden; position:relative;"></div>

      <!-- Angle readout -->
      <div id="angle-readout"
           style="position:absolute; left:0; top:0; display:none; pointer-events:none;
                  transform: translate(12px, 12px);
                  padding:6px 8px; border-radius:10px;
                  font-weight:900; font-size:12px;
                  background:rgba(0,0,0,0.55);
                  border:1px solid rgba(255,255,255,0.20);
                  color:#fff; z-index:50;">
        0.0°
      </div>

      <div style="position:absolute; left:12px; bottom:12px; background:rgba(255,255,255,0.9); padding:8px 10px; border-radius:12px; font-weight:800; color:#374151; border:1px solid #e5e7eb;">
        Hover highlights (temporary). Tap/click selects (stays). Move requires Move tool active. Two-finger zoom/pan.
      </div>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px; height: var(--stage-h); overflow:auto;">
      <div class="map-title" style="font-size:13px; margin-bottom:6px;">3D Map Editor</div>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:10px;">
        <button id="btn-undo" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Undo</button>
        <button id="btn-redo" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Redo</button>
        <button id="btn-commit" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Commit (Lock)</button>
        <button id="btn-edit" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Uncommit (Unlock)</button>
        <button id="btn-top" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Top View</button>
        <button id="btn-reset" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Reset View</button>
      </div>

      <div id="status" style="font-weight:900; color:#6b7280; margin-bottom:12px;">Booting&hellip;</div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:6px;">Base Model</div>
      <select id="base-model-select" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; font-weight:700; margin-bottom:6px;"></select>
      <div style="display:flex; gap:8px; margin-bottom:6px;">
        <button id="base-model-default" class="btn" type="button" style="padding:6px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Set Default</button>
      </div>
      <div id="base-model-note" style="font-size:12px; color:#6b7280; font-weight:700; margin-bottom:10px;">
        Switch between original and exported maps. Non-original maps load without overlay JSON.
      </div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:6px;">Buildings</div>
      <div id="building-selected" style="font-size:12px; color:#6b7280; font-weight:700; margin-bottom:6px;">Selected: None</div>
      <input id="building-filter" type="text" placeholder="Filter buildings" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; font-weight:700; margin-bottom:8px;">
      <div id="building-list" style="display:flex; flex-direction:column; gap:6px; max-height:180px; overflow:auto;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:10px;">Assets</div>
      <div id="asset-list" style="display:flex; flex-direction:column; gap:8px;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:6px;">Tools</div>
      <div id="tool-indicator" style="font-weight:800; color:#6b7280; margin-bottom:10px;">Active tool: None</div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button id="tool-move" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Move</button>
        <button id="tool-rotate" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Rotate</button>
        <button id="tool-scale" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Scale</button>
        <button id="tool-road" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Road</button>
        <button id="tool-align" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Auto Align</button>
        <button id="tool-delete" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Delete</button>
        <button id="tool-save" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Save</button>
        <button id="tool-export" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Export GLB</button>
        <button id="tool-cancel" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;">Cancel Tool</button>
      </div>

      <div id="road-controls" style="display:none; margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
        <div style="font-weight:900; margin-bottom:6px;">Road Tool</div>
        <div style="font-size:12px; color:#6b7280; font-weight:700; line-height:1.45; margin-bottom:10px;">
          Tap the map to place a road point. Drag a road point to draw a segment (Draw) or reposition it (Move). Use two-finger gestures to pan/zoom.
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px;">
          <div style="font-weight:900; font-size:12px;">Width</div>
          <div id="road-width-readout" style="font-weight:900; font-size:12px; color:#6b7280;">12</div>
        </div>
        <input id="road-width" type="range" min="2" max="60" step="1" value="12" style="width:100%;">

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
          <button id="road-finish" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Finish Road</button>
          <button id="road-cancel" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Cancel Draft</button>
          <button id="road-new" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">New Road</button>
          <button id="road-snap" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Snap: On</button>
          <button id="road-building-snap" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Attach: Off</button>
          <button id="road-drag-mode" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Drag: Draw</button>
        </div>

        <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
          <div style="font-size:12px; color:#6b7280; font-weight:800; line-height:1.45; margin-bottom:10px;">
            Selected point: <span id="road-point-selected" style="font-weight:900; color:#111827;">None</span>
            <span style="margin-left:10px;">Extend from: <span id="road-extend-from" style="font-weight:900; color:#111827;">Auto</span></span>
          </div>

          <div style="font-weight:900; margin-bottom:6px;">Connect to Building</div>
          <div style="font-size:12px; color:#6b7280; font-weight:800; line-height:1.45; margin-bottom:10px;">
            Target: <span id="road-connect-target" style="font-weight:900; color:#111827;">None</span>
          </div>

          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button id="road-pick-building" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Pick Building</button>
            <button id="road-clear-building" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Clear</button>
          </div>

          <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
            <button id="road-connect-front" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Front</button>
            <button id="road-connect-back" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Back</button>
            <button id="road-connect-left" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Left</button>
            <button id="road-connect-right" class="btn" type="button" style="padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-weight:900;">Right</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script type="importmap">
{
  "imports": {
    "three": "../three.js-master/build/three.module.js",
    "three/addons/": "../three.js-master/examples/jsm/"
  }
}
</script>

<script type="module">
import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { TransformControls } from "three/addons/controls/TransformControls.js";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { GLTFExporter } from "three/addons/exporters/GLTFExporter.js";

// Allow editing base model objects too
const ALLOW_EDIT_BASE_MODEL = true;

// REQUIRE pressing a tool button before tool works
let currentTool = "none"; // "none" | "move" | "rotate" | "scale" | "road"

// Auto Align — works only with Move
let autoAlignEnabled = false;
const ALIGN_SNAP_DIST = 10;
const ALIGN_GUIDE_Y_OFFSET = 0.05;

// Roads (generated geometry)
const ROAD_Y_OFFSET = 0.2;
const ROAD_DEFAULT_WIDTH = 12;
const ROAD_SNAP_STEP = 10;
const ROAD_SNAP_FINE_DEG = 5; // when Snap is Off, lock angle to 5° increments
const ROAD_MIN_POINT_DIST = 2;
const ROAD_HANDLE_BASE_SIZE = 6;
const ROAD_MITER_LIMIT = 2.5;
const ROAD_BUILDING_SNAP_DIST = 18; // world units (XZ) to snap a dragged road point to a building side
const ROAD_BUILDING_GAP = 0; // extra clearance between road edge and building wall (0 = touch)

let roadSnapEnabled = true;
let roadBuildingSnapEnabled = false;
let roadDragMode = "draw"; // "draw" | "move" (draw creates a new segment from a point; move drags an existing point)
let roadDraft = null; // { points: THREE.Vector3[], width: number, mesh: THREE.Mesh|null }
let isDraggingRoadHandle = false;
let roadHandleDrag = null; // { road: THREE.Object3D, index: number, pointerId: number, beforePoints: number[][], beforeWidth: number }
let roadTap = null; // { pointerId: number, startX: number, startY: number, hadMultiTouch: boolean }
let isDraggingRoadSegment = false;
let roadSegmentDrag = null; // { road: THREE.Object3D, startIndex: number, pointerId: number, startX: number, startY: number, startWorld: THREE.Vector3, lastEndWorld: THREE.Vector3|null, beforePoints: number[][], beforeWidth: number }
let roadSelectedPointIndex = null; // number (0-based) or null
let roadExtendFrom = null; // "start" | "end" | null (overrides nearest-end extension)
let roadExtendFromRoadId = null; // road id string|null (guards extend-from state)
let roadPickBuildingMode = false; // when true, next tap selects a building as connect target
let roadConnectTarget = null; // THREE.Object3D|null (base model building)

const statusEl = document.getElementById("status");
const mapStage = document.getElementById("map-stage");
const angleReadoutEl = document.getElementById("angle-readout");

const btnReset = document.getElementById("btn-reset");
const btnTop = document.getElementById("btn-top");

 const btnUndo = document.getElementById("btn-undo");
 const btnRedo = document.getElementById("btn-redo");
 const btnCommit = document.getElementById("btn-commit");
 const btnEdit = document.getElementById("btn-edit");

const assetListEl = document.getElementById("asset-list");
const baseModelSelect = document.getElementById("base-model-select");
const baseModelNote = document.getElementById("base-model-note");
const baseModelDefaultBtn = document.getElementById("base-model-default");
const buildingListEl = document.getElementById("building-list");
const buildingFilterEl = document.getElementById("building-filter");
const buildingSelectedEl = document.getElementById("building-selected");

const toolMove = document.getElementById("tool-move");
const toolRotate = document.getElementById("tool-rotate");
const toolScale = document.getElementById("tool-scale");
const toolRoad = document.getElementById("tool-road");
const toolAlign = document.getElementById("tool-align");
const toolDelete = document.getElementById("tool-delete");
const toolSave = document.getElementById("tool-save");
const toolExport = document.getElementById("tool-export");
const toolCancel = document.getElementById("tool-cancel");
const toolIndicator = document.getElementById("tool-indicator");

const roadControls = document.getElementById("road-controls");
const roadWidthEl = document.getElementById("road-width");
const roadWidthReadoutEl = document.getElementById("road-width-readout");
const roadFinishBtn = document.getElementById("road-finish");
const roadCancelBtn = document.getElementById("road-cancel");
const roadNewBtn = document.getElementById("road-new");
const roadSnapBtn = document.getElementById("road-snap");
const roadBuildingSnapBtn = document.getElementById("road-building-snap");
const roadDragModeBtn = document.getElementById("road-drag-mode");
const roadPointSelectedEl = document.getElementById("road-point-selected");
const roadExtendFromEl = document.getElementById("road-extend-from");
const roadConnectTargetEl = document.getElementById("road-connect-target");
const roadPickBuildingBtn = document.getElementById("road-pick-building");
const roadClearBuildingBtn = document.getElementById("road-clear-building");
const roadConnectFrontBtn = document.getElementById("road-connect-front");
const roadConnectBackBtn = document.getElementById("road-connect-back");
const roadConnectLeftBtn = document.getElementById("road-connect-left");
const roadConnectRightBtn = document.getElementById("road-connect-right");

 function setStatus(msg) {
   if (statusEl) statusEl.textContent = msg;
   console.log("[MapEditor]", msg);
 }
 
 function updateCommitButtons() {
   const hasSelection = !!selected;
   const locked = !!selected?.userData?.locked;
 
   if (btnCommit) {
     btnCommit.disabled = !hasSelection || locked;
     btnCommit.style.opacity = btnCommit.disabled ? "0.6" : "1";
   }
   if (btnEdit) {
     btnEdit.disabled = !hasSelection || !locked;
     btnEdit.style.opacity = btnEdit.disabled ? "0.6" : "1";
   }
 }

function showError(title, err) {
  console.error(err);
  setStatus(title);
  const pre = document.createElement("pre");
  pre.style.whiteSpace = "pre-wrap";
  pre.style.padding = "12px";
  pre.style.margin = "12px";
  pre.style.background = "#fff";
  pre.style.border = "1px solid #e5e7eb";
  pre.style.borderRadius = "12px";
  pre.textContent = `${title}\n\n${String(err?.message || err)}`;
  mapStage.appendChild(pre);
}

function setActiveToolButton(btn) {
  [toolMove, toolRotate, toolScale, toolRoad].forEach(b => {
    if (!b) return;
    b.style.outline = "none";
    b.style.boxShadow = "none";
  });
  if (btn) {
    btn.style.outline = "2px solid #111827";
    btn.style.outlineOffset = "2px";
  }
}

function updateToolIndicator() {
  if (!toolIndicator) return;
  const labels = { none: "None", move: "Move", rotate: "Rotate", scale: "Scale", road: "Road" };
  const label = labels[currentTool] || "None";
  const alignTag = (currentTool === "move" && autoAlignEnabled) ? " (Auto Align On)" : "";
  toolIndicator.textContent = `Active tool: ${label}${alignTag}`;
  if (toolCancel) {
    toolCancel.disabled = currentTool === "none";
    toolCancel.style.opacity = toolCancel.disabled ? "0.6" : "1";
  }
  updateRoadControls();
}

function updateAutoAlignButton() {
  if (!toolAlign) return;
  if (autoAlignEnabled) {
    toolAlign.style.outline = "2px solid #ef4444";
    toolAlign.style.outlineOffset = "2px";
    toolAlign.style.background = "#fee2e2";
  } else {
    toolAlign.style.outline = "none";
    toolAlign.style.outlineOffset = "0";
    toolAlign.style.background = "#fff";
  }
}

function clearActiveTool(showStatus = true) {
  currentTool = "none";
  autoAlignEnabled = false;
  setActiveToolButton(null);
  updateAutoAlignButton();
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  syncTransformToSelection();
  updateToolIndicator();
  clearAlignGuides();
  hideAngleReadout();
  if (controls) controls.enabled = true;
  if (showStatus) setStatus("Tool cleared");
}

setStatus("Booting…");
updateToolIndicator();
updateAutoAlignButton();

// Block TransformControls keyboard mode switching (W/E/R)
const TRANSFORM_KEY_BLOCK = new Set(["KeyW", "KeyE", "KeyR"]);
window.addEventListener("keydown", (e) => {
  if (TRANSFORM_KEY_BLOCK.has(e.code)) {
    e.preventDefault();
    e.stopImmediatePropagation();
  }
}, true);

// -------------------- Renderer / Scene / Camera --------------------
const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.outputColorSpace = THREE.SRGBColorSpace;
mapStage.appendChild(renderer.domElement);

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xf3f4f6);

const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 10000);
camera.position.set(0, 500, 500);

const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.dampingFactor = 0.08;
controls.screenSpacePanning = true;
controls.mouseButtons = {
  LEFT: THREE.MOUSE.ROTATE,
  MIDDLE: THREE.MOUSE.DOLLY,
  RIGHT: THREE.MOUSE.PAN
};
renderer.domElement.addEventListener("contextmenu", (e) => e.preventDefault());

// Pointer tracking (helps make Road tool touch-friendly: 1-finger edits, 2-finger camera)
const activeTouchPointerIds = new Set();
function syncControlsForPointers() {
  if (currentTool === "road") {
    const touchCount = activeTouchPointerIds.size;
    controls.enabled = touchCount !== 1 && !isDraggingRoadHandle && !isDraggingRoadSegment;
  }
}
renderer.domElement.addEventListener("pointerdown", (e) => {
  if (e.pointerType === "touch" || e.pointerType === "pen") activeTouchPointerIds.add(e.pointerId);
  if (currentTool === "road" && roadTap && activeTouchPointerIds.size > 1) roadTap.hadMultiTouch = true;
  syncControlsForPointers();
}, true);
window.addEventListener("pointerup", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  syncControlsForPointers();
}, true);
window.addEventListener("pointercancel", (e) => {
  activeTouchPointerIds.delete(e.pointerId);
  syncControlsForPointers();
}, true);

// Lights
scene.add(new THREE.AmbientLight(0xffffff, 0.9));
const dir = new THREE.DirectionalLight(0xffffff, 0.8);
dir.position.set(200, 300, 150);
scene.add(dir);

// Helpers
scene.add(new THREE.GridHelper(5000, 200));
scene.add(new THREE.AxesHelper(100));

// Auto Align guides (red lines)
const alignGuideMaterial = new THREE.LineBasicMaterial({
  color: 0xef4444,
  transparent: true,
  opacity: 0.9,
  depthTest: false
});
const alignGuides = new THREE.Group();
alignGuides.renderOrder = 20;
scene.add(alignGuides);

function clearAlignGuides() {
  while (alignGuides.children.length) {
    const child = alignGuides.children.pop();
    child.geometry?.dispose?.();
  }
}

function addGuideLine(a, b) {
  const geom = new THREE.BufferGeometry().setFromPoints([a, b]);
  const line = new THREE.Line(geom, alignGuideMaterial);
  line.renderOrder = 20;
  alignGuides.add(line);
}

function getBoxValues(box) {
  return {
    minX: box.min.x, maxX: box.max.x, centerX: (box.min.x + box.max.x) / 2,
    minY: box.min.y, maxY: box.max.y, centerY: (box.min.y + box.max.y) / 2,
    minZ: box.min.z, maxZ: box.max.z, centerZ: (box.min.z + box.max.z) / 2,
  };
}

function findBestAlignment(movingBox, otherBox, axis) {
  const m = getBoxValues(movingBox);
  const o = getBoxValues(otherBox);
  const isX = axis === "x";

  const mMin = isX ? m.minX : m.minZ;
  const mMax = isX ? m.maxX : m.maxZ;
  const mCenter = isX ? m.centerX : m.centerZ;

  const oMin = isX ? o.minX : o.minZ;
  const oMax = isX ? o.maxX : o.maxZ;
  const oCenter = isX ? o.centerX : o.centerZ;

  const candidates = [
    { delta: oMin - mMin, type: "minToMin" },
    { delta: oMax - mMax, type: "maxToMax" },
    { delta: oCenter - mCenter, type: "centerToCenter" },
    { delta: oMax - mMin, type: "minToMax" },
    { delta: oMin - mMax, type: "maxToMin" },
  ];

  let best = null;
  for (const c of candidates) {
    const abs = Math.abs(c.delta);
    if (abs > ALIGN_SNAP_DIST) continue;
    if (!best || abs < best.absDelta) {
      best = { ...c, absDelta: abs };
    }
  }
  return best;
}

function edgeValueForType(type, box, axis, isMoving) {
  const min = axis === "x" ? box.min.x : box.min.z;
  const max = axis === "x" ? box.max.x : box.max.z;
  const center = axis === "x" ? (box.min.x + box.max.x) / 2 : (box.min.z + box.max.z) / 2;

  if (type === "minToMin") return min;
  if (type === "maxToMax") return max;
  if (type === "centerToCenter") return center;
  if (type === "minToMax") return isMoving ? min : max;
  if (type === "maxToMin") return isMoving ? max : min;
  return center;
}

function applyAutoAlign(obj) {
  clearAlignGuides();
  if (!autoAlignEnabled || currentTool !== "move") return;
  if (!obj) return;
  if (obj.userData?.type === "road") return;

  const movingBox = new THREE.Box3().setFromObject(obj);
  if (movingBox.isEmpty()) return;

  let bestX = null;
  let bestZ = null;

  for (const other of overlayRoot.children) {
    if (!other || other === obj || !other.visible) continue;
    if (other.userData?.type === "road") continue;

    const otherBox = new THREE.Box3().setFromObject(other);
    if (otherBox.isEmpty()) continue;

    const candX = findBestAlignment(movingBox, otherBox, "x");
    if (candX && (!bestX || candX.absDelta < bestX.absDelta)) {
      bestX = { ...candX, otherBox };
    }

    const candZ = findBestAlignment(movingBox, otherBox, "z");
    if (candZ && (!bestZ || candZ.absDelta < bestZ.absDelta)) {
      bestZ = { ...candZ, otherBox };
    }
  }

  if (bestX) obj.position.x += bestX.delta;
  if (bestZ) obj.position.z += bestZ.delta;

  obj.updateMatrixWorld(true);

  if (bestX || bestZ) {
    movingBox.setFromObject(obj);
    const m = getBoxValues(movingBox);

    if (bestX) {
      const o = getBoxValues(bestX.otherBox);
      const y = Math.max(m.minY, o.minY) + ALIGN_GUIDE_Y_OFFSET;

      if (bestX.type === "centerToCenter") {
        const x = m.centerX;
        addGuideLine(
          new THREE.Vector3(x, y, m.centerZ),
          new THREE.Vector3(x, y, o.centerZ)
        );
      } else {
        const x1 = edgeValueForType(bestX.type, movingBox, "x", true);
        const x2 = edgeValueForType(bestX.type, bestX.otherBox, "x", false);
        const z = (m.centerZ + o.centerZ) / 2;
        addGuideLine(
          new THREE.Vector3(x1, y, z),
          new THREE.Vector3(x2, y, z)
        );
      }
    }

    if (bestZ) {
      const o = getBoxValues(bestZ.otherBox);
      const y = Math.max(m.minY, o.minY) + ALIGN_GUIDE_Y_OFFSET;

      if (bestZ.type === "centerToCenter") {
        const z = m.centerZ;
        addGuideLine(
          new THREE.Vector3(m.centerX, y, z),
          new THREE.Vector3(o.centerX, y, z)
        );
      } else {
        const z1 = edgeValueForType(bestZ.type, movingBox, "z", true);
        const z2 = edgeValueForType(bestZ.type, bestZ.otherBox, "z", false);
        const x = (m.centerX + o.centerX) / 2;
        addGuideLine(
          new THREE.Vector3(x, y, z1),
          new THREE.Vector3(x, y, z2)
        );
      }
    }
  }
}

// Resize
function resize() {
  const w = mapStage.clientWidth;
  const h = mapStage.clientHeight;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}
window.addEventListener("resize", resize);

// -------------------- Base Model --------------------
const MODEL_DIR = "../models/";
const ORIGINAL_MODEL_NAME = "tnts_map.glb";
let currentModelName = ORIGINAL_MODEL_NAME;
let assetsLoaded = false;
let isModelSwitching = false;

const loader = new GLTFLoader();

let campusRoot = null;
let defaultCameraState = null;
let isTopView = false;

// Overlay root (placed assets)
const overlayRoot = new THREE.Group();
overlayRoot.name = "overlayRoot";
scene.add(overlayRoot);

// -------------------- Roads (generated geometry) --------------------
const roadDraftRoot = new THREE.Group();
roadDraftRoot.name = "roadDraftRoot";
scene.add(roadDraftRoot);

const roadEditRoot = new THREE.Group();
roadEditRoot.name = "roadEditRoot";
roadEditRoot.visible = false;
scene.add(roadEditRoot);

// Hover/ghost preview for next road segment (shown before tapping to place)
const roadHoverRoot = new THREE.Group();
roadHoverRoot.name = "roadHoverRoot";
roadHoverRoot.visible = false;
scene.add(roadHoverRoot);

const roadMaterial = new THREE.MeshStandardMaterial({
  color: 0x9ca3af,
  roughness: 1.0,
  metalness: 0.0,
  polygonOffset: true,
  polygonOffsetFactor: -4,
  polygonOffsetUnits: -4
});
const roadPreviewMaterial = new THREE.MeshBasicMaterial({
  color: 0x2563eb,
  transparent: true,
  opacity: 0.25,
  depthTest: false
});
const roadHandleMaterial = new THREE.MeshBasicMaterial({
  color: 0xf59e0b,
  depthTest: false
});

const roadHandleGeometry = new THREE.SphereGeometry(1, 16, 16);

function hideRoadHoverPreview() {
  roadHoverRoot.visible = false;
}

function showRoadHoverPreviewSegment(aWorld, bWorld, width) {
  if (!aWorld || !bWorld) return hideRoadHoverPreview();
  roadHoverRoot.visible = true;
  ensureRoadMeshFor(roadHoverRoot, [aWorld, bWorld], width, { preview: true });
}

// Road snapping is angle-based (relative to the start point), NOT grid-based.
const ROAD_SURFACE_NORMAL_MIN_Y = 0.35; // only treat upward-ish faces as walkable placement surfaces
const ROAD_SURFACE_RAY_Y = 10000;
const _roadPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
const _roadPlaneHit = new THREE.Vector3();
const _roadDownOrigin = new THREE.Vector3();
const _roadDownDir = new THREE.Vector3(0, -1, 0);
const _roadRayOrigin = new THREE.Vector3();
const _roadRayDir = new THREE.Vector3();
const _roadFaceNormal = new THREE.Vector3();
const _roadNormalMatrix = new THREE.Matrix3();
let _roadPointIdSeq = 1;
let roadSnapBuildingCache = []; // { name: string, obj: THREE.Object3D, box: THREE.Box3, center: THREE.Vector3 }
let roadSnapObstacleCache = []; // { name: string|null, obj: THREE.Object3D, box: THREE.Box3, center: THREE.Vector3 }
let roadGroundTargets = []; // Object3D[] (meshes/groups) used as the "base ground" height source for roads

function roadAngleStepRad() {
  return roadSnapEnabled ? (Math.PI / 2) : THREE.MathUtils.degToRad(ROAD_SNAP_FINE_DEG);
}

function quantizeAngleRad(angleRad, stepRad) {
  if (!Number.isFinite(angleRad) || !Number.isFinite(stepRad) || stepRad <= 0) return angleRad;
  return Math.round(angleRad / stepRad) * stepRad;
}

function getPointOnHorizontalPlaneFromEvent(event, y) {
  if (!event) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  _roadPlane.constant = -Number(y || 0);
  const hit = raycaster.ray.intersectPlane(_roadPlane, _roadPlaneHit);
  return hit ? _roadPlaneHit : null;
}

function getRoadSurfacePointFromEvent(event) {
  if (!event) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const targets = [];
  if (campusRoot) targets.push(campusRoot);
  targets.push(overlayRoot);

  const hits = raycaster.intersectObjects(targets, true);
  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh) continue; // don't place onto existing road meshes
    if (!h.face) return h.point.clone();

    _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
    _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
    if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;

    return h.point.clone();
  }

  // Fallback: ground plane (y=0)
  return getGroundPointFromEvent(event);
}

function sampleRoadSurfaceYAtXZ(x, z, refY = null) {
  _roadDownOrigin.set(x, ROAD_SURFACE_RAY_Y, z);
  raycaster.set(_roadDownOrigin, _roadDownDir);

  const targets = [];
  if (campusRoot) targets.push(campusRoot);
  targets.push(overlayRoot);

  const hits = raycaster.intersectObjects(targets, true);

  if (!Number.isFinite(refY)) {
    // Old behavior: first upward-ish hit from above.
    for (const h of hits) {
      const obj = h?.object;
      if (!obj) continue;
      if (obj.userData?.isRoadMesh) continue;
      if (!h.face) return h.point.y;

      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;

      return h.point.y;
    }
    return 0;
  }

  // Prefer the surface closest to the reference height (prevents snapping up to roofs when editing on ground).
  let bestY = null;
  let bestScore = Infinity;
  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh) continue;
    if (h.face) {
      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) continue;
    }

    const y = h.point.y;
    const score = Math.abs(y - refY);
    if (score < bestScore) {
      bestScore = score;
      bestY = y;
      if (bestScore < 1e-6) break;
    }
  }
  return bestY ?? 0;
}

function isGroundLikeName(name) {
  const n = String(name || "").trim();
  if (!n) return false;
  const lower = n.toLowerCase();
  if (lower === "ground" || lower === "cground") return true;
  // Matches tokens like "GROUND", "CGROUND", "C_GROUND", "campus_ground", "ground_mesh", etc (but not "PLAYGROUND").
  return /(^|[^a-z0-9])c?ground($|[^a-z0-9])/i.test(n);
}

function isGroundObjectOrChild(obj) {
  if (!obj) return false;
  let p = obj;
  while (p && p !== campusRoot) {
    if (isGroundLikeName(p.name)) return true;
    p = p.parent;
  }
  return false;
}

function rebuildRoadGroundTargets() {
  roadGroundTargets = [];
  if (!campusRoot) return;
  const seen = new Set();
  campusRoot.traverse((obj) => {
    if (!obj || !obj.name) return;
    if (!isGroundLikeName(obj.name)) return;
    // Prefer the highest ground-like object in the hierarchy (avoid duplicates).
    let p = obj.parent;
    while (p && p !== campusRoot) {
      if (isGroundLikeName(p.name)) return;
      p = p.parent;
    }
    if (seen.has(obj.uuid)) return;
    seen.add(obj.uuid);
    roadGroundTargets.push(obj);
  });
}

function sampleRoadBaseYAtXZ(x, z, refY = null) {
  if (!roadGroundTargets || !roadGroundTargets.length) return 0;
  _roadDownOrigin.set(x, ROAD_SURFACE_RAY_Y, z);
  raycaster.set(_roadDownOrigin, _roadDownDir);

  const hits = raycaster.intersectObjects(roadGroundTargets, true);
  if (!hits.length) return 0;

  let bestY = null;
  let bestScore = Infinity;
  let firstY = null;

  for (const h of hits) {
    const obj = h?.object;
    if (!obj) continue;
    if (obj.userData?.isRoadMesh) continue;

    const y = h.point.y;
    if (firstY == null) firstY = y;

    let ok = true;
    if (h.face) {
      _roadNormalMatrix.getNormalMatrix(obj.matrixWorld);
      _roadFaceNormal.copy(h.face.normal).applyNormalMatrix(_roadNormalMatrix).normalize();
      if (_roadFaceNormal.y < ROAD_SURFACE_NORMAL_MIN_Y) ok = false;
    }
    if (!ok) continue;

    if (!Number.isFinite(refY)) return y;
    const score = Math.abs(y - refY);
    if (score < bestScore) {
      bestScore = score;
      bestY = y;
      if (bestScore < 1e-6) break;
    }
  }

  // If normals are flipped or filtered out, fall back to the first hit on the named ground object.
  return bestY ?? firstY ?? 0;
}

function newRoadPointId() {
  return `rp_${Date.now()}_${_roadPointIdSeq++}`;
}

function cloneRoadPointMeta(metaArr) {
  if (!Array.isArray(metaArr)) return [];
  return metaArr.map((m) => {
    if (!m || typeof m !== "object") return null;
    const b = (m.building && typeof m.building === "object") ? { ...m.building } : null;
    return { ...m, building: b };
  });
}

function ensureRoadPointMetaArrays(roadObj) {
  const road = roadObj?.userData?.road;
  if (!road) return;
  const pts = Array.isArray(road.points) ? road.points : [];
  if (!Array.isArray(road.pointIds)) road.pointIds = [];
  if (!Array.isArray(road.pointMeta)) road.pointMeta = [];

  while (road.pointIds.length < pts.length) road.pointIds.push(newRoadPointId());
  while (road.pointMeta.length < pts.length) road.pointMeta.push(null);

  if (road.pointIds.length > pts.length) road.pointIds.length = pts.length;
  if (road.pointMeta.length > pts.length) road.pointMeta.length = pts.length;
}

function distSqPointToBoxXZ(x, z, box) {
  const cx = Math.max(box.min.x, Math.min(box.max.x, x));
  const cz = Math.max(box.min.z, Math.min(box.max.z, z));
  const dx = x - cx;
  const dz = z - cz;
  return dx * dx + dz * dz;
}

function intersectRayAabbXZ(ox, oz, dx, dz, minX, maxX, minZ, maxZ) {
  const EPS = 1e-8;
  let tmin = -Infinity;
  let tmax = Infinity;

  if (Math.abs(dx) < EPS) {
    if (ox < minX || ox > maxX) return null;
  } else {
    const tx1 = (minX - ox) / dx;
    const tx2 = (maxX - ox) / dx;
    const txMin = Math.min(tx1, tx2);
    const txMax = Math.max(tx1, tx2);
    tmin = Math.max(tmin, txMin);
    tmax = Math.min(tmax, txMax);
  }

  if (Math.abs(dz) < EPS) {
    if (oz < minZ || oz > maxZ) return null;
  } else {
    const tz1 = (minZ - oz) / dz;
    const tz2 = (maxZ - oz) / dz;
    const tzMin = Math.min(tz1, tz2);
    const tzMax = Math.max(tz1, tz2);
    tmin = Math.max(tmin, tzMin);
    tmax = Math.min(tmax, tzMax);
  }

  if (tmax < tmin) return null;
  return { tEnter: tmin, tExit: tmax };
}

function determineApproachSide(anchorW, endX, endZ, buildingCenter) {
  if (!buildingCenter) return "front";
  let dx = (anchorW ? (anchorW.x - buildingCenter.x) : 0);
  let dz = (anchorW ? (anchorW.z - buildingCenter.z) : 0);
  if (!Number.isFinite(dx) || !Number.isFinite(dz) || (Math.abs(dx) < 1e-8 && Math.abs(dz) < 1e-8)) {
    dx = endX - buildingCenter.x;
    dz = endZ - buildingCenter.z;
  }
  if (Math.abs(dx) >= Math.abs(dz)) return dx < 0 ? "left" : "right";
  return dz < 0 ? "back" : "front";
}

function getBuildingLabelForObject(obj) {
  let p = obj;
  while (p && p !== campusRoot) {
    if (p.name && !isGenericNodeName(p.name) && !isGroundLikeName(p.name)) return p.name;
    p = p.parent;
  }
  if (obj?.name && !isGenericNodeName(obj.name) && !isGroundLikeName(obj.name)) return obj.name;
  return null;
}

function getBuildingRootFromObject(obj) {
  let p = obj;
  while (p && p !== campusRoot) {
    if (p.name && !isGenericNodeName(p.name) && !isGroundLikeName(p.name)) return p;
    p = p.parent;
  }
  if (obj?.name && !isGenericNodeName(obj.name) && !isGroundLikeName(obj.name)) return obj;
  return null;
}

function buildLiveRoadSnapSources() {
  const obstacles = [];
  const buildings = [];
  const meshes = [];
  if (!campusRoot) return { obstacles, buildings };

  campusRoot.updateMatrixWorld?.(true);
  const tmpSize = new THREE.Vector3();

  // Named buildings (for metadata + soft snapping)
  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj || !obj.name || isGenericNodeName(obj.name)) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;

    const existing = byName.get(obj.name);
    if (!existing) {
      byName.set(obj.name, obj);
      return;
    }
    if (isEmptyGroup(obj) && !isEmptyGroup(existing)) {
      byName.set(obj.name, obj);
    }
  });
  for (const [name, obj] of byName.entries()) {
    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) continue;
    box.getSize(tmpSize);
    if (isGroundObjectOrChild(obj)) continue;
    buildings.push({ name, obj, box, center: box.getCenter(new THREE.Vector3()) });
  }

  // Obstacles (for hard-stop collisions) — use collider meshes when available
  const seenObstacle = new Set();
  const addObstacle = (mesh) => {
    if (!mesh) return;
    if (seenObstacle.has(mesh.uuid)) return;
    const box = new THREE.Box3().setFromObject(mesh);
    if (box.isEmpty()) return;
    box.getSize(tmpSize);
    if (isGroundLikeName(mesh.name)) return;
    if (isGroundObjectOrChild(mesh)) return;
    seenObstacle.add(mesh.uuid);
    meshes.push(mesh);
    obstacles.push({
      name: getBuildingLabelForObject(mesh),
      obj: mesh,
      box,
      center: box.getCenter(new THREE.Vector3())
    });
  };

  if (Array.isArray(baseColliderMeshes) && baseColliderMeshes.length) {
    for (const mesh of baseColliderMeshes) addObstacle(mesh);
  } else {
    campusRoot.traverse((obj) => {
      if (!obj || !obj.isMesh) return;
      addObstacle(obj);
    });
  }

  return { obstacles, buildings, meshes };
}

function rebuildRoadSnapBuildingCache() {
  roadSnapBuildingCache = [];
  roadSnapObstacleCache = [];
  if (!campusRoot) return;
  campusRoot.updateMatrixWorld?.(true);
  const tmpSize = new THREE.Vector3();

  // 1) Named buildings (for metadata + soft snapping)
  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj || !obj.name || isGenericNodeName(obj.name)) return;
    if (obj.userData?.isPlaced) return;
    if (isGroundLikeName(obj.name)) return;

    const existing = byName.get(obj.name);
    if (!existing) {
      byName.set(obj.name, obj);
      return;
    }
    if (isEmptyGroup(obj) && !isEmptyGroup(existing)) {
      byName.set(obj.name, obj);
    }
  });
  for (const [name, obj] of byName.entries()) {
    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) continue;
    box.getSize(tmpSize);
    if (isGroundObjectOrChild(obj)) continue;
    roadSnapBuildingCache.push({ name, obj, box, center: box.getCenter(new THREE.Vector3()) });
  }

  // 2) Obstacles (for hard-stop collisions) — use collider meshes when available
  const obstacleMeshes = (Array.isArray(baseColliderMeshes) && baseColliderMeshes.length)
    ? baseColliderMeshes
    : [];

  const seenObstacle = new Set();
  if (obstacleMeshes.length) {
    for (const mesh of obstacleMeshes) {
      if (!mesh) continue;
      if (seenObstacle.has(mesh.uuid)) continue;
      const box = new THREE.Box3().setFromObject(mesh);
      if (box.isEmpty()) continue;
      box.getSize(tmpSize);
      if (isGroundLikeName(mesh.name)) continue;
      if (isGroundObjectOrChild(mesh)) continue;
      seenObstacle.add(mesh.uuid);
      roadSnapObstacleCache.push({
        name: getBuildingLabelForObject(mesh),
        obj: mesh,
        box,
        center: box.getCenter(new THREE.Vector3())
      });
    }
  } else {
    // Fallback: traverse meshes if colliders aren't built yet
    campusRoot.traverse((obj) => {
      if (!obj || !obj.isMesh) return;
      if (seenObstacle.has(obj.uuid)) return;
      const box = new THREE.Box3().setFromObject(obj);
      if (box.isEmpty()) return;
      box.getSize(tmpSize);
      if (isGroundLikeName(obj.name)) return;
      if (isGroundObjectOrChild(obj)) return;
      seenObstacle.add(obj.uuid);
      roadSnapObstacleCache.push({
        name: getBuildingLabelForObject(obj),
        obj,
        box,
        center: box.getCenter(new THREE.Vector3())
      });
    });
  }
}

function snapRoadEndToBuildingSide(anchorW, endX, endZ, width, opts = null) {
  const obstacles = [];
  const providedObstacles = Array.isArray(opts?.obstacles) ? opts.obstacles : null;
  const providedBuildings = Array.isArray(opts?.buildings) ? opts.buildings : null;
  const providedMeshes = Array.isArray(opts?.meshes) ? opts.meshes : null;

  if (providedObstacles && providedObstacles.length) {
    obstacles.push(...providedObstacles);
    if (providedBuildings && providedBuildings.length) obstacles.push(...providedBuildings);
  } else {
    if (roadSnapObstacleCache && roadSnapObstacleCache.length) obstacles.push(...roadSnapObstacleCache);
    if (roadSnapBuildingCache && roadSnapBuildingCache.length) obstacles.push(...roadSnapBuildingCache);
  }
  if (!obstacles.length) return null;
  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const half = Math.max(0.1, w / 2);
  const gap = Math.max(0, Number(ROAD_BUILDING_GAP) || 0);
  const backOff = gap > 0 ? Math.min(0.02, gap * 0.5) : 1e-4;
  const pad = half + gap; // keep road edges out of the building volume

  // 1) Hard stop: if the segment ray crosses a building (expanded by road width),
  // clamp to the FIRST impact point (like hitting a wall).
  if (anchorW) {
    const vx = endX - anchorW.x;
    const vz = endZ - anchorW.z;
    const maxLen = Math.hypot(vx, vz);
    if (maxLen > 1e-6) {
      const dirX = vx / maxLen;
      const dirZ = vz / maxLen;

      // 1a) Prefer real mesh-surface raycast (true wall hit).
      if (providedMeshes && providedMeshes.length) {
        const prevFar = raycaster.far;
        _roadRayOrigin.set(anchorW.x, anchorW.y, anchorW.z);
        _roadRayDir.set(dirX, 0, dirZ).normalize();
        raycaster.set(_roadRayOrigin, _roadRayDir);
        raycaster.far = maxLen;

        const hits = raycaster.intersectObjects(providedMeshes, true);
        for (const h of hits) {
          const obj = h?.object;
          if (!obj) continue;
          if (obj.userData?.isRoadMesh) continue;
          if (isGroundLikeName(obj.name)) continue;
          if (isGroundObjectOrChild(obj)) {
            // If it's ground-like, ignore it for road-wall collisions.
            continue;
          }
          const pt = h.point;
          if (!pt) continue;

          const root = getBuildingRootFromObject(obj);
          const buildingName = root?.name || getBuildingLabelForObject(obj);
          let side = "front";
          if (root) {
            const rootBox = new THREE.Box3().setFromObject(root);
            const center = rootBox.getCenter(new THREE.Vector3());
            side = determineApproachSide(anchorW, pt.x, pt.z, center);
          }

          const outX = pt.x - _roadRayDir.x * backOff;
          const outZ = pt.z - _roadRayDir.z * backOff;

          raycaster.far = prevFar;
          return {
            x: outX,
            z: outZ,
            buildingObj: root || obj,
            buildingName: buildingName || null,
            side,
          };
        }
        raycaster.far = prevFar;
      }

      let bestHit = null; // { t, buildingName, buildingObj, minX,maxX,minZ,maxZ }
      for (const b of obstacles) {
        if (!b?.box) continue;
        const box = b.box;
        const minX = box.min.x - pad;
        const maxX = box.max.x + pad;
        const minZ = box.min.z - pad;
        const maxZ = box.max.z + pad;

        const hit = intersectRayAabbXZ(anchorW.x, anchorW.z, dirX, dirZ, minX, maxX, minZ, maxZ);
        if (!hit) continue;
        if (hit.tExit < 0) continue;

        let t = hit.tEnter;
        if (t < 0) t = hit.tExit; // started inside the expanded box; clamp to leaving boundary
        if (t < 0 || t > maxLen) continue;

        if (!bestHit || t < bestHit.t) {
          bestHit = { t, buildingName: b.name || null, buildingObj: b.obj, minX, maxX, minZ, maxZ };
        }
      }

      if (bestHit) {
        const hitX = anchorW.x + dirX * bestHit.t;
        const hitZ = anchorW.z + dirZ * bestHit.t;

        // Determine which wall we hit (closest boundary on the expanded box).
        const dL = Math.abs(hitX - bestHit.minX);
        const dR = Math.abs(hitX - bestHit.maxX);
        const dB = Math.abs(hitZ - bestHit.minZ);
        const dF = Math.abs(hitZ - bestHit.maxZ);
        const minD = Math.min(dL, dR, dB, dF);
        let side = "front";
        if (minD === dL) side = "left";
        else if (minD === dR) side = "right";
        else if (minD === dB) side = "back";
        else if (minD === dF) side = "front";

        // Pull slightly back along the ray so we stay OUTSIDE the expanded volume.
        const outX = hitX - dirX * backOff;
        const outZ = hitZ - dirZ * backOff;

        return {
          x: outX,
          z: outZ,
          buildingObj: bestHit.buildingObj,
          buildingName: bestHit.buildingName,
          side,
        };
      }
    }
  }

  // 2) Soft snap: if the pointer is close to a building, snap to the nearest side.
  const buildingList = providedBuildings ?? roadSnapBuildingCache;
  if (!buildingList || !buildingList.length) return null;
  const snapDist = Math.max(ROAD_BUILDING_SNAP_DIST, w * 0.75);
  const maxSq = snapDist * snapDist;

  let best = null;
  for (const b of buildingList) {
    if (!b?.box) continue;
    const dSq = distSqPointToBoxXZ(endX, endZ, b.box);
    if (dSq > maxSq) continue;
    if (!best || dSq < best.dSq) best = { ...b, dSq };
  }
  if (!best) return null;

  const side = determineApproachSide(anchorW, endX, endZ, best.center);
  const box = best.box;
  const clamp = THREE.MathUtils.clamp;
  const offset = pad;

  let x = endX;
  let z = endZ;
  if (side === "left") {
    x = box.min.x - offset;
    z = clamp(endZ, box.min.z, box.max.z);
  } else if (side === "right") {
    x = box.max.x + offset;
    z = clamp(endZ, box.min.z, box.max.z);
  } else if (side === "front") {
    z = box.max.z + offset;
    x = clamp(endX, box.min.x, box.max.x);
  } else if (side === "back") {
    z = box.min.z - offset;
    x = clamp(endX, box.min.x, box.max.x);
  }

  return {
    x,
    z,
    buildingObj: best.obj,
    buildingName: best.name,
    side,
  };
}

function makeBuildingLinkMeta(buildingName, side, pointId) {
  const name = String(buildingName || "").trim();
  const s = String(side || "").trim();
  return {
    id: pointId || null,
    label: (name && s) ? `${name}_${s}` : (name || null),
    building: (name && s) ? { name, side: s } : (name ? { name } : null),
    linkedAt: Date.now(),
  };
}

function computeSnappedRoadEnd(anchorW, rawOnPlane, opts = {}) {
  const { width = null, out = null, snapSources = null } = opts;
  if (!anchorW || !rawOnPlane) return null;
  const dx = rawOnPlane.x - anchorW.x;
  const dz = rawOnPlane.z - anchorW.z;
  const len = Math.hypot(dx, dz);
  if (len < ROAD_MIN_POINT_DIST) return null;

  const ang = Math.atan2(dz, dx);
  const step = roadAngleStepRad();
  const snapAng = quantizeAngleRad(ang, step);

  const ux = Math.cos(snapAng);
  const uz = Math.sin(snapAng);
  const proj = dx * ux + dz * uz; // closest point on snapped ray to the pointer (angle-quantized, not grid-based)
  if (proj < ROAD_MIN_POINT_DIST) return null;

  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  let x = anchorW.x + ux * proj;
  let z = anchorW.z + uz * proj;

  let snap = null;
  if (out && typeof out === "object") {
    out.snapped = false;
    out.buildingName = null;
    out.side = null;
  }

  if (roadBuildingSnapEnabled) {
    snap = snapRoadEndToBuildingSide(anchorW, x, z, w, snapSources || null);
    if (snap) {
      x = snap.x;
      z = snap.z;
      if (out && typeof out === "object") {
        out.snapped = true;
        out.buildingName = snap.buildingName || null;
        out.side = snap.side || null;
      }
    }
  }

  const y = sampleRoadBaseYAtXZ(x, z, anchorW.y);
  return new THREE.Vector3(x, y, z);
}

function isRoadObject(obj) {
  return !!(obj && obj.userData?.isPlaced && obj.userData?.type === "road");
}

function setRoadWidthReadout(value) {
  if (!roadWidthReadoutEl) return;
  const v = Number(value);
  roadWidthReadoutEl.textContent = Number.isFinite(v) ? String(Math.round(v)) : String(value ?? "");
}

function updateRoadControls() {
  if (!roadControls) return;
  const isActive = currentTool === "road";
  roadControls.style.display = isActive ? "block" : "none";

  let hasEditableSelectedRoad = false;
  try {
    hasEditableSelectedRoad = !!(selected && isRoadObject(selected) && canEditObject(selected));
  } catch (_) {
    hasEditableSelectedRoad = false;
  }

  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  setRoadWidthReadout(width);

  if (roadWidthEl) {
    // Width is both the default for new road points and an editor for selected roads.
    roadWidthEl.disabled = !isActive;
    roadWidthEl.style.opacity = roadWidthEl.disabled ? "0.6" : "1";
  }

  if (roadFinishBtn) {
    const canFinish = !!(roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length >= 2);
    roadFinishBtn.disabled = !isActive || !canFinish;
    roadFinishBtn.style.opacity = roadFinishBtn.disabled ? "0.6" : "1";
  }
  if (roadCancelBtn) {
    const canCancel = !!(roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length);
    roadCancelBtn.disabled = !isActive || !canCancel;
    roadCancelBtn.style.opacity = roadCancelBtn.disabled ? "0.6" : "1";
  }
  if (roadNewBtn) {
    roadNewBtn.disabled = !isActive;
    roadNewBtn.style.opacity = roadNewBtn.disabled ? "0.6" : "1";
  }
  if (roadSnapBtn) {
    roadSnapBtn.textContent = `Snap: ${roadSnapEnabled ? "On" : "Off"}`;
  }
  if (roadBuildingSnapBtn) {
    roadBuildingSnapBtn.disabled = !isActive;
    roadBuildingSnapBtn.textContent = `Attach: ${roadBuildingSnapEnabled ? "On" : "Off"}`;
    roadBuildingSnapBtn.style.opacity = roadBuildingSnapBtn.disabled ? "0.6" : "1";
  }
  if (roadDragModeBtn) {
    roadDragModeBtn.disabled = !isActive;
    roadDragModeBtn.textContent = `Drag: ${roadDragMode === "move" ? "Move" : "Draw"}`;
    roadDragModeBtn.style.opacity = roadDragModeBtn.disabled ? "0.6" : "1";
  }

  // Selected point + extend-from labels
  if (roadPointSelectedEl || roadExtendFromEl) {
    let pointLabel = "None";
    let extendLabel = "Auto";

    if (hasEditableSelectedRoad && selected && isRoadObject(selected)) {
      const ptsArr = Array.isArray(selected.userData?.road?.points) ? selected.userData.road.points : [];
      const lastIdx = ptsArr.length - 1;
      if (Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0 && roadSelectedPointIndex <= lastIdx) {
        if (roadSelectedPointIndex === 0) pointLabel = "Start";
        else if (roadSelectedPointIndex === lastIdx) pointLabel = "End";
        else pointLabel = String(roadSelectedPointIndex + 1);
      }

      if (roadExtendFromRoadId && roadExtendFromRoadId === selected.userData?.id && roadExtendFrom) {
        extendLabel = roadExtendFrom === "start" ? "Start" : "End";
      }
    }

    if (roadPointSelectedEl) roadPointSelectedEl.textContent = pointLabel;
    if (roadExtendFromEl) roadExtendFromEl.textContent = extendLabel;
  }

  // Building connect UI
  if (roadConnectTargetEl) {
    roadConnectTargetEl.textContent = roadConnectTarget?.name || "None";
  }

  const canPickBuilding = isActive && hasEditableSelectedRoad;
  if (roadPickBuildingBtn) {
    roadPickBuildingBtn.disabled = !canPickBuilding;
    roadPickBuildingBtn.textContent = roadPickBuildingMode ? "Picking…" : "Pick Building";
    roadPickBuildingBtn.style.opacity = roadPickBuildingBtn.disabled ? "0.6" : "1";
  }
  if (roadClearBuildingBtn) {
    roadClearBuildingBtn.disabled = !isActive || !roadConnectTarget;
    roadClearBuildingBtn.style.opacity = roadClearBuildingBtn.disabled ? "0.6" : "1";
  }

  let canConnect = false;
  if (isActive && hasEditableSelectedRoad && roadConnectTarget && Number.isInteger(roadSelectedPointIndex)) {
    const ptsArr = Array.isArray(selected?.userData?.road?.points) ? selected.userData.road.points : [];
    canConnect = roadSelectedPointIndex >= 0 && roadSelectedPointIndex < ptsArr.length;
  }
  for (const b of [roadConnectFrontBtn, roadConnectBackBtn, roadConnectLeftBtn, roadConnectRightBtn]) {
    if (!b) continue;
    b.disabled = !canConnect;
    b.style.opacity = b.disabled ? "0.6" : "1";
  }
}

function clearRoadPointSelection() {
  roadSelectedPointIndex = null;
  roadExtendFrom = null;
  roadExtendFromRoadId = null;
}

function selectRoadPoint(roadObj, index, opts = {}) {
  const { mode = "toggle", showStatus = true } = opts; // mode: "toggle" | "set"

  if (!roadObj || !isRoadObject(roadObj)) {
    clearRoadPointSelection();
    updateRoadControls();
    return;
  }

  const ptsArr = Array.isArray(roadObj.userData?.road?.points) ? roadObj.userData.road.points : [];
  const lastIdx = ptsArr.length - 1;

  const idx = Number.isInteger(index) ? index : null;
  roadSelectedPointIndex = (idx != null && idx >= 0 && idx <= lastIdx) ? idx : null;

  let endpoint = null;
  if (roadSelectedPointIndex === 0) endpoint = "start";
  else if (roadSelectedPointIndex === lastIdx) endpoint = "end";

  if (endpoint) {
    const isSame = (roadExtendFrom === endpoint && roadExtendFromRoadId === roadObj.userData?.id);
    const shouldToggleOff = (mode === "toggle" && isSame);
    if (shouldToggleOff) {
      roadExtendFrom = null;
      roadExtendFromRoadId = null;
      if (showStatus) setStatus("Extend-from cleared (auto)");
    } else {
      roadExtendFrom = endpoint;
      roadExtendFromRoadId = roadObj.userData?.id || null;
      if (showStatus) setStatus(`Extend from ${endpoint === "start" ? "Start" : "End"} selected — drag the point to draw a segment`);
    }
  } else {
    // Interior point selected; extension goes back to auto.
    if (roadExtendFromRoadId === roadObj.userData?.id) {
      roadExtendFrom = null;
      roadExtendFromRoadId = null;
    }
    if (showStatus) setStatus(roadDragMode === "draw"
      ? "Road point selected — drag to branch a new segment"
      : "Road point selected — drag to move (switch Drag mode to Draw to create segments)");
  }

  updateRoadControls();
}

function isConnectableBuilding(obj) {
  return !!(obj && !obj.userData?.isPlaced && obj.parent === campusRoot && !isGroundLikeName(obj.name));
}

function setRoadConnectTarget(obj, opts = {}) {
  const { showStatus = true } = opts;
  roadConnectTarget = obj || null;
  roadPickBuildingMode = false;
  updateRoadControls();
  if (showStatus) {
    setStatus(roadConnectTarget ? `Road connect target: ${roadConnectTarget.name}` : "Road connect target cleared");
  }
}

function getBuildingSideCenterWorld(buildingObj, side) {
  if (!buildingObj) return null;
  const box = new THREE.Box3().setFromObject(buildingObj);
  if (box.isEmpty()) return null;
  const center = box.getCenter(new THREE.Vector3());
  const p = new THREE.Vector3(center.x, box.min.y, center.z);
  if (side === "front") p.z = box.max.z;
  else if (side === "back") p.z = box.min.z;
  else if (side === "left") p.x = box.min.x;
  else if (side === "right") p.x = box.max.x;
  return p;
}

function connectRoadPointToBuilding(roadObj, pointIndex, buildingObj, side) {
  if (!roadObj || !isRoadObject(roadObj) || !canEditObject(roadObj)) return false;
  if (!Number.isInteger(pointIndex)) return false;
  if (!buildingObj) return false;

  const targetW = getBuildingSideCenterWorld(buildingObj, side);
  if (!targetW) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[pointIndex]) return false;

  ensureRoadPointMetaArrays(roadObj);
  const beforePoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const beforeWidth = Number(roadObj.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const beforeIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const beforeMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);

  // Push the connection point OUTSIDE the building by (half road width + epsilon),
  // so road segments don't pass through the building volume.
  const connectW = Number(roadObj.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const half = Math.max(0.1, connectW / 2);
  const gap = Math.max(0, Number(ROAD_BUILDING_GAP) || 0);
  const offset = half + gap;
  const box = new THREE.Box3().setFromObject(buildingObj);
  if (!box.isEmpty()) {
    const clamp = THREE.MathUtils.clamp;
    if (side === "left") {
      targetW.x = box.min.x - offset;
      targetW.z = clamp(targetW.z, box.min.z, box.max.z);
    } else if (side === "right") {
      targetW.x = box.max.x + offset;
      targetW.z = clamp(targetW.z, box.min.z, box.max.z);
    } else if (side === "front") {
      targetW.z = box.max.z + offset;
      targetW.x = clamp(targetW.x, box.min.x, box.max.x);
    } else if (side === "back") {
      targetW.z = box.min.z - offset;
      targetW.x = clamp(targetW.x, box.min.x, box.max.x);
    }
  }

  // Keep the point on the same "ground/base" height near the current point.
  const currentW = roadObj.localToWorld(ptsLocal[pointIndex].clone());
  targetW.y = sampleRoadBaseYAtXZ(targetW.x, targetW.z, currentW.y);

  const local = roadObj.worldToLocal(targetW.clone());

  ptsLocal[pointIndex].copy(local);
  setRoadLocalPoints(roadObj, ptsLocal);
  roadObj.userData.road.width = beforeWidth;

  // Mark this node as building-linked for later routing.
  if (Array.isArray(roadObj.userData?.road?.pointIds) && Array.isArray(roadObj.userData?.road?.pointMeta)) {
    const pid = roadObj.userData.road.pointIds[pointIndex];
    roadObj.userData.road.pointMeta[pointIndex] = makeBuildingLinkMeta(buildingObj.name, side, pid);
  }

  rebuildRoadObject(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  const afterPoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const afterIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);
  undoStack.push({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth: beforeWidth, beforeIds, afterIds, beforeMeta, afterMeta });
  redoStack = [];
  setStatus(`Connected point to ${buildingObj.name} (${side}) ✓ (undo available)`);
  updateRoadControls();
  return true;
}

function computeDraftPreviewPoint(ptWorld) {
  if (!roadDraft || !Array.isArray(roadDraft.points) || !roadDraft.points.length) return null;

  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;

  const last = roadDraft.points[roadDraft.points.length - 1];
  const delta = pt.clone().sub(last);
  delta.y = 0;
  if (delta.length() < ROAD_MIN_POINT_DIST) return null;

  if (roadSnapEnabled && roadDraft.points.length >= 2) {
    const prev = roadDraft.points[roadDraft.points.length - 2];
    const lastDir = last.clone().sub(prev);
    lastDir.y = 0;
    const snappedDir = snapRoadTurn(lastDir, delta);
    const len = Math.max(ROAD_MIN_POINT_DIST, delta.dot(snappedDir));
    pt.copy(last).addScaledVector(snappedDir, len);
    snapXZ(pt);
    pt.y = 0;
  }

  return pt;
}

function computeExtendPreviewSegment(roadObj, ptsLocal, ptWorld, extendEnd) {
  if (!roadObj || !isRoadObject(roadObj)) return null;
  if (!Array.isArray(ptsLocal) || ptsLocal.length < 1) return null;

  const ptW = snapXZ(ptWorld.clone());
  ptW.y = 0;

  const startW = roadObj.localToWorld(ptsLocal[0].clone());
  const endW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 1].clone());
  const anchorW = extendEnd ? endW : startW;

  let newW = ptW.clone();
  if (roadSnapEnabled && ptsLocal.length >= 2) {
    if (extendEnd) {
      const prevW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 2].clone());
      const lastDir = endW.clone().sub(prevW); lastDir.y = 0;
      const desired = ptW.clone().sub(endW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = endW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    } else {
      const nextW = roadObj.localToWorld(ptsLocal[1].clone());
      const lastDir = startW.clone().sub(nextW); lastDir.y = 0; // direction from next -> start
      const desired = ptW.clone().sub(startW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = startW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    }
  }

  if (newW.distanceTo(anchorW) < ROAD_MIN_POINT_DIST) return null;
  return { anchorW, newW };
}

function updateRoadPreview(event) {
  if (currentTool !== "road") return hideRoadHoverPreview();
  if (roadPickBuildingMode) return hideRoadHoverPreview();
  if (activeTouchPointerIds.size >= 2) return hideRoadHoverPreview();
  if (!event || !isEventOnStage(event)) return hideRoadHoverPreview();
  const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;

  // Preview for extending/branching from a selected road point.
  if (selected && isRoadObject(selected) && canEditObject(selected)) {
    const ptsLocal = getRoadLocalPoints(selected);
    const lastIdx = ptsLocal.length - 1;
    if (lastIdx >= 0) {
      let anchorIdx = null;

      if (Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0 && roadSelectedPointIndex <= lastIdx) {
        anchorIdx = roadSelectedPointIndex;
      } else if (roadExtendFromRoadId && roadExtendFromRoadId === selected.userData?.id && roadExtendFrom) {
        anchorIdx = roadExtendFrom === "start" ? 0 : lastIdx;
      } else if (lastIdx >= 1) {
        const hint = getRoadSurfacePointFromEvent(event);
        if (hint) {
          const startW = selected.localToWorld(ptsLocal[0].clone());
          const endW = selected.localToWorld(ptsLocal[lastIdx].clone());
          const dxs = hint.x - startW.x, dzs = hint.z - startW.z;
          const dxe = hint.x - endW.x,  dze = hint.z - endW.z;
          anchorIdx = (dxs * dxs + dzs * dzs) <= (dxe * dxe + dze * dze) ? 0 : lastIdx;
        }
      }

      if (anchorIdx == null) anchorIdx = 0;
      const anchorW = selected.localToWorld(ptsLocal[anchorIdx].clone());
      const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, anchorW.y);
      const width = Number(selected.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
      const endW = computeSnappedRoadEnd(anchorW, rawOnPlane, { width, snapSources: liveSources });
      if (endW) {
        showRoadHoverPreviewSegment(anchorW, endW, width);
        return;
      }
    }
  }

  // Preview for drafting a new road (legacy draft mode).
  if (roadDraft && Array.isArray(roadDraft.points) && roadDraft.points.length) {
    const last = roadDraft.points[roadDraft.points.length - 1];
    const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, last.y);
    const width = Number(roadDraft.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const cand = computeSnappedRoadEnd(last, rawOnPlane, { width, snapSources: liveSources });
    if (cand) {
      showRoadHoverPreviewSegment(last, cand, width);
      return;
    }
  }

  hideRoadHoverPreview();
}

function snapXZ(pt, step = ROAD_SNAP_STEP) {
  // Kept for compatibility with older code paths; road snapping is angle-based now.
  return pt;
}

function clonePointsArray(points) {
  return (points || []).map(p => [p[0], p[1], p[2]]);
}

function vec3FromArray(a) {
  return new THREE.Vector3(a[0], a[1], a[2]);
}

function arraysFromVec3(points) {
  return points.map(v => [v.x, v.y, v.z]);
}

function buildRoadGeometry(points, width) {
  const geom = new THREE.BufferGeometry();
  if (!Array.isArray(points) || points.length < 1) return geom;

  const half = Math.max(0.1, (Number(width) || ROAD_DEFAULT_WIDTH) / 2);

  // Clean consecutive duplicates (prevents zero-length segments creating spikes)
  const pts = [];
  const EPS_SQ = 1e-6;
  for (const p of points) {
    if (!p) continue;
    if (!pts.length) pts.push(p.clone());
    else {
      const last = pts[pts.length - 1];
      const dx = p.x - last.x;
      const dz = p.z - last.z;
      if ((dx * dx + dz * dz) > EPS_SQ) pts.push(p.clone());
    }
  }

  // Single node: render a simple square "cap" driven by road width.
  if (pts.length === 1) {
    const p = pts[0];
    const y = p.y + ROAD_Y_OFFSET;

    const posArr = new Float32Array([
      p.x - half, y, p.z - half,
      p.x + half, y, p.z - half,
      p.x + half, y, p.z + half,
      p.x - half, y, p.z + half,
    ]);
    const nArr = new Float32Array([
      0, 1, 0,
      0, 1, 0,
      0, 1, 0,
      0, 1, 0,
    ]);
    const uvArr = new Float32Array([
      0, 0,
      1, 0,
      1, 1,
      0, 1,
    ]);
    const idxArr = new Uint16Array([0, 2, 1, 0, 3, 2]);

    geom.setAttribute("position", new THREE.BufferAttribute(posArr, 3));
    geom.setAttribute("normal", new THREE.BufferAttribute(nArr, 3));
    geom.setAttribute("uv", new THREE.BufferAttribute(uvArr, 2));
    geom.setIndex(new THREE.BufferAttribute(idxArr, 1));
    return geom;
  }

  if (pts.length < 2) return geom;

  // UV distance along centerline
  let accLen = 0;
  const segLens = new Array(pts.length).fill(0);
  for (let i = 1; i < pts.length; i++) {
    const d = pts[i].clone().sub(pts[i - 1]);
    d.y = 0;
    accLen += d.length();
    segLens[i] = accLen;
  }
  const totalLen = Math.max(1e-6, accLen);

  const positions = [];
  const uvs = [];
  const normals = [];
  const indices = [];
  let vCount = 0;

  function addVertex(x, y, z, u, v) {
    positions.push(x, y, z);
    uvs.push(u, v);
    normals.push(0, 1, 0);
    return vCount++;
  }
  function crossXZ(ax, az, bx, bz) {
    return ax * bz - az * bx;
  }
  function intersectLinesXZ(pA, dA, pB, dB, out) {
    const denom = crossXZ(dA.x, dA.z, dB.x, dB.z);
    if (Math.abs(denom) < 1e-6) return null;
    const dx = pB.x - pA.x;
    const dz = pB.z - pA.z;
    const t = crossXZ(dx, dz, dB.x, dB.z) / denom;
    out.set(pA.x + dA.x * t, 0, pA.z + dA.z * t);
    return out;
  }

  const dir = new THREE.Vector3();
  const normal = new THREE.Vector3();
  const tmp = new THREE.Vector3();
  const pA = new THREE.Vector3();
  const pB = new THREE.Vector3();
  const miter = new THREE.Vector3();

  // 1) Segment quads (constant width per segment)
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i];
    const p1 = pts[i + 1];

    dir.copy(p1).sub(p0);
    dir.y = 0;
    const len = dir.length();
    if (len < 1e-6) continue;
    dir.multiplyScalar(1 / len);

    normal.set(-dir.z, 0, dir.x);

    const y0 = p0.y + ROAD_Y_OFFSET;
    const y1 = p1.y + ROAD_Y_OFFSET;
    const v0 = segLens[i] / totalLen;
    const v1 = segLens[i + 1] / totalLen;

    const l0 = addVertex(p0.x + normal.x * half, y0, p0.z + normal.z * half, 0, v0);
    const r0 = addVertex(p0.x - normal.x * half, y0, p0.z - normal.z * half, 1, v0);
    const l1 = addVertex(p1.x + normal.x * half, y1, p1.z + normal.z * half, 0, v1);
    const r1 = addVertex(p1.x - normal.x * half, y1, p1.z - normal.z * half, 1, v1);

    // Winding order so the road is visible from above (Y+)
    indices.push(l0, l1, r0);
    indices.push(r0, l1, r1);
  }

  // 2) Outer-corner join fill (round join fan)
  const TWO_PI = Math.PI * 2;
  for (let i = 1; i < pts.length - 1; i++) {
    const pPrev = pts[i - 1];
    const p = pts[i];
    const pNext = pts[i + 1];

    const dirPrev = p.clone().sub(pPrev);
    dirPrev.y = 0;
    const dirNext = pNext.clone().sub(p);
    dirNext.y = 0;
    if (dirPrev.lengthSq() < 1e-8 || dirNext.lengthSq() < 1e-8) continue;
    dirPrev.normalize();
    dirNext.normalize();

    const dot = dirPrev.dot(dirNext);
    if (dot > 0.9999) continue; // almost straight

    const turn = crossXZ(dirPrev.x, dirPrev.z, dirNext.x, dirNext.z);
    if (Math.abs(turn) < 1e-8) continue;

    const nPrev = new THREE.Vector3(-dirPrev.z, 0, dirPrev.x);
    const nNext = new THREE.Vector3(-dirNext.z, 0, dirNext.x);

    // Choose convex (outer) side based on turn direction.
    // For CCW (left) turns, convex side is RIGHT; for CW (right) turns, convex side is LEFT.
    if (turn > 0) {
      nPrev.multiplyScalar(-1);
      nNext.multiplyScalar(-1);
    }
    const outerPrev = nPrev;
    const outerNext = nNext;

    const y = p.y + ROAD_Y_OFFSET;
    const v = segLens[i] / totalLen;
    const iCenter = addVertex(p.x, y, p.z, 0.5, v);

    const startAng = Math.atan2(outerPrev.z, outerPrev.x);
    let endAng = Math.atan2(outerNext.z, outerNext.x);
    const ccw = turn > 0;

    if (ccw) {
      while (endAng <= startAng) endAng += TWO_PI;
    } else {
      while (endAng >= startAng) endAng -= TWO_PI;
    }

    const delta = endAng - startAng;
    const absDelta = Math.abs(delta);
    const steps = Math.max(1, Math.min(24, Math.ceil(absDelta / (Math.PI / 18)))); // ~10° per step

    const arcIdx = [];
    for (let s = 0; s <= steps; s++) {
      const t = s / steps;
      const ang = startAng + delta * t;
      const x = p.x + Math.cos(ang) * half;
      const z = p.z + Math.sin(ang) * half;
      arcIdx.push(addVertex(x, y, z, 0.5, v));
    }

    for (let s = 0; s < steps; s++) {
      const aIdx = arcIdx[s];
      const bIdx = arcIdx[s + 1];
      // We want top-facing triangles (+Y). In XZ plane, CCW gives -Y, so flip for ccw arcs.
      if (ccw) indices.push(iCenter, bIdx, aIdx);
      else indices.push(iCenter, aIdx, bIdx);
    }
  }

  const posArr = new Float32Array(positions);
  const uvArr = new Float32Array(uvs);
  const nArr = new Float32Array(normals);
  const IndexArray = (vCount > 65535) ? Uint32Array : Uint16Array;
  const idxArr = new IndexArray(indices);

  geom.setAttribute("position", new THREE.BufferAttribute(posArr, 3));
  geom.setAttribute("normal", new THREE.BufferAttribute(nArr, 3));
  geom.setAttribute("uv", new THREE.BufferAttribute(uvArr, 2));
  geom.setIndex(new THREE.BufferAttribute(idxArr, 1));
  return geom;
}

function replaceMeshGeometry(mesh, geom) {
  if (!mesh) return;
  const old = mesh.geometry;
  mesh.geometry = geom;
  old?.dispose?.();
}

function ensureRoadMeshFor(targetRoot, points, width, opts = {}) {
  const { preview = false } = opts;
  const existing = targetRoot?.children?.find?.(c => c && c.isMesh && c.userData?.isRoadMesh === true) || null;
  const geom = buildRoadGeometry(points, width);
  if (existing) {
    replaceMeshGeometry(existing, geom);
    existing.material = preview ? roadPreviewMaterial : roadMaterial;
    return existing;
  }
  const mesh = new THREE.Mesh(geom, preview ? roadPreviewMaterial : roadMaterial);
  mesh.userData.isRoadMesh = true;
  mesh.renderOrder = preview ? 15 : 1;
  targetRoot.add(mesh);
  return mesh;
}

function clearRoadDraft() {
  if (roadDraft && roadDraft.mesh) {
    roadDraft.mesh.geometry?.dispose?.();
  }
  roadDraft = null;
  hideRoadHoverPreview();
  while (roadDraftRoot.children.length) {
    const c = roadDraftRoot.children.pop();
    c.geometry?.dispose?.();
  }
  updateRoadControls();
}

function getRoadLocalPoints(obj) {
  const pts = obj?.userData?.road?.points;
  if (!Array.isArray(pts)) return [];
  return pts.map(vec3FromArray);
}

function setRoadLocalPoints(obj, points) {
  if (!obj.userData.road) obj.userData.road = {};
  obj.userData.road.points = arraysFromVec3(points);
}

function rebuildRoadObject(obj) {
  if (!isRoadObject(obj)) return;
  if (!obj.userData.road) obj.userData.road = {};
  ensureRoadPointMetaArrays(obj);
  const width = Number(obj.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const pts = getRoadLocalPoints(obj);
  ensureRoadMeshFor(obj, pts, width, { preview: false });
}

function createRoadFromWorldPoints(pointsWorld, width, opts = {}) {
  const {
    recordHistory = true,
    autoSelect = true,
    forcedId = null,
    forcedLocked = false,
    forcedName = "road",
  } = opts;

  if (!Array.isArray(pointsWorld) || !pointsWorld.length) return null;
  const w = Number(width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

  const origin = pointsWorld[0].clone();
  const group = new THREE.Group();
  group.name = "road";
  group.position.copy(origin);

  const ptsLocal = pointsWorld.map(p => p.clone().sub(origin));
  group.userData.road = { width: w, points: arraysFromVec3(ptsLocal) };
  ensureRoadPointMetaArrays(group);
  ensureRoadMeshFor(group, ptsLocal, w, { preview: false });

  markPlaced(group, {
    id: forcedId ?? ("road_" + Date.now() + "_" + Math.floor(Math.random() * 1000)),
    asset: null,
    name: forcedName,
    locked: !!forcedLocked,
    type: "road"
  });

  overlayRoot.add(group);
  if (recordHistory) {
    undoStack.push({ type: "add", obj: group, parent: overlayRoot });
    redoStack = [];
  }

  if (autoSelect) {
    selectObject(group);
    selectRoadPoint(group, 0, { mode: "set", showStatus: false });
  }

  updateRoadControls();
  return group;
}

function spawnRoadNodeAt(pointWorld, opts = {}) {
  const pt = pointWorld?.clone?.();
  if (!pt) return null;
  // Keep road nodes at ground/base height (avoid ramping up onto roofs).
  pt.y = sampleRoadBaseYAtXZ(pt.x, pt.z, 0);
  const w = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const road = createRoadFromWorldPoints([pt], w, opts);
  if (road) setStatus("Road point placed — drag a point to create a segment");
  return road;
}

function beginRoadDraft(ptWorld) {
  clearRoadDraft();
  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;
  roadDraft = { points: [pt], width, mesh: null };
  roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  updateRoadControls();
  setStatus("Road: start point set — tap to add more points, then Finish Road");
}

function snapDirToCardinal(dir) {
  if (!dir) return dir;
  dir.y = 0;
  if (dir.lengthSq() < 1e-8) {
    dir.set(1, 0, 0);
    return dir;
  }
  if (Math.abs(dir.x) >= Math.abs(dir.z)) dir.set(Math.sign(dir.x) || 1, 0, 0);
  else dir.set(0, 0, Math.sign(dir.z) || 1);
  return dir;
}

function snapRoadTurn(lastDir, desiredDir) {
  if (!lastDir || lastDir.lengthSq() < 1e-8) return desiredDir;
  if (!desiredDir || desiredDir.lengthSq() < 1e-8) return lastDir;

  const ld = lastDir.clone();
  ld.y = 0;
  if (ld.lengthSq() < 1e-8) return desiredDir;
  ld.normalize();

  const dd = desiredDir.clone();
  dd.y = 0;
  if (dd.lengthSq() < 1e-8) return ld;
  dd.normalize();

  const straight = ld;
  const left = new THREE.Vector3(-ld.z, 0, ld.x);
  const right = new THREE.Vector3(ld.z, 0, -ld.x);

  const dots = [
    { dir: straight, dot: straight.dot(dd) },
    { dir: left, dot: left.dot(dd) },
    { dir: right, dot: right.dot(dd) },
  ].sort((a, b) => b.dot - a.dot);

  return dots[0].dir.clone();
}

function addRoadDraftPoint(ptWorld) {
  if (!roadDraft) return beginRoadDraft(ptWorld);
  const width = Number(roadWidthEl?.value || roadDraft.width || ROAD_DEFAULT_WIDTH);
  roadDraft.width = width;

  const pt = snapXZ(ptWorld.clone());
  pt.y = 0;

  const last = roadDraft.points[roadDraft.points.length - 1];
  const delta = pt.clone().sub(last);
  delta.y = 0;
  if (delta.length() < ROAD_MIN_POINT_DIST) return;

  if (roadSnapEnabled && roadDraft.points.length >= 2) {
    const prev = roadDraft.points[roadDraft.points.length - 2];
    const lastDir = last.clone().sub(prev);
    lastDir.y = 0;
    const snappedDir = snapRoadTurn(lastDir, delta);
    const len = Math.max(ROAD_MIN_POINT_DIST, delta.dot(snappedDir));
    pt.copy(last).addScaledVector(snappedDir, len);
    snapXZ(pt);
    pt.y = 0;
  }

  roadDraft.points.push(pt);
  roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  updateRoadControls();
  setStatus(`Road: ${roadDraft.points.length} points (Finish when ready)`);
}

function finishRoadDraft() {
  if (!roadDraft || roadDraft.points.length < 2) return;

  const group = new THREE.Group();
  group.name = "road";

  const pointsLocal = roadDraft.points.map(p => new THREE.Vector3(p.x, p.y, p.z));
  const width = Number(roadDraft.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

  group.userData.road = {
    width,
    points: arraysFromVec3(pointsLocal),
  };
  ensureRoadPointMetaArrays(group);

  ensureRoadMeshFor(group, pointsLocal, width, { preview: false });

  markPlaced(group, {
    id: "road_" + Date.now(),
    asset: null,
    name: "road",
    locked: false,
    type: "road"
  });

  overlayRoot.add(group);
  undoStack.push({ type: "add", obj: group, parent: overlayRoot });
  redoStack = [];
  clearRoadDraft();
  selectObject(group);
  setStatus("Road placed (undo available)");
}

const _roadCenterNdc = new THREE.Vector2(0, 0);
function getGroundPointAtStageCenter() {
  raycaster.setFromCamera(_roadCenterNdc, camera);
  const pt = new THREE.Vector3();
  const hit = raycaster.ray.intersectPlane(groundPlane, pt);
  return hit ? pt : null;
}

function spawnDefaultRoadSegment() {
  const center = getGroundPointAtStageCenter() || controls?.target?.clone?.() || new THREE.Vector3(0, 0, 0);
  center.y = 0;
  snapXZ(center);

  const dir = new THREE.Vector3();
  camera.getWorldDirection(dir);
  dir.y = 0;
  if (dir.lengthSq() < 1e-8) dir.set(1, 0, 0);
  else dir.normalize();
  if (roadSnapEnabled) snapDirToCardinal(dir);

  const width = Number(roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const len = Math.max(ROAD_SNAP_STEP * 10, 100);

  const p0World = center.clone().addScaledVector(dir, -len / 2);
  const p1World = center.clone().addScaledVector(dir, len / 2);
  p0World.y = 0;
  p1World.y = 0;
  snapXZ(p0World);
  snapXZ(p1World);

  const group = new THREE.Group();
  group.name = "road";
  group.position.copy(center);

  const p0Local = p0World.clone().sub(center);
  const p1Local = p1World.clone().sub(center);

  group.userData.road = {
    width,
    points: arraysFromVec3([p0Local, p1Local]),
  };
  ensureRoadPointMetaArrays(group);

  ensureRoadMeshFor(group, [p0Local, p1Local], width, { preview: false });

  markPlaced(group, {
    id: "road_" + Date.now() + "_" + Math.floor(Math.random() * 1000),
    asset: null,
    name: "road",
    locked: false,
    type: "road"
  });

  overlayRoot.add(group);
  undoStack.push({ type: "add", obj: group, parent: overlayRoot });
  redoStack = [];

  selectObject(group);
  setStatus("Road spawned — drag a road point to draw a segment (Draw) or reposition it (Move)");
  updateRoadControls();
  return group;
}

function clearRoadHandles() {
  while (roadEditRoot.children.length) {
    roadEditRoot.children.pop();
  }
}

function updateRoadHandleScale() {
  if (!roadEditRoot.visible) return;
  const d = camera.position.distanceTo(controls.target);
  const s = Math.max(0.6, Math.min(4.0, d / 250)) * ROAD_HANDLE_BASE_SIZE;
  for (const h of roadEditRoot.children) {
    if (!h?.userData?.isRoadHandle) continue;
    h.scale.setScalar(s);
  }
}

function buildRoadHandlesFor(roadObj) {
  clearRoadHandles();
  roadEditRoot.visible = false;
  if (!roadObj || !isRoadObject(roadObj)) return;
  if (!canEditObject(roadObj)) return;
  const ptsLocal = getRoadLocalPoints(roadObj);
  for (let i = 0; i < ptsLocal.length; i++) {
    const handle = new THREE.Mesh(roadHandleGeometry, roadHandleMaterial);
    handle.userData.isRoadHandle = true;
    handle.userData.roadId = roadObj.userData.id;
    handle.userData.pointIndex = i;

    const wp = roadObj.localToWorld(ptsLocal[i].clone());
    handle.position.copy(wp);
    handle.renderOrder = 9998;
    roadEditRoot.add(handle);
  }
  roadEditRoot.visible = true;
  updateRoadHandleScale();
}

function syncRoadHandlesToRoad(roadObj) {
  if (!roadObj || !isRoadObject(roadObj) || !roadEditRoot.visible) return;
  const ptsLocal = getRoadLocalPoints(roadObj);
  for (const h of roadEditRoot.children) {
    if (!h?.userData?.isRoadHandle) continue;
    const idx = h.userData.pointIndex;
    if (idx == null || !ptsLocal[idx]) continue;
    const wp = roadObj.localToWorld(ptsLocal[idx].clone());
    h.position.copy(wp);
  }
}

function pickRoadHandle(event) {
  if (!roadEditRoot.visible || !roadEditRoot.children.length) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects(roadEditRoot.children, true);
  if (!hits.length) return null;
  const hit = hits[0].object;
  if (!hit?.userData?.isRoadHandle) return null;
  const idx = hit.userData.pointIndex;
  if (idx == null) return null;
  return { index: idx, handle: hit };
}

function setRoadPointIndexFromWorld(roadObj, index, ptWorld) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[index]) return false;

  const w = ptWorld.clone();
  const local = roadObj.worldToLocal(w);

  ptsLocal[index].copy(local);
  setRoadLocalPoints(roadObj, ptsLocal);
  rebuildRoadObject(roadObj);
  syncRoadHandlesToRoad(roadObj);
  return true;
}

function beginRoadSegmentDrag(roadObj, startIndex, pointerId, clientX, clientY) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (!ptsLocal[startIndex]) return false;

  roadObj.updateMatrixWorld(true);
  const startWorld = roadObj.localToWorld(ptsLocal[startIndex].clone());
  ensureRoadPointMetaArrays(roadObj);

  isDraggingRoadSegment = true;
  roadSegmentDrag = {
    road: roadObj,
    startIndex,
    pointerId,
    startX: clientX,
    startY: clientY,
    startWorld,
    lastEndWorld: null,
    lastSnap: null, // { buildingName: string, side: string }|null
    beforePoints: clonePointsArray(roadObj.userData?.road?.points || []),
    beforeWidth: Number(roadObj.userData?.road?.width || ROAD_DEFAULT_WIDTH),
    beforeIds: Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [],
    beforeMeta: cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []),
  };

  controls.enabled = false;
  try { renderer.domElement.setPointerCapture(pointerId); } catch (_) {}
  setStatus("Road: drag to place next point…");
  return true;
}

function updateRoadSegmentDrag(event) {
  if (!isDraggingRoadSegment || !roadSegmentDrag) return;
  if (!event || roadSegmentDrag.pointerId !== event.pointerId) return;

  const anchorW = roadSegmentDrag.startWorld;
  const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, anchorW.y);
  const width = Number(roadSegmentDrag.road?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
  const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;
  const snapOut = {};
  const endW = computeSnappedRoadEnd(anchorW, rawOnPlane, { width, out: snapOut, snapSources: liveSources });
  roadSegmentDrag.lastEndWorld = endW;
  roadSegmentDrag.lastSnap = snapOut?.snapped ? { buildingName: snapOut.buildingName, side: snapOut.side } : null;

  if (endW) {
    showRoadHoverPreviewSegment(anchorW, endW, width);
  } else {
    hideRoadHoverPreview();
  }
}

function endRoadSegmentDrag(pointerId = null, event = null) {
  if (!isDraggingRoadSegment || !roadSegmentDrag) return;
  if (pointerId != null && roadSegmentDrag.pointerId !== pointerId) return;

  const drag = roadSegmentDrag;
  const roadObj = drag.road;
  const startIndex = drag.startIndex;
  const startWorld = drag.startWorld;

  isDraggingRoadSegment = false;
  roadSegmentDrag = null;

  controls.enabled = true;
  syncControlsForPointers();
  hideRoadHoverPreview();

  const TAP_SLOP_PX = 8;
  const dx = event ? Math.abs(event.clientX - drag.startX) : TAP_SLOP_PX + 1;
  const dy = event ? Math.abs(event.clientY - drag.startY) : TAP_SLOP_PX + 1;
  const isTap = dx <= TAP_SLOP_PX && dy <= TAP_SLOP_PX;

  if (isTap) {
    selectRoadPoint(roadObj, startIndex, { mode: "toggle", showStatus: true });
    updateRoadControls();
    return;
  }

  // Compute final snapped end point.
  let endWorld = drag.lastEndWorld;
  let endSnap = drag.lastSnap || null;
  if (!endWorld && event) {
    const rawOnPlane = getPointOnHorizontalPlaneFromEvent(event, startWorld.y);
    const width = Number(roadObj?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const liveSources = roadBuildingSnapEnabled ? buildLiveRoadSnapSources() : null;
    const snapOut = {};
    endWorld = computeSnappedRoadEnd(startWorld, rawOnPlane, { width, out: snapOut, snapSources: liveSources });
    endSnap = snapOut?.snapped ? { buildingName: snapOut.buildingName, side: snapOut.side } : null;
  }
  if (!endWorld) {
    setStatus("Road: canceled");
    updateRoadControls();
    return;
  }

  const horizLen = Math.hypot(endWorld.x - startWorld.x, endWorld.z - startWorld.z);
  if (horizLen < ROAD_MIN_POINT_DIST) {
    updateRoadControls();
    return;
  }

  roadObj?.updateMatrixWorld?.(true);
  const ptsLocal = getRoadLocalPoints(roadObj);
  const lastIdx = ptsLocal.length - 1;

  // Endpoint extension edits the same road; interior-point drag branches into a new road.
  const isEndpoint = (startIndex === 0 || startIndex === lastIdx || ptsLocal.length === 1);
  if (!isEndpoint) {
    const width = Number(roadObj.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);
    const branch = createRoadFromWorldPoints([startWorld, endWorld], width, { recordHistory: true, autoSelect: true });
    if (branch) {
      ensureRoadPointMetaArrays(roadObj);
      ensureRoadPointMetaArrays(branch);
      // Copy start-point meta if present, and set end-point meta if snapped to a building.
      const startMeta = Array.isArray(roadObj.userData?.road?.pointMeta) ? roadObj.userData.road.pointMeta[startIndex] : null;
      if (Array.isArray(branch.userData?.road?.pointMeta)) {
        branch.userData.road.pointMeta[0] = startMeta ? { ...startMeta, building: (startMeta.building ? { ...startMeta.building } : null) } : null;
      }
      if (endSnap?.buildingName && endSnap?.side && Array.isArray(branch.userData?.road?.pointIds) && Array.isArray(branch.userData?.road?.pointMeta)) {
        const pid = branch.userData.road.pointIds[1];
        branch.userData.road.pointMeta[1] = makeBuildingLinkMeta(endSnap.buildingName, endSnap.side, pid);
      }
      selectRoadPoint(branch, 1, { mode: "set", showStatus: false });
      setStatus("Road branch created (undo available)");
    }
    updateRoadControls();
    return;
  }

  const beforePoints = drag.beforePoints;
  const beforeWidth = drag.beforeWidth;
  const beforeIds = drag.beforeIds || [];
  const beforeMeta = drag.beforeMeta || [];
  const newLocal = roadObj.worldToLocal(endWorld.clone());

  ensureRoadPointMetaArrays(roadObj);
  const roadData = roadObj.userData.road;

  const newId = newRoadPointId();
  const newMeta = (endSnap?.buildingName && endSnap?.side) ? makeBuildingLinkMeta(endSnap.buildingName, endSnap.side, newId) : null;

  if (ptsLocal.length === 1) {
    ptsLocal.push(newLocal);
    roadData.pointIds.push(newId);
    roadData.pointMeta.push(newMeta);
  } else if (startIndex === 0) {
    ptsLocal.unshift(newLocal);
    roadData.pointIds.unshift(newId);
    roadData.pointMeta.unshift(newMeta);
  } else {
    ptsLocal.push(newLocal);
    roadData.pointIds.push(newId);
    roadData.pointMeta.push(newMeta);
  }

  setRoadLocalPoints(roadObj, ptsLocal);
  roadObj.userData.road.width = beforeWidth;
  rebuildRoadObject(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  const afterPoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const afterIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);
  undoStack.push({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth: beforeWidth, beforeIds, afterIds, beforeMeta, afterMeta });
  redoStack = [];

  // Auto-select the new endpoint so the user can keep drawing.
  const wasSinglePoint = Array.isArray(beforePoints) && beforePoints.length === 1;
  const extendedAtStart = !wasSinglePoint && startIndex === 0;
  const newIdx = extendedAtStart ? 0 : (ptsLocal.length - 1);
  selectRoadPoint(roadObj, newIdx, { mode: "set", showStatus: false });
  setStatus("Road segment added (undo available)");
  updateRoadControls();
}

function beginRoadHandleDrag(roadObj, index, pointerId) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;
  if (!Array.isArray(roadObj.userData?.road?.points)) return false;

  isDraggingRoadHandle = true;
  ensureRoadPointMetaArrays(roadObj);
  roadHandleDrag = {
    road: roadObj,
    index,
    pointerId,
    beforePoints: clonePointsArray(roadObj.userData.road.points),
    beforeWidth: Number(roadObj.userData.road.width || ROAD_DEFAULT_WIDTH),
    beforeIds: Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [],
    beforeMeta: cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []),
  };
  controls.enabled = false;
  try { renderer.domElement.setPointerCapture(pointerId); } catch (_) {}
  setStatus("Road point: dragging…");
  return true;
}

function endRoadHandleDrag(pointerId = null) {
  if (!isDraggingRoadHandle || !roadHandleDrag) return;
  if (pointerId != null && roadHandleDrag.pointerId !== pointerId) return;

  const handleIndex = roadHandleDrag.index;
  const roadObj = roadHandleDrag.road;
  const beforePoints = roadHandleDrag.beforePoints;
  const beforeWidth = roadHandleDrag.beforeWidth;
  const beforeIds = roadHandleDrag.beforeIds || [];
  const beforeMeta = roadHandleDrag.beforeMeta || [];
  const afterPoints = clonePointsArray(roadObj?.userData?.road?.points || []);
  const afterWidth = Number(roadObj?.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  const afterIds = Array.isArray(roadObj?.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj?.userData?.road?.pointMeta || []);

  isDraggingRoadHandle = false;
  roadHandleDrag = null;
  controls.enabled = true;

  const changed = JSON.stringify(beforePoints) !== JSON.stringify(afterPoints) || Math.abs(beforeWidth - afterWidth) > 1e-6;
  if (changed && roadObj) {
    undoStack.push({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth, beforeIds, afterIds, beforeMeta, afterMeta });
    redoStack = [];
    selectRoadPoint(roadObj, handleIndex, { mode: "set", showStatus: false });
    setStatus("Road edited ✓ (undo available)");
  } else {
    // Treat a simple tap as point selection (and endpoint selection for extension).
    selectRoadPoint(roadObj, handleIndex, { mode: "toggle", showStatus: true });
  }
  updateRoadControls();
}

function extendRoadAtPoint(roadObj, ptWorld) {
  if (!roadObj || !isRoadObject(roadObj)) return false;
  if (!canEditObject(roadObj)) return false;

  const ptsLocal = getRoadLocalPoints(roadObj);
  if (ptsLocal.length < 1) return false;

  const beforePoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const beforeWidth = Number(roadObj.userData?.road?.width || ROAD_DEFAULT_WIDTH);
  ensureRoadPointMetaArrays(roadObj);
  const beforeIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const beforeMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);

  const ptW = snapXZ(ptWorld.clone());
  ptW.y = 0;

  const startW = roadObj.localToWorld(ptsLocal[0].clone());
  const endW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 1].clone());
  const distStart = ptW.distanceTo(startW);
  const distEnd = ptW.distanceTo(endW);
  let extendEnd = distEnd <= distStart;

  // If user picked an endpoint handle, override nearest-end logic.
  if (roadExtendFromRoadId && roadExtendFromRoadId === roadObj.userData?.id && roadExtendFrom) {
    extendEnd = roadExtendFrom === "end";
  }

  let newW = ptW.clone();
  if (roadSnapEnabled && ptsLocal.length >= 2) {
    if (extendEnd) {
      const prevW = roadObj.localToWorld(ptsLocal[ptsLocal.length - 2].clone());
      const lastDir = endW.clone().sub(prevW); lastDir.y = 0;
      const desired = ptW.clone().sub(endW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = endW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    } else {
      const nextW = roadObj.localToWorld(ptsLocal[1].clone());
      const lastDir = startW.clone().sub(nextW); lastDir.y = 0; // direction from next -> start
      const desired = ptW.clone().sub(startW); desired.y = 0;
      const snappedDir = snapRoadTurn(lastDir, desired);
      const len = Math.max(ROAD_MIN_POINT_DIST, desired.dot(snappedDir));
      newW = startW.clone().addScaledVector(snappedDir, len);
      snapXZ(newW);
      newW.y = 0;
    }
  }

  const newLocal = roadObj.worldToLocal(newW.clone());
  newLocal.y = 0;
  snapXZ(newLocal);

  const endLocal = extendEnd ? ptsLocal[ptsLocal.length - 1] : ptsLocal[0];
  if (newLocal.distanceTo(endLocal) < ROAD_MIN_POINT_DIST) return false;

  const oldLastIdx = ptsLocal.length - 1;
  const newId = newRoadPointId();
  const newMeta = null;
  if (extendEnd) {
    ptsLocal.push(newLocal);
    roadObj.userData.road.pointIds.push(newId);
    roadObj.userData.road.pointMeta.push(newMeta);
  } else {
    ptsLocal.unshift(newLocal);
    roadObj.userData.road.pointIds.unshift(newId);
    roadObj.userData.road.pointMeta.unshift(newMeta);
  }

  // Keep point selection stable after inserting a new endpoint.
  if (selected === roadObj && Number.isInteger(roadSelectedPointIndex) && roadSelectedPointIndex >= 0) {
    if (extendEnd) {
      if (roadSelectedPointIndex === oldLastIdx) roadSelectedPointIndex = ptsLocal.length - 1;
    } else {
      if (roadSelectedPointIndex !== 0) roadSelectedPointIndex = roadSelectedPointIndex + 1;
      else roadSelectedPointIndex = 0;
    }
  }

  setRoadLocalPoints(roadObj, ptsLocal);
  roadObj.userData.road.width = beforeWidth;
  rebuildRoadObject(roadObj);
  buildRoadHandlesFor(roadObj);
  syncRoadHandlesToRoad(roadObj);

  const afterPoints = clonePointsArray(roadObj.userData?.road?.points || []);
  const afterIds = Array.isArray(roadObj.userData?.road?.pointIds) ? roadObj.userData.road.pointIds.slice() : [];
  const afterMeta = cloneRoadPointMeta(roadObj.userData?.road?.pointMeta || []);
  undoStack.push({ type: "road_edit", obj: roadObj, beforePoints, afterPoints, beforeWidth, afterWidth: beforeWidth, beforeIds, afterIds, beforeMeta, afterMeta });
  redoStack = [];
  setStatus("Road extended ✓ (undo available)");
  updateRoadControls();
  return true;
}

// Collision (solid objects)
const COLLIDE_WITH_BASE_MODEL = true;
const MIN_BASE_COLLIDER_HEIGHT = 0.5;
const BASE_MIN_Y_OVERLAP = 0.15;
const COLLISION_EPS = 0.001;

const baseColliderMeshes = [];
const _boxA = new THREE.Box3();
const _boxB = new THREE.Box3();

// Ground plane
const groundPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

function setMouseFromEvent(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((event.clientY - rect.top) / rect.height) * 2 - 1);
}

function isEventOnStage(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  return event.clientX >= rect.left &&
    event.clientX <= rect.right &&
    event.clientY >= rect.top &&
    event.clientY <= rect.bottom;
}

function getGroundPointFromEvent(event) {
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const pt = new THREE.Vector3();
  const hit = raycaster.ray.intersectPlane(groundPlane, pt);
  return hit ? pt : null;
}

// -------------------- ROTATE PROXY (object center) --------------------
const rotateProxy = new THREE.Object3D();
rotateProxy.name = "rotateProxy";
scene.add(rotateProxy);

function getWorldBBoxCenter(obj, out = new THREE.Vector3()) {
  const box = new THREE.Box3().setFromObject(obj);
  box.getCenter(out);
  return out;
}

function updateRotateProxyAtSelection() {
  if (!selected) return;
  getWorldBBoxCenter(selected, rotateProxy.position);
  if (!isTransformDragging) {
    rotateProxy.quaternion.copy(selected.quaternion);
  }
  rotateProxy.scale.set(1, 1, 1);
  rotateProxy.updateMatrixWorld(true);
}

// -------------------- Angle readout helpers --------------------
let lastPointerClientX = 0;
let lastPointerClientY = 0;

function showAngleReadout(text) {
  if (!angleReadoutEl) return;
  angleReadoutEl.style.display = "block";
  angleReadoutEl.textContent = text;
  angleReadoutEl.style.left = lastPointerClientX + "px";
  angleReadoutEl.style.top = lastPointerClientY + "px";
}
function hideAngleReadout() {
  if (!angleReadoutEl) return;
  angleReadoutEl.style.display = "none";
}

window.addEventListener("pointermove", (e) => {
  lastPointerClientX = e.clientX;
  lastPointerClientY = e.clientY;
}, { passive:true });

// -------------------- Transform Controls (FIXED) --------------------
const transform = new TransformControls(camera, renderer.domElement);

// ✅ FIX: Add the helper (Object3D) to the scene, NOT transform itself
const transformHelper = transform.getHelper();
transformHelper.visible = false;
scene.add(transformHelper);

transform.setSize(1.0);
transform.setSpace("local");
transform.showX = true;
transform.showY = true;
transform.showZ = true;
transform.showE = false;

// Make gizmo ALWAYS visible & clickable (render on top)
function forceGizmoAlwaysVisible() {
  const root = transformHelper;
  root.renderOrder = 9999;
  root.traverse((n) => {
    if (n.isLine || n.isMesh) {
      n.renderOrder = 9999;
      if (n.material) {
        const mats = Array.isArray(n.material) ? n.material : [n.material];
        for (const m of mats) {
          m.depthTest = false;
          m.depthWrite = false;
          m.transparent = true;
        }
      }
    }
  });
}
forceGizmoAlwaysVisible();

function updateGizmoSize() {
  if (!transform || !transform.object) return;
  const d = camera.position.distanceTo(controls.target);
  let size = Math.max(0.75, Math.min(6, d / 150));
  if (currentTool === "rotate" && transform.object === rotateProxy) {
    size = Math.max(1.5, Math.min(4.5, d / 220));
  }
  transform.setSize(size);
}

// Rotate drag state
let isTransformDragging = false;
let gizmoLastSafePos = null;
let isGizmoPointerDown = false;

let rotateStartProxyQuat = new THREE.Quaternion();
let rotateStartSelQuat = new THREE.Quaternion();
let rotateStartSelPos = new THREE.Vector3();
let rotateCenterWorld = new THREE.Vector3();
let rotateAxis = null;

transform.addEventListener("dragging-changed", (e) => {
  isTransformDragging = !!e.value;
  controls.enabled = !e.value;

  if (!e.value) {
    isGizmoPointerDown = false;
    clearAlignGuides();
    hideAngleReadout();

    if (currentTool === "rotate" && selected) {
      updateRotateProxyAtSelection();
    }
  }
});

// -------------------- Undo/Redo --------------------
let undoStack = [];
let redoStack = [];

function cloneTRS(obj) {
  return {
    position: obj.position.clone(),
    rotation: obj.rotation.clone(),
    scale: obj.scale.clone()
  };
}
function applyTRS(obj, trs) {
  if (!obj || !trs) return;
  obj.position.copy(trs.position);
  obj.rotation.copy(trs.rotation);
  obj.scale.copy(trs.scale);
  obj.updateMatrixWorld();
}
function removeObject(obj) {
  if (!obj) return;
  if (transform.object === obj) transform.detach();
  if (selected === obj) {
    selected = null;
    updateCommitButtons();
    clearRoadHandles();
    roadEditRoot.visible = false;
    updateRoadControls();
  }
  obj.parent?.remove(obj);
}
function insertObject(parent, obj) {
  if (!parent || !obj) return;
  parent.add(obj);
}

let activeBefore = null;

function historyTargetObject() {
  if (currentTool === "rotate" && selected) return selected;
  return transform.object;
}

transform.addEventListener("mouseDown", () => {
  const targetObj = historyTargetObject();
  if (!targetObj) return;

  activeBefore = cloneTRS(targetObj);
  isGizmoPointerDown = true;

  if (currentTool === "move") gizmoLastSafePos = targetObj.position.clone();
  else gizmoLastSafePos = null;

  if (currentTool === "rotate" && selected) {
    rotateAxis = transform.axis || null;
    rotateStartProxyQuat.copy(rotateProxy.quaternion);
    rotateStartSelQuat.copy(selected.quaternion);
    rotateStartSelPos.copy(selected.position);
    rotateCenterWorld.copy(getWorldBBoxCenter(selected));
    showAngleReadout("0.0°");
  }
});

transform.addEventListener("objectChange", () => {
  if (currentTool === "move") {
    const obj = transform.object;
    if (!obj) return;
    if (!gizmoLastSafePos) gizmoLastSafePos = obj.position.clone();

    if (autoAlignEnabled) applyAutoAlign(obj);
    else clearAlignGuides();

    if (isColliding(obj)) {
      obj.position.copy(gizmoLastSafePos);
      obj.updateMatrixWorld();
      clearAlignGuides();
    } else {
      gizmoLastSafePos.copy(obj.position);
    }
    return;
  }

  if (currentTool === "rotate" && selected && transform.object === rotateProxy) {
    if (!isTransformDragging) return;

    const invStart = rotateStartProxyQuat.clone().invert();
    const delta = rotateProxy.quaternion.clone().multiply(invStart);

    selected.quaternion.copy(delta.clone().multiply(rotateStartSelQuat));

    const center = rotateCenterWorld.clone();
    const rel = rotateStartSelPos.clone().sub(center);
    rel.applyQuaternion(delta);
    selected.position.copy(center.add(rel));
    selected.updateMatrixWorld(true);

    const eul = new THREE.Euler().setFromQuaternion(delta, "XYZ");
    let deg = 0;
    if (rotateAxis === "X") deg = THREE.MathUtils.radToDeg(eul.x);
    else if (rotateAxis === "Y") deg = THREE.MathUtils.radToDeg(eul.y);
    else if (rotateAxis === "Z") deg = THREE.MathUtils.radToDeg(eul.z);
    else {
      const ang = 2 * Math.acos(Math.max(-1, Math.min(1, delta.w)));
      deg = THREE.MathUtils.radToDeg(ang);
    }
    showAngleReadout(`${deg.toFixed(1)}°`);
  }
});

transform.addEventListener("mouseUp", () => {
  const targetObj = historyTargetObject();
  if (!targetObj || !activeBefore) return;

  const after = cloneTRS(targetObj);
  const before = activeBefore;
  activeBefore = null;
  gizmoLastSafePos = null;

  undoStack.push({ type: "transform", obj: targetObj, before, after });
  redoStack = [];
  setStatus("Changed (undo available)");
  isGizmoPointerDown = false;
  clearAlignGuides();

  if (currentTool === "rotate" && selected) updateRotateProxyAtSelection();
});

function doUndo() {
  const cmd = undoStack.pop();
  if (!cmd) return;

  if (cmd.type === "transform") applyTRS(cmd.obj, cmd.before);
  else if (cmd.type === "road_edit") applyRoadEdit(cmd.obj, cmd.beforePoints, cmd.beforeWidth, cmd.beforeIds, cmd.beforeMeta);
  else if (cmd.type === "add") removeObject(cmd.obj);
  else if (cmd.type === "remove") insertObject(cmd.parent, cmd.obj);

  redoStack.push(cmd);
  setStatus("Undo ✓");
}
function doRedo() {
  const cmd = redoStack.pop();
  if (!cmd) return;

  if (cmd.type === "transform") applyTRS(cmd.obj, cmd.after);
  else if (cmd.type === "road_edit") applyRoadEdit(cmd.obj, cmd.afterPoints, cmd.afterWidth, cmd.afterIds, cmd.afterMeta);
  else if (cmd.type === "add") insertObject(cmd.parent, cmd.obj);
  else if (cmd.type === "remove") removeObject(cmd.obj);

  undoStack.push(cmd);
  setStatus("Redo ✓");
}

function applyRoadEdit(obj, points, width, pointIds = null, pointMeta = null) {
  if (!obj || !isRoadObject(obj)) return;
  if (!obj.userData.road) obj.userData.road = {};
  obj.userData.road.points = clonePointsArray(Array.isArray(points) ? points : []);
  if (width != null) obj.userData.road.width = Number(width || ROAD_DEFAULT_WIDTH);
  if (Array.isArray(pointIds)) obj.userData.road.pointIds = pointIds.slice();
  if (Array.isArray(pointMeta)) obj.userData.road.pointMeta = cloneRoadPointMeta(pointMeta);
  ensureRoadPointMetaArrays(obj);
  rebuildRoadObject(obj);
  if (selected === obj && currentTool === "road") {
    buildRoadHandlesFor(obj);
    syncRoadHandlesToRoad(obj);
  }
  updateRoadControls();
}

btnUndo?.addEventListener("click", doUndo);
btnRedo?.addEventListener("click", doRedo);

// -------------------- Placed metadata --------------------
function markPlaced(obj, meta) {
  obj.userData.isPlaced = true;
  obj.userData.type = meta.type || "asset";
  obj.userData.asset = meta.asset;
  obj.userData.id = meta.id;
  obj.userData.locked = !!meta.locked;
  obj.userData.nameLabel = meta.name || "item";
}

// -------------------- Hover & Selection --------------------
let hovered = null;
let selected = null;
updateCommitButtons();

const hoverOriginal = new Map();
const selectOriginal = new Map();
const selectTransparencyBackup = new Map();

function ensureHighlightMaterials(map, mesh) {
  if (map.has(mesh.uuid)) return;
  map.set(mesh.uuid, { material: mesh.material });

  const mat = mesh.material;
  if (Array.isArray(mat)) {
    mesh.material = mat.map(m => (m && m.clone ? m.clone() : m));
  } else if (mat && mat.clone) {
    mesh.material = mat.clone();
  }
}
function getMaterialsArray(material) {
  if (!material) return [];
  return Array.isArray(material) ? material : [material];
}
function applyTint(mesh, type) {
  const mats = getMaterialsArray(mesh.material);
  const isHover = type === "hover";
  mats.forEach((mat) => {
    if (!mat) return;
    if (mat.emissive) mat.emissive.setHex(isHover ? 0x3344ff : 0x22c55e);
    else if (mat.color) {
      if (isHover) mat.color.offsetHSL(0, 0, 0.15);
      else mat.color.offsetHSL(0.3, 0, 0.1);
    }
    mat.needsUpdate = true;
  });
}
function restoreOriginal(map, mesh) {
  const saved = map.get(mesh.uuid);
  if (!saved) return;
  mesh.material = saved.material;
  map.delete(mesh.uuid);
}
function isMeshInsideObject(mesh, obj) {
  if (!mesh || !obj) return false;
  let p = mesh;
  while (p) {
    if (p === obj) return true;
    p = p.parent;
  }
  return false;
}
function applyHover(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    if (selected && isMeshInsideObject(n, selected)) return;
    ensureHighlightMaterials(hoverOriginal, n);
    applyTint(n, "hover");
  });
}
function clearHover(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    restoreOriginal(hoverOriginal, n);
  });
}

function applySelectedTransparency(obj, opacity = 0.55) {
  selectTransparencyBackup.clear();
  obj.traverse((n) => {
    if (!n.isMesh) return;
    const mats = getMaterialsArray(n.material);
    if (!mats.length) return;

    if (!selectTransparencyBackup.has(n.uuid)) {
      selectTransparencyBackup.set(n.uuid, mats.map(m => ({
        m,
        transparent: m.transparent,
        opacity: m.opacity
      })));
    }

    for (const m of mats) {
      m.transparent = true;
      const baseOp = (typeof m.opacity === "number") ? m.opacity : 1;
      m.opacity = Math.min(baseOp, opacity);
      m.needsUpdate = true;
    }
  });
}
function restoreSelectedTransparency(obj) {
  obj.traverse((n) => {
    if (!n.isMesh) return;
    const backup = selectTransparencyBackup.get(n.uuid);
    if (!backup) return;
    for (const b of backup) {
      b.m.transparent = b.transparent;
      b.m.opacity = b.opacity;
      b.m.needsUpdate = true;
    }
  });
  selectTransparencyBackup.clear();
}

function applySelected(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    ensureHighlightMaterials(selectOriginal, n);
    applyTint(n, "selected");
  });
  applySelectedTransparency(obj, 0.55);
}
function clearSelected(obj) {
  if (!obj) return;
  obj.traverse((n) => {
    if (!n.isMesh) return;
    restoreOriginal(selectOriginal, n);
  });
  restoreSelectedTransparency(obj);
}

// -------------------- Collision helpers --------------------
function rebuildBaseColliders() {
  baseColliderMeshes.length = 0;
  if (!campusRoot || !COLLIDE_WITH_BASE_MODEL) return;
  const tmpBox = new THREE.Box3();
  const tmpSize = new THREE.Vector3();

  campusRoot.traverse((n) => {
    if (!n.isMesh) return;
    tmpBox.setFromObject(n);
    if (tmpBox.isEmpty()) return;
    tmpBox.getSize(tmpSize);
    if (tmpSize.y < MIN_BASE_COLLIDER_HEIGHT) return;
    baseColliderMeshes.push(n);
  });
}
function getOverlap(a, b) {
  return {
    x: Math.min(a.max.x, b.max.x) - Math.max(a.min.x, b.min.x),
    y: Math.min(a.max.y, b.max.y) - Math.max(a.min.y, b.min.y),
    z: Math.min(a.max.z, b.max.z) - Math.max(a.min.z, b.min.z),
  };
}
function boxesOverlap(a, b, eps, minYOverlap) {
  const o = getOverlap(a, b);
  if (o.x <= eps) return false;
  if (o.z <= eps) return false;
  if (o.y <= minYOverlap) return false;
  return true;
}
function isColliding(obj) {
  if (!obj) return false;
  if (obj.userData?.type === "road") return false;

  _boxA.setFromObject(obj);
  if (_boxA.isEmpty()) return false;

  for (const other of overlayRoot.children) {
    if (!other || other === obj) continue;
    if (!other.visible) continue;
    if (other.userData?.type === "road") continue;
    _boxB.setFromObject(other);
    if (boxesOverlap(_boxA, _boxB, COLLISION_EPS, COLLISION_EPS)) return true;
  }

  if (COLLIDE_WITH_BASE_MODEL) {
    for (const mesh of baseColliderMeshes) {
      if (!mesh || !mesh.visible) continue;
      if (isMeshInsideObject(mesh, obj)) continue;
      _boxB.setFromObject(mesh);
      if (boxesOverlap(_boxA, _boxB, COLLISION_EPS, BASE_MIN_Y_OVERLAP)) return true;
    }
  }

  return false;
}

// -------------------- Building list helpers --------------------
function isGenericNodeName(name) {
  if (!name) return true;
  const n = String(name).toLowerCase();
  return n === "scene" ||
    n === "auxscene" ||
    n === "root" ||
    n === "rootnode" ||
    n === "gltf" ||
    n === "model" ||
    n === "group";
}

function isRenderableObject(obj) {
  return !!(obj && (obj.isMesh || obj.isLine || obj.isPoints));
}

function isEmptyGroup(obj) {
  if (!obj) return false;
  if (isRenderableObject(obj)) return false;
  return Array.isArray(obj.children) && obj.children.length > 0;
}

function updateBuildingSelected(obj) {
  if (!buildingSelectedEl) return;
  if (obj && obj.name) buildingSelectedEl.textContent = `Selected: ${obj.name}`;
  else buildingSelectedEl.textContent = "Selected: None";
}

function refreshBuildingList() {
  if (!buildingListEl) return;
  buildingListEl.innerHTML = "";

  if (!campusRoot) {
    buildingListEl.innerHTML = "<div style=\"color:#6b7280;font-weight:700;\">No model loaded</div>";
    return;
  }

  const byName = new Map();
  campusRoot.traverse((obj) => {
    if (!obj || !obj.name || isGenericNodeName(obj.name)) return;
    if (obj.userData?.isPlaced) return;

    const existing = byName.get(obj.name);
    if (!existing) {
      byName.set(obj.name, obj);
      return;
    }

    // Prefer named empty/group over meshes
    if (isEmptyGroup(obj) && !isEmptyGroup(existing)) {
      byName.set(obj.name, obj);
    }
  });

  const filter = (buildingFilterEl?.value || "").trim().toLowerCase();
  const items = Array.from(byName.entries())
    .filter(([name]) => !filter || name.toLowerCase().includes(filter))
    .sort((a, b) => a[0].localeCompare(b[0]));

  if (!items.length) {
    buildingListEl.innerHTML = "<div style=\"color:#6b7280;font-weight:700;\">No matches</div>";
    return;
  }

  for (const [name, obj] of items) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = name;
    btn.style.padding = "8px 10px";
    btn.style.borderRadius = "10px";
    btn.style.border = "1px solid #e5e7eb";
    btn.style.background = "#fff";
    btn.style.fontWeight = "800";
    btn.style.textAlign = "left";
    btn.style.cursor = "pointer";

    btn.addEventListener("click", () => {
      if (currentTool === "road" && selected && isRoadObject(selected) && canEditObject(selected)) {
        setRoadConnectTarget(obj);
        return;
      }
      selectObject(obj);
    });

    buildingListEl.appendChild(btn);
  }
}

buildingFilterEl?.addEventListener("input", () => {
  refreshBuildingList();
});

// -------------------- Picking (overlay + base) --------------------
function pickObject(event) {
  if (!campusRoot) return null;

  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const hits = raycaster.intersectObjects([overlayRoot, campusRoot], true);
  if (!hits.length) return null;

  const isGroundHit = (obj) => {
    if (!obj) return true;
    if (isGroundLikeName(obj.name)) return true;
    if (isGroundObjectOrChild(obj)) return true;
    return false;
  };

  // 1) Overlay objects first (roads/assets) — ignore ground meshes.
  for (const h of hits) {
    let obj = h.object;
    if (isGroundHit(obj)) continue;
    while (obj) {
      if (obj.userData?.isPlaced) return obj;
      if (obj.parent === overlayRoot) return obj;
      if (obj.parent === campusRoot) break;
      obj = obj.parent;
    }
  }

  // 2) Base model objects (buildings), excluding ground.
  for (const h of hits) {
    let obj = h.object;
    if (isGroundHit(obj)) continue;
    while (obj) {
      if (obj.parent === campusRoot) return obj;
      obj = obj.parent;
    }
  }

  // 3) Ground fallback only if no overlay hit.
  for (const h of hits) {
    let obj = h.object;
    if (!isGroundHit(obj)) continue;
    while (obj && obj.parent !== campusRoot) obj = obj.parent;
    return obj || h.object;
  }

  return null;
}

function pickRoadObject(event) {
  if (!overlayRoot) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const hits = raycaster.intersectObjects([overlayRoot], true);
  if (!hits.length) return null;

  for (const h of hits) {
    let obj = h.object;
    while (obj) {
      if (isRoadObject(obj)) return obj;
      if (obj.parent === overlayRoot) break;
      obj = obj.parent;
    }
  }
  return null;
}

// -------------------- Selection / edit rules --------------------
 function canEditObject(obj) {
   if (!obj) return false;
   if (obj.userData?.locked) return false;
   if (obj.userData?.isPlaced) return true;
   return ALLOW_EDIT_BASE_MODEL === true;
 }
function isTransformToolActive() {
  return currentTool === "move" || currentTool === "rotate" || currentTool === "scale";
}

// IMPORTANT: show/hide helper, not transform
function syncTransformToSelection() {
  if (!selected || !canEditObject(selected) || !isTransformToolActive()) {
    transform.detach();
    transformHelper.visible = false;
    hideAngleReadout();
    return;
  }

  transformHelper.visible = true;

  if (currentTool === "move") {
    transform.detach();
    transform.attach(selected);
    transform.setMode("translate");
  }

  if (currentTool === "scale") {
    transform.detach();
    transform.attach(selected);
    transform.setMode("scale");
  }

  if (currentTool === "rotate") {
    updateRotateProxyAtSelection();
    transform.detach();
    transform.attach(rotateProxy);
    transform.setMode("rotate");
    transform.setSpace("local");
  }

  updateGizmoSize();
  forceGizmoAlwaysVisible();
}

 function selectObject(obj) {
   if (selected) clearSelected(selected);

   // Clear road point/extend selection when changing selection
   clearRoadPointSelection();
   roadPickBuildingMode = false;
   hideRoadHoverPreview();

   selected = obj || null;
   transform.detach();

   if (!selected) {
     transformHelper.visible = false;
     hideAngleReadout();
     updateBuildingSelected(null);
     clearRoadHandles();
     roadEditRoot.visible = false;
     setStatus("No selection");
     updateCommitButtons();
     updateRoadControls();
     return;
   }

  if (hovered === selected) {
    clearHover(hovered);
    hovered = null;
  }

   applySelected(selected);

   syncTransformToSelection();
 
   const isPlaced = !!selected.userData?.isPlaced;
   const isLocked = !!selected.userData?.locked;
   const isRoad = isRoadObject(selected);
   if (isRoad) rebuildRoadObject(selected);
   
   if (isLocked) {
      if (isRoad) setStatus("Selected road (committed/locked) — press Uncommit to edit");
      else setStatus(isPlaced
        ? "Selected overlay (committed/locked) — press Uncommit to edit"
       : "Selected base object (committed/locked) — press Uncommit to edit");
    } else if (canEditObject(selected)) {
      if (isRoad && currentTool === "road") {
       setStatus("Selected road (editable) — drag a road point to draw a segment (Draw) or reposition it (Move)");
      } else if (isTransformToolActive()) {
        setStatus(isRoad ? "Selected road (editable)" : (isPlaced ? "Selected overlay (editable)" : "Selected base object (editable)"));
      } else {
       setStatus(isRoad
         ? "Selected road (press Road tool to edit points)"
         : (isPlaced ? "Selected overlay (click Move/Rotate/Scale to edit)" : "Selected base object (click Move/Rotate/Scale to edit)"));
     }
   } else {
     setStatus(isRoad ? "Selected road (view only)" : (isPlaced ? "Selected overlay (view only)" : "Selected base object (view only)"));
   }

   updateBuildingSelected(selected);
   updateCommitButtons();

   if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
   else {
     clearRoadHandles();
     roadEditRoot.visible = false;
   }

   updateRoadControls();
 }

function commitSelected() {
  if (!selected) return setStatus("Select an object first");
  if (selected.userData?.locked) return setStatus("Already committed (locked)");
 
  selected.userData.locked = true;
 
   // Prevent Undo/Redo from modifying a committed object
   undoStack = undoStack.filter(cmd => cmd?.obj !== selected);
   redoStack = redoStack.filter(cmd => cmd?.obj !== selected);
 
  syncTransformToSelection();
  updateCommitButtons();
  if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
  updateRoadControls();
  setStatus("Committed (locked)");
}
 
 function uncommitSelected() {
   if (!selected) return setStatus("Select an object first");
   if (!selected.userData?.locked) return setStatus("Not committed");
 
   selected.userData.locked = false;
   syncTransformToSelection();
   updateCommitButtons();
   if (currentTool === "road" && isRoadObject(selected)) buildRoadHandlesFor(selected);
   updateRoadControls();
   setStatus("Uncommitted (unlocked)");
 }
 
 btnCommit?.addEventListener("click", commitSelected);
 btnEdit?.addEventListener("click", uncommitSelected);

// -------------------- Tools --------------------
toolMove?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  currentTool = "move";
  transform.setMode("translate");
  setActiveToolButton(toolMove);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Move tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Move tool active (selection is committed/locked — press Uncommit)"
      : "Move tool active (selection is view only)");
  }
  setStatus("Move tool active");
});

toolRotate?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  currentTool = "rotate";
  autoAlignEnabled = false;
  updateAutoAlignButton();
  clearAlignGuides();

  transform.setMode("rotate");
  transform.setSpace("local");

  setActiveToolButton(toolRotate);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Rotate tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Rotate tool active (selection is committed/locked — press Uncommit)"
      : "Rotate tool active (selection is view only)");
  }
  setStatus("Rotate tool active");
});

toolScale?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  currentTool = "scale";
  autoAlignEnabled = false;
  updateAutoAlignButton();
  clearAlignGuides();

  transform.setMode("scale");
  setActiveToolButton(toolScale);
  syncTransformToSelection();
  updateToolIndicator();
  if (!selected) return setStatus("Scale tool active (select an object)");
  if (!canEditObject(selected)) {
    return setStatus(selected.userData?.locked
      ? "Scale tool active (selection is committed/locked — press Uncommit)"
      : "Scale tool active (selection is view only)");
  }
  setStatus("Scale tool active");
});

toolRoad?.addEventListener("click", () => {
  autoAlignEnabled = false;
  updateAutoAlignButton();
  clearAlignGuides();

  currentTool = "road";
  setActiveToolButton(toolRoad);
  syncTransformToSelection();
  updateToolIndicator();

  if (selected && !isRoadObject(selected)) {
    selectObject(null);
  }

  // Leaving asset placement mode when switching to Road tool
  if (pendingAssetPath) {
    pendingAssetPath = null;
    clearPreview();
  }

  clearRoadDraft(); // legacy draft mode off by default (node/edge workflow places immediately)

  if (selected && isRoadObject(selected)) {
    if (canEditObject(selected)) {
      buildRoadHandlesFor(selected);
      syncRoadHandlesToRoad(selected);
      setStatus(roadDragMode === "draw"
        ? "Road tool active — drag a road point to draw a segment (Snap On=90°, Off=5°)"
        : "Road tool active — drag a road point to move it (switch Drag mode to Draw to create segments)");
    } else {
      clearRoadHandles();
      roadEditRoot.visible = false;
      setStatus("Road tool active — selected road is locked (press Uncommit to edit)");
    }
  } else {
    clearRoadHandles();
    roadEditRoot.visible = false;
    setStatus("Road tool active — tap the map to place a road point");
  }
});

toolCancel?.addEventListener("click", () => {
  clearActiveTool(true);
});

roadFinishBtn?.addEventListener("click", () => {
  finishRoadDraft();
});
roadCancelBtn?.addEventListener("click", () => {
  clearRoadDraft();
  setStatus("Road draft canceled");
});
roadNewBtn?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  if (pendingAssetPath) {
    pendingAssetPath = null;
    clearPreview();
  }
  selectObject(null);
  setStatus("Road: tap the map to place the first point");
});
roadSnapBtn?.addEventListener("click", () => {
  roadSnapEnabled = !roadSnapEnabled;
  updateRoadControls();
  setStatus(roadSnapEnabled ? "Snap: On (90° cardinal)" : `Snap: Off (${ROAD_SNAP_FINE_DEG}° increments)`);
});
roadBuildingSnapBtn?.addEventListener("click", () => {
  roadBuildingSnapEnabled = !roadBuildingSnapEnabled;
  updateRoadControls();
  setStatus(roadBuildingSnapEnabled
    ? "Attach enabled — drag a road point to snap to a building"
    : "Attach disabled — free layout mode");
});
roadDragModeBtn?.addEventListener("click", () => {
  roadDragMode = (roadDragMode === "draw") ? "move" : "draw";
  updateRoadControls();
  setStatus(roadDragMode === "draw"
    ? "Road drag mode: Draw (drag a point to create a segment)"
    : "Road drag mode: Move (drag a point to reposition)");
});

roadPickBuildingBtn?.addEventListener("click", () => {
  if (!(selected && isRoadObject(selected))) return setStatus("Select a road first");
  if (!canEditObject(selected)) return setStatus("Road locked — press Uncommit");
  roadPickBuildingMode = !roadPickBuildingMode;
  hideRoadHoverPreview();
  updateRoadControls();
  setStatus(roadPickBuildingMode ? "Pick Building: tap a building (or click in list)" : "Pick Building canceled");
});
roadClearBuildingBtn?.addEventListener("click", () => {
  setRoadConnectTarget(null);
});

function connectSelectedRoadPointTo(side) {
  if (!(selected && isRoadObject(selected))) return setStatus("Select a road first");
  if (!canEditObject(selected)) return setStatus("Road locked — press Uncommit");
  if (!roadConnectTarget) return setStatus("Pick a building target first");
  if (!Number.isInteger(roadSelectedPointIndex)) return setStatus("Tap a road point first");
  connectRoadPointToBuilding(selected, roadSelectedPointIndex, roadConnectTarget, side);
}
roadConnectFrontBtn?.addEventListener("click", () => connectSelectedRoadPointTo("front"));
roadConnectBackBtn?.addEventListener("click", () => connectSelectedRoadPointTo("back"));
roadConnectLeftBtn?.addEventListener("click", () => connectSelectedRoadPointTo("left"));
roadConnectRightBtn?.addEventListener("click", () => connectSelectedRoadPointTo("right"));

roadWidthEl?.addEventListener("input", () => {
  setRoadWidthReadout(roadWidthEl.value);
  if (roadDraft) {
    roadDraft.width = Number(roadWidthEl.value || ROAD_DEFAULT_WIDTH);
    roadDraft.mesh = ensureRoadMeshFor(roadDraftRoot, roadDraft.points, roadDraft.width, { preview: true });
  }
  updateRoadControls();
});
roadWidthEl?.addEventListener("change", () => {
  const width = Number(roadWidthEl.value || ROAD_DEFAULT_WIDTH);
  if (selected && isRoadObject(selected) && canEditObject(selected)) {
    const beforeWidth = Number(selected.userData?.road?.width || ROAD_DEFAULT_WIDTH);
    if (Math.abs(beforeWidth - width) > 1e-6) {
      ensureRoadPointMetaArrays(selected);
      const beforePoints = clonePointsArray(selected.userData?.road?.points || []);
      const beforeIds = Array.isArray(selected.userData?.road?.pointIds) ? selected.userData.road.pointIds.slice() : [];
      const beforeMeta = cloneRoadPointMeta(selected.userData?.road?.pointMeta || []);
      selected.userData.road.width = width;
      rebuildRoadObject(selected);
      syncRoadHandlesToRoad(selected);
      const afterPoints = clonePointsArray(selected.userData?.road?.points || []);
      const afterIds = Array.isArray(selected.userData?.road?.pointIds) ? selected.userData.road.pointIds.slice() : [];
      const afterMeta = cloneRoadPointMeta(selected.userData?.road?.pointMeta || []);
      undoStack.push({
        type: "road_edit",
        obj: selected,
        beforePoints,
        afterPoints,
        beforeWidth,
        afterWidth: width,
        beforeIds,
        afterIds,
        beforeMeta,
        afterMeta,
      });
      redoStack = [];
      setStatus("Road width changed (undo available)");
    }
  }
  updateRoadControls();
});

toolAlign?.addEventListener("click", () => {
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadPickBuildingMode = false;
  hideRoadHoverPreview();
  autoAlignEnabled = !autoAlignEnabled;
  updateAutoAlignButton();

  if (autoAlignEnabled) {
    if (currentTool !== "move") {
      currentTool = "move";
      transform.setMode("translate");
      setActiveToolButton(toolMove);
      syncTransformToSelection();
    }
    updateToolIndicator();
    setStatus("Auto Align enabled (Move)");
  } else {
    clearAlignGuides();
    updateToolIndicator();
    setStatus("Auto Align disabled");
  }
});

toolDelete?.addEventListener("click", () => {
  if (!selected) return setStatus("Select an object first");
  if (!selected.userData?.isPlaced) return setStatus("Delete only removes placed overlay objects");
  if (selected.userData.locked) return setStatus("Locked — press Uncommit to delete");

  const obj = selected;
  const parent = obj.parent;

  clearSelected(obj);
  transform.detach();
  transformHelper.visible = false;
  selected = null;
  hideAngleReadout();
  updateCommitButtons();
  clearRoadHandles();
  roadEditRoot.visible = false;
  updateRoadControls();

  undoStack.push({ type: "remove", obj, parent });
  redoStack = [];

  parent.remove(obj);
  setStatus("Deleted (undo available)");
});

// -------------------- Asset palette --------------------
let pendingAssetPath = null;
let lastPointerEvent = null;

// Placement preview
let previewRoot = null;
let previewBox = null;
let previewBoxHelper = null;
let previewYOffset = 0;
let previewAssetPath = null;
let previewLoadToken = 0;
let previewLoadingPath = null;

function clearPreview() {
  if (previewBoxHelper) {
    scene.remove(previewBoxHelper);
    previewBoxHelper = null;
    previewBox = null;
  }
  if (previewRoot) {
    previewRoot.traverse((n) => {
      if (n.isMesh && n.material && n.material.dispose) {
        n.material.dispose();
      }
    });
    scene.remove(previewRoot);
    previewRoot = null;
  }
  previewAssetPath = null;
  previewYOffset = 0;
  previewLoadingPath = null;
}

function buildPreviewFor(assetPath) {
  if (!assetPath) return;
  if (previewAssetPath === assetPath && previewRoot) return;
  if (previewLoadingPath === assetPath) return;

  const token = ++previewLoadToken;
  clearPreview();
  previewLoadingPath = assetPath;

  loader.load(
    assetPath,
    (gltf) => {
      if (token !== previewLoadToken) return;
      previewLoadingPath = null;
      if (pendingAssetPath !== assetPath) return;

      const root = gltf.scene;

      root.traverse((n) => {
        if (!n.isMesh) return;
        const mat = new THREE.MeshBasicMaterial({
          color: 0x2563eb,
          wireframe: true,
          transparent: true,
          opacity: 0.35,
          depthTest: false
        });
        mat.depthWrite = false;
        n.material = mat;
        n.renderOrder = 10;
      });

      root.updateMatrixWorld(true);

      const box = new THREE.Box3().setFromObject(root);
      previewYOffset = -box.min.y;
      root.position.set(0, previewYOffset, 0);

      scene.add(root);
      previewRoot = root;
      previewAssetPath = assetPath;

      previewBox = new THREE.Box3();
      previewBoxHelper = new THREE.Box3Helper(previewBox, 0x2563eb);
      previewBoxHelper.material.transparent = true;
      previewBoxHelper.material.opacity = 0.4;
      previewBoxHelper.material.depthTest = false;
      previewBoxHelper.renderOrder = 11;
      scene.add(previewBoxHelper);

      if (lastPointerEvent) updatePreviewPosition(lastPointerEvent);
    },
    undefined,
    (err) => {
      if (token !== previewLoadToken) return;
      previewLoadingPath = null;
      console.error(err);
    }
  );
}

function updatePreviewPosition(event) {
  if (!previewRoot || !event) return;
  const pt = getGroundPointFromEvent(event);
  if (!pt) return;

  previewRoot.position.copy(pt);
  previewRoot.position.y += previewYOffset;
  previewRoot.updateMatrixWorld(true);

  if (previewBox && previewBoxHelper) {
    previewBox.setFromObject(previewRoot);
    previewBoxHelper.update();
  }
}

async function loadAssetList() {
  const res = await fetch("mapEditor.php?action=list_assets");
  const data = await res.json();
  if (!data.ok) throw new Error("Failed to list assets");

  assetListEl.innerHTML = "";
  data.assets.forEach(a => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = a.label;
    btn.style.padding = "10px";
    btn.style.borderRadius = "12px";
    btn.style.border = "1px solid #e5e7eb";
    btn.style.background = "#fff";
    btn.style.fontWeight = "900";
    btn.style.textAlign = "left";
    btn.style.cursor = "pointer";

    btn.addEventListener("click", () => {
      pendingAssetPath = a.path;
      buildPreviewFor(pendingAssetPath);
      setStatus(`Selected asset: ${a.label} — click on ground to place`);
    });

    assetListEl.appendChild(btn);
  });
}

async function spawnAssetAt(assetPath, point, opts = {}) {
  const {
    recordHistory = true,
    autoSelect = true,
    snapToGround = true,
    forcedId = null,
    forcedLocked = null,
    forcedName = null,
  } = opts;

  return new Promise((resolve, reject) => {
    loader.load(
      assetPath,
      (gltf) => {
        const root = gltf.scene;

        root.traverse((n) => {
          if (n.isMesh) {
            n.castShadow = false;
            n.receiveShadow = false;
          }
        });

        root.position.copy(point);

        if (snapToGround) {
          const box = new THREE.Box3().setFromObject(root);
          const minY = box.min.y;
          root.position.y -= minY;
        }

        root.updateMatrixWorld(true);

        markPlaced(root, {
          id: forcedId ?? ("obj_" + Date.now()),
          asset: assetPath,
          name: forcedName ?? assetPath.split("/").pop().replace(/\.(glb|gltf)$/i, ""),
          locked: forcedLocked ?? false
        });

        overlayRoot.add(root);

        if (recordHistory) {
          undoStack.push({ type: "add", obj: root, parent: overlayRoot });
          redoStack = [];
        }

        if (autoSelect) selectObject(root);

        setStatus(recordHistory ? "Placed (undo available)" : "Loaded item");
        resolve(root);
      },
      undefined,
      (err) => reject(err)
    );
  });
}

// -------------------- Hover update --------------------
function updateHover(event) {
  lastPointerEvent = event;

  if (!pendingAssetPath && previewRoot) clearPreview();
  if (pendingAssetPath) {
    buildPreviewFor(pendingAssetPath);
    updatePreviewPosition(event);
  }

  if (isDraggingRoadSegment) return;

  if (isTransformDragging || isDraggingObject || isDraggingRoadHandle) {
    hideRoadHoverPreview();
    return;
  }

  const p = (currentTool === "road") ? pickRoadObject(event) : pickObject(event);
  const nextHover = (p && selected && p === selected) ? null : p;

  if (hovered && hovered !== nextHover) {
    clearHover(hovered);
    hovered = null;
  }

  if (nextHover && hovered !== nextHover) {
    hovered = nextHover;
    applyHover(hovered);
  }

  if (!nextHover && hovered) {
    clearHover(hovered);
    hovered = null;
  }

  updateRoadPreview(event);
}

renderer.domElement.addEventListener("pointermove", updateHover);
renderer.domElement.addEventListener("pointerleave", () => {
  if (hovered) {
    clearHover(hovered);
    hovered = null;
  }
  hideRoadHoverPreview();
});

// -------------------- Direct drag move (ONLY when Move tool is active) --------------------
let isDraggingObject = false;
let dragOffset = new THREE.Vector3();
let dragBeforeTRS = null;
let dragLastSafePos = new THREE.Vector3();

renderer.domElement.addEventListener("pointerdown", async (e) => {
  if (e.button !== 0) return;
  if (isGizmoPointerDown) return;

  if (pendingAssetPath) {
    const pt = getGroundPointFromEvent(e);
    if (!pt) return;

    try {
      await spawnAssetAt(pendingAssetPath, pt, { recordHistory: true, autoSelect: true, snapToGround: true });
      pendingAssetPath = null;
      clearPreview();
    } catch (err) {
      showError("ASSET LOAD ERROR", err);
    }
    return;
  }

  if (currentTool === "road") {
    // Let OrbitControls handle multi-touch gestures (pan/zoom)
    if (activeTouchPointerIds.size >= 2) return;

    const h = pickRoadHandle(e);
    if (h && selected && isRoadObject(selected)) {
      if (roadDragMode === "move") {
        if (beginRoadHandleDrag(selected, h.index, e.pointerId)) {
          e.preventDefault();
          return;
        }
      } else {
        if (beginRoadSegmentDrag(selected, h.index, e.pointerId, e.clientX, e.clientY)) {
          e.preventDefault();
          return;
        }
      }
    }

    // Defer selection / placement to pointerup (helps avoid accidental taps during multi-touch gestures)
    roadTap = { pointerId: e.pointerId, startX: e.clientX, startY: e.clientY, hadMultiTouch: false };
    return;
  }

  const picked = pickObject(e);

  if (picked) {
    selectObject(picked);

    if (currentTool === "move" && selected && canEditObject(selected) && !isTransformDragging && !isGizmoPointerDown) {
      const pt = getGroundPointFromEvent(e);
      if (!pt) return;

      controls.enabled = false;
      isDraggingObject = true;

      dragBeforeTRS = cloneTRS(selected);
      dragOffset.copy(selected.position).sub(pt);
      dragLastSafePos.copy(selected.position);

      setStatus("Dragging… release to drop");
      e.preventDefault();
      return;
    }
  } else {
    selectObject(null);
  }
});

renderer.domElement.addEventListener("pointermove", (e) => {
  if (isDraggingRoadSegment && roadSegmentDrag && roadSegmentDrag.pointerId === e.pointerId) {
    updateRoadSegmentDrag(e);
    e.preventDefault();
    return;
  }

  if (isDraggingRoadHandle && roadHandleDrag && roadHandleDrag.pointerId === e.pointerId) {
    const pt = getRoadSurfacePointFromEvent(e);
    if (!pt) return;
    const roadObj = roadHandleDrag.road;
    const pointIndex = roadHandleDrag.index;
    const width = Number(roadObj?.userData?.road?.width || roadWidthEl?.value || ROAD_DEFAULT_WIDTH);

    let anchorW = null;
    let refY = 0;
    try {
      roadObj?.updateMatrixWorld?.(true);
      const beforeArr = roadHandleDrag.beforePoints?.[pointIndex];
      if (Array.isArray(beforeArr) && beforeArr.length >= 3) {
        const beforeLocal = vec3FromArray(beforeArr);
        anchorW = roadObj.localToWorld(beforeLocal.clone());
        refY = anchorW.y;
      }
    } catch (_) {}

    let x = pt.x;
    let z = pt.z;
    let snap = null;
    if (roadBuildingSnapEnabled) {
      const liveSources = buildLiveRoadSnapSources();
      snap = snapRoadEndToBuildingSide(anchorW, x, z, width, liveSources);
      if (snap) {
        x = snap.x;
        z = snap.z;
      }
    }
    const y = sampleRoadBaseYAtXZ(x, z, Number.isFinite(refY) ? refY : 0);
    const wpt = new THREE.Vector3(x, y, z);

    setRoadPointIndexFromWorld(roadObj, pointIndex, wpt);

    // Auto-tag/clear a building link when a point snaps (so routing can later use it).
    if (roadBuildingSnapEnabled) {
      try {
        const roadData = roadObj?.userData?.road;
        if (roadData && Array.isArray(roadData.pointIds) && Array.isArray(roadData.pointMeta)) {
          if (snap?.buildingName && snap?.side) {
            const pid = roadData.pointIds[pointIndex];
            roadData.pointMeta[pointIndex] = makeBuildingLinkMeta(snap.buildingName, snap.side, pid);
          } else {
            const meta = roadData.pointMeta[pointIndex];
            if (meta && typeof meta === "object" && meta.building) roadData.pointMeta[pointIndex] = null;
          }
        }
      } catch (_) {}
    }
    e.preventDefault();
    return;
  }

  if (!isDraggingObject || !selected) return;
  const pt = getGroundPointFromEvent(e);
  if (!pt) return;

  selected.position.copy(pt.add(dragOffset));
  selected.updateMatrixWorld();

  if (autoAlignEnabled) applyAutoAlign(selected);
  else clearAlignGuides();

  if (isColliding(selected)) {
    selected.position.copy(dragLastSafePos);
    selected.updateMatrixWorld();
    clearAlignGuides();
  } else {
    dragLastSafePos.copy(selected.position);
  }
});

window.addEventListener("pointerup", (e) => {
  if (isDraggingRoadSegment) {
    endRoadSegmentDrag(e.pointerId, e);
  }

  if (isDraggingRoadHandle) {
    endRoadHandleDrag(e.pointerId);
  }

  if (currentTool === "road" && roadTap && roadTap.pointerId === e.pointerId) {
    const tap = roadTap;
    roadTap = null;

    const TAP_SLOP_PX = 8;
    const dx = Math.abs(e.clientX - tap.startX);
    const dy = Math.abs(e.clientY - tap.startY);
    if (dx > TAP_SLOP_PX || dy > TAP_SLOP_PX) return;

    if (!tap.hadMultiTouch) {
      if (!isEventOnStage(e)) return;
      const picked = roadPickBuildingMode ? pickObject(e) : pickRoadObject(e);

      // Pick-building mode (for connecting road points to a building)
      if (roadPickBuildingMode) {
        if (picked && isConnectableBuilding(picked)) {
          setRoadConnectTarget(picked);
        } else {
          setStatus("Pick Building: tap a building object");
        }
        hideRoadHoverPreview();
        e.preventDefault();
        return;
      }

      if (picked && isRoadObject(picked)) {
        selectObject(picked);
      } else {
        const pt = getRoadSurfacePointFromEvent(e);
        if (pt) spawnRoadNodeAt(pt);
      }
      hideRoadHoverPreview();
      e.preventDefault();
    }
  }

  if (!isDraggingObject || !selected) return;

  isDraggingObject = false;
  controls.enabled = true;

  const after = cloneTRS(selected);
  const before = dragBeforeTRS;
  dragBeforeTRS = null;

  if (before) {
    undoStack.push({ type: "transform", obj: selected, before, after });
    redoStack = [];
  }

  clearAlignGuides();
  setStatus("Dropped ✓ (undo available)");
});

window.addEventListener("pointercancel", (e) => {
  if (isDraggingRoadSegment) endRoadSegmentDrag(e.pointerId, e);
  if (isDraggingRoadHandle) endRoadHandleDrag(e.pointerId);
  if (roadTap && roadTap.pointerId === e.pointerId) roadTap = null;
});

// -------------------- Save / Load overlay --------------------
function serializeOverlay() {
  const items = [];
  overlayRoot.children.forEach((obj) => {
    if (!obj.userData.isPlaced) return;

    const type = obj.userData?.type || "asset";

    if (type === "road") {
      ensureRoadPointMetaArrays(obj);
      const road = obj.userData?.road || {};
      items.push({
        type: "road",
        id: obj.userData.id,
        name: obj.userData.nameLabel,
        position: [obj.position.x, obj.position.y, obj.position.z],
        rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
        scale:    [obj.scale.x, obj.scale.y, obj.scale.z],
        locked: !!obj.userData.locked,
        width: Number(road.width || ROAD_DEFAULT_WIDTH),
        points: Array.isArray(road.points) ? road.points : [],
        pointIds: Array.isArray(road.pointIds) ? road.pointIds : [],
        pointMeta: Array.isArray(road.pointMeta) ? road.pointMeta : []
      });
      return;
    }

    items.push({
      type: "asset",
      id: obj.userData.id,
      asset: obj.userData.asset,
      name: obj.userData.nameLabel,
      position: [obj.position.x, obj.position.y, obj.position.z],
      rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
      scale:    [obj.scale.x, obj.scale.y, obj.scale.z],
      locked: !!obj.userData.locked
    });
  });

  return { version: 2, items };
}

async function saveOverlay() {
  const payload = serializeOverlay();
  const res = await fetch("mapEditor.php?action=save_overlay", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || "Save failed");
  setStatus("Saved overlay ✓");
}

toolSave?.addEventListener("click", () => {
  saveOverlay().catch(err => showError("SAVE ERROR", err));
});

function buildExportDefaultName() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  const ts = `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
  return `tnts_map_export_${ts}.glb`;
}

function exportSceneAsGlb() {
  if (!campusRoot) throw new Error("Base model not loaded yet");

  const defName = buildExportDefaultName();
  const nameInput = prompt("Save GLB as (new file):", defName);
  if (!nameInput) return Promise.resolve(false);
  const fileName = nameInput.trim();
  if (!fileName) return Promise.resolve(false);

  const exporter = new GLTFExporter();

  campusRoot.updateMatrixWorld(true);
  overlayRoot.updateMatrixWorld(true);

  setStatus("Exporting GLB…");

  return new Promise((resolve, reject) => {
    const exportNodes = [
      ...campusRoot.children,
      ...overlayRoot.children
    ];
    exporter.parse(
      exportNodes,
      async (glb) => {
        try {
          const res = await fetch(`mapEditor.php?action=export_glb&name=${encodeURIComponent(fileName)}`, {
            method: "POST",
            headers: { "Content-Type": "model/gltf-binary" },
            body: glb
          });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || "Export failed");
          setStatus(`Exported: ${data.file}`);
          if (baseModelSelect) {
            await loadModelList();
            baseModelSelect.value = currentModelName;
          }
          resolve(true);
        } catch (err) {
          reject(err);
        }
      },
      (err) => reject(err),
      { binary: true }
    );
  });
}

toolExport?.addEventListener("click", () => {
  exportSceneAsGlb().catch(err => showError("EXPORT ERROR", err));
});

async function loadOverlay() {
  const res = await fetch("mapEditor.php?action=load_overlay");
  let data = null;
  try {
    data = await res.json();
  } catch (err) {
    showError("OVERLAY LOAD ERROR", err);
    setStatus("Overlay load failed");
    return;
  }

  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;

  while (overlayRoot.children.length) overlayRoot.remove(overlayRoot.children[0]);

  const items = Array.isArray(data?.items) ? data.items : [];
  for (const it of items) {
    if (it && (it.type === "road" || Array.isArray(it.points))) {
      const group = new THREE.Group();
      group.name = "road";

      const width = Number(it.width || ROAD_DEFAULT_WIDTH);
      const pointsArr = Array.isArray(it.points) ? it.points : [];
      group.userData.road = {
        width,
        points: pointsArr,
        pointIds: Array.isArray(it.pointIds) ? it.pointIds.slice() : undefined,
        pointMeta: Array.isArray(it.pointMeta) ? cloneRoadPointMeta(it.pointMeta) : undefined,
      };
      ensureRoadPointMetaArrays(group);

      const ptsLocal = pointsArr.map(vec3FromArray);
      ensureRoadMeshFor(group, ptsLocal, width, { preview: false });

      markPlaced(group, {
        id: it.id || ("road_" + Date.now()),
        asset: null,
        name: it.name || "road",
        locked: !!it.locked,
        type: "road"
      });

      if (Array.isArray(it.position)) group.position.set(it.position[0], it.position[1], it.position[2]);
      if (Array.isArray(it.rotation)) group.rotation.set(it.rotation[0], it.rotation[1], it.rotation[2]);
      if (Array.isArray(it.scale)) group.scale.set(it.scale[0], it.scale[1], it.scale[2]);

      group.userData.locked = !!it.locked;
      overlayRoot.add(group);
      group.updateMatrixWorld(true);
      continue;
    }

    const obj = await spawnAssetAt(
      it.asset,
      new THREE.Vector3(it.position[0], it.position[1], it.position[2]),
      {
        recordHistory: false,
        autoSelect: false,
        snapToGround: false,
        forcedId: it.id,
        forcedLocked: !!it.locked,
        forcedName: it.name
      }
    );

    obj.rotation.set(it.rotation[0], it.rotation[1], it.rotation[2]);
    obj.scale.set(it.scale[0], it.scale[1], it.scale[2]);
    obj.userData.locked = !!it.locked;
    obj.updateMatrixWorld(true);
  }

  if (hovered) { clearHover(hovered); hovered = null; }
  if (selected) { clearSelected(selected); selected = null; }
  updateBuildingSelected(null);
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
  updateCommitButtons();
  undoStack = [];
  redoStack = [];

  setStatus("Overlay loaded ✓");
}

function updateBaseModelNote(name) {
  if (!baseModelNote) return;
  if (name === ORIGINAL_MODEL_NAME) {
    baseModelNote.textContent = "Original map. Overlay JSON will load.";
  } else if (name) {
    baseModelNote.textContent = "Exported map. Overlay JSON will NOT load to avoid duplicates.";
  } else {
    baseModelNote.textContent = "No base models found.";
  }
}

function clearOverlayObjects() {
  while (overlayRoot.children.length) overlayRoot.remove(overlayRoot.children[0]);
}

function resetEditorState() {
  clearPreview();
  clearRoadDraft();
  clearRoadHandles();
  roadEditRoot.visible = false;
  roadSnapBuildingCache = [];
  roadSnapObstacleCache = [];
  roadGroundTargets = [];
  clearAlignGuides();
  if (hovered) { clearHover(hovered); hovered = null; }
  if (selected) { clearSelected(selected); selected = null; }
  updateBuildingSelected(null);
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
  updateCommitButtons();
  updateRoadControls();
  undoStack = [];
  redoStack = [];
}

function disposeObject3D(root) {
  if (!root) return;
  root.traverse((n) => {
    if (n.isMesh) {
      if (n.geometry && n.geometry.dispose) n.geometry.dispose();
      if (n.material) {
        const mats = Array.isArray(n.material) ? n.material : [n.material];
        mats.forEach((m) => m && m.dispose && m.dispose());
      }
    }
  });
}

async function loadModelList() {
  if (!baseModelSelect) return [];

  const res = await fetch("mapEditor.php?action=list_models");
  const data = await res.json();
  if (!data.ok) throw new Error("Failed to list models");

  const models = Array.isArray(data.models) ? data.models : [];
  baseModelSelect.innerHTML = "";

  if (!models.length) {
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = "No .glb files found";
    baseModelSelect.appendChild(opt);
    baseModelSelect.disabled = true;
    updateBaseModelNote("");
    return models;
  }

  for (const m of models) {
    const opt = document.createElement("option");
    opt.value = m.file;
    if (m.isDefault && m.isOriginal) opt.textContent = `${m.file} (original, default)`;
    else if (m.isDefault) opt.textContent = `${m.file} (default)`;
    else if (m.isOriginal) opt.textContent = `${m.file} (original)`;
    else opt.textContent = m.file;
    baseModelSelect.appendChild(opt);
  }

  baseModelSelect.disabled = false;
  if (!models.some(m => m.file === currentModelName)) {
    currentModelName = models[0].file;
  }
  baseModelSelect.value = currentModelName;
  updateBaseModelNote(currentModelName);

  return models;
}

async function getDefaultModelName() {
  const res = await fetch("mapEditor.php?action=get_default_model");
  const data = await res.json();
  if (data && data.ok && data.file) return data.file;
  return ORIGINAL_MODEL_NAME;
}

async function setDefaultModelName(name) {
  const res = await fetch("mapEditor.php?action=set_default_model", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ file: name })
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || "Failed to set default");
  return data.file;
}

async function switchBaseModel(nextName) {
  if (isModelSwitching) return;
  if (!nextName || nextName === currentModelName) return;

  const ok = confirm("Switching the base model may discard unsaved overlay edits. Continue?");
  if (!ok) {
    if (baseModelSelect) baseModelSelect.value = currentModelName;
    return;
  }

  isModelSwitching = true;
  if (baseModelSelect) baseModelSelect.disabled = true;

  try {
    const shouldLoadOverlay = nextName === ORIGINAL_MODEL_NAME;
    await loadBaseModel(nextName, { loadOverlay: shouldLoadOverlay });
  } finally {
    if (baseModelSelect) baseModelSelect.disabled = false;
    isModelSwitching = false;
  }
}

async function loadBaseModel(modelName, opts = {}) {
  const shouldLoadOverlay = opts.loadOverlay !== false;
  const modelUrl = `${MODEL_DIR}${modelName}`;

  setStatus("Loading base model…");
  resetEditorState();

  if (!shouldLoadOverlay) {
    clearOverlayObjects();
  }

  if (campusRoot) {
    scene.remove(campusRoot);
    disposeObject3D(campusRoot);
    campusRoot = null;
  }

  return new Promise((resolve, reject) => {
    loader.load(
      modelUrl,
      async (gltf) => {
        campusRoot = gltf.scene;
        scene.add(campusRoot);
        rebuildBaseColliders();
        refreshBuildingList();
        rebuildRoadSnapBuildingCache();
        rebuildRoadGroundTargets();

        const box = new THREE.Box3().setFromObject(campusRoot);
        const size = box.getSize(new THREE.Vector3());
        const center = box.getCenter(new THREE.Vector3());

        controls.target.copy(center);
        const maxDim = Math.max(size.x, size.y, size.z);
        const dist = maxDim * 1.2;

        camera.position.set(center.x, center.y + dist, center.z + dist);
        camera.near = Math.max(0.1, dist / 500);
        camera.far  = dist * 10;
        camera.updateProjectionMatrix();
        controls.update();

        defaultCameraState = {
          position: camera.position.clone(),
          target: controls.target.clone(),
        };

        resize();

        isTopView = true;
        if (btnTop) btnTop.textContent = "3D View";
        setTopView();

        if (!assetsLoaded) {
          await loadAssetList();
          assetsLoaded = true;
        }

        if (shouldLoadOverlay) {
          await loadOverlay();
        } else {
          resetEditorState();
        }

        currentTool = "none";
        setActiveToolButton(null);
        updateToolIndicator();

        currentModelName = modelName;
        updateBaseModelNote(currentModelName);
        if (baseModelSelect) baseModelSelect.value = currentModelName;

        setStatus(shouldLoadOverlay
          ? "Ready ✓ (hover + click select; press Move/Rotate/Scale to edit)"
          : "Ready ✓ (overlay not loaded)"
        );

        resolve();
      },
      undefined,
      (err) => {
        showError(`GLB LOAD ERROR — (check ${modelName})`, err);
        reject(err);
      }
    );
  });
}

baseModelSelect?.addEventListener("change", () => {
  const nextName = baseModelSelect.value;
  switchBaseModel(nextName);
});

baseModelDefaultBtn?.addEventListener("click", async () => {
  if (!baseModelSelect) return;
  const name = baseModelSelect.value;
  if (!name) return;
  try {
    const saved = await setDefaultModelName(name);
    await loadModelList();
    if (baseModelSelect) baseModelSelect.value = currentModelName;
    setStatus(`Default model set: ${saved}`);
  } catch (err) {
    showError("DEFAULT MODEL ERROR", err);
  }
});

// -------------------- View controls --------------------
function setTopView() {
  if (!campusRoot) return;

  const box = new THREE.Box3().setFromObject(campusRoot);
  const size = box.getSize(new THREE.Vector3());
  const center = box.getCenter(new THREE.Vector3());

  const height = Math.max(size.x, size.z) * 1.2;

  camera.position.set(center.x, height, center.z);
  camera.lookAt(center.x, 0, center.z);

  controls.target.copy(center);
  controls.enableRotate = false;
  controls.enablePan = true;
  controls.enableZoom = true;
  controls.update();
  setStatus("Top View (default)");
}

function set3DView() {
  if (!defaultCameraState) return;
  camera.position.copy(defaultCameraState.position);
  controls.target.copy(defaultCameraState.target);
  controls.enableRotate = true;
  controls.enablePan = true;
  controls.enableZoom = true;
  camera.updateProjectionMatrix();
  controls.update();
  setStatus("3D View");
}

btnTop?.addEventListener("click", () => {
  if (!campusRoot) return;
  if (!isTopView) {
    setTopView();
    btnTop.textContent = "3D View";
  } else {
    set3DView();
    btnTop.textContent = "Top View";
  }
  isTopView = !isTopView;
});

btnReset?.addEventListener("click", () => {
  if (!campusRoot || !defaultCameraState) return;
  isTopView = false;
  btnTop.textContent = "Top View";
  set3DView();
});

// -------------------- Load base model --------------------
(async () => {
  try {
    currentModelName = await getDefaultModelName();
  } catch (err) {
    currentModelName = ORIGINAL_MODEL_NAME;
  }

  try {
    await loadModelList();
  } catch (err) {
    console.warn("Model list failed:", err);
    updateBaseModelNote(currentModelName);
  }

  const shouldLoadOverlay = currentModelName === ORIGINAL_MODEL_NAME;
  loadBaseModel(currentModelName, { loadOverlay: shouldLoadOverlay })
    .catch(() => {});
})();

// -------------------- Animation loop --------------------
function animate() {
  requestAnimationFrame(animate);
  controls.update();
  updateGizmoSize();

  if (selected && currentTool === "rotate" && transform.object === rotateProxy && !isTransformDragging) {
    updateRotateProxyAtSelection();
  }

  if (currentTool === "road" && selected && isRoadObject(selected) && roadEditRoot.visible) {
    syncRoadHandlesToRoad(selected);
    updateRoadHandleScale();
  }

  renderer.render(scene, camera);
}
resize();
animate();
</script>

<?php admin_layout_end(); ?>
