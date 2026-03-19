// js/map3d.js
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { buildGuideKey, buildGuideStepsFromPoints } from "./guide-utils.js";

const mapStage = document.getElementById("map-stage");
if (!mapStage) throw new Error("Missing #map-stage");

const infoCard = document.getElementById("info-card");
const cardTitle = document.getElementById("card-title");
const cardInfo = document.getElementById("card-info");
const cardRoomsWrap = document.getElementById("card-rooms-wrap");
const cardRoomsStatus = document.getElementById("card-rooms-status");
const cardRoomsList = document.getElementById("card-rooms-list");
const closeBtn = document.getElementById("close-btn");
const directionsBtn = document.getElementById("directions-btn");
const clearRouteBtn = document.getElementById("clear-route-btn");
const directionsPanel = document.getElementById("directions-panel");
const directionsTitle = document.getElementById("directions-title");
const directionsSummary = document.getElementById("directions-summary");
const directionsStatus = document.getElementById("directions-status");
const directionsSteps = document.getElementById("directions-steps");
const directionsCloseBtn = document.getElementById("directions-close-btn");
const mapView3dBtn = document.getElementById("map-view-3d-btn");
const mapView2dBtn = document.getElementById("map-view-2d-btn");
const mapZoomSlider = document.getElementById("map-zoom-slider");
const mapZoomValue = document.getElementById("map-zoom-value");


// ==========================
// ✅ ON-SCREEN KEYBOARD (KIOSK) — FIXED (no reverse typing, backspace works)
// Paste this whole block into map3d.js (does NOT touch routing logic)
// ==========================

// 1) Set this to your real search input selector (IMPORTANT)
const SEARCH_INPUT_SELECTOR = "#search-input";

// Find the search input with selector + fallbacks
function getSearchInput() {
  let el = document.querySelector(SEARCH_INPUT_SELECTOR);
  if (el) return el;

  // Fallbacks (safe)
  el =
    document.querySelector('input[type="search"]') ||
    document.querySelector('input[placeholder*="Search" i]') ||
    document.querySelector('input[id*="search" i]') ||
    document.querySelector('input[class*="search" i]');

  return el || null;
}

function ensureKeyboardStyles() {
  if (document.getElementById("kiosk-kb-styles")) return;

  const style = document.createElement("style");
  style.id = "kiosk-kb-styles";
  style.textContent = `
    /* Kiosk on-screen keyboard */
    #kiosk-kb-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      display: none;
      align-items: flex-end;
      justify-content: center;
      z-index: 9999;
      touch-action: manipulation;
    }
    #kiosk-kb {
      width: min(980px, 98vw);
      background: #f3f3f3;
      border-top-left-radius: 18px;
      border-top-right-radius: 18px;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.25);
      padding: 14px 14px 18px;
      user-select: none;
    }
    #kiosk-kb .kb-top {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      padding: 0 4px 10px;
    }
    #kiosk-kb .kb-title {
      font-weight: 800;
      color: #222;
      font-size: 14px;
      letter-spacing: .2px;
    }
    #kiosk-kb .kb-actions {
      display: flex;
      gap: 8px;
    }
    .kb-btn {
      border: 1px solid #c9c9c9;
      background: #fff;
      border-radius: 10px;
      padding: 10px 12px;
      font-weight: 800;
      font-size: 14px;
      cursor: pointer;
      min-width: 64px;
      touch-action: manipulation;
    }
    .kb-btn:active { transform: translateY(1px); }

    #kiosk-kb .kb-rows {
      display: grid;
      gap: 10px;
    }
    .kb-row {
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 1fr;
      gap: 8px;
    }
    .kb-key {
      border: 1px solid #bdbdbd;
      background: #ffffff;
      border-radius: 12px;
      padding: 14px 0;
      font-weight: 900;
      font-size: 16px;
      cursor: pointer;
      text-align: center;
      touch-action: manipulation;
    }
    .kb-key:active { background: #ececec; transform: translateY(1px); }
    .kb-key.wide { grid-column: span 2; }
    .kb-key.space { grid-column: span 5; }
    .kb-key.primary {
      background: #800000;
      color: #fff;
      border-color: #800000;
    }
    .kb-key.primary:active { filter: brightness(0.95); }

    @media (max-width: 520px) {
      .kb-key { font-size: 14px; padding: 12px 0; }
      .kb-btn { font-size: 12px; padding: 9px 10px; }
    }
  `;
  document.head.appendChild(style);
}

function createKeyboard() {
  ensureKeyboardStyles();
  if (document.getElementById("kiosk-kb-overlay")) return;

  const overlay = document.createElement("div");
  overlay.id = "kiosk-kb-overlay";

  const kb = document.createElement("div");
  kb.id = "kiosk-kb";

  kb.innerHTML = `
    <div class="kb-top">
      <div class="kb-title">On-screen Keyboard</div>
      <div class="kb-actions">
        <button class="kb-btn" data-kb-action="clear" type="button">CLEAR</button>
        <button class="kb-btn" data-kb-action="close" type="button">CLOSE</button>
      </div>
    </div>

    <div class="kb-rows">
      <div class="kb-row">
        ${"QWERTYUIOP".split("").map(k => `<div class="kb-key" data-kb-key="${k}">${k}</div>`).join("")}
      </div>
      <div class="kb-row">
        ${"ASDFGHJKL".split("").map(k => `<div class="kb-key" data-kb-key="${k}">${k}</div>`).join("")}
        <div class="kb-key wide" data-kb-action="backspace">⌫</div>
      </div>
      <div class="kb-row">
        <div class="kb-key wide" data-kb-action="shift">SHIFT</div>
        ${"ZXCVBNM".split("").map(k => `<div class="kb-key" data-kb-key="${k}">${k}</div>`).join("")}
        <div class="kb-key wide" data-kb-action="enter">ENTER</div>
      </div>
      <div class="kb-row">
        <div class="kb-key wide" data-kb-key="-">-</div>
        <div class="kb-key wide" data-kb-key="'">'</div>
        <div class="kb-key space" data-kb-action="space">SPACE</div>
        <div class="kb-key wide primary" data-kb-action="done">DONE</div>
      </div>
    </div>
  `;

  overlay.appendChild(kb);
  document.body.appendChild(overlay);

  // Tap outside keyboard closes it
  overlay.addEventListener("pointerdown", (e) => {
    if (e.target === overlay) hideKeyboard();
  }, { passive: false });
}

// Keyboard state
let kbTargetInput = null;
let kbShift = false;

// ----- FIX HELPERS -----
function ensureFocusAndCaretEnd(inputEl) {
  if (!inputEl) return;

  // Re-focus input (key presses steal focus)
  try { inputEl.focus({ preventScroll: true }); }
  catch (_) { try { inputEl.focus(); } catch (_) {} }

  // Force caret to end (prevents reverse typing)
  const endPos = inputEl.value.length;
  try { inputEl.setSelectionRange(endPos, endPos); } catch (_) {}
}

function getCaretSafe(inputEl) {
  const len = inputEl.value.length;

  let start = Number.isFinite(inputEl.selectionStart) ? inputEl.selectionStart : len;
  let end   = Number.isFinite(inputEl.selectionEnd) ? inputEl.selectionEnd : len;

  start = Math.max(0, Math.min(start, len));
  end   = Math.max(0, Math.min(end, len));

  return { start, end, len };
}

// ----- SHOW / HIDE -----
function showKeyboardFor(inputEl) {
  createKeyboard();
  kbTargetInput = inputEl;

  const overlay = document.getElementById("kiosk-kb-overlay");
  overlay.style.display = "flex";

  ensureFocusAndCaretEnd(inputEl);
}

function hideKeyboard() {
  const overlay = document.getElementById("kiosk-kb-overlay");
  if (overlay) overlay.style.display = "none";
  kbShift = false;
}

// ----- INPUT MUTATION -----
function insertText(inputEl, text) {
  if (!inputEl) return;

  ensureFocusAndCaretEnd(inputEl);
  const { start, end } = getCaretSafe(inputEl);

  const before = inputEl.value.slice(0, start);
  const after  = inputEl.value.slice(end);

  inputEl.value = before + text + after;

  const newPos = start + text.length;
  try { inputEl.setSelectionRange(newPos, newPos); } catch (_) {}

  inputEl.dispatchEvent(new Event("input", { bubbles: true }));
}

function backspace(inputEl) {
  if (!inputEl) return;

  ensureFocusAndCaretEnd(inputEl);
  const { start, end } = getCaretSafe(inputEl);

  if (start !== end) {
    // delete selection
    const before = inputEl.value.slice(0, start);
    const after  = inputEl.value.slice(end);
    inputEl.value = before + after;
    try { inputEl.setSelectionRange(start, start); } catch (_) {}
  } else if (start > 0) {
    // delete char before caret
    const before = inputEl.value.slice(0, start - 1);
    const after  = inputEl.value.slice(start);
    inputEl.value = before + after;
    try { inputEl.setSelectionRange(start - 1, start - 1); } catch (_) {}
  }

  inputEl.dispatchEvent(new Event("input", { bubbles: true }));
}

function clearInput(inputEl) {
  if (!inputEl) return;
  inputEl.value = "";
  inputEl.dispatchEvent(new Event("input", { bubbles: true }));
  ensureFocusAndCaretEnd(inputEl);
}

// ----- KEY HANDLER (use pointerdown instead of click) -----
function kbHandlePress(e) {
  const keyEl = e.target.closest("[data-kb-key], [data-kb-action]");
  if (!keyEl || !kbTargetInput) return;

  // Prevent focus from leaving the input (CRITICAL)
  e.preventDefault();
  e.stopPropagation();

  ensureFocusAndCaretEnd(kbTargetInput);

  const action = keyEl.getAttribute("data-kb-action");
  const key = keyEl.getAttribute("data-kb-key");

  if (action) {
    switch (action) {
      case "close":
        hideKeyboard();
        return;

      case "done":
        // ✅ Run search then close
        handleSearchSelect(kbTargetInput.value, { autoRoute: true });
        hideKeyboard();
        return;

      case "clear":
        clearInput(kbTargetInput);
        // input event will fire → handleSearchSelect via listener
        return;

      case "backspace":
        backspace(kbTargetInput);
        // input event will fire → handleSearchSelect via listener
        return;

      case "space":
        insertText(kbTargetInput, " ");
        // input event will fire → handleSearchSelect via listener
        return;

      case "enter":
        // ✅ Run search then close
        handleSearchSelect(kbTargetInput.value, { autoRoute: true });
        hideKeyboard();
        return;

      case "shift":
        kbShift = !kbShift;
        return;

      default:
        return;
    }
  }

  if (key) {
    const char = kbShift ? key.toUpperCase() : key.toLowerCase();
    insertText(kbTargetInput, char);
    if (kbShift) kbShift = false; // auto-shift off
    // input event will fire → handleSearchSelect via listener
  }
}


// ----- INIT -----
function setupOnScreenKeyboard() {
  const input = getSearchInput();
  if (!input) {
    console.warn("On-screen keyboard: search input not found. Set SEARCH_INPUT_SELECTOR.");
    return;
  }

  createKeyboard();

  // ✅ Force normal typing direction (fixes “backwards / left side” issues)
  input.style.direction = "ltr";
  input.style.textAlign = "left";

  // Open keyboard on focus/tap
  const open = () => showKeyboardFor(input);
  input.addEventListener("focus", open);
  input.addEventListener("pointerdown", open, { passive: true });
  input.addEventListener("click", open);

  // ✅ Trigger building select while typing
  input.addEventListener("input", () => {
    handleSearchSelect(input.value);
  });

  // ✅ Hardware keyboard Enter OR on-screen Enter dispatch
  input.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") {
      ev.preventDefault();
      handleSearchSelect(input.value, { autoRoute: true });
      hideKeyboard();
    }
  });

  const searchBtn = document.querySelector(".searchbtn");
  if (searchBtn) {
    searchBtn.addEventListener("click", () => {
      handleSearchSelect(input.value, { autoRoute: true });
    });
  }

  // Bind keyboard presses (pointerdown avoids blur issues)
  const overlay = document.getElementById("kiosk-kb-overlay");
  overlay.addEventListener("pointerdown", kbHandlePress, { passive: false });

  // Kiosk-friendly input attributes
  input.setAttribute("autocomplete", "off");
  input.setAttribute("autocorrect", "off");
  input.setAttribute("autocapitalize", "off");
  input.setAttribute("spellcheck", "false");

  console.log("On-screen keyboard + search ready for:", input);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", setupOnScreenKeyboard);
} else {
  setupOnScreenKeyboard();
}











