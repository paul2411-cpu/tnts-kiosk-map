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
  <div class="map-head" style="display:flex; gap:10px; align-items:center; justify-content:space-between;">
    <div class="map-title">3D Map Editor View</div>

    <div style="display:flex; gap:8px; align-items:center;">
      <button id="btn-undo" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Undo</button>
      <button id="btn-redo" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Redo</button>
      <button id="btn-commit" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Commit (Lock)</button>
      <button id="btn-edit" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Edit</button>

      <button id="btn-top" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Top View</button>
      <button id="btn-reset" class="btn" type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Reset View</button>

      <span id="status" style="font-weight:800;color:#6b7280;">Booting&hellip;</span>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: 1fr 300px; gap:12px;">
    <div style="position:relative;">
      <div id="map-stage" style="width:100%; height:560px; background:#f3f4f6; border-radius:14px; overflow:hidden; position:relative;"></div>

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
        Hover highlights (temporary). Click selects (stays). Move requires Move tool active. Wheel zoom. Right-drag pan.
      </div>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px; height:560px; overflow:auto;">
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
      <input id="building-filter" type="text" placeholder="Filter buildings" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; font-weight:700; margin-bottom:8px;">
      <div id="building-list" style="display:flex; flex-direction:column; gap:6px; max-height:180px; overflow:auto;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:10px;">Assets</div>
      <div id="asset-clipboard" style="font-size:12px; color:#6b7280; font-weight:700; margin-bottom:6px;">Clipboard: None</div>
      <div id="asset-list" style="display:flex; flex-direction:column; gap:8px;"></div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">

      <div style="font-weight:900; margin-bottom:6px;">Tools</div>
      <div id="tool-indicator" style="font-weight:800; color:#6b7280; margin-bottom:10px;">Active tool: None</div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button id="tool-move" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Move</button>
        <button id="tool-rotate" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Rotate</button>
        <button id="tool-scale" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Scale</button>
        <button id="tool-align" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Auto Align</button>
        <button id="tool-delete" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Delete</button>
        <button id="tool-copy" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Copy</button>
        <button id="tool-save" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Save</button>
        <button id="tool-export" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;font-weight:800;">Export GLB</button>
        <button id="tool-cancel" class="btn" type="button" style="padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;">Cancel Tool</button>
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

// REQUIRE pressing Move/Rotate/Scale button before tool works
let currentTool = "none"; // "none" | "move" | "rotate" | "scale"

// Auto Align — works only with Move
let autoAlignEnabled = false;
const ALIGN_SNAP_DIST = 10;
const ALIGN_GUIDE_Y_OFFSET = 0.05;

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
const assetClipboardEl = document.getElementById("asset-clipboard");
const baseModelSelect = document.getElementById("base-model-select");
const baseModelNote = document.getElementById("base-model-note");
const baseModelDefaultBtn = document.getElementById("base-model-default");
const buildingListEl = document.getElementById("building-list");
const buildingFilterEl = document.getElementById("building-filter");

const toolMove = document.getElementById("tool-move");
const toolRotate = document.getElementById("tool-rotate");
const toolScale = document.getElementById("tool-scale");
const toolAlign = document.getElementById("tool-align");
const toolDelete = document.getElementById("tool-delete");
const toolCopy = document.getElementById("tool-copy");
const toolSave = document.getElementById("tool-save");
const toolExport = document.getElementById("tool-export");
const toolCancel = document.getElementById("tool-cancel");
const toolIndicator = document.getElementById("tool-indicator");