// --- Scene
const scene = new THREE.Scene();
scene.background = new THREE.Color(0xffffff);

scene.add(new THREE.AmbientLight(0xffffff, 1.0));
const dirLight = new THREE.DirectionalLight(0xffffff, 1.2);
dirLight.position.set(5, 10, 7);
scene.add(dirLight);

// --- Camera
const VIEW_MODE_3D = "3d";
const VIEW_MODE_2D = "2d";
const DEFAULT_PERSPECTIVE_FOV = 55;
const PERSPECTIVE_FOV_MIN = 18;
const PERSPECTIVE_FOV_MAX = 75;
const ORTHO_ZOOM_MIN = 0.85;
const ORTHO_ZOOM_MAX = 12;
const ORTHO_PLAN_PADDING = 1.12;
const ORTHO_CAMERA_HEIGHT_FACTOR = 1.5;
const HORIZON_POLAR_EPSILON = 0.02;
const STARTUP_CAMERA_CLOSENESS = 0.5;
const DEFAULT_VIEW_DIRECTION = new THREE.Vector3(1, 0.8, 1).normalize();

const perspectiveCamera = new THREE.PerspectiveCamera(
  DEFAULT_PERSPECTIVE_FOV,
  1,
  0.001,
  100000
);
perspectiveCamera.position.set(0, 5, 10);

const orthographicCamera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.001, 100000);
orthographicCamera.up.set(0, 0, -1);

let camera = perspectiveCamera;
let controls = null;
let currentViewMode = VIEW_MODE_3D;

const mapBounds = new THREE.Box3();
const mapWorldSize = new THREE.Vector3(1, 1, 1);
const mapWorldCenter = new THREE.Vector3();
let mapWorldDiagonal = 1;
let orthoFitHeight = 1;

const perspectiveViewState = {
  initialized: false,
  position: new THREE.Vector3(),
  target: new THREE.Vector3(),
  fov: DEFAULT_PERSPECTIVE_FOV
};

const orthographicViewState = {
  initialized: false,
  target: new THREE.Vector3(),
  zoom: 1
};

// --- Renderer (attach to mapStage, not body)
const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
mapStage.appendChild(renderer.domElement);
const canvas = renderer.domElement;
canvas.style.width = "100%";
canvas.style.height = "100%";
canvas.style.display = "block";
canvas.style.touchAction = "none";

function ensureBuildingLabelStyles() {
  if (document.getElementById("map-building-label-styles")) return;

  const style = document.createElement("style");
  style.id = "map-building-label-styles";
  style.textContent = `
    #map-building-label-layer {
      position: absolute;
      inset: 0;
      overflow: hidden;
      pointer-events: none;
      z-index: 40;
    }
    .map-building-label {
      position: absolute;
      left: 0;
      top: 0;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(128, 0, 0, 0.9);
      color: #fff;
      font: 800 13px/1.1 "Trebuchet MS", "Segoe UI", sans-serif;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      white-space: nowrap;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      opacity: 0.96;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
      transform: translate(-50%, -100%);
    }
    .map-building-label.is-hovered {
      background: rgba(180, 0, 0, 0.96);
    }
    .map-building-label.is-selected {
      background: rgba(17, 24, 39, 0.96);
    }
    @media (max-width: 640px) {
      .map-building-label {
        padding: 5px 8px;
        font-size: 10px;
      }
    }
  `;
  document.head.appendChild(style);
}

function ensureBuildingLabelLayer() {
  ensureBuildingLabelStyles();

  if (getComputedStyle(mapStage).position === "static") {
    mapStage.style.position = "relative";
  }
  if (!buildingLabelLayer) {
    buildingLabelLayer = document.createElement("div");
    buildingLabelLayer.id = "map-building-label-layer";
  }
  if (!buildingLabelLayer.parentElement) {
    mapStage.appendChild(buildingLabelLayer);
  }
  return buildingLabelLayer;
}

function ensureNavigationOverlayStyles() {
  if (document.getElementById("map-navigation-overlay-styles")) return;

  const style = document.createElement("style");
  style.id = "map-navigation-overlay-styles";
  style.textContent = `
    #map-navigation-overlay {
      position: absolute;
      inset: 0;
      overflow: hidden;
      pointer-events: none;
      z-index: 45;
    }
    .map-kiosk-pin {
      position: absolute;
      left: 0;
      top: 0;
      display: none;
      transform: translate(-50%, -100%);
      text-align: center;
      white-space: nowrap;
    }
    .map-kiosk-pin__icon {
      position: relative;
      width: 28px;
      height: 28px;
      margin: 0 auto;
      border: 4px solid #ff1f1f;
      border-radius: 50% 50% 50% 0;
      background: #fff;
      box-shadow: 0 10px 24px rgba(239, 68, 68, 0.24);
      transform: rotate(-45deg);
    }
    .map-kiosk-pin__icon::after {
      content: "";
      position: absolute;
      left: 50%;
      top: 50%;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ff1f1f;
      transform: translate(-50%, -50%);
    }
    .map-kiosk-pin__label {
      margin-top: 10px;
      padding: 5px 10px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.96);
      color: #7f1d1d;
      font: 800 12px/1.1 "Trebuchet MS", "Segoe UI", sans-serif;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
    }
    @media (max-width: 640px) {
      .map-kiosk-pin__icon {
        width: 24px;
        height: 24px;
        border-width: 3px;
      }
      .map-kiosk-pin__label {
        font-size: 10px;
        padding: 4px 8px;
      }
    }
  `;
  document.head.appendChild(style);
}

let navigationOverlayLayer = null;
let kioskMarkerEl = null;

function ensureNavigationOverlayLayer() {
  ensureNavigationOverlayStyles();

  if (getComputedStyle(mapStage).position === "static") {
    mapStage.style.position = "relative";
  }
  if (!navigationOverlayLayer) {
    navigationOverlayLayer = document.createElement("div");
    navigationOverlayLayer.id = "map-navigation-overlay";
  }
  if (!navigationOverlayLayer.parentElement) {
    mapStage.appendChild(navigationOverlayLayer);
  }
  return navigationOverlayLayer;
}

function ensureKioskMarker() {
  const layer = ensureNavigationOverlayLayer();
  if (!kioskMarkerEl) {
    kioskMarkerEl = document.createElement("div");
    kioskMarkerEl.className = "map-kiosk-pin";
    kioskMarkerEl.innerHTML = `
      <div class="map-kiosk-pin__icon"></div>
      <div class="map-kiosk-pin__label">You are here</div>
    `;
  }
  if (!kioskMarkerEl.parentElement) {
    layer.appendChild(kioskMarkerEl);
  }
  return kioskMarkerEl;
}

// --- Controls
function getOrthoCameraHeightOffset() {
  return Math.max(mapWorldDiagonal * ORTHO_CAMERA_HEIGHT_FACTOR, mapWorldSize.y * 4, 20);
}

function syncViewModeUi() {
  const is3d = currentViewMode === VIEW_MODE_3D;
  mapView3dBtn?.classList.toggle("is-active", is3d);
  mapView2dBtn?.classList.toggle("is-active", !is3d);
  mapView3dBtn?.setAttribute("aria-pressed", is3d ? "true" : "false");
  mapView2dBtn?.setAttribute("aria-pressed", !is3d ? "true" : "false");
}

function getPerspectiveZoomPercent(fov = perspectiveCamera.fov) {
  const normalized = (PERSPECTIVE_FOV_MAX - fov) / (PERSPECTIVE_FOV_MAX - PERSPECTIVE_FOV_MIN);
  return clamp(normalized * 100, 0, 100);
}

function getPerspectiveFovFromPercent(percent) {
  const normalized = clamp(percent, 0, 100) / 100;
  return PERSPECTIVE_FOV_MAX - (normalized * (PERSPECTIVE_FOV_MAX - PERSPECTIVE_FOV_MIN));
}

function getOrthoZoomPercent(zoom = orthographicCamera.zoom) {
  const normalized = (zoom - ORTHO_ZOOM_MIN) / (ORTHO_ZOOM_MAX - ORTHO_ZOOM_MIN);
  return clamp(normalized * 100, 0, 100);
}

function getOrthoZoomFromPercent(percent) {
  const normalized = clamp(percent, 0, 100) / 100;
  return ORTHO_ZOOM_MIN + (normalized * (ORTHO_ZOOM_MAX - ORTHO_ZOOM_MIN));
}

function getCurrentZoomPercent() {
  return currentViewMode === VIEW_MODE_2D
    ? getOrthoZoomPercent()
    : getPerspectiveZoomPercent();
}

function syncZoomUi() {
  const percent = Math.round(getCurrentZoomPercent());
  if (mapZoomSlider && document.activeElement !== mapZoomSlider) {
    mapZoomSlider.value = String(percent);
  }
  if (mapZoomValue) {
    mapZoomValue.textContent = `${percent}%`;
  }
}

function applyActiveZoomPercent(percent) {
  const safePercent = clamp(Number(percent) || 0, 0, 100);
  if (currentViewMode === VIEW_MODE_2D) {
    orthographicCamera.zoom = getOrthoZoomFromPercent(safePercent);
    orthographicCamera.updateProjectionMatrix();
    orthographicViewState.zoom = orthographicCamera.zoom;
  } else {
    perspectiveCamera.fov = getPerspectiveFovFromPercent(safePercent);
    perspectiveCamera.updateProjectionMatrix();
    perspectiveViewState.fov = perspectiveCamera.fov;
  }
  syncZoomUi();
}

function nudgeActiveZoomPercent(deltaPercent) {
  applyActiveZoomPercent(getCurrentZoomPercent() + deltaPercent);
}

function updatePerspectiveProjection(rect) {
  const width = Math.max(1, rect.width || 1);
  const height = Math.max(1, rect.height || 1);
  perspectiveCamera.aspect = width / height;
  perspectiveCamera.updateProjectionMatrix();
}

function updateOrthographicProjection(rect) {
  const width = Math.max(1, rect.width || 1);
  const height = Math.max(1, rect.height || 1);
  const aspect = width / height;

  orthoFitHeight = Math.max(
    1,
    mapWorldSize.z * ORTHO_PLAN_PADDING,
    (mapWorldSize.x * ORTHO_PLAN_PADDING) / aspect
  );

  const halfHeight = orthoFitHeight / 2;
  const halfWidth = halfHeight * aspect;

  orthographicCamera.left = -halfWidth;
  orthographicCamera.right = halfWidth;
  orthographicCamera.top = halfHeight;
  orthographicCamera.bottom = -halfHeight;
  orthographicCamera.near = 0.01;
  orthographicCamera.far = Math.max(getOrthoCameraHeightOffset() + (mapWorldDiagonal * 20), 1000);
  orthographicCamera.updateProjectionMatrix();
}

function buildControlsForMode(cameraObject, mode) {
  const nextControls = new OrbitControls(cameraObject, canvas);

  nextControls.enableDamping = true;
  nextControls.dampingFactor = 0.1;
  nextControls.enablePan = true;
  nextControls.screenSpacePanning = true;
  nextControls.enableZoom = false;
  nextControls.zoomToCursor = false;
  nextControls.panSpeed = mode === VIEW_MODE_2D ? 0.8 : 0.5;

  if (mode === VIEW_MODE_2D) {
    nextControls.enableRotate = false;
    nextControls.rotateSpeed = 0;
    nextControls.mouseButtons = {
      LEFT: THREE.MOUSE.PAN,
      MIDDLE: THREE.MOUSE.PAN,
      RIGHT: THREE.MOUSE.PAN,
    };
    nextControls.touches = {
      ONE: THREE.TOUCH.PAN,
      TWO: THREE.TOUCH.PAN,
    };
  } else {
    nextControls.enableRotate = true;
    nextControls.rotateSpeed = 0.5;
    nextControls.minPolarAngle = 0;
    nextControls.maxPolarAngle = Math.PI / 2 - HORIZON_POLAR_EPSILON;
    nextControls.mouseButtons = {
      LEFT: THREE.MOUSE.ROTATE,
      MIDDLE: THREE.MOUSE.PAN,
      RIGHT: THREE.MOUSE.PAN,
    };
    nextControls.touches = {
      ONE: THREE.TOUCH.ROTATE,
      TWO: THREE.TOUCH.PAN,
    };
    nextControls.minDistance = 0;
    nextControls.maxDistance = mapWorldDiagonal * 5;
  }

  return nextControls;
}

function replaceControls(nextCamera, nextMode, target) {
  controls?.dispose?.();
  camera = nextCamera;
  controls = buildControlsForMode(camera, nextMode);
  controls.target.copy(target || mapWorldCenter);
  controls.update();
  syncViewModeUi();
  syncZoomUi();
}

function captureViewState(mode = currentViewMode) {
  if (!controls) return;

  if (mode === VIEW_MODE_2D) {
    orthographicViewState.target.copy(controls.target);
    orthographicViewState.zoom = orthographicCamera.zoom;
    orthographicViewState.initialized = true;
    return;
  }

  perspectiveViewState.target.copy(controls.target);
  perspectiveViewState.position.copy(perspectiveCamera.position);
  perspectiveViewState.fov = perspectiveCamera.fov;
  perspectiveViewState.initialized = true;
}

function initializePerspectiveViewState(target = mapWorldCenter) {
  perspectiveViewState.target.copy(target);
  perspectiveViewState.position
    .copy(target)
    .add(DEFAULT_VIEW_DIRECTION.clone().multiplyScalar(mapWorldDiagonal * STARTUP_CAMERA_CLOSENESS));
  perspectiveViewState.fov = DEFAULT_PERSPECTIVE_FOV;
  perspectiveViewState.initialized = true;
}

function initializeOrthographicViewState(target = mapWorldCenter) {
  orthographicViewState.target.copy(target);
  orthographicViewState.zoom = 1;
  orthographicViewState.initialized = true;
}

function activatePerspectiveView({ target = null, reset = false } = {}) {
  const focusTarget = target ? target.clone() : perspectiveViewState.target.clone();
  if (reset || !perspectiveViewState.initialized) {
    initializePerspectiveViewState(focusTarget);
  } else if (target) {
    const delta = focusTarget.clone().sub(perspectiveViewState.target);
    perspectiveViewState.target.copy(focusTarget);
    perspectiveViewState.position.add(delta);
  }

  currentViewMode = VIEW_MODE_3D;
  perspectiveCamera.position.copy(perspectiveViewState.position);
  perspectiveCamera.fov = clamp(perspectiveViewState.fov, PERSPECTIVE_FOV_MIN, PERSPECTIVE_FOV_MAX);
  perspectiveCamera.near = Math.max(0.01, mapWorldDiagonal / 1000);
  perspectiveCamera.far = Math.max(mapWorldDiagonal * 100, 1000);
  updatePerspectiveProjection(mapStage.getBoundingClientRect());
  replaceControls(perspectiveCamera, currentViewMode, perspectiveViewState.target.clone());
  controls.minDistance = 0;
  controls.maxDistance = mapWorldDiagonal * 5;
  controls.update();
}

function activateOrthographicView({ target = null, reset = false } = {}) {
  const focusTarget = target ? target.clone() : orthographicViewState.target.clone();
  if (reset || !orthographicViewState.initialized) {
    initializeOrthographicViewState(focusTarget);
  } else if (target) {
    orthographicViewState.target.copy(focusTarget);
  }

  currentViewMode = VIEW_MODE_2D;
  orthographicCamera.zoom = clamp(orthographicViewState.zoom, ORTHO_ZOOM_MIN, ORTHO_ZOOM_MAX);
  updateOrthographicProjection(mapStage.getBoundingClientRect());
  orthographicCamera.position.set(
    orthographicViewState.target.x,
    orthographicViewState.target.y + getOrthoCameraHeightOffset(),
    orthographicViewState.target.z
  );
  replaceControls(orthographicCamera, currentViewMode, orthographicViewState.target.clone());
  controls.update();
}

function setViewMode(nextMode, { reset = false } = {}) {
  const normalizedMode = nextMode === VIEW_MODE_2D ? VIEW_MODE_2D : VIEW_MODE_3D;
  if (normalizedMode === currentViewMode && !reset) {
    syncViewModeUi();
    syncZoomUi();
    return;
  }

  const nextTarget = controls?.target?.clone() || mapWorldCenter.clone();
  captureViewState(currentViewMode);

  if (normalizedMode === VIEW_MODE_2D) {
    activateOrthographicView({ target: nextTarget, reset: reset || !orthographicViewState.initialized });
  } else {
    activatePerspectiveView({ target: nextTarget, reset: reset || !perspectiveViewState.initialized });
  }
}

// --- Resize to container
function resizeToContainer() {
  const rect = mapStage.getBoundingClientRect();
  const w = Math.max(1, Math.floor(rect.width));
  const h = Math.max(1, Math.floor(rect.height));

  renderer.setSize(w, h, false);
  updatePerspectiveProjection(rect);
  updateOrthographicProjection(rect);
  syncZoomUi();
}

controls = buildControlsForMode(camera, currentViewMode);
controls.target.copy(mapWorldCenter);
controls.update();
syncViewModeUi();
syncZoomUi();
resizeToContainer();

const ro = new ResizeObserver(() => resizeToContainer());
ro.observe(mapStage);
window.addEventListener("resize", () => resizeToContainer());

mapView3dBtn?.addEventListener("click", () => {
  setViewMode(VIEW_MODE_3D);
  logCameraState("VIEW_3D");
});

mapView2dBtn?.addEventListener("click", () => {
  setViewMode(VIEW_MODE_2D);
  logCameraState("VIEW_2D");
});

mapZoomSlider?.addEventListener("input", (event) => {
  applyActiveZoomPercent(event.target.value);
});

// --- Mouse coords relative to canvas rect
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();
const activeTouchPointers = new Map();
let touchSceneTap = null;
let suppressSceneClickUntil = 0;
let pinchDistance = 0;
let isSceneHoverActive = false;

function setMouseFromEvent(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
}

canvas.addEventListener("pointermove", (event) => {
  if (event.pointerType && event.pointerType !== "mouse") return;
  isSceneHoverActive = true;
  setMouseFromEvent(event);
});
canvas.addEventListener("pointerleave", () => {
  isSceneHoverActive = false;
  hoveredBuildingName = null;
  clearHoverTint();
  clearHoverOutline();
});

// ==========================
// Custom wheel / pinch zoom
// ==========================
const WHEEL_ZOOM_FACTOR = 0.06;
const TOUCH_TAP_SLOP_PX = 12;
const TOUCH_CLICK_SUPPRESS_MS = 450;
const TOUCH_PINCH_SLOP_PX = 2;
const TOUCH_ZOOM_FACTOR = 0.18;

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

function onWheelZoom(e) {
  e.preventDefault();
  e.stopPropagation();
  nudgeActiveZoomPercent(-e.deltaY * WHEEL_ZOOM_FACTOR);
  logCameraState("WHEEL_ZOOM");
}

renderer.domElement.addEventListener("wheel", onWheelZoom, { passive: false });

function isTouchLikePointer(event) {
  return event.pointerType === "touch" || event.pointerType === "pen";
}

function trackTouchPointer(event) {
  if (!isTouchLikePointer(event)) return;
  activeTouchPointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
}

function releaseTouchPointer(pointerId) {
  activeTouchPointers.delete(pointerId);
  if (activeTouchPointers.size < 2) pinchDistance = 0;
}

function getTouchDistance() {
  const points = Array.from(activeTouchPointers.values());
  if (points.length < 2) return 0;
  const [a, b] = points;
  return Math.hypot(b.x - a.x, b.y - a.y);
}

function updateTouchZoom() {
  if (activeTouchPointers.size < 2) {
    pinchDistance = 0;
    return;
  }
  const distance = getTouchDistance();
  if (!(distance > 0)) return;
  if (!(pinchDistance > 0)) {
    pinchDistance = distance;
    return;
  }
  const delta = distance - pinchDistance;
  if (Math.abs(delta) < TOUCH_PINCH_SLOP_PX) return;
  nudgeActiveZoomPercent(delta * TOUCH_ZOOM_FACTOR);
  pinchDistance = distance;
}

function isEventOnCanvas(event) {
  const rect = canvas.getBoundingClientRect();
  return (
    event.clientX >= rect.left &&
    event.clientX <= rect.right &&
    event.clientY >= rect.top &&
    event.clientY <= rect.bottom
  );
}

const LIVE_MAP_ENDPOINT = "../api/map_live.php";
const BUILDING_DETAILS_ENDPOINT = "../api/map_building_details.php";
const SEARCH_INDEX_ENDPOINT = "../api/map_search.php";
const FALLBACK_MODEL_URL = "../models/tnts_navigation.glb";
const LIVE_POLL_INTERVAL_MS = 30000;

let activeRoutesByKey = new Map(); // normalized name -> { name, mode, names|points, distance? }
let activeGuidesByKey = new Map(); // guide key -> published guide entry
let routeNamesForSearch = [];      // display names
let dbBuildingNamesForSearch = []; // DB building names
let dbRoomEntriesForSearch = [];   // [{ roomName, buildingName }]
let dbBuildingsByKey = new Map();  // normalized building name -> metadata
let liveVersion = null;
let livePollTimer = null;
let livePollBusy = false;
let currentLiveModelFile = "";
let cardDetailsRequestSeq = 0;
let cardRoomsCache = new Map();
let publishedKioskPoint = null;
let publishedKioskPointAligned = null;
let selectedGuideTarget = null;

const kioskMarkerScreen = new THREE.Vector3();

function rebuildRouteCatalog(entries) {
  activeRoutesByKey = new Map();
  routeNamesForSearch = [];
  for (const entry of (entries || [])) {
    const name = String(entry?.name || "").trim();
    const key = normalizeQuery(name);
    if (!key || !name) continue;
    activeRoutesByKey.set(key, entry);
    routeNamesForSearch.push(name);
  }
}

function inferPublishedKioskPoint(entries) {
  const buckets = new Map();
  for (const entry of (entries || [])) {
    const point = Array.isArray(entry?.points) ? entry.points[0] : null;
    if (!Array.isArray(point) || point.length < 3) continue;
    const key = point.map((value) => Number(value).toFixed(3)).join("|");
    const bucket = buckets.get(key) || { count: 0, point };
    bucket.count += 1;
    buckets.set(key, bucket);
  }

  let best = null;
  for (const bucket of buckets.values()) {
    if (!best || bucket.count > best.count) best = bucket;
  }
  if (!best?.point) return null;

  return new THREE.Vector3(
    Number(best.point[0]),
    Number(best.point[1]),
    Number(best.point[2])
  );
}

function setPublishedKioskPoint(point) {
  publishedKioskPoint = point?.clone?.() || null;
  publishedKioskPointAligned = publishedKioskPoint?.clone?.() || null;
  if (publishedKioskPoint && loadedModel) {
    publishedKioskPointAligned = alignPointToModelSurface(publishedKioskPoint);
  }
  updateKioskMarker();
}

function setPublishedRoutes(routesObj) {
  if (!routesObj || typeof routesObj !== "object") {
    setPublishedKioskPoint(null);
    return false;
  }
  const entries = [];
  for (const [rawKey, rawEntry] of Object.entries(routesObj)) {
    if (!rawEntry || !Array.isArray(rawEntry.points) || rawEntry.points.length < 2) continue;
    const name = String(rawEntry.name || rawKey || "").trim();
    if (!name) continue;
    const points = rawEntry.points
      .filter(p => Array.isArray(p) && p.length >= 3)
      .map(p => [Number(p[0]), Number(p[1]), Number(p[2])])
      .filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1]) && Number.isFinite(p[2]));
    if (points.length < 2) continue;
    entries.push({
      name,
      mode: "points",
      points,
      distance: Number(rawEntry.distance)
    });
  }
  if (!entries.length) {
    setPublishedKioskPoint(null);
    return false;
  }
  rebuildRouteCatalog(entries);
  setPublishedKioskPoint(inferPublishedKioskPoint(entries));
  return true;
}