function setStatus(msg) {
  if (statusEl) statusEl.textContent = msg;
  console.log("[MapEditor]", msg);
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
  [toolMove, toolRotate, toolScale].forEach(b => {
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
  const labels = { none: "None", move: "Move", rotate: "Rotate", scale: "Scale" };
  const label = labels[currentTool] || "None";
  const alignTag = (currentTool === "move" && autoAlignEnabled) ? " (Auto Align On)" : "";
  toolIndicator.textContent = `Active tool: ${label}${alignTag}`;
  if (toolCancel) {
    toolCancel.disabled = currentTool === "none";
    toolCancel.style.opacity = toolCancel.disabled ? "0.6" : "1";
  }
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
  syncTransformToSelection();
  updateToolIndicator();
  clearAlignGuides();
  hideAngleReadout();
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

  const movingBox = new THREE.Box3().setFromObject(obj);
  if (movingBox.isEmpty()) return;

  let bestX = null;
  let bestZ = null;

  for (const other of overlayRoot.children) {
    if (!other || other === obj || !other.visible) continue;

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
  if (selected === obj) selected = null;
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
  else if (cmd.type === "add") removeObject(cmd.obj);
  else if (cmd.type === "remove") insertObject(cmd.parent, cmd.obj);

  redoStack.push(cmd);
  setStatus("Undo ✓");
}
function doRedo() {
  const cmd = redoStack.pop();
  if (!cmd) return;

  if (cmd.type === "transform") applyTRS(cmd.obj, cmd.after);
  else if (cmd.type === "add") insertObject(cmd.parent, cmd.obj);
  else if (cmd.type === "remove") removeObject(cmd.obj);

  undoStack.push(cmd);
  setStatus("Redo ✓");
}

btnUndo?.addEventListener("click", doUndo);
btnRedo?.addEventListener("click", doRedo);

// -------------------- Placed metadata --------------------
function markPlaced(obj, meta) {
  obj.userData.isPlaced = true;
  obj.userData.asset = meta.asset;
  obj.userData.id = meta.id;
  obj.userData.locked = !!meta.locked;
  obj.userData.nameLabel = meta.name || "item";
}

// -------------------- Hover & Selection --------------------
let hovered = null;
let selected = null;

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

  _boxA.setFromObject(obj);
  if (_boxA.isEmpty()) return false;

  for (const other of overlayRoot.children) {
    if (!other || other === obj) continue;
    if (!other.visible) continue;
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

// -------------------- Picking (overlay + base) --------------------
let meshCountCache = new WeakMap();
let campusMeshCount = 0;

function countMeshes(obj) {
  if (!obj) return 0;
  const cached = meshCountCache.get(obj);
  if (typeof cached === "number") return cached;
  let count = 0;
  obj.traverse((n) => { if (n.isMesh) count++; });
  meshCountCache.set(obj, count);
  return count;
}

function refreshMeshCounts() {
  meshCountCache = new WeakMap();
  campusMeshCount = campusRoot ? countMeshes(campusRoot) : 0;
}

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

function isWrapperGroup(obj) {
  if (!obj || !campusRoot) return false;
  if (obj.parent !== campusRoot) return false;
  if (obj.userData?.isPlaced) return false;
  const name = obj.name || "";
  const generic = !name || isGenericNodeName(name);
  if (!generic) return false;
  const total = campusMeshCount || countMeshes(campusRoot);
  if (!total) return false;
  const count = countMeshes(obj);
  return (count / total) >= 0.9;
}

function isSelectableEmptyGroup(obj) {
  if (!isEmptyGroup(obj)) return false;
  if (obj === campusRoot) return false;
  if (obj.userData?.isPlaced) return false;
  const count = countMeshes(obj);
  if (count < 2) return false;
  return true;
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
    if (isWrapperGroup(obj)) return;

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
      selectObject(obj);
      setStatus(`Selected: ${name}`);
    });

    buildingListEl.appendChild(btn);
  }
}

buildingFilterEl?.addEventListener("input", () => {
  refreshBuildingList();
});

function pickObject(event) {
  if (!campusRoot) return null;

  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);

  const hits = raycaster.intersectObjects([overlayRoot, campusRoot], true);
  if (!hits.length) return null;

  const hit = hits[0].object;

  // Overlay: return the placed root if available
  let obj = hit;
  while (obj) {
    if (obj.userData?.isPlaced) return obj;
    if (obj.parent === overlayRoot) return obj;
    obj = obj.parent;
  }

  // Base model: prefer Blender empty/group parents or named groups
  let firstNamedEmpty = null;
  let firstEmptyGroup = null;
  let firstNamed = null;
  let firstMultiMesh = null;
  let firstDirectChild = null;

  obj = hit;
  while (obj && obj !== campusRoot) {
    const hasName = !!(obj.name && !isGenericNodeName(obj.name));
    if (hasName && isEmptyGroup(obj) && !firstNamedEmpty) firstNamedEmpty = obj;
    if (hasName && !firstNamed) firstNamed = obj;
    if (!firstEmptyGroup && isSelectableEmptyGroup(obj)) firstEmptyGroup = obj;
    if (!firstMultiMesh && countMeshes(obj) > 1) firstMultiMesh = obj;
    if (!firstDirectChild && obj.parent === campusRoot) firstDirectChild = obj;
    obj = obj.parent;
  }

  const candidate = firstNamedEmpty || firstNamed || firstEmptyGroup || firstMultiMesh || firstDirectChild || hit;
  if (isWrapperGroup(candidate)) return hit;
  return candidate;
}