function setPublishedGuides(guidesObj) {
  activeGuidesByKey = new Map();
  if (!guidesObj || typeof guidesObj !== "object") return false;

  for (const [rawKey, rawEntry] of Object.entries(guidesObj)) {
    if (!rawEntry || typeof rawEntry !== "object") continue;
    const destinationType = String(rawEntry.destinationType || rawEntry.type || (String(rawKey).startsWith("room::") ? "room" : "building")).trim() || "building";
    const buildingName = String(rawEntry.buildingName || rawEntry.name || rawEntry.routeName || rawEntry.destinationName || "").trim();
    const roomName = String(rawEntry.roomName || "").trim();
    const key = String(rawEntry.key || rawKey || buildGuideKey(destinationType, { buildingName, roomName })).trim();
    if (!key || !buildingName) continue;

    activeGuidesByKey.set(key, {
      key,
      destinationType,
      buildingName,
      roomName,
      destinationName: String(rawEntry.destinationName || "").trim(),
      routeName: String(rawEntry.routeName || buildingName).trim(),
      status: String(rawEntry.status || "").trim(),
      guideMode: String(rawEntry.guideMode || "").trim(),
      manualText: String(rawEntry.manualText || "").trim(),
      notes: Array.isArray(rawEntry.notes) ? rawEntry.notes.map((note) => String(note || "").trim()).filter(Boolean) : [],
      finalSteps: Array.isArray(rawEntry.finalSteps) ? rawEntry.finalSteps : [],
      autoSteps: Array.isArray(rawEntry.autoSteps) ? rawEntry.autoSteps : [],
      distance: Number(rawEntry.distance)
    });
  }

  return activeGuidesByKey.size > 0;
}

function setSelectedGuideTarget(target) {
  if (!target || !String(target.buildingName || "").trim()) {
    selectedGuideTarget = null;
    return;
  }
  selectedGuideTarget = {
    type: String(target.type || "building").trim() || "building",
    buildingName: String(target.buildingName || "").trim(),
    roomName: String(target.roomName || "").trim()
  };
}

function getGuideEntryForTarget(target) {
  if (!target || !target.buildingName) return null;
  const buildingCandidates = [
    String(target.buildingName || "").trim(),
    String(getDisplayBuildingName(target.buildingName) || "").trim()
  ].filter(Boolean);

  for (const candidate of buildingCandidates) {
    const exactKey = buildGuideKey(target.type || "building", {
      buildingName: candidate,
      roomName: target.roomName || ""
    });
    const exact = activeGuidesByKey.get(exactKey);
    if (exact) return exact;
  }

  for (const candidate of buildingCandidates) {
    const buildingKey = buildGuideKey("building", { buildingName: candidate });
    const buildingGuide = activeGuidesByKey.get(buildingKey);
    if (buildingGuide) return buildingGuide;
  }

  return null;
}

function buildDirectionsTarget() {
  if (selectedGuideTarget?.buildingName) return selectedGuideTarget;
  if (selectedBuildingName) {
    return {
      type: "building",
      buildingName: selectedBuildingName,
      roomName: ""
    };
  }
  return null;
}

function formatGuideDistanceText(distance) {
  const safe = Number(distance);
  if (!Number.isFinite(safe) || safe <= 0) return "Distance unavailable";
  return `${Math.round(safe)} units`;
}

function hideDirectionsPanel() {
  directionsPanel?.classList.add("hidden");
  if (directionsSummary) directionsSummary.textContent = "Select a destination to view directions.";
  if (directionsTitle) directionsTitle.textContent = "Directions";
  if (directionsStatus) {
    directionsStatus.classList.add("hidden");
    directionsStatus.textContent = "";
  }
  directionsSteps?.replaceChildren?.();
}

function renderDirectionsSteps(steps) {
  if (!directionsSteps) return;
  directionsSteps.replaceChildren();
  const safeSteps = Array.isArray(steps) ? steps.filter((step) => String(step?.text || "").trim()) : [];
  if (!safeSteps.length) {
    const empty = document.createElement("div");
    empty.className = "directions-panel-summary";
    empty.textContent = "Step-by-step guidance is unavailable for this route.";
    directionsSteps.appendChild(empty);
    return;
  }

  safeSteps.forEach((step, index) => {
    const row = document.createElement("div");
    row.className = "directions-step";

    const num = document.createElement("div");
    num.className = "directions-step-num";
    num.textContent = String(index + 1);

    const text = document.createElement("div");
    text.className = "directions-step-text";
    text.textContent = String(step?.text || "").trim();

    row.appendChild(num);
    row.appendChild(text);
    directionsSteps.appendChild(row);
  });
}

function buildPublicGuideLandmarks() {
  const landmarks = [];
  for (const entry of buildingLabels) {
    const point = entry?.worldPosition;
    if (!point) continue;
    landmarks.push({
      type: "building",
      name: getDisplayBuildingName(entry.name) || entry.name,
      x: point.x,
      y: point.y,
      z: point.z
    });
  }
  return landmarks;
}

function showDirectionsPanelForSelection(target, routeEntry, alignedPoints, routeDistance) {
  if (!directionsPanel || !target?.buildingName) return;

  const displayBuildingName = getDisplayBuildingName(target.buildingName) || target.buildingName;
  const title = target.type === "room" && target.roomName
    ? `${target.roomName}`
    : displayBuildingName;
  const summary = target.type === "room" && target.roomName
    ? `Route via ${displayBuildingName} • ${formatGuideDistanceText(routeDistance)}`
    : `${displayBuildingName} • ${formatGuideDistanceText(routeDistance)}`;

  const guideEntry = getGuideEntryForTarget(target);
  const usablePublishedGuide = guideEntry
    && guideEntry.status !== "orphaned"
    && guideEntry.status !== "missing_route"
    && Array.isArray(guideEntry.finalSteps)
    && guideEntry.finalSteps.length > 0;

  const steps = usablePublishedGuide
    ? guideEntry.finalSteps
    : buildGuideStepsFromPoints(
        alignedPoints.map((point) => [point.x, point.y, point.z]),
        {
          destinationName: target.roomName || displayBuildingName,
          arrivalText: target.type === "room" && target.roomName
            ? `Arrive at ${displayBuildingName}. Proceed to ${target.roomName}.`
            : `Arrive at ${displayBuildingName}.`,
          landmarks: buildPublicGuideLandmarks()
        }
      );

  if (directionsTitle) directionsTitle.textContent = title;
  if (directionsSummary) directionsSummary.textContent = summary;
  if (directionsStatus) {
    const notes = usablePublishedGuide ? guideEntry.notes : [];
    if (!usablePublishedGuide) {
      directionsStatus.textContent = "Using an auto-generated fallback because no published text guide is available yet.";
      directionsStatus.classList.remove("hidden");
    } else if (Array.isArray(notes) && notes.length) {
      directionsStatus.textContent = notes[0];
      directionsStatus.classList.remove("hidden");
    } else {
      directionsStatus.classList.add("hidden");
      directionsStatus.textContent = "";
    }
  }

  renderDirectionsSteps(steps);
  directionsPanel.classList.remove("hidden");
}

function getRouteEntry(name) {
  const key = normalizeQuery(name);
  if (!key) return null;
  return activeRoutesByKey.get(key) || null;
}

rebuildRouteCatalog([]);

function registerDbBuildingMeta(entry) {
  for (const key of getBuildingLookupKeys(entry?.name)) {
    if (!dbBuildingsByKey.has(key)) {
      dbBuildingsByKey.set(key, entry);
    }
  }
}