// -------------------- Selection / edit rules --------------------
function canEditObject(obj) {
  if (!obj) return false;
  if (obj.userData?.isPlaced) return !obj.userData.locked;
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

  selected = obj || null;
  transform.detach();

  if (!selected) {
    transformHelper.visible = false;
    hideAngleReadout();
    setStatus("No selection");
    return;
  }

  if (hovered === selected) {
    clearHover(hovered);
    hovered = null;
  }

  applySelected(selected);

  if (canEditObject(selected)) {
    syncTransformToSelection();
    if (isTransformToolActive()) {
      setStatus(selected.userData?.isPlaced ? "Selected overlay (editable)" : "Selected base object (editable)");
    } else {
      setStatus(selected.userData?.isPlaced ? "Selected overlay (click Move/Rotate/Scale to edit)" : "Selected base object (click Move/Rotate/Scale to edit)");
    }
  } else {
    setStatus(selected.userData?.isPlaced ? "Selected overlay (locked) — press Edit" : "Selected base object (view only)");
  }
}

// Commit/Edit only apply to overlay placed objects
btnCommit?.addEventListener("click", () => {
  if (!selected) return;
  if (!selected.userData?.isPlaced) return setStatus("Commit applies only to placed overlay objects");
  selected.userData.locked = true;
  syncTransformToSelection();
  setStatus("Committed (locked)");
});
btnEdit?.addEventListener("click", () => {
  if (!selected) return;
  if (!selected.userData?.isPlaced) return setStatus("Edit applies only to placed overlay objects");
  selected.userData.locked = false;
  syncTransformToSelection();
  setStatus("Editing (unlocked)");
});

// -------------------- Tools --------------------
toolMove?.addEventListener("click", () => {
  currentTool = "move";
  transform.setMode("translate");
  setActiveToolButton(toolMove);
  syncTransformToSelection();
  updateToolIndicator();
  setStatus(selected ? "Move tool active" : "Move tool active (select an object)");
});

toolRotate?.addEventListener("click", () => {
  currentTool = "rotate";
  autoAlignEnabled = false;
  updateAutoAlignButton();
  clearAlignGuides();

  transform.setMode("rotate");
  transform.setSpace("local");

  setActiveToolButton(toolRotate);
  syncTransformToSelection();
  updateToolIndicator();
  setStatus(selected ? "Rotate tool active" : "Rotate tool active (select an object)");
});

toolScale?.addEventListener("click", () => {
  currentTool = "scale";
  autoAlignEnabled = false;
  updateAutoAlignButton();
  clearAlignGuides();

  transform.setMode("scale");
  setActiveToolButton(toolScale);
  syncTransformToSelection();
  updateToolIndicator();
  setStatus(selected ? "Scale tool active" : "Scale tool active (select an object)");
});

toolCancel?.addEventListener("click", () => {
  clearActiveTool(true);
  clearPendingPlacement();
});

toolAlign?.addEventListener("click", () => {
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
  if (!selected.userData?.isPlaced) return setStatus("Delete only removes overlay objects (placed assets)");
  if (selected.userData.locked) return setStatus("Locked — press Edit to delete");

  const obj = selected;
  const parent = obj.parent;

  clearSelected(obj);
  transform.detach();
  transformHelper.visible = false;
  selected = null;
  hideAngleReadout();

  undoStack.push({ type: "remove", obj, parent });
  redoStack = [];

  parent.remove(obj);
  setStatus("Deleted (undo available)");
});

toolCopy?.addEventListener("click", () => {
  if (!selected) return setStatus("Select an object to copy");
  if (!selected.userData?.isPlaced) return setStatus("Copy works only for placed assets");

  pendingCopy = {
    asset: selected.userData.asset,
    label: selected.userData.nameLabel || selected.userData.asset,
    rotation: [selected.rotation.x, selected.rotation.y, selected.rotation.z],
    scale: [selected.scale.x, selected.scale.y, selected.scale.z],
    locked: !!selected.userData.locked
  };
  pendingAssetPath = pendingCopy.asset;
  updateClipboardStatus();
  buildPreviewFor(pendingAssetPath);
  setStatus(`Copied: ${pendingCopy.label} — click on ground to paste`);
});

// -------------------- Asset palette --------------------
let pendingAssetPath = null;
let lastPointerEvent = null;
let pendingCopy = null;

// Placement preview
let previewRoot = null;
let previewBox = null;
let previewBoxHelper = null;
let previewYOffset = 0;
let previewAssetPath = null;
let previewLoadToken = 0;
let previewLoadingPath = null;

function updateClipboardStatus() {
  if (!assetClipboardEl) return;
  if (pendingCopy && pendingCopy.label) {
    assetClipboardEl.textContent = `Clipboard: ${pendingCopy.label}`;
  } else {
    assetClipboardEl.textContent = "Clipboard: None";
  }
}

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

function clearPendingPlacement() {
  pendingAssetPath = null;
  pendingCopy = null;
  clearPreview();
  updateClipboardStatus();
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

      // Apply copied rotation/scale to preview if in copy mode
      if (pendingCopy && pendingCopy.asset === assetPath) {
        if (pendingCopy.rotation) {
          root.rotation.set(pendingCopy.rotation[0], pendingCopy.rotation[1], pendingCopy.rotation[2]);
        }
        if (pendingCopy.scale) {
          root.scale.set(pendingCopy.scale[0], pendingCopy.scale[1], pendingCopy.scale[2]);
        }
      }

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
      pendingCopy = null;
      updateClipboardStatus();
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
    forcedRotation = null,
    forcedScale = null,
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

        if (forcedScale) {
          root.scale.set(forcedScale[0], forcedScale[1], forcedScale[2]);
        }
        if (forcedRotation) {
          root.rotation.set(forcedRotation[0], forcedRotation[1], forcedRotation[2]);
        }

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

  if (isTransformDragging || isDraggingObject) return;

  const p = pickObject(event);
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
}

renderer.domElement.addEventListener("pointermove", updateHover);
renderer.domElement.addEventListener("pointerleave", () => {
  if (hovered) {
    clearHover(hovered);
    hovered = null;
  }
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
      const opts = { recordHistory: true, autoSelect: true, snapToGround: true };
      if (pendingCopy) {
        opts.forcedRotation = pendingCopy.rotation;
        opts.forcedScale = pendingCopy.scale;
        opts.forcedLocked = pendingCopy.locked;
        opts.forcedName = pendingCopy.label;
      }

      await spawnAssetAt(pendingAssetPath, pt, opts);

      if (!pendingCopy) {
        pendingAssetPath = null;
        clearPreview();
      } else {
        setStatus("Pasted copy (click again to place more)");
      }
    } catch (err) {
      showError("ASSET LOAD ERROR", err);
    }
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

window.addEventListener("pointerup", () => {
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

// -------------------- Save / Load overlay --------------------
function serializeOverlay() {
  const items = [];
  overlayRoot.children.forEach((obj) => {
    if (!obj.userData.isPlaced) return;

    items.push({
      id: obj.userData.id,
      asset: obj.userData.asset,
      name: obj.userData.nameLabel,
      position: [obj.position.x, obj.position.y, obj.position.z],
      rotation: [obj.rotation.x, obj.rotation.y, obj.rotation.z],
      scale:    [obj.scale.x, obj.scale.y, obj.scale.z],
      locked: !!obj.userData.locked
    });
  });

  return { version: 1, items };
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

  while (overlayRoot.children.length) overlayRoot.remove(overlayRoot.children[0]);

  const items = Array.isArray(data?.items) ? data.items : [];
  for (const it of items) {
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
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
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
  clearAlignGuides();
  clearPendingPlacement();
  if (hovered) { clearHover(hovered); hovered = null; }
  if (selected) { clearSelected(selected); selected = null; }
  transform.detach();
  transformHelper.visible = false;
  hideAngleReadout();
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
        refreshMeshCounts();
        refreshBuildingList();

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

  renderer.render(scene, camera);
}
resize();
animate();
</script>

<?php admin_layout_end(); ?>