function getDbBuildingMeta(name) {
  for (const key of getBuildingLookupKeys(name)) {
    const entry = dbBuildingsByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

function getDisplayBuildingName(name) {
  return String(getDbBuildingMeta(name)?.name || name || "").trim();
}

function getRouteEntryForBuilding(name) {
  const displayName = getDisplayBuildingName(name);
  return getRouteEntry(displayName) || getRouteEntry(name);
}

function refreshBuildingLabelText() {
  for (const entry of buildingLabels) {
    const nextLabel = formatBuildingLabel(getDisplayBuildingName(entry.name));
    if (entry.element.textContent !== nextLabel) {
      entry.element.textContent = nextLabel;
    }
  }
  updateBuildingLabels();
}

async function loadSearchCatalog(modelFile = currentLiveModelFile) {
  try {
    const url = new URL(SEARCH_INDEX_ENDPOINT, window.location.href);
    url.searchParams.set("v", String(Date.now()));
    if (modelFile) url.searchParams.set("model", modelFile);

    const res = await fetch(url.toString(), { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data || data.ok === false) throw new Error(data?.error || "Invalid search payload");

    const buildings = Array.isArray(data.buildings) ? data.buildings : [];
    const rooms = Array.isArray(data.rooms) ? data.rooms : [];

    cardRoomsCache.clear();
    dbBuildingsByKey = new Map();
    dbBuildingNamesForSearch = [];
    for (const raw of buildings) {
      const name = String(raw?.name || "").trim();
      if (!name) continue;
      registerDbBuildingMeta({
        id: raw?.id ?? null,
        name,
        description: String(raw?.description || "").trim(),
        imagePath: String(raw?.imagePath || "").trim(),
        modelFile: String(raw?.modelFile || "").trim()
      });
      dbBuildingNamesForSearch.push(name);
    }

    dbRoomEntriesForSearch = rooms
      .map((r) => ({
        roomName: String(r?.roomName || "").trim(),
        buildingName: String(r?.buildingName || "").trim(),
        modelFile: String(r?.modelFile || "").trim()
      }))
      .filter((r) => r.roomName && r.buildingName);

    refreshBuildingLabelText();
    if (selectedBuildingName) {
      showCard(selectedBuildingName);
    }

    // If user already typed, re-run search now that DB rooms/buildings are ready.
    if (loadedModel && String(pendingSearchQuery || "").trim()) {
      handleSearchSelect(pendingSearchQuery);
    }
  } catch (err) {
    console.warn("Search catalog unavailable (route/building-only search remains active):", err);
    dbBuildingNamesForSearch = [];
    dbRoomEntriesForSearch = [];
    dbBuildingsByKey = new Map();
  }
}




// ==========================
// ✅ SEARCH → SELECT BUILDING (highlight + info card)
// ==========================
let pendingSearchQuery = "";

function normalizeQuery(q) {
  return String(q || "")
    .trim()
    .toUpperCase()
    .replace(/\s+/g, "_");
}

function normalizeLoose(q) {
  return normalizeQuery(q).replace(/[_\-.]/g, "");
}

function removeOneNumericSuffix(name) {
  return String(name || "").replace(/([._-]\d+)$/g, "");
}

function isGenericNodeName(name) {
  const n = String(name || "").trim().toUpperCase();
  const flat = n.replace(/[\s_.-]+/g, "");
  return (
    flat === "SCENE" ||
    flat === "AUXSCENE" ||
    flat === "ROOT" ||
    flat === "ROOTNODE" ||
    flat === "GLTF" ||
    flat === "MODEL" ||
    flat === "GROUP" ||
    flat === "COLLECTION" ||
    flat === "SCENECOLLECTION" ||
    flat === "MASTERCOLLECTION"
  );
}

function scoreSearchMatch(queryNorm, targetNorm) {
  if (!queryNorm || !targetNorm) return 0;
  if (targetNorm === queryNorm) return 120;

  const qFlat = normalizeLoose(queryNorm);
  const tFlat = normalizeLoose(targetNorm);
  if (tFlat === qFlat) return 110;

  if (targetNorm.startsWith(queryNorm)) return 90;
  if (tFlat.startsWith(qFlat)) return 85;
  if (targetNorm.includes(queryNorm)) return 70;
  if (tFlat.includes(qFlat)) return 65;
  return 0;
}

function collectSearchCandidates(queryNorm) {
  if (!queryNorm) return [];

  const out = [];
  const seen = new Set();

  const pushCandidate = (type, label, buildingName, roomName = "") => {
    const key = `${type}::${String(label)}::${String(buildingName)}::${String(roomName)}`;
    if (seen.has(key)) return;
    seen.add(key);

    let score = 0;
    if (type === "room") {
      // Match by room only, and by room+building when user includes both.
      const roomNorm = normalizeQuery(roomName);
      const roomWithBuildingNorm = normalizeQuery(`${roomName} ${buildingName}`);
      const roomLabelNorm = normalizeQuery(label);
      score = Math.max(
        scoreSearchMatch(queryNorm, roomNorm),
        scoreSearchMatch(queryNorm, roomWithBuildingNorm) + 5,
        scoreSearchMatch(queryNorm, roomLabelNorm) + 3
      );
    } else {
      score = scoreSearchMatch(queryNorm, normalizeQuery(label));
    }
    if (score <= 0) return;

    const hasRoute = !!getRouteEntry(buildingName);
    out.push({
      type,
      label,
      buildingName,
      roomName,
      hasRoute,
      score: score + (hasRoute ? 20 : 0)
    });
  };

  // Route-published buildings (highest confidence for routing).
  for (const name of routeNamesForSearch) {
    pushCandidate("building", name, name, "");
  }

  // DB buildings (may include buildings without published route yet).
  for (const bName of dbBuildingNamesForSearch) {
    pushCandidate("building", bName, bName, "");
  }

  // DB rooms -> route by parent building.
  for (const row of dbRoomEntriesForSearch) {
    pushCandidate("room", `${row.roomName} (${row.buildingName})`, row.buildingName, row.roomName);
  }

  out.sort((a, b) => {
    if (b.score !== a.score) return b.score - a.score;
    if (a.type !== b.type) return a.type === "building" ? -1 : 1;
    return a.label.localeCompare(b.label);
  });
  return out;
}

function selectBuildingByName(buildingName) {
  if (!loadedModel || !buildingName) return false;

  let candidateName = String(buildingName);
  const normTarget = normalizeQuery(candidateName);
  for (const routed of routeNamesForSearch) {
    if (normalizeQuery(routed) === normTarget) {
      candidateName = routed;
      break;
    }
  }

  let entry = getBuildingEntryByName(candidateName) || getBuildingEntryByName(buildingName);
  let anchor = entry?.object || null;

  if (!anchor) {
    // Fallback for case/spacing differences and loader-added numeric suffixes.
    loadedModel.traverse((obj) => {
      if (anchor || !obj?.name) return;
      if (isGenericNodeName(obj.name)) return;

      const objNorm = normalizeQuery(obj.name);
      const objNormNoSuffix = normalizeQuery(removeOneNumericSuffix(obj.name));
      if (objNorm === normTarget || objNormNoSuffix === normTarget) {
        anchor = obj;
      }
    });
  }

  if (!anchor) return false;

  entry = entry || getBuildingEntryByName(anchor.name);
  const meshForOutline = entry?.meshForOutline || findOutlineMeshForObject(anchor);
  if (!meshForOutline) return false;

  const previousSelectedBuilding = selectedBuildingName;
  selectedBuildingName = entry?.name || anchor.name || candidateName;
  if (!previousSelectedBuilding || normalizeQuery(previousSelectedBuilding) !== normalizeQuery(selectedBuildingName)) {
    hideDirectionsPanel();
  }

  // Clear previous selection visuals
  clearHoverTint();
  clearHoverOutline();
  clearClickOutline();

  setClickOutline(meshForOutline);
  showCard(selectedBuildingName);

  return true;
}

function handleSearchSelect(rawValue, opts = {}) {
  const { autoRoute = false } = opts;
  pendingSearchQuery = rawValue; // remember latest search even if GLB not loaded yet

  if (!loadedModel) return; // model not ready yet

  const q = normalizeQuery(rawValue);
  if (!q) {
    // if user cleared search, hide card + selection
    hideCardAndClear();
    return;
  }

  const candidates = collectSearchCandidates(q);
  if (!candidates.length) return;

  // Prefer rooms/buildings whose parent building has a published route.
  const prioritized = [
    ...candidates.filter((c) => c.hasRoute),
    ...candidates.filter((c) => !c.hasRoute)
  ];

  let selected = null;
  for (const candidate of prioritized) {
    if (selectBuildingByName(candidate.buildingName)) {
      selected = candidate;
      break;
    }
  }
  if (!selected) return;

  if (selected.type === "room" && selected.roomName) {
    setSelectedGuideTarget({
      type: "room",
      buildingName: selected.buildingName,
      roomName: selected.roomName
    });
    hideDirectionsPanel();
    const routeReady = !!getRouteEntryForBuilding(selected.buildingName);
    cardInfo.textContent = routeReady
      ? `Matched ${selected.roomName} in ${getDisplayBuildingName(selected.buildingName)}. Select "Get Directions" to show the route.`
      : `Matched ${selected.roomName} in ${getDisplayBuildingName(selected.buildingName)}. No published route for this building.`;
    if (autoRoute) {
      activateDirectionsForSelectedBuilding();
    }
    return;
    cardInfo.textContent = routeReady
      ? `Matched ${selected.roomName} in ${selected.buildingName}. Select “Get Directions” to show the route.`
      : `Matched ${selected.roomName} in ${selected.buildingName}. No published route for this building.`;
  }

  if (autoRoute) {
    activateDirectionsForSelectedBuilding();
  }
}



// --- Interaction state
let loadedModel = null;

let hoveredMesh = null;
let hoveredOriginalMat = null;
let hoveredTintMat = null;

let clickOutline = null;
let hoverOutline = null;
let selectedBuildingName = null;
let hoveredBuildingName = null;

let routeMesh = null;
let routeArrowMesh = null;
let routeAnimationState = null;
let buildingLabelLayer = null;
let buildingLabels = [];
let buildingEntriesByKey = new Map();
let buildingEntriesByObject = new Map();

const labelBox = new THREE.Box3();
const labelSize = new THREE.Vector3();
const labelCenter = new THREE.Vector3();
const labelScreen = new THREE.Vector3();
const surfaceAlignOrigin = new THREE.Vector3();
const surfaceAlignDown = new THREE.Vector3(0, -1, 0);

const ROUTE_RIBBON_LIFT = 0.35;
const ROUTE_RIBBON_THICKNESS = 1.2;
const ROUTE_ARROW_LIFT = ROUTE_RIBBON_LIFT + 0.04;
const ROUTE_ARROW_LENGTH = 4.2;
const ROUTE_ARROW_WIDTH = 2.3;
const ROUTE_ANIMATION_MAX_DELTA = 0.05;

const routeRibbonMaterial = new THREE.MeshBasicMaterial({
  color: 0xff0000,
  transparent: true,
  opacity: 1.0,
  side: THREE.DoubleSide,
  depthWrite: false,
  depthTest: false,
});

const routeArrowMaterial = new THREE.MeshBasicMaterial({
  color: 0xffffff,
  transparent: true,
  opacity: 0.96,
  side: THREE.DoubleSide,
  depthWrite: false,
  depthTest: false,
});

const frameClock = new THREE.Clock();

function getMaterialArray(material) {
  if (!material) return [];
  return Array.isArray(material) ? material : [material];
}

function disposeReplacedMaterials(currentMaterial, originalMaterial) {
  const originals = new Set(getMaterialArray(originalMaterial));
  for (const mat of getMaterialArray(currentMaterial)) {
    if (!mat || originals.has(mat) || !mat.dispose) continue;
    mat.dispose();
  }
}

function isWaypointName(name) {
  return /^KIOSK_START(?:[._-]?\d+)?$/i.test(String(name || "").trim());
}

function isRoadLikeName(name) {
  const raw = String(name || "").trim();
  const loose = normalizeLoose(raw);
  if (!loose) return false;
  return loose === "ROAD" || /^ROAD\d+$/.test(loose);
}

function isGroundOrHelperName(name) {
  const loose = normalizeLoose(name);
  if (!loose) return true;
  return (
    isRoadLikeName(name) ||
    loose === "GROUND" ||
    loose === "FLOOR" ||
    loose === "TERRAIN" ||
    loose.startsWith("CIRCLE") ||
    loose.includes("STAIR")
  );
}

function hasMeshDescendant(obj) {
  let found = false;
  obj?.traverse?.((child) => {
    if (found) return;
    if (child?.isMesh && child.geometry) found = true;
  });
  return found;
}

function formatBuildingLabel(name) {
  return String(name || "")
    .replace(/[_.|]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function getBuildingLookupKeys(name) {
  const raw = String(name || "").trim();
  if (!raw) return [];

  const keys = new Set();
  const push = (value) => {
    const key = normalizeQuery(value);
    if (key) keys.add(key);
  };

  push(raw);
  push(removeOneNumericSuffix(raw));

  const dotless = raw.replace(/\./g, "");
  push(dotless);
  push(removeOneNumericSuffix(dotless));

  return [...keys];
}

function registerBuildingEntry(entry) {
  if (entry?.object?.uuid) {
    buildingEntriesByObject.set(entry.object.uuid, entry);
  }
  for (const key of getBuildingLookupKeys(entry?.name)) {
    if (!buildingEntriesByKey.has(key)) {
      buildingEntriesByKey.set(key, entry);
    }
  }
}

function getBuildingEntryByName(name) {
  for (const key of getBuildingLookupKeys(name)) {
    const entry = buildingEntriesByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

function clearBuildingLabels() {
  for (const entry of buildingLabels) {
    entry.element?.remove?.();
  }
  buildingLabelLayer?.replaceChildren?.();
  buildingLabels = [];
  buildingEntriesByKey = new Map();
  buildingEntriesByObject = new Map();
}

function isBuildingRootName(name) {
  return !!String(name || "").trim() &&
    !isGenericNodeName(name) &&
    !isWaypointName(name) &&
    !isGroundOrHelperName(name);
}

function findOutlineMeshForObject(obj) {
  if (obj?.isMesh && obj.geometry) return obj;

  let mesh = null;
  obj?.traverse?.((child) => {
    if (mesh) return;
    if (child?.isMesh && child.geometry) mesh = child;
  });
  return mesh;
}

function findTopBuildingRoot(obj, modelRoot) {
  let current = obj;
  while (current && current !== modelRoot) {
    if (current.parent === modelRoot) {
      return isBuildingRootName(current.name) ? current : null;
    }
    current = current.parent;
  }
  return null;
}

function hasRenderableBounds(obj) {
  if (!obj || !hasMeshDescendant(obj)) return false;
  labelBox.setFromObject(obj);
  if (labelBox.isEmpty()) return false;
  labelBox.getSize(labelSize);
  return labelSize.length() > 0.01;
}

function getLabelAnchorPosition(obj, modelSize) {
  labelBox.setFromObject(obj);
  if (labelBox.isEmpty()) return null;

  labelBox.getSize(labelSize);
  labelBox.getCenter(labelCenter);

  const lift = Math.max(labelSize.y * 0.18, modelSize * 0.012, 0.35);
  return new THREE.Vector3(labelCenter.x, labelBox.max.y + lift, labelCenter.z);
}

function rebuildBuildingLabels(model, modelSize) {
  clearBuildingLabels();
  buildingLabelLayer = ensureBuildingLabelLayer();
  if (!model) return;

  for (const root of (model.children || [])) {
    if (!root?.name || !isBuildingRootName(root.name) || !hasRenderableBounds(root)) continue;
    const meshForOutline = findOutlineMeshForObject(root);
    const worldPosition = getLabelAnchorPosition(root, modelSize);
    if (!meshForOutline || !worldPosition) continue;

    const element = document.createElement("div");
    element.className = "map-building-label";
    element.textContent = formatBuildingLabel(getDisplayBuildingName(root.name));
    buildingLabelLayer.appendChild(element);

    const entry = {
      name: root.name,
      object: root,
      meshForOutline,
      worldPosition,
      element
    };

    registerBuildingEntry(entry);
    buildingLabels.push(entry);
  }

  updateBuildingLabels();
}

function findBuildingEntryFromHit(hitObject) {
  if (!loadedModel) return null;
  const root = findTopBuildingRoot(hitObject, loadedModel);
  return root ? (buildingEntriesByObject.get(root.uuid) || null) : null;
}

function updateBuildingLabels() {
  if (!buildingLabels.length || !buildingLabelLayer) return;

  const rect = mapStage.getBoundingClientRect();
  const width = rect.width || 1;
  const height = rect.height || 1;

  const hoveredNorm = normalizeQuery(hoveredBuildingName);
  const selectedNorm = normalizeQuery(selectedBuildingName);

  for (const entry of buildingLabels) {
    labelScreen.copy(entry.worldPosition).project(camera);

    const x = (labelScreen.x * 0.5 + 0.5) * width;
    const y = (-labelScreen.y * 0.5 + 0.5) * height;
    const isVisible =
      labelScreen.z > -1 &&
      labelScreen.z < 1 &&
      x >= -180 &&
      x <= width + 180 &&
      y >= -80 &&
      y <= height + 80;

    entry.element.style.display = isVisible ? "block" : "none";
    if (isVisible) {
      entry.element.style.left = `${x}px`;
      entry.element.style.top = `${y}px`;
    }
    entry.element.classList.toggle("is-hovered", !!hoveredNorm && normalizeQuery(entry.name) === hoveredNorm);
    entry.element.classList.toggle("is-selected", !!selectedNorm && normalizeQuery(entry.name) === selectedNorm);
  }
}

function clearHoverTint() {
  if (hoveredMesh && hoveredOriginalMat) {
    const current = hoveredMesh.material;
    hoveredMesh.material = hoveredOriginalMat;
    disposeReplacedMaterials(current, hoveredOriginalMat);
  }
  hoveredMesh = null;
  hoveredOriginalMat = null;
  hoveredTintMat = null;
}

function applyHoverTint(mesh) {
  clearHoverTint();
  hoveredMesh = mesh;
  hoveredOriginalMat = mesh.material;

  const sourceMats = getMaterialArray(mesh.material);
  const tintedMats = sourceMats.map((m) => {
    if (!m || !m.clone) return m;
    const clone = m.clone();
    if (clone.color) clone.color.set(0xaaaaaa);
    return clone;
  });
  hoveredTintMat = Array.isArray(mesh.material) ? tintedMats : tintedMats[0];
  mesh.material = hoveredTintMat;
}

function clearHoverOutline() {
  if (!hoverOutline) return;
  if (hoverOutline.parent) hoverOutline.parent.remove(hoverOutline);
  hoverOutline.geometry.dispose();
  hoverOutline.material.dispose();
  hoverOutline = null;
}

function setHoverOutline(mesh) {
  clearHoverOutline();
  if (!mesh || !mesh.geometry) return;

  const edges = new THREE.EdgesGeometry(mesh.geometry);
  const mat = new THREE.LineBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.35 });
  hoverOutline = new THREE.LineSegments(edges, mat);
  mesh.add(hoverOutline);
  hoverOutline.raycast = () => null;
}

function clearClickOutline() {
  if (!clickOutline) return;
  if (clickOutline.parent) clickOutline.parent.remove(clickOutline);
  clickOutline.geometry.dispose();
  clickOutline.material.dispose();
  clickOutline = null;
}

function setClickOutline(target) {
  clearClickOutline();
  if (!target) return;

  clickOutline = new THREE.BoxHelper(target, 0x000000);
  scene.add(clickOutline);
  clickOutline.raycast = () => null;
}

function clearRouteOnly() {
  if (routeMesh) {
    scene.remove(routeMesh);
    routeMesh.geometry?.dispose?.();
    routeMesh = null;
  }
  if (routeArrowMesh) {
    scene.remove(routeArrowMesh);
    routeArrowMesh.geometry?.dispose?.();
    routeArrowMesh = null;
  }
  routeAnimationState = null;
}

function resetCardRoomsPanel() {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  cardRoomsWrap.classList.add("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = "Loading rooms...";
  cardRoomsList.replaceChildren();
}

function setCardRoomsLoading(message = "Loading rooms...") {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = message;
  cardRoomsList.replaceChildren();
}

function setCardRoomsMessage(message) {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = message;
  cardRoomsList.replaceChildren();
}

function renderCardRoomsList(rooms) {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsList.replaceChildren();

  if (!Array.isArray(rooms) || !rooms.length) {
    cardRoomsStatus.classList.remove("hidden");
    cardRoomsStatus.textContent = "No rooms found for this building in the published model.";
    return;
  }

  cardRoomsStatus.classList.add("hidden");
  for (const room of rooms) {
    const item = document.createElement("div");
    item.className = "card-room-item";

    const head = document.createElement("div");
    head.className = "card-room-head";

    const name = document.createElement("div");
    name.className = "card-room-name";
    name.textContent = String(room?.name || "Unnamed Room");

    const number = document.createElement("div");
    number.className = "card-room-number";
    number.textContent = String(room?.roomNumber || "").trim() || "-";

    head.appendChild(name);
    head.appendChild(number);
    item.appendChild(head);

    const metaParts = [];
    if (String(room?.floorNumber || "").trim()) metaParts.push(`Floor ${String(room.floorNumber).trim()}`);
    if (String(room?.roomType || "").trim()) metaParts.push(String(room.roomType).trim());
    if (String(room?.description || "").trim()) metaParts.push(String(room.description).trim());

    if (metaParts.length) {
      const meta = document.createElement("div");
      meta.className = "card-room-meta";
      meta.textContent = metaParts.join(" • ");
      item.appendChild(meta);
    }

    cardRoomsList.appendChild(item);
  }
}

async function loadCardBuildingDetails(buildingName) {
  const displayName = getDisplayBuildingName(buildingName) || String(buildingName || "").trim();
  const meta = getDbBuildingMeta(buildingName);
  if (!displayName) {
    resetCardRoomsPanel();
    return;
  }

  const requestSeq = ++cardDetailsRequestSeq;
  const cacheKey = `${currentLiveModelFile}::${meta?.id ?? ""}::${normalizeQuery(displayName)}`;
  if (cardRoomsCache.has(cacheKey)) {
    if (requestSeq !== cardDetailsRequestSeq) return;
    renderCardRoomsList(cardRoomsCache.get(cacheKey));
    return;
  }

  setCardRoomsLoading("Loading rooms...");

  try {
    const url = new URL(BUILDING_DETAILS_ENDPOINT, window.location.href);
    if (currentLiveModelFile) url.searchParams.set("model", currentLiveModelFile);
    if (meta?.id != null) url.searchParams.set("buildingId", String(meta.id));
    url.searchParams.set("name", displayName);

    const res = await fetch(url.toString(), { cache: "no-store" });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
    if (requestSeq !== cardDetailsRequestSeq) return;

    const rooms = Array.isArray(data.rooms) ? data.rooms : [];
    cardRoomsCache.set(cacheKey, rooms);
    renderCardRoomsList(rooms);
  } catch (err) {
    if (requestSeq !== cardDetailsRequestSeq) return;
    setCardRoomsMessage(`Room list unavailable: ${err?.message || err}`);
  }
}

function showCard(buildingName) {
  if (!selectedGuideTarget || normalizeQuery(selectedGuideTarget.buildingName) !== normalizeQuery(buildingName)) {
    setSelectedGuideTarget({
      type: "building",
      buildingName,
      roomName: ""
    });
  }
  const displayName = getDisplayBuildingName(buildingName) || buildingName;
  const meta = getDbBuildingMeta(buildingName);
  const description = String(meta?.description || "").trim();
  const routeAvailable = !!getRouteEntryForBuilding(buildingName);

  infoCard.classList.remove("hidden");
  cardTitle.textContent = displayName;
  cardInfo.textContent = description
    ? `${description} ${routeAvailable ? 'Select "Get Directions" to show the route.' : "No published route for this building."}`
    : (routeAvailable ? 'Select "Get Directions" to show the route.' : "No published route for this building.");
  directionsBtn.disabled = !routeAvailable;
  loadCardBuildingDetails(buildingName).catch(() => {});
  return;

  infoCard.classList.remove("hidden");
  cardTitle.textContent = buildingName;
  const hasRoute = !!getRouteEntry(buildingName);
  cardInfo.textContent = hasRoute
    ? "Select “Get Directions” to show the route."
    : "No published route for this building.";
  directionsBtn.disabled = !hasRoute;
}

function hideCardAndClear() {
  infoCard.classList.add("hidden");
  selectedBuildingName = null;
  hoveredBuildingName = null;
  setSelectedGuideTarget(null);
  directionsBtn.disabled = true;
  clearRouteBtn.disabled = true;
  cardDetailsRequestSeq++;

  clearRouteOnly();
  hideDirectionsPanel();
  clearClickOutline();
  resetCardRoomsPanel();
}

closeBtn.addEventListener("click", (event) => {
  event.preventDefault();
  event.stopPropagation();
  hideCardAndClear();
});

function activateDirectionsForSelectedBuilding() {
  if (!loadedModel || !selectedBuildingName) return;

  const routeEntry = getRouteEntryForBuilding(selectedBuildingName);
  if (!routeEntry) {
    console.warn("No route configured for:", selectedBuildingName);
    hideDirectionsPanel();
    showCard(selectedBuildingName);
    return;
  }

  let points = [];
  if (routeEntry.mode === "points") {
    points = routeEntry.points
      .map(p => Array.isArray(p) ? new THREE.Vector3(p[0], p[1], p[2]) : null)
      .filter(Boolean);
  } else {
    points = resolveRoutePoints(routeEntry.names);
  }

  if (points.length < 2) {
    console.warn("Route points < 2. Check console ROUTE DEBUG MISSING list.");
    cardInfo.textContent = "Route data is incomplete for this building.";
    hideDirectionsPanel();
    return;
  }

  const alignedPoints = alignRoutePointsToModelSurface(points);
  const routeDistance = Number.isFinite(routeEntry.distance) ? routeEntry.distance : measureRouteLength(alignedPoints);
  const started = startAnimatedRoute(alignedPoints, routeDistance);
  if (!started) {
    cardInfo.textContent = "Route data is incomplete for this building.";
    hideDirectionsPanel();
    return;
  }
  showDirectionsPanelForSelection(buildDirectionsTarget(), routeEntry, alignedPoints, routeDistance);
  clearRouteBtn.disabled = false;
}

directionsBtn.addEventListener("click", () => {
  activateDirectionsForSelectedBuilding();
});

clearRouteBtn.addEventListener("click", () => {
  clearRouteOnly();
  hideDirectionsPanel();
  clearRouteBtn.disabled = true;
});

directionsCloseBtn?.addEventListener("click", (event) => {
  event.preventDefault();
  event.stopPropagation();
  clearRouteOnly();
  hideDirectionsPanel();
  clearRouteBtn.disabled = true;
});

function findBestOutlineMesh(hitObject) {
  let o = hitObject;
  while (o) {
    if (o.isMesh && o.geometry) return o;
    o = o.parent;
  }
  return hitObject.isMesh ? hitObject : null;
}

function getObjectByNameFlexible(root, name) {
  if (!root || !name) return null;

  // 1) exact
  let obj = root.getObjectByName(name);
  if (obj) return obj;

  // 2) dotless
  const dotless = name.replace(/\./g, "");
  obj = root.getObjectByName(dotless);
  if (obj) return obj;

  // 3) if it's KIOSK_START### -> try KIOSK_START.###
  const m1 = dotless.match(/^(KIOSK_START)(\d{3})$/);
  if (m1) {
    const dotted = `${m1[1]}.${m1[2]}`;
    obj = root.getObjectByName(dotted);
    if (obj) return obj;
  }

  // 4) if it's KIOSK_START.### -> try KIOSK_START###
  const m2 = name.match(/^(KIOSK_START)\.(\d{3})$/);
  if (m2) {
    const noDot = `${m2[1]}${m2[2]}`;
    obj = root.getObjectByName(noDot);
    if (obj) return obj;
  }

  return null;
}

function resolveRoutePoints(nameList) {
  if (!loadedModel) return [];

  loadedModel.updateMatrixWorld(true);

  const points = [];
  const found = [];
  const missing = [];
  const rows = [];

  for (const nm of nameList) {
    const obj = getObjectByNameFlexible(loadedModel, nm);
    if (!obj) {
      missing.push(nm);
      continue;
    }

    obj.updateMatrixWorld(true);

    const p = new THREE.Vector3();
    obj.getWorldPosition(p);

    points.push(p);
    found.push(obj.name);

    rows.push({
      requested: nm,
      actual: obj.name,
      x: Number(p.x.toFixed(3)),
      y: Number(p.y.toFixed(3)),
      z: Number(p.z.toFixed(3)),
    });
  }

  console.group(`ROUTE DEBUG: ${selectedBuildingName}`);
  console.log("FOUND:", found);
  if (missing.length) console.error("MISSING:", missing);
  console.table(rows);
  console.groupEnd();

  return points;
}

function alignPointToModelSurface(point) {
  const src = point?.clone?.();
  if (!src || !loadedModel || !Number.isFinite(src.x) || !Number.isFinite(src.z)) return src || null;

  const refY = Number.isFinite(src.y) ? src.y : 0;
  surfaceAlignOrigin.set(src.x, refY + 5000, src.z);
  raycaster.set(surfaceAlignOrigin, surfaceAlignDown);

  const hits = raycaster.intersectObjects(loadedModel.children || [], true);
  let y = refY;
  for (const hit of hits) {
    if (hit?.object && hit.object.visible !== false) {
      y = hit.point.y;
      break;
    }
  }
  src.y = y;
  return src;
}

function alignRoutePointsToModelSurface(points) {
  if (!Array.isArray(points) || points.length < 2) return Array.isArray(points) ? points : [];
  const aligned = [];
  for (const point of points) {
    const alignedPoint = alignPointToModelSurface(point);
    if (alignedPoint) aligned.push(alignedPoint);
  }
  return aligned.length >= 2 ? aligned : points;
}

function createRibbonGeometry(points, { lift = ROUTE_RIBBON_LIFT, thickness = ROUTE_RIBBON_THICKNESS } = {}) {
  if (!Array.isArray(points) || points.length < 2) return null;

  const positions = [];
  const indices = [];
  let vi = 0;

  for (let i = 0; i < points.length - 1; i++) {
    const a = points[i].clone();
    const b = points[i + 1].clone();
    a.y += lift;
    b.y += lift;

    const dir = new THREE.Vector3().subVectors(b, a);
    const len = dir.length();
    if (len < 0.0001) continue;
    dir.normalize();

    const perp = new THREE.Vector3(-dir.z, 0, dir.x).normalize().multiplyScalar(thickness / 2);
    const p1 = a.clone().add(perp);
    const p2 = a.clone().sub(perp);
    const p3 = b.clone().sub(perp);
    const p4 = b.clone().add(perp);

    positions.push(
      p1.x, p1.y, p1.z,
      p2.x, p2.y, p2.z,
      p3.x, p3.y, p3.z,
      p4.x, p4.y, p4.z
    );

    indices.push(
      vi + 0, vi + 1, vi + 2,
      vi + 0, vi + 2, vi + 3
    );

    vi += 4;
  }

  if (!positions.length || !indices.length) return null;

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
  geometry.setIndex(indices);
  geometry.computeVertexNormals();
  return geometry;
}

function setRouteRibbonGeometry(geometry) {
  if (!geometry) {
    if (routeMesh) {
      scene.remove(routeMesh);
      routeMesh.geometry?.dispose?.();
      routeMesh = null;
    }
    return;
  }

  if (!routeMesh) {
    routeMesh = new THREE.Mesh(geometry, routeRibbonMaterial);
    routeMesh.renderOrder = 999;
    routeMesh.frustumCulled = false;
    scene.add(routeMesh);
    return;
  }

  routeMesh.geometry?.dispose?.();
  routeMesh.geometry = geometry;
  if (!routeMesh.parent) scene.add(routeMesh);
}

function setRouteArrowGeometry(geometry) {
  if (!geometry) {
    if (routeArrowMesh) {
      scene.remove(routeArrowMesh);
      routeArrowMesh.geometry?.dispose?.();
      routeArrowMesh = null;
    }
    return;
  }

  if (!routeArrowMesh) {
    routeArrowMesh = new THREE.Mesh(geometry, routeArrowMaterial);
    routeArrowMesh.renderOrder = 1000;
    routeArrowMesh.frustumCulled = false;
    scene.add(routeArrowMesh);
    return;
  }

  routeArrowMesh.geometry?.dispose?.();
  routeArrowMesh.geometry = geometry;
  if (!routeArrowMesh.parent) scene.add(routeArrowMesh);
}

function measureRouteLength(points) {
  if (!Array.isArray(points) || points.length < 2) return 0;
  let total = 0;
  for (let i = 0; i < points.length - 1; i++) {
    const a = points[i];
    const b = points[i + 1];
    if (!a || !b) continue;
    total += Math.hypot(b.x - a.x, b.z - a.z);
  }
  return total;
}

function getAnimatedRouteSpeed(routeDistance) {
  const safeDistance = Math.max(1, Number(routeDistance) || 0);
  const duration = THREE.MathUtils.clamp(1.8 + (Math.sqrt(safeDistance) * 0.22), 2.6, 7.5);
  return safeDistance / duration;
}

function createRouteAnimationState(points, routeDistance) {
  const cleanPoints = (points || [])
    .filter((point) => point && Number.isFinite(point.x) && Number.isFinite(point.z))
    .map((point) => point.clone());
  if (cleanPoints.length < 2) return null;

  const segmentLengths = [];
  const cumulativeLengths = [0];
  let totalLength = 0;

  for (let i = 0; i < cleanPoints.length - 1; i++) {
    const a = cleanPoints[i];
    const b = cleanPoints[i + 1];
    const length = Math.hypot(b.x - a.x, b.z - a.z);
    segmentLengths.push(length);
    totalLength += length;
    cumulativeLengths.push(totalLength);
  }
  if (!(totalLength > 0.001)) return null;

  return {
    points: cleanPoints,
    segmentLengths,
    cumulativeLengths,
    totalLength,
    revealLength: 0,
    speed: getAnimatedRouteSpeed(Number.isFinite(routeDistance) ? routeDistance : totalLength),
  };
}

function getVisibleRoutePoints(state, revealLength) {
  if (!state?.points?.length || state.points.length < 2) return [];

  const visible = [state.points[0].clone()];
  let remaining = Math.max(0, Math.min(revealLength, state.totalLength));

  for (let i = 0; i < state.points.length - 1; i++) {
    const a = state.points[i];
    const b = state.points[i + 1];
    const segmentLength = state.segmentLengths[i];
    if (!(segmentLength > 0.0001)) continue;

    if (remaining >= segmentLength) {
      visible.push(b.clone());
      remaining -= segmentLength;
      continue;
    }

    if (remaining > 0) {
      const t = remaining / segmentLength;
      visible.push(a.clone().lerp(b, t));
    }
    break;
  }

  return visible;
}

function sampleRouteAtDistance(state, distance) {
  if (!state?.points?.length) return null;
  const safeDistance = Math.max(0, Math.min(distance, state.totalLength));

  for (let i = 0; i < state.segmentLengths.length; i++) {
    const start = state.cumulativeLengths[i];
    const end = state.cumulativeLengths[i + 1];
    const segmentLength = state.segmentLengths[i];
    if (!(segmentLength > 0.0001)) continue;
    if (safeDistance > end && i < state.segmentLengths.length - 1) continue;

    const a = state.points[i];
    const b = state.points[i + 1];
    const t = THREE.MathUtils.clamp((safeDistance - start) / segmentLength, 0, 1);
    const point = a.clone().lerp(b, t);
    const tangent = new THREE.Vector3(b.x - a.x, 0, b.z - a.z);
    if (tangent.lengthSq() < 1e-6) tangent.set(1, 0, 0);
    else tangent.normalize();
    return { point, tangent };
  }

  const lastPoint = state.points[state.points.length - 1].clone();
  const prevPoint = state.points[state.points.length - 2] || lastPoint;
  const tangent = new THREE.Vector3(lastPoint.x - prevPoint.x, 0, lastPoint.z - prevPoint.z);
  if (tangent.lengthSq() < 1e-6) tangent.set(1, 0, 0);
  else tangent.normalize();
  return { point: lastPoint, tangent };
}

function createRouteArrowGeometry(state) {
  if (!state) return null;

  const revealLength = Math.max(0, Math.min(state.revealLength, state.totalLength));
  const placements = [];
  const spacing = THREE.MathUtils.clamp(state.totalLength / 6, 10, 18);
  const startOffset = Math.min(8, state.totalLength * 0.24);
  const minTail = ROUTE_ARROW_LENGTH * 0.35;

  for (let distance = startOffset; distance <= revealLength - minTail; distance += spacing) {
    placements.push(distance);
  }

  const headDistance = revealLength - (ROUTE_ARROW_LENGTH * 0.55);
  if (headDistance > ROUTE_ARROW_LENGTH * 0.45) {
    const lastPlacement = placements.length ? placements[placements.length - 1] : -Infinity;
    if (headDistance - lastPlacement > spacing * 0.45) {
      placements.push(headDistance);
    }
  }
  if (!placements.length) return null;

  const positions = [];
  const indices = [];
  let vi = 0;

  for (const distance of placements) {
    const sample = sampleRouteAtDistance(state, distance);
    if (!sample) continue;

    const forward = sample.tangent.clone();
    const side = new THREE.Vector3(-forward.z, 0, forward.x).normalize();
    const center = sample.point.clone();
    center.y += ROUTE_ARROW_LIFT;

    const tip = center.clone().add(forward.clone().multiplyScalar(ROUTE_ARROW_LENGTH * 0.5));
    const baseCenter = center.clone().add(forward.clone().multiplyScalar(-ROUTE_ARROW_LENGTH * 0.5));
    const left = baseCenter.clone().add(side.clone().multiplyScalar(ROUTE_ARROW_WIDTH * 0.5));
    const right = baseCenter.clone().add(side.clone().multiplyScalar(-ROUTE_ARROW_WIDTH * 0.5));

    positions.push(
      tip.x, tip.y, tip.z,
      left.x, left.y, left.z,
      right.x, right.y, right.z
    );
    indices.push(vi, vi + 1, vi + 2);
    vi += 3;
  }

  if (!positions.length) return null;

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
  geometry.setIndex(indices);
  geometry.computeVertexNormals();
  return geometry;
}

function renderAnimatedRoute(state) {
  const visiblePoints = getVisibleRoutePoints(state, state?.revealLength || 0);
  setRouteRibbonGeometry(createRibbonGeometry(visiblePoints));
  setRouteArrowGeometry(createRouteArrowGeometry(state));
}

function startAnimatedRoute(points, routeDistance) {
  clearRouteOnly();
  const state = createRouteAnimationState(points, routeDistance);
  if (!state) return false;
  routeAnimationState = state;
  renderAnimatedRoute(routeAnimationState);
  return true;
}

function updateRouteAnimation(deltaSeconds) {
  if (!routeAnimationState) return;
  if (routeAnimationState.revealLength >= routeAnimationState.totalLength) return;

  const clampedDelta = Math.max(0, Math.min(Number(deltaSeconds) || 0, ROUTE_ANIMATION_MAX_DELTA));
  if (!(clampedDelta > 0)) return;

  routeAnimationState.revealLength = Math.min(
    routeAnimationState.totalLength,
    routeAnimationState.revealLength + (routeAnimationState.speed * clampedDelta)
  );
  renderAnimatedRoute(routeAnimationState);
}

function drawRibbonPath(points) {
  clearRouteOnly();
  const state = createRouteAnimationState(points, measureRouteLength(points));
  if (!state) return false;
  state.revealLength = state.totalLength;
  renderAnimatedRoute(state);
  return true;
}

function updateKioskMarker() {
  if (!kioskMarkerEl && !publishedKioskPointAligned && !publishedKioskPoint) return;

  const marker = ensureKioskMarker();
  const point = publishedKioskPointAligned || publishedKioskPoint;
  if (!loadedModel || !point || !activeRoutesByKey.size) {
    marker.style.display = "none";
    return;
  }

  const rect = mapStage.getBoundingClientRect();
  const width = rect.width || 1;
  const height = rect.height || 1;
  kioskMarkerScreen.copy(point).project(camera);

  const x = (kioskMarkerScreen.x * 0.5 + 0.5) * width;
  const y = (-kioskMarkerScreen.y * 0.5 + 0.5) * height;
  const isVisible =
    kioskMarkerScreen.z > -1 &&
    kioskMarkerScreen.z < 1 &&
    x >= -120 &&
    x <= width + 120 &&
    y >= -120 &&
    y <= height + 120;

  marker.style.display = isVisible ? "block" : "none";
  if (isVisible) {
    marker.style.left = `${x}px`;
    marker.style.top = `${y}px`;
  }
}

// --- Live map boot / reload
const loader = new GLTFLoader();

function addCacheBuster(url, v) {
  if (!url) return url;
  const token = encodeURIComponent(String(v || Date.now()));
  const sep = String(url).includes("?") ? "&" : "?";
  return `${url}${sep}v=${token}`;
}

function disposeHierarchy(root) {
  if (!root) return;
  const disposedGeometries = new Set();
  const disposedMaterials = new Set();
  const disposedTextures = new Set();

  root.traverse((node) => {
    if (!(node.isMesh || node.isLine || node.isPoints)) return;

    if (node.geometry && node.geometry.dispose && !disposedGeometries.has(node.geometry)) {
      disposedGeometries.add(node.geometry);
      node.geometry.dispose();
    }

    for (const mat of getMaterialArray(node.material)) {
      if (!mat || disposedMaterials.has(mat)) continue;
      disposedMaterials.add(mat);

      for (const key of Object.keys(mat)) {
        const tex = mat[key];
        if (tex && tex.isTexture && tex.dispose && !disposedTextures.has(tex)) {
          disposedTextures.add(tex);
          tex.dispose();
        }
      }

      if (mat.dispose) mat.dispose();
    }
  });
}

function applyLoadedModel(newModel) {
  const previousModel = loadedModel;

  clearRouteOnly();
  clearClickOutline();
  clearHoverOutline();
  clearHoverTint();
  clearBuildingLabels();
  selectedBuildingName = null;
  hoveredBuildingName = null;
  setSelectedGuideTarget(null);
  infoCard?.classList?.add("hidden");
  hideDirectionsPanel();
  clearRouteBtn.disabled = true;

  if (previousModel) {
    scene.remove(previousModel);
    disposeHierarchy(previousModel);
  }

  loadedModel = newModel;
  scene.add(loadedModel);
  loadedModel.updateMatrixWorld(true);
  publishedKioskPointAligned = publishedKioskPoint ? alignPointToModelSurface(publishedKioskPoint) : null;

  // 1. Calculate Bounding Box
  mapBounds.setFromObject(loadedModel);
  mapBounds.getSize(mapWorldSize);
  mapBounds.getCenter(mapWorldCenter);
  mapWorldDiagonal = Math.max(mapWorldSize.length(), 1);

  // 2. Reset both view states against the current model bounds
  initializePerspectiveViewState(mapWorldCenter);
  initializeOrthographicViewState(mapWorldCenter);
  resizeToContainer();
  setViewMode(currentViewMode, { reset: true });

  directionsBtn.disabled = true;
  clearRouteBtn.disabled = true;
  rebuildBuildingLabels(loadedModel, mapWorldDiagonal);

  if (typeof pendingSearchQuery !== "undefined" && pendingSearchQuery.trim()) {
    handleSearchSelect(pendingSearchQuery);
  }

  console.log("GLB loaded. Size:", mapWorldDiagonal, "Center:", mapWorldCenter);
}

function loadModel(modelUrl) {
  return new Promise((resolve, reject) => {
    loader.load(
      modelUrl,
      (gltf) => {
        applyLoadedModel(gltf.scene);
        resolve();
      },
      undefined,
      (error) => reject(error)
    );
  });
}

async function fetchLiveMapPayload() {
  const url = addCacheBuster(LIVE_MAP_ENDPOINT, Date.now());
  const res = await fetch(url, { cache: "no-store" });
  if (!res.ok) throw new Error(`Live map fetch failed (${res.status})`);
  const payload = await res.json();
  if (!payload || payload.ok === false) throw new Error("Live map payload invalid");
  return payload;
}

function applyPublishedRoutes(payload) {
  const ok = setPublishedRoutes(payload?.routes || null);
  setPublishedGuides(payload?.guides || null);
  if (!ok) {
    rebuildRouteCatalog([]);
    setPublishedKioskPoint(null);
  }
}

async function loadLiveMap(payload, { forceReloadModel = false } = {}) {
  const modelUrl = String(payload?.modelUrl || FALLBACK_MODEL_URL);
  const version = String(payload?.version || "");
  const shouldReload = forceReloadModel || !loadedModel || version !== liveVersion;
  currentLiveModelFile = String(payload?.modelFile || currentLiveModelFile || "");

  applyPublishedRoutes(payload);

  if (shouldReload) {
    await loadModel(addCacheBuster(modelUrl, version || Date.now()));
  }

  liveVersion = version || liveVersion;
  if (payload?.publishedAt) {
    console.log("Live map version:", liveVersion, "publishedAt:", payload.publishedAt);
  }

  // Refresh searchable rooms/buildings from DB when map payload is loaded.
  loadSearchCatalog(currentLiveModelFile).catch(() => {});
}

async function bootLiveMap() {
  try {
    const payload = await fetchLiveMapPayload();
    await loadLiveMap(payload, { forceReloadModel: true });
  } catch (err) {
    console.warn("Live map unavailable, using fallback:", err);
    rebuildRouteCatalog([]);
    setPublishedGuides(null);
    setPublishedKioskPoint(null);
    currentLiveModelFile = "";
    await loadModel(addCacheBuster(FALLBACK_MODEL_URL, Date.now()));
    loadSearchCatalog("").catch(() => {});
  }
}

async function checkLiveMapUpdates() {
  if (livePollBusy) return;
  livePollBusy = true;
  try {
    const payload = await fetchLiveMapPayload();
    const nextVersion = String(payload?.version || "");
    if (!nextVersion || nextVersion !== liveVersion) {
      await loadLiveMap(payload, { forceReloadModel: true });
    } else {
      currentLiveModelFile = String(payload?.modelFile || currentLiveModelFile || "");
      applyPublishedRoutes(payload);
      loadSearchCatalog(currentLiveModelFile).catch(() => {});
    }
  } catch (_) {
    // Keep current map/routes when offline or temporarily unavailable.
  } finally {
    livePollBusy = false;
  }
}

bootLiveMap().catch((error) => {
  console.error("LIVE MAP BOOT ERROR:", error);
  alert("Map failed to load.");
});

if (!livePollTimer) {
  livePollTimer = setInterval(checkLiveMapUpdates, LIVE_POLL_INTERVAL_MS);
}


function pickSceneSelection(event) {
  if (!loadedModel) return null;
  setMouseFromEvent(event);
  raycaster.setFromCamera(mouse, camera);
  const intersects = raycaster.intersectObjects(loadedModel.children, true);
  if (intersects.length === 0) return null;
  const hit = intersects[0].object;
  const buildingEntry = findBuildingEntryFromHit(hit);
  if (!buildingEntry) return null;
  return { hit, buildingEntry };
}

function isInteractiveOverlayClick(event) {
  return !!event.target?.closest?.("#info-card, #directions-panel, #map-view-controls, .topbar, .bottom-nav");
}

function handleSceneSelection(event) {
  if (isInteractiveOverlayClick(event)) return;
  if (!loadedModel || !isEventOnCanvas(event)) return;

  const selection = pickSceneSelection(event);
  if (!selection) {
    hideCardAndClear();
    return;
  }

  const { hit, buildingEntry } = selection;
  const previousSelectedBuilding = selectedBuildingName;
  selectedBuildingName = buildingEntry.name;
  setSelectedGuideTarget({
    type: "building",
    buildingName: buildingEntry.name,
    roomName: ""
  });
  if (!previousSelectedBuilding || normalizeQuery(previousSelectedBuilding) !== normalizeQuery(selectedBuildingName)) {
    hideDirectionsPanel();
  }

  const outlineTarget = buildingEntry.object || hit;
  if (outlineTarget) setClickOutline(outlineTarget);

  showCard(buildingEntry.name);
}

canvas.addEventListener("pointerdown", (event) => {
  if (!isTouchLikePointer(event)) return;
  isSceneHoverActive = false;
  hoveredBuildingName = null;
  clearHoverTint();
  clearHoverOutline();
  trackTouchPointer(event);
  if (touchSceneTap) touchSceneTap.hadMultiTouch = touchSceneTap.hadMultiTouch || activeTouchPointers.size > 1;
  touchSceneTap = {
    pointerId: event.pointerId,
    startX: event.clientX,
    startY: event.clientY,
    moved: false,
    hadMultiTouch: activeTouchPointers.size > 1
  };
}, true);

canvas.addEventListener("pointermove", (event) => {
  if (!isTouchLikePointer(event)) return;
  trackTouchPointer(event);
  if (touchSceneTap && touchSceneTap.pointerId === event.pointerId) {
    const dx = Math.abs(event.clientX - touchSceneTap.startX);
    const dy = Math.abs(event.clientY - touchSceneTap.startY);
    if (dx > TOUCH_TAP_SLOP_PX || dy > TOUCH_TAP_SLOP_PX) touchSceneTap.moved = true;
  }
  if (touchSceneTap && activeTouchPointers.size > 1) touchSceneTap.hadMultiTouch = true;
  updateTouchZoom();
}, true);

window.addEventListener("pointerup", (event) => {
  if (!isTouchLikePointer(event)) return;
  const tap = touchSceneTap && touchSceneTap.pointerId === event.pointerId ? touchSceneTap : null;
  releaseTouchPointer(event.pointerId);
  if (!tap) return;
  touchSceneTap = null;
  if (tap.hadMultiTouch || tap.moved || !isEventOnCanvas(event)) return;
  suppressSceneClickUntil = performance.now() + TOUCH_CLICK_SUPPRESS_MS;
  handleSceneSelection(event);
}, true);

window.addEventListener("pointercancel", (event) => {
  if (!isTouchLikePointer(event)) return;
  releaseTouchPointer(event.pointerId);
  if (touchSceneTap && touchSceneTap.pointerId === event.pointerId) touchSceneTap = null;
}, true);

// --- Click selection
window.addEventListener("click", (event) => {
  if (performance.now() < suppressSceneClickUntil) return;
  handleSceneSelection(event);
});

// --- Hover
function animate() {
  requestAnimationFrame(animate);
  const deltaSeconds = frameClock.getDelta();

  if (loadedModel && isSceneHoverActive) {
    raycaster.setFromCamera(mouse, camera);
    const intersects = raycaster.intersectObjects(loadedModel.children, true);

    if (intersects.length > 0) {
      const hit = intersects[0].object;
      const buildingEntry = findBuildingEntryFromHit(hit);
      const buildingName = buildingEntry?.name || null;

      if (buildingName) {
        hoveredBuildingName = buildingName;
        // Hover follows the exact child mesh under the cursor.
        // Building-root resolution is still used only to know which building is being hovered.
        const mesh = findBestOutlineMesh(hit);
        if (mesh && hoveredMesh !== mesh) {
          applyHoverTint(mesh);
          setHoverOutline(mesh);
        }
      } else {
        hoveredBuildingName = null;
        clearHoverTint();
        clearHoverOutline();
      }
    } else {
      hoveredBuildingName = null;
      clearHoverTint();
      clearHoverOutline();
    }
  } else if (hoveredBuildingName) {
    hoveredBuildingName = null;
    clearHoverTint();
    clearHoverOutline();
  }

  updateRouteAnimation(deltaSeconds);
  controls.update();
  updateBuildingLabels();
  updateKioskMarker();
  renderer.render(scene, camera);
}

// --- CAMERA DEBUG (DEVTOOLS)
window.__camDebug = {
  get camera() { return camera; },
  get controls() { return controls; },
  perspectiveCamera,
  orthographicCamera
};

function logCameraState(tag = "") {
  const camPos = camera.position;
  const target = controls.target;
  const distance = camPos.distanceTo(target);
  const isOrtho = !!camera.isOrthographicCamera;
  const zoomValue = isOrtho
    ? Number(camera.zoom || 1).toFixed(2)
    : Number(camera.fov || DEFAULT_PERSPECTIVE_FOV).toFixed(2);

  console.log(
    `%c[CAMERA ${tag}]`,
    "color:#00aaff;font-weight:bold",
    {
      pos: { x: camPos.x.toFixed(3), y: camPos.y.toFixed(3), z: camPos.z.toFixed(3) },
      target: { x: target.x.toFixed(3), y: target.y.toFixed(3), z: target.z.toFixed(3) },
      distance: distance.toFixed(3),
      mode: currentViewMode,
      zoom: zoomValue,
      minDistance: controls.minDistance,
      maxDistance: controls.maxDistance,
    }
  );
}

animate();
