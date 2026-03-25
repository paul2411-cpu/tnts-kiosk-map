// js/map3d.js
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { buildGuideKey, buildGuideStepsFromPoints } from "./guide-utils.js";
import { splitEntityPrefix } from "./map-entity-utils.js";

const mapStage = document.getElementById("map-stage");
if (!mapStage) throw new Error("Missing #map-stage");

const infoCard = document.getElementById("info-card");
const cardTitle = document.getElementById("card-title");
const cardInfo = document.getElementById("card-info");
const cardRoomsWrap = document.getElementById("card-rooms-wrap");
const cardRoomsTitle = infoCard?.querySelector(".card-rooms-title") || null;
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
const eventFocusCard = document.getElementById("event-focus-card");
const eventFocusSchedule = document.getElementById("event-focus-schedule");
const eventFocusHealth = document.getElementById("event-focus-health");
const eventFocusTitle = document.getElementById("event-focus-title");
const eventFocusDate = document.getElementById("event-focus-date");
const eventFocusLocation = document.getElementById("event-focus-location");
const eventFocusCopy = document.getElementById("event-focus-copy");
const eventFocusMessage = document.getElementById("event-focus-message");
const eventFocusCloseBtn = document.getElementById("event-focus-close-btn");
const eventFocusMapBtn = document.getElementById("event-focus-map-btn");
const eventFocusRouteBtn = document.getElementById("event-focus-route-btn");
const pageUrl = new URL(window.location.href);
const requestedEventId = Number.parseInt(pageUrl.searchParams.get("event") || "", 10) || 0;
const requestedEventAutoRoute = ["1", "true", "yes"].includes(
  String(pageUrl.searchParams.get("autoroute") || "").toLowerCase()
);
const requestedDestinationFromSession = String(
  sessionStorage.getItem("tnts:jumpToDestination")
  || sessionStorage.getItem("tnts:jumpToBuilding")
  || ""
).trim();
const requestedDestination = String(pageUrl.searchParams.get("destination") || requestedDestinationFromSession).trim();
let pendingRequestedDestination = requestedDestination;

function clearEventRequestParamsFromUrl() {
  try {
    const nextUrl = new URL(window.location.href);
    let changed = false;
    if (nextUrl.searchParams.has("event")) {
      nextUrl.searchParams.delete("event");
      changed = true;
    }
    if (nextUrl.searchParams.has("autoroute")) {
      nextUrl.searchParams.delete("autoroute");
      changed = true;
    }
    if (!changed) return;
    const nextHref = `${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`;
    window.history.replaceState(window.history.state, "", nextHref);
  } catch (_) {}
}


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
  if (window.TNTSOnScreenKeyboard) return;
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

const publishedRoadRoot = new THREE.Group();
publishedRoadRoot.name = "publishedRoadRoot";
publishedRoadRoot.visible = false;
scene.add(publishedRoadRoot);

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
let eventAreaMarkerGroup = null;

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

function setEventPillState(element, label, variantClass) {
  if (!element) return;
  element.className = "event-focus-card__pill";
  if (!label) {
    element.classList.add("hidden");
    element.textContent = "";
    return;
  }
  if (variantClass) element.classList.add(variantClass);
  element.classList.remove("hidden");
  element.textContent = label;
}

function showEventFocusCard() {
  eventFocusCard?.classList.remove("hidden");
}

function hideEventFocusCard() {
  eventFocusCard?.classList.add("hidden");
}

function renderEventFocusPayload(payload) {
  if (!eventFocusCard || !payload) return;

  setEventPillState(
    eventFocusSchedule,
    String(payload.scheduleLabel || "").trim(),
    payload.schedule ? `schedule-${payload.schedule}` : ""
  );
  setEventPillState(
    eventFocusHealth,
    String(payload.healthLabel || "").trim(),
    payload.health ? `health-${payload.health}` : ""
  );

  if (eventFocusTitle) eventFocusTitle.textContent = payload.title || "Event";
  if (eventFocusDate) eventFocusDate.textContent = payload.dateLabel || "Date TBA";
  if (eventFocusLocation) eventFocusLocation.textContent = payload.locationLabel || "Location to be announced";
  if (eventFocusCopy) {
    eventFocusCopy.textContent = payload.description || "No additional event details were provided.";
  }
  if (eventFocusMessage) {
    eventFocusMessage.textContent = payload.healthMessage || "This event does not have a map action right now.";
  }
  if (eventFocusMapBtn) {
    eventFocusMapBtn.classList.toggle("hidden", !payload.canMap);
  }
  if (eventFocusRouteBtn) {
    eventFocusRouteBtn.disabled = !payload.canRoute;
    eventFocusRouteBtn.classList.toggle("hidden", !payload.canRoute);
  }

  showEventFocusCard();
}

function showEventNoticeCard(title, message) {
  renderEventFocusPayload({
    title: title || "Event unavailable",
    description: "",
    dateLabel: "",
    locationLabel: "Map preview",
    health: "broken",
    healthLabel: "Unavailable",
    healthMessage: message || "The requested event could not be loaded.",
    canMap: false,
    canRoute: false,
    schedule: "",
    scheduleLabel: ""
  });
  if (eventFocusMapBtn) eventFocusMapBtn.classList.add("hidden");
  if (eventFocusRouteBtn) eventFocusRouteBtn.classList.add("hidden");
}

function clearEventAreaHighlight() {
  if (eventAreaMarkerGroup) {
    eventAreaMarkerGroup.visible = false;
  }
}

function ensureEventAreaHighlight() {
  if (eventAreaMarkerGroup) return eventAreaMarkerGroup;

  const ring = new THREE.Mesh(
    new THREE.RingGeometry(6.5, 8, 72),
    new THREE.MeshBasicMaterial({
      color: 0xb42318,
      transparent: true,
      opacity: 0.72,
      side: THREE.DoubleSide,
      depthWrite: false,
    })
  );
  ring.rotation.x = -Math.PI / 2;

  const core = new THREE.Mesh(
    new THREE.SphereGeometry(1.1, 24, 16),
    new THREE.MeshStandardMaterial({
      color: 0xdc2626,
      emissive: 0x7f1d1d,
      emissiveIntensity: 0.44,
    })
  );
  core.position.y = 1.2;

  const stem = new THREE.Mesh(
    new THREE.CylinderGeometry(0.08, 0.08, 2.4, 16),
    new THREE.MeshStandardMaterial({ color: 0x111827 })
  );
  stem.position.y = 1.2;

  eventAreaMarkerGroup = new THREE.Group();
  eventAreaMarkerGroup.visible = false;
  eventAreaMarkerGroup.add(ring, core, stem);
  scene.add(eventAreaMarkerGroup);
  return eventAreaMarkerGroup;
}

function renderEventAreaHighlight(point, radius = 8) {
  if (!point) {
    clearEventAreaHighlight();
    return;
  }

  const safeRadius = Math.max(2, Number(radius) || 8);
  const marker = ensureEventAreaHighlight();
  marker.visible = true;
  marker.position.copy(point);

  const ring = marker.children[0];
  if (ring?.geometry) ring.geometry.dispose();
  ring.geometry = new THREE.RingGeometry(safeRadius * 0.84, safeRadius, 72);
  ring.rotation.x = -Math.PI / 2;
}

function updateEventAreaHighlightAnimation(timeMs) {
  if (!eventAreaMarkerGroup?.visible) return;
  const ring = eventAreaMarkerGroup.children[0];
  if (!ring) return;
  const pulse = 1 + (Math.sin(timeMs / 340) * 0.05);
  ring.scale.setScalar(pulse);
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
      // OrbitControls only supports TWO as DOLLY_PAN or DOLLY_ROTATE.
      // With enableZoom disabled, DOLLY_PAN still gives us two-finger pan.
      TWO: THREE.TOUCH.DOLLY_PAN,
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
      // Keep two-finger movement in the supported DOLLY_PAN mode so touch panning works.
      TWO: THREE.TOUCH.DOLLY_PAN,
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

const eventFocusBounds = new THREE.Box3();
const eventFocusCenter = new THREE.Vector3();
const eventFocusSize = new THREE.Vector3();

function syncCameraToCurrentViewState() {
  if (!controls) return;

  if (currentViewMode === VIEW_MODE_2D) {
    orthographicCamera.position.set(
      orthographicViewState.target.x,
      orthographicViewState.target.y + getOrthoCameraHeightOffset(),
      orthographicViewState.target.z
    );
    controls.object = orthographicCamera;
    controls.target.copy(orthographicViewState.target);
    orthographicCamera.updateProjectionMatrix();
  } else {
    perspectiveCamera.position.copy(perspectiveViewState.position);
    controls.object = perspectiveCamera;
    controls.target.copy(perspectiveViewState.target);
    perspectiveCamera.updateProjectionMatrix();
  }

  controls.update();
  syncZoomUi();
}

function focusActiveViewOnPoint(point, { distanceScale = 0.2 } = {}) {
  if (!point) return;

  if (currentViewMode === VIEW_MODE_2D) {
    orthographicViewState.target.copy(point);
    syncCameraToCurrentViewState();
    return;
  }

  const distance = Math.max(mapWorldDiagonal * distanceScale, 14);
  perspectiveViewState.target.copy(point);
  perspectiveViewState.position
    .copy(point)
    .add(DEFAULT_VIEW_DIRECTION.clone().multiplyScalar(distance));
  syncCameraToCurrentViewState();
}

function focusActiveViewOnObject(object3D) {
  if (!object3D) return;
  eventFocusBounds.setFromObject(object3D);
  if (eventFocusBounds.isEmpty()) return;

  eventFocusBounds.getCenter(eventFocusCenter);
  eventFocusBounds.getSize(eventFocusSize);
  const diagonal = Math.max(eventFocusSize.length(), 1);
  focusActiveViewOnPoint(eventFocusCenter, {
    distanceScale: Math.max(0.14, Math.min((diagonal / Math.max(mapWorldDiagonal, 1)) * 1.8, 0.3))
  });
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
// Custom wheel gesture handling
// ==========================
const WHEEL_ZOOM_FACTOR = 0.06;
const TOUCH_TAP_SLOP_PX = 12;
const TOUCH_CLICK_SUPPRESS_MS = 450;
const WHEEL_MOUSE_STEP_THRESHOLD = 72;
const wheelPanOffset = new THREE.Vector3();
const wheelPanAxis = new THREE.Vector3();
const wheelPanAxisAlt = new THREE.Vector3();

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

function isLikelyMouseWheelEvent(event) {
  const absDeltaX = Math.abs(event.deltaX);
  const absDeltaY = Math.abs(event.deltaY);
  if (event.deltaMode === 1 || event.deltaMode === 2) return true;
  return absDeltaX === 0 && absDeltaY >= WHEEL_MOUSE_STEP_THRESHOLD && Number.isInteger(absDeltaY);
}

function shouldPanFromWheel(event) {
  if (event.ctrlKey || event.metaKey) return false;
  if (Math.abs(event.deltaX) > 0) return true;
  return !isLikelyMouseWheelEvent(event);
}

function panActiveViewByWheel(deltaX, deltaY) {
  if (!controls || !camera) return;

  const element = renderer.domElement;
  const width = Math.max(1, element.clientWidth || 1);
  const height = Math.max(1, element.clientHeight || 1);
  const panSpeed = Number(controls.panSpeed) || 1;

  wheelPanOffset.set(0, 0, 0);

  const panLeft = (distance) => {
    wheelPanAxis.setFromMatrixColumn(camera.matrix, 0);
    wheelPanAxis.multiplyScalar(-distance);
    wheelPanOffset.add(wheelPanAxis);
  };

  const panUp = (distance) => {
    if (controls.screenSpacePanning) {
      wheelPanAxis.setFromMatrixColumn(camera.matrix, 1);
    } else {
      wheelPanAxis.setFromMatrixColumn(camera.matrix, 0);
      wheelPanAxisAlt.crossVectors(camera.up, wheelPanAxis);
      wheelPanAxis.copy(wheelPanAxisAlt);
    }
    wheelPanAxis.multiplyScalar(distance);
    wheelPanOffset.add(wheelPanAxis);
  };

  if (camera.isPerspectiveCamera) {
    const targetDistance =
      camera.position.distanceTo(controls.target) * Math.tan((camera.fov * Math.PI / 180) / 2);
    panLeft((2 * deltaX * targetDistance / height) * panSpeed);
    panUp((2 * deltaY * targetDistance / height) * panSpeed);
  } else if (camera.isOrthographicCamera) {
    panLeft((deltaX * (camera.right - camera.left) / camera.zoom / width) * panSpeed);
    panUp((deltaY * (camera.top - camera.bottom) / camera.zoom / height) * panSpeed);
  } else {
    return;
  }

  controls.target.add(wheelPanOffset);
  camera.position.add(wheelPanOffset);
  camera.updateMatrixWorld();

  if (currentViewMode === VIEW_MODE_2D) {
    orthographicViewState.target.copy(controls.target);
  } else {
    perspectiveViewState.target.copy(controls.target);
    perspectiveViewState.position.copy(perspectiveCamera.position);
  }

  controls.update();
}

function onWheelZoom(e) {
  e.preventDefault();
  e.stopPropagation();

  if (shouldPanFromWheel(e)) {
    panActiveViewByWheel(e.deltaX, e.deltaY);
    logCameraState("WHEEL_PAN");
    return;
  }

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
const EVENT_DETAILS_ENDPOINT = "../api/event_details.php";
const FALLBACK_MODEL_URL = "../models/tnts_navigation.glb";
const LIVE_POLL_INTERVAL_MS = 30000;

let activeRoutesByKey = new Map(); // normalized name -> { name, mode, names|points, distance? }
let activeGuidesByKey = new Map(); // guide key -> published guide entry
let routeNamesForSearch = [];      // display names
let dbBuildingNamesForSearch = []; // DB building names
let dbTopLevelEntriesForSearch = [];
let dbRoomEntriesForSearch = [];   // [{ roomName, buildingName }]
let dbBuildingsByKey = new Map();  // normalized building name -> metadata
let dbBuildingsByUid = new Map();  // stable uid -> metadata
let dbRoomsByKey = new Map();      // normalized building+room -> metadata
let liveVersion = null;
let livePollTimer = null;
let livePollBusy = false;
let currentLiveModelFile = "";
let cardDetailsRequestSeq = 0;
let cardRoomsCache = new Map();
let publishedKioskPoint = null;
let publishedKioskPointAligned = null;
let selectedGuideTarget = null;
let activeEventPayload = null;
let activeEventAutoRoute = requestedEventAutoRoute;
let activeEventLoadPromise = null;

const kioskMarkerScreen = new THREE.Vector3();
const PUBLISHED_ROAD_Y_OFFSET = 0.8;
const PUBLISHED_ROAD_DEFAULT_WIDTH = 12;
const PUBLISHED_ROAD_THICKNESS = 0.3;

const publishedRoadMaterial = new THREE.MeshStandardMaterial({
  color: 0x9ca3af,
  roughness: 1.0,
  metalness: 0.0,
  polygonOffset: true,
  polygonOffsetFactor: -4,
  polygonOffsetUnits: -4
});

const publishedRoadSideMaterial = new THREE.MeshStandardMaterial({
  color: 0x6b7280,
  roughness: 1.0,
  metalness: 0.0,
  polygonOffset: true,
  polygonOffsetFactor: -3,
  polygonOffsetUnits: -3
});

function normalizeBuildingUid(uid) {
  return String(uid || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "");
}

function getRouteEntryKeys(entry = {}) {
  const keys = new Set();
  const uid = normalizeBuildingUid(entry?.buildingUid || entry?.destinationUid || entry?.uid || "");
  if (uid) keys.add(uid);
  for (const value of [entry?.name, entry?.buildingName, entry?.objectName, entry?.modelObjectName]) {
    const key = normalizeQuery(value);
    if (key) keys.add(key);
  }
  return [...keys];
}

function rebuildRouteCatalog(entries) {
  activeRoutesByKey = new Map();
  routeNamesForSearch = [];
  for (const entry of (entries || [])) {
    const name = String(entry?.name || "").trim();
    if (!name) continue;
    for (const key of getRouteEntryKeys(entry)) {
      if (!activeRoutesByKey.has(key)) {
        activeRoutesByKey.set(key, entry);
      }
    }
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

function disposePublishedRoadGeometries(root) {
  root?.traverse?.((node) => {
    if (node?.geometry?.dispose) node.geometry.dispose();
  });
}

function clearPublishedRoadnet() {
  for (let i = publishedRoadRoot.children.length - 1; i >= 0; i--) {
    const child = publishedRoadRoot.children[i];
    disposePublishedRoadGeometries(child);
    publishedRoadRoot.remove(child);
  }
  publishedRoadRoot.visible = false;
}

function toFiniteTriplet(value, fallback = [0, 0, 0]) {
  if (!Array.isArray(value) || value.length < 3) return fallback.slice(0, 3);
  return [
    Number.isFinite(Number(value[0])) ? Number(value[0]) : fallback[0],
    Number.isFinite(Number(value[1])) ? Number(value[1]) : fallback[1],
    Number.isFinite(Number(value[2])) ? Number(value[2]) : fallback[2]
  ];
}

function buildPublishedRoadGeometry(points, width) {
  const geometry = new THREE.BufferGeometry();
  if (!Array.isArray(points) || points.length < 1) return geometry;

  const halfWidth = Math.max(0.1, (Number(width) || PUBLISHED_ROAD_DEFAULT_WIDTH) / 2);
  const cleanPoints = [];
  const epsilonSq = 1e-6;

  for (const point of points) {
    if (!point) continue;
    if (!cleanPoints.length) {
      cleanPoints.push(point.clone());
      continue;
    }

    const last = cleanPoints[cleanPoints.length - 1];
    const dx = point.x - last.x;
    const dz = point.z - last.z;
    if ((dx * dx + dz * dz) > epsilonSq) cleanPoints.push(point.clone());
  }

  if (cleanPoints.length === 1) {
    const point = cleanPoints[0];
    const y = point.y + PUBLISHED_ROAD_Y_OFFSET;

    geometry.setAttribute("position", new THREE.BufferAttribute(new Float32Array([
      point.x - halfWidth, y, point.z - halfWidth,
      point.x + halfWidth, y, point.z - halfWidth,
      point.x + halfWidth, y, point.z + halfWidth,
      point.x - halfWidth, y, point.z + halfWidth
    ]), 3));
    geometry.setAttribute("normal", new THREE.BufferAttribute(new Float32Array([
      0, 1, 0,
      0, 1, 0,
      0, 1, 0,
      0, 1, 0
    ]), 3));
    geometry.setAttribute("uv", new THREE.BufferAttribute(new Float32Array([
      0, 0,
      1, 0,
      1, 1,
      0, 1
    ]), 2));
    geometry.setIndex(new THREE.BufferAttribute(new Uint16Array([0, 2, 1, 0, 3, 2]), 1));
    return geometry;
  }

  if (cleanPoints.length < 2) return geometry;

  let accumulatedLength = 0;
  const segmentLengths = new Array(cleanPoints.length).fill(0);
  for (let i = 1; i < cleanPoints.length; i++) {
    const delta = cleanPoints[i].clone().sub(cleanPoints[i - 1]);
    delta.y = 0;
    accumulatedLength += delta.length();
    segmentLengths[i] = accumulatedLength;
  }
  const totalLength = Math.max(1e-6, accumulatedLength);

  const positions = [];
  const uvs = [];
  const normals = [];
  const indices = [];
  let vertexCount = 0;

  const addVertex = (x, y, z, u, v) => {
    positions.push(x, y, z);
    uvs.push(u, v);
    normals.push(0, 1, 0);
    return vertexCount++;
  };

  const crossXZ = (ax, az, bx, bz) => (ax * bz) - (az * bx);
  const direction = new THREE.Vector3();
  const normal = new THREE.Vector3();
  const twoPi = Math.PI * 2;

  for (let i = 0; i < cleanPoints.length - 1; i++) {
    const start = cleanPoints[i];
    const end = cleanPoints[i + 1];

    direction.copy(end).sub(start);
    direction.y = 0;
    const length = direction.length();
    if (length < 1e-6) continue;
    direction.multiplyScalar(1 / length);

    normal.set(-direction.z, 0, direction.x);

    const y0 = start.y + PUBLISHED_ROAD_Y_OFFSET;
    const y1 = end.y + PUBLISHED_ROAD_Y_OFFSET;
    const v0 = segmentLengths[i] / totalLength;
    const v1 = segmentLengths[i + 1] / totalLength;

    const left0 = addVertex(start.x + normal.x * halfWidth, y0, start.z + normal.z * halfWidth, 0, v0);
    const right0 = addVertex(start.x - normal.x * halfWidth, y0, start.z - normal.z * halfWidth, 1, v0);
    const left1 = addVertex(end.x + normal.x * halfWidth, y1, end.z + normal.z * halfWidth, 0, v1);
    const right1 = addVertex(end.x - normal.x * halfWidth, y1, end.z - normal.z * halfWidth, 1, v1);

    indices.push(left0, left1, right0);
    indices.push(right0, left1, right1);
  }

  for (let i = 1; i < cleanPoints.length - 1; i++) {
    const previous = cleanPoints[i - 1];
    const current = cleanPoints[i];
    const next = cleanPoints[i + 1];

    const previousDirection = current.clone().sub(previous);
    previousDirection.y = 0;
    const nextDirection = next.clone().sub(current);
    nextDirection.y = 0;
    if (previousDirection.lengthSq() < 1e-8 || nextDirection.lengthSq() < 1e-8) continue;
    previousDirection.normalize();
    nextDirection.normalize();

    const dot = previousDirection.dot(nextDirection);
    if (dot > 0.9999) continue;

    const turn = crossXZ(previousDirection.x, previousDirection.z, nextDirection.x, nextDirection.z);
    if (Math.abs(turn) < 1e-8) continue;

    const previousNormal = new THREE.Vector3(-previousDirection.z, 0, previousDirection.x);
    const nextNormal = new THREE.Vector3(-nextDirection.z, 0, nextDirection.x);
    if (turn > 0) {
      previousNormal.multiplyScalar(-1);
      nextNormal.multiplyScalar(-1);
    }

    const y = current.y + PUBLISHED_ROAD_Y_OFFSET;
    const v = segmentLengths[i] / totalLength;
    const centerIndex = addVertex(current.x, y, current.z, 0.5, v);

    const startAngle = Math.atan2(previousNormal.z, previousNormal.x);
    let endAngle = Math.atan2(nextNormal.z, nextNormal.x);
    const ccw = turn > 0;

    if (ccw) {
      while (endAngle <= startAngle) endAngle += twoPi;
    } else {
      while (endAngle >= startAngle) endAngle -= twoPi;
    }

    const delta = endAngle - startAngle;
    const steps = Math.max(1, Math.min(24, Math.ceil(Math.abs(delta) / (Math.PI / 18))));
    const arcIndices = [];

    for (let step = 0; step <= steps; step++) {
      const t = step / steps;
      const angle = startAngle + delta * t;
      arcIndices.push(addVertex(
        current.x + Math.cos(angle) * halfWidth,
        y,
        current.z + Math.sin(angle) * halfWidth,
        0.5,
        v
      ));
    }

    for (let step = 0; step < steps; step++) {
      const aIndex = arcIndices[step];
      const bIndex = arcIndices[step + 1];
      if (ccw) indices.push(centerIndex, bIndex, aIndex);
      else indices.push(centerIndex, aIndex, bIndex);
    }
  }

  const IndexArray = vertexCount > 65535 ? Uint32Array : Uint16Array;
  geometry.setAttribute("position", new THREE.BufferAttribute(new Float32Array(positions), 3));
  geometry.setAttribute("normal", new THREE.BufferAttribute(new Float32Array(normals), 3));
  geometry.setAttribute("uv", new THREE.BufferAttribute(new Float32Array(uvs), 2));
  geometry.setIndex(new THREE.BufferAttribute(new IndexArray(indices), 1));
  return geometry;
}

function buildPublishedRoadSideGeometry(topGeometry, thickness) {
  const geometry = new THREE.BufferGeometry();
  if (!topGeometry?.attributes?.position) return geometry;

  const depth = Number(thickness || 0);
  if (!(depth > 0)) return geometry;

  const edges = new THREE.EdgesGeometry(topGeometry);
  const positions = edges.attributes?.position?.array;
  if (!positions || positions.length < 6) {
    edges.dispose();
    return geometry;
  }

  const sidePositions = [];
  const sideIndices = [];
  let vertex = 0;

  for (let i = 0; i < positions.length; i += 6) {
    const ax = positions[i];
    const ay = positions[i + 1];
    const az = positions[i + 2];
    const bx = positions[i + 3];
    const by = positions[i + 4];
    const bz = positions[i + 5];

    sidePositions.push(
      ax, ay, az,
      bx, by, bz,
      bx, by - depth, bz,
      ax, ay - depth, az
    );
    sideIndices.push(vertex, vertex + 1, vertex + 2, vertex, vertex + 2, vertex + 3);
    vertex += 4;
  }

  geometry.setAttribute("position", new THREE.BufferAttribute(new Float32Array(sidePositions), 3));
  geometry.setIndex(sideIndices);
  geometry.computeVertexNormals();
  edges.dispose();
  return geometry;
}

function buildPublishedRoadObject(roadItem) {
  if (!roadItem || (roadItem.type !== "road" && !Array.isArray(roadItem.points))) return null;

  const width = Number(roadItem.width);
  const safeWidth = Number.isFinite(width) && width > 0 ? width : PUBLISHED_ROAD_DEFAULT_WIDTH;
  const localPoints = Array.isArray(roadItem.points)
    ? roadItem.points.map((point) => {
        const [x, y, z] = toFiniteTriplet(point);
        return new THREE.Vector3(x, y, z);
      })
    : [];
  if (!localPoints.length) return null;

  const group = new THREE.Group();
  group.name = String(roadItem.name || "road");

  const topGeometry = buildPublishedRoadGeometry(localPoints, safeWidth);
  if (!topGeometry?.attributes?.position) return null;
  const topMesh = new THREE.Mesh(topGeometry, publishedRoadMaterial);
  topMesh.renderOrder = 1;
  topMesh.userData.isPublishedRoad = true;
  group.add(topMesh);

  if (PUBLISHED_ROAD_THICKNESS > 0) {
    const sideGeometry = buildPublishedRoadSideGeometry(topGeometry, PUBLISHED_ROAD_THICKNESS);
    if (sideGeometry?.attributes?.position) {
      const sideMesh = new THREE.Mesh(sideGeometry, publishedRoadSideMaterial);
      sideMesh.renderOrder = 0;
      sideMesh.userData.isPublishedRoadSide = true;
      group.add(sideMesh);
    }
  }

  const [px, py, pz] = toFiniteTriplet(roadItem.position);
  const [rx, ry, rz] = toFiniteTriplet(roadItem.rotation);
  const [sx, sy, sz] = toFiniteTriplet(roadItem.scale, [1, 1, 1]);
  group.position.set(px, py, pz);
  group.rotation.set(rx, ry, rz);
  group.scale.set(sx, sy, sz);
  group.updateMatrixWorld(true);
  return group;
}

function setPublishedRoadnet(roadItems) {
  clearPublishedRoadnet();
  if (!Array.isArray(roadItems) || !roadItems.length) return false;

  let added = 0;
  for (const roadItem of roadItems) {
    const roadObject = buildPublishedRoadObject(roadItem);
    if (!roadObject) continue;
    publishedRoadRoot.add(roadObject);
    added += 1;
  }

  publishedRoadRoot.visible = added > 0;
  return added > 0;
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
      buildingUid: normalizeBuildingUid(rawEntry?.buildingUid || rawEntry?.destinationUid || rawEntry?.uid || ""),
      objectName: String(rawEntry?.objectName || rawEntry?.modelObjectName || "").trim(),
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
    const buildingUid = normalizeBuildingUid(rawEntry.buildingUid || rawEntry.destinationUid || rawEntry.uid || "");
    const objectName = String(rawEntry.objectName || rawEntry.modelObjectName || "").trim();
    const roomName = String(rawEntry.roomName || "").trim();
    const key = String(buildGuideKey(destinationType, { buildingName, buildingUid, roomName }) || rawEntry.key || rawKey).trim();
    if (!key || !buildingName) continue;

    activeGuidesByKey.set(key, {
      key,
      destinationType,
      buildingName,
      buildingUid,
      objectName,
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
    buildingUid: normalizeBuildingUid(target.buildingUid || ""),
    objectName: String(target.objectName || "").trim(),
    roomName: String(target.roomName || "").trim()
  };
}

function getExactGuideEntryForTarget(target) {
  if (!target || !target.buildingName) return null;
  const buildingUid = normalizeBuildingUid(target.buildingUid || getDbBuildingMeta(target.buildingName)?.buildingUid || "");
  const buildingCandidates = [
    String(target.buildingName || "").trim(),
    String(target.objectName || "").trim(),
    String(getDisplayBuildingName(target.buildingName) || "").trim()
  ].filter(Boolean);

  for (const candidate of buildingCandidates) {
    for (const maybeUid of [buildingUid, ""]) {
      const exactKey = buildGuideKey(target.type || "building", {
        buildingName: candidate,
        buildingUid: maybeUid,
        roomName: target.roomName || ""
      });
      const exact = activeGuidesByKey.get(exactKey);
      if (exact) return exact;
    }
  }

  return null;
}

function getBuildingGuideEntryForTarget(target) {
  if (!target || !target.buildingName) return null;
  const buildingUid = normalizeBuildingUid(target.buildingUid || getDbBuildingMeta(target.buildingName)?.buildingUid || "");
  const buildingCandidates = [
    String(target.buildingName || "").trim(),
    String(target.objectName || "").trim(),
    String(getDisplayBuildingName(target.buildingName) || "").trim()
  ].filter(Boolean);

  for (const candidate of buildingCandidates) {
    for (const maybeUid of [buildingUid, ""]) {
      const buildingKey = buildGuideKey("building", { buildingName: candidate, buildingUid: maybeUid });
      const buildingGuide = activeGuidesByKey.get(buildingKey);
      if (buildingGuide) return buildingGuide;
    }
  }

  return null;
}

function buildDirectionsTarget() {
  if (selectedGuideTarget?.buildingName) return selectedGuideTarget;
  if (selectedBuildingName) {
    const meta = getDbBuildingMeta(selectedBuildingName);
    return {
      type: String(meta?.entityType || "building").trim() || "building",
      buildingName: selectedBuildingName,
      buildingUid: normalizeBuildingUid(meta?.buildingUid || ""),
      objectName: String(meta?.objectName || "").trim(),
      roomName: ""
    };
  }
  return null;
}

function getRouteEntryForTarget(target) {
  if (!target) return null;
  if (typeof target === "string") return getRouteEntryForBuilding(target);

  const candidateKeys = new Set();
  const buildingUid = normalizeBuildingUid(target.buildingUid || "");
  if (buildingUid) candidateKeys.add(buildingUid);

  const rawNames = [
    target.buildingName,
    target.objectName
  ].filter(Boolean);

  for (const value of rawNames) {
    for (const key of getBuildingLookupKeys(value)) {
      candidateKeys.add(key);
    }
  }

  for (const value of rawNames) {
    const meta = getDbBuildingMeta(value);
    if (!meta) continue;
    const metaUid = normalizeBuildingUid(meta?.buildingUid || "");
    if (metaUid) candidateKeys.add(metaUid);
    for (const key of getBuildingLookupKeys(meta?.name || "")) candidateKeys.add(key);
    for (const key of getBuildingLookupKeys(meta?.objectName || "")) candidateKeys.add(key);
  }

  for (const key of candidateKeys) {
    const entry = activeRoutesByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

function formatGuideDistanceText(distance) {
  const safe = Number(distance);
  if (!Number.isFinite(safe) || safe <= 0) return "Distance unavailable";
  return `${Math.round(safe)} units`;
}

function isUsablePublishedGuide(entry) {
  return !!(
    entry
    && entry.status !== "orphaned"
    && entry.status !== "missing_route"
    && Array.isArray(entry.finalSteps)
    && entry.finalSteps.length > 0
  );
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

function normalizeRoomGuideLine(text) {
  return String(text || "")
    .replace(/^\s*(?:[-*•]|\d+[.)])\s*/, "")
    .trim();
}

function parseRoomGuideText(text) {
  return String(text || "")
    .replace(/\r\n/g, "\n")
    .split(/\n+/)
    .map(normalizeRoomGuideLineSafe)
    .filter(Boolean)
    .map((line) => ({ kind: "room_detail", text: line }));
}

function normalizeRoomGuideLineSafe(text) {
  return String(text || "")
    .replace(/^\s*(?:[-*]|\u2022|\u00e2\u20ac\u00a2|\d+[.)])\s*/, "")
    .trim();
}

function buildRoomLookupKeys(buildingName, roomName) {
  const safeRoom = String(roomName || "").trim();
  if (!safeRoom) return [];

  const roomKeys = new Set();
  const pushRoom = (value) => {
    const key = normalizeQuery(value);
    if (key) roomKeys.add(key);
  };
  pushRoom(safeRoom);
  pushRoom(removeOneNumericSuffix(safeRoom));
  pushRoom(safeRoom.replace(/\./g, ""));
  pushRoom(removeOneNumericSuffix(safeRoom.replace(/\./g, "")));

  const keys = [];
  for (const buildingKey of getBuildingLookupKeys(buildingName)) {
    for (const roomKey of roomKeys) {
      keys.push(`${buildingKey}::${roomKey}`);
    }
  }
  return keys;
}

function registerDbRoomMeta(entry) {
  for (const key of buildRoomLookupKeys(entry?.buildingName, entry?.roomName)) {
    if (!dbRoomsByKey.has(key)) {
      dbRoomsByKey.set(key, entry);
    }
  }
}

function getDbRoomMeta(buildingName, roomName) {
  const buildingCandidates = [
    String(buildingName || "").trim(),
    String(getDisplayBuildingName(buildingName) || "").trim()
  ].filter(Boolean);

  for (const candidate of buildingCandidates) {
    for (const key of buildRoomLookupKeys(candidate, roomName)) {
      const entry = dbRoomsByKey.get(key);
      if (entry) return entry;
    }
  }
  return null;
}

function buildRoomSupplementSteps(target, roomMeta, displayBuildingName) {
  if (!target?.roomName) return [];

  const manualSteps = parseRoomGuideText(roomMeta?.indoorGuideText || "");
  if (manualSteps.length) return manualSteps;

  const steps = [];
  const floorValue = String(roomMeta?.floorNumber || "").trim();
  if (floorValue) {
    if (floorValue === "0") {
      steps.push({ kind: "room_floor", text: "Stay on the ground floor." });
    } else {
      steps.push({ kind: "room_floor", text: `Go to floor ${floorValue}.` });
    }
  }

  const roomNumber = String(roomMeta?.roomNumber || "").trim();
  if (roomNumber && normalizeQuery(roomNumber) !== normalizeQuery(target.roomName || "")) {
    steps.push({ kind: "room_number", text: `Look for room number ${roomNumber}.` });
  }

  const description = String(roomMeta?.description || "").trim();
  if (description) {
    steps.push({
      kind: "room_note",
      text: /[.!?]$/.test(description) ? description : `${description}.`
    });
  }

  if (!steps.length) {
    steps.push({
      kind: "room_fallback",
      text: `Proceed inside ${displayBuildingName} to ${target.roomName}. Follow posted room signs if needed.`
    });
  }

  return steps;
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

  const summaryText = String(summary || "").replace(/\s*(?:\u2022|\u00e2\u20ac\u00a2)\s*/g, " - ");
  const exactGuideEntry = getExactGuideEntryForTarget(target);
  const buildingGuideEntry = getBuildingGuideEntryForTarget(target);
  const usableExactGuide = isUsablePublishedGuide(exactGuideEntry);
  const usableBuildingGuide = isUsablePublishedGuide(buildingGuideEntry);
  const roomMeta = target.type === "room" && target.roomName
    ? getDbRoomMeta(target.buildingName, target.roomName)
    : null;

  let steps = [];
  let statusText = "";

  if (usableExactGuide) {
    steps = exactGuideEntry.finalSteps;
    const notes = Array.isArray(exactGuideEntry.notes) ? exactGuideEntry.notes : [];
    statusText = notes[0] || "";
  } else {
    const routeSteps = usableBuildingGuide
      ? buildingGuideEntry.finalSteps
      : buildGuideStepsFromPoints(
          alignedPoints.map((point) => [point.x, point.y, point.z]),
          {
            destinationName: displayBuildingName,
            arrivalText: `Arrive at ${displayBuildingName}.`,
            landmarks: buildPublicGuideLandmarks()
          }
        );

    if (target.type === "room" && target.roomName) {
      const roomSteps = buildRoomSupplementSteps(target, roomMeta, displayBuildingName);
      steps = [...routeSteps, ...roomSteps];
      statusText = String(roomMeta?.indoorGuideText || "").trim()
        ? "Using the building route plus saved room-specific indoor guidance."
        : "Using the building route plus a room-level fallback because no published room text guide is available yet.";
    } else {
      steps = routeSteps;
      const notes = usableBuildingGuide && Array.isArray(buildingGuideEntry?.notes) ? buildingGuideEntry.notes : [];
      statusText = usableBuildingGuide
        ? (notes[0] || "")
        : "Using an auto-generated fallback because no published text guide is available yet.";
    }
  }

  if (directionsTitle) directionsTitle.textContent = title;
  if (directionsSummary) directionsSummary.textContent = summaryText;
  if (directionsStatus) {
    if (statusText) {
      directionsStatus.textContent = statusText;
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
  const buildingMeta = getDbBuildingMeta(name);
  const candidateKeys = new Set([
    normalizeBuildingUid(buildingMeta?.buildingUid || ""),
    ...getBuildingLookupKeys(buildingMeta?.name || ""),
    ...getBuildingLookupKeys(buildingMeta?.objectName || ""),
    ...getBuildingLookupKeys(name)
  ].filter(Boolean));
  for (const key of candidateKeys) {
    const entry = activeRoutesByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

rebuildRouteCatalog([]);

function registerDbBuildingMeta(entry) {
  const normalizedUid = normalizeBuildingUid(entry?.buildingUid || "");
  const normalizedEntry = {
    ...entry,
    buildingUid: normalizedUid,
    objectName: String(entry?.objectName || "").trim(),
    entityType: String(entry?.entityType || "building").trim() || "building",
    location: String(entry?.location || "").trim(),
    contactInfo: String(entry?.contactInfo || "").trim()
  };
  if (normalizedUid && !dbBuildingsByUid.has(normalizedUid)) {
    dbBuildingsByUid.set(normalizedUid, normalizedEntry);
  }
  const aliases = [
    normalizedEntry?.name,
    normalizedEntry?.objectName
  ];
  for (const alias of aliases) {
    for (const key of getBuildingLookupKeys(alias)) {
      if (!dbBuildingsByKey.has(key)) {
        dbBuildingsByKey.set(key, normalizedEntry);
      }
    }
  }
}

function getDbBuildingMeta(nameOrUid) {
  const safeUid = normalizeBuildingUid(nameOrUid);
  if (safeUid && dbBuildingsByUid.has(safeUid)) {
    return dbBuildingsByUid.get(safeUid) || null;
  }
  for (const key of getBuildingLookupKeys(nameOrUid)) {
    const entry = dbBuildingsByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

function getDisplayBuildingName(name) {
  return String(getDbBuildingMeta(name)?.name || name || "").trim();
}

function getDestinationEntityType(name) {
  return String(getDbBuildingMeta(name)?.entityType || "building").trim() || "building";
}

function getEntityTypeLabel(entityType) {
  const safe = String(entityType || "building").trim().toLowerCase();
  if (!safe) return "destination";
  return safe.replace(/_/g, " ");
}

function getRouteEntryForBuilding(name) {
  const meta = getDbBuildingMeta(name);
  const displayName = String(meta?.name || getDisplayBuildingName(name) || name || "").trim();
  const objectName = String(meta?.objectName || "").trim();
  const buildingUid = normalizeBuildingUid(meta?.buildingUid || "");
  const candidateKeys = new Set([
    buildingUid,
    ...getBuildingLookupKeys(displayName),
    ...getBuildingLookupKeys(objectName),
    ...getBuildingLookupKeys(name)
  ].filter(Boolean));
  for (const key of candidateKeys) {
    const entry = activeRoutesByKey.get(key);
    if (entry) return entry;
  }
  return null;
}

function refreshBuildingLabelText() {
  buildingEntriesByKey = new Map();
  buildingEntriesByObject = new Map();
  for (const entry of buildingLabels) {
    const meta = getDbBuildingMeta(entry.objectName || entry.name || entry.displayName);
    entry.displayName = String(meta?.name || entry.displayName || entry.name || "").trim();
    entry.objectName = String(meta?.objectName || entry.objectName || entry.name || "").trim();
    const nextLabel = formatBuildingLabel(getDisplayBuildingName(entry.displayName || entry.name || entry.objectName));
    if (entry.element.textContent !== nextLabel) {
      entry.element.textContent = nextLabel;
    }
    registerBuildingEntry(entry);
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
    dbBuildingsByUid = new Map();
    dbRoomsByKey = new Map();
    dbBuildingNamesForSearch = [];
    dbTopLevelEntriesForSearch = [];
    for (const raw of buildings) {
      const name = String(raw?.name || "").trim();
      if (!name) continue;
      const entry = {
        id: raw?.id ?? null,
        buildingUid: raw?.buildingUid ?? "",
        name,
        objectName: String(raw?.objectName || "").trim(),
        entityType: String(raw?.entityType || "building").trim() || "building",
        description: String(raw?.description || "").trim(),
        imagePath: String(raw?.imagePath || "").trim(),
        modelFile: String(raw?.modelFile || "").trim(),
        location: String(raw?.location || "").trim(),
        contactInfo: String(raw?.contactInfo || "").trim()
      };
      registerDbBuildingMeta(entry);
      dbTopLevelEntriesForSearch.push(entry);
      dbBuildingNamesForSearch.push(name);
    }

    dbRoomEntriesForSearch = rooms
      .map((r) => ({
        id: r?.id ?? null,
        roomName: String(r?.roomName || "").trim(),
        buildingName: String(r?.buildingName || "").trim(),
        roomNumber: String(r?.roomNumber || "").trim(),
        roomType: String(r?.roomType || "").trim(),
        floorNumber: String(r?.floorNumber || "").trim(),
        description: String(r?.description || "").trim(),
        indoorGuideText: String(r?.indoorGuideText || "").trim(),
        modelFile: String(r?.modelFile || "").trim()
      }))
      .filter((r) => r.roomName && r.buildingName);
    dbRoomEntriesForSearch.forEach(registerDbRoomMeta);

    refreshBuildingLabelText();
    if (selectedGuideTarget?.type === "room" && selectedGuideTarget.buildingName && selectedGuideTarget.roomName) {
      const persistedRoom = getDbRoomMeta(selectedGuideTarget.buildingName, selectedGuideTarget.roomName);
      if (persistedRoom) {
        showRoomCard(persistedRoom);
      } else if (selectedBuildingName) {
        showCard(selectedBuildingName);
      }
    } else if (selectedBuildingName) {
      showCard(selectedBuildingName);
    }

    // If user already typed, re-run search now that DB rooms/buildings are ready.
    if (loadedModel && String(pendingSearchQuery || "").trim()) {
      handleSearchSelect(pendingSearchQuery);
    }
    if (loadedModel && pendingRequestedDestination && requestedEventId <= 0) {
      const destinationQuery = String(pendingRequestedDestination).trim();
      pendingRequestedDestination = "";
      sessionStorage.removeItem("tnts:jumpToDestination");
      sessionStorage.removeItem("tnts:jumpToBuilding");
      handleSearchSelect(destinationQuery, { autoRoute: requestedEventAutoRoute });
    }
    if (loadedModel && activeEventPayload) {
      applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: false });
    }
  } catch (err) {
    console.warn("Search catalog unavailable (route/building-only search remains active):", err);
    dbBuildingNamesForSearch = [];
    dbTopLevelEntriesForSearch = [];
    dbRoomEntriesForSearch = [];
    dbBuildingsByKey = new Map();
    dbBuildingsByUid = new Map();
    dbRoomsByKey = new Map();
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

function getRoomSearchAliases(roomName, roomNumber = "") {
  const aliases = new Set();
  const pushAlias = (value) => {
    const safeValue = String(value || "").trim();
    if (safeValue) aliases.add(safeValue);
  };

  const safeRoomNumber = String(roomNumber || "").trim();
  pushAlias(roomName);
  if (safeRoomNumber) {
    pushAlias(safeRoomNumber);
    pushAlias(`ROOM ${safeRoomNumber}`);
  }

  return Array.from(aliases);
}

function getRoomSearchCombinedAliases(roomName, buildingName, roomNumber = "") {
  const safeBuildingName = String(buildingName || "").trim();
  if (!safeBuildingName) return [];
  return getRoomSearchAliases(roomName, roomNumber).map((alias) => `${alias} ${safeBuildingName}`);
}

function collectSearchCandidates(queryNorm) {
  if (!queryNorm) return [];

  const out = [];
  const seen = new Set();

  const pushCandidate = (candidate) => {
    const type = String(candidate?.type || "").trim();
    const label = String(candidate?.label || "").trim();
    const buildingName = String(candidate?.buildingName || "").trim();
    const roomName = String(candidate?.roomName || "").trim();
    const roomNumber = String(candidate?.roomNumber || "").trim();
    const roomType = String(candidate?.roomType || "").trim();
    const floorNumber = String(candidate?.floorNumber || "").trim();
    const description = String(candidate?.description || "").trim();
    const indoorGuideText = String(candidate?.indoorGuideText || "").trim();
    const roomId = candidate?.id ?? null;
    const key = `${type}::${label}::${buildingName}::${roomName}::${roomNumber}`;
    if (seen.has(key)) return;
    seen.add(key);

    let score = 0;
    if (type === "room") {
      const roomAliases = getRoomSearchAliases(roomName, roomNumber);
      const roomNorms = roomAliases.map((alias) => normalizeQuery(alias)).filter(Boolean);
      const roomCombinedNorms = getRoomSearchCombinedAliases(roomName, buildingName, roomNumber)
        .map((alias) => normalizeQuery(alias))
        .filter(Boolean);
      const roomLabelNorm = normalizeQuery(label);
      const roomLabelScore = scoreSearchMatch(queryNorm, roomLabelNorm);
      const queryFlat = normalizeLoose(queryNorm);
      const standaloneScores = roomNorms.map((targetNorm) => scoreSearchMatch(queryNorm, targetNorm));
      const combinedScores = roomCombinedNorms.map((targetNorm) => scoreSearchMatch(queryNorm, targetNorm));
      const queryStartsWithRoom = roomNorms.some((targetNorm) => {
        const roomFlat = normalizeLoose(targetNorm);
        return !!roomFlat && (queryNorm.startsWith(targetNorm) || queryFlat.startsWith(roomFlat));
      });

      score = Math.max(
        ...standaloneScores,
        ...combinedScores.map((candidateScore) => (
          candidateScore > 0
            ? candidateScore + (queryStartsWithRoom ? 80 : 8)
            : 0
        )),
        roomLabelScore > 0 ? roomLabelScore + 3 : 0
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
      roomNumber,
      roomType,
      floorNumber,
      description,
      indoorGuideText,
      id: roomId,
      hasRoute,
      score: score + (hasRoute ? 20 : 0)
    });
  };

  // Route-published buildings (highest confidence for routing).
  for (const name of routeNamesForSearch) {
    pushCandidate({
      type: getDestinationEntityType(name),
      label: name,
      buildingName: name
    });
  }

  // DB top-level destinations (buildings, venues, areas, landmarks, facilities).
  for (const entry of dbTopLevelEntriesForSearch) {
    const bName = String(entry?.name || "").trim();
    if (!bName) continue;
    pushCandidate({
      type: String(entry?.entityType || "building").trim() || "building",
      label: bName,
      buildingName: bName,
      description: String(entry?.description || "").trim(),
      id: entry?.id ?? null
    });
  }

  // DB rooms -> route by parent building.
  for (const row of dbRoomEntriesForSearch) {
    pushCandidate({
      type: "room",
      label: `${row.roomName} (${row.buildingName})`,
      buildingName: row.buildingName,
      roomName: row.roomName,
      roomNumber: row.roomNumber,
      roomType: row.roomType,
      floorNumber: row.floorNumber,
      description: row.description,
      indoorGuideText: row.indoorGuideText,
      id: row.id ?? null
    });
  }

  out.sort((a, b) => {
    if (b.score !== a.score) return b.score - a.score;
    if (a.type !== b.type) return a.type === "building" ? -1 : 1;
    return a.label.localeCompare(b.label);
  });
  return out;
}

function getExactStandaloneRoomCandidates(queryNorm, candidates) {
  const queryFlat = normalizeLoose(queryNorm);
  if (!queryFlat) return [];

  return candidates.filter((candidate) => {
    if (candidate?.type !== "room") return false;
    return getRoomSearchAliases(candidate.roomName, candidate.roomNumber)
      .some((alias) => normalizeLoose(alias) === queryFlat);
  });
}

function selectBuildingByName(buildingName, opts = {}) {
  const { showInfoCard = true } = opts;
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
    clearRouteOnly();
    hideDirectionsPanel();
    clearRouteBtn.disabled = true;
  }

  // Clear previous selection visuals
  clearHoverTint();
  clearHoverOutline();
  clearClickOutline();

  setClickOutline(meshForOutline);
  if (showInfoCard) {
    showCard(selectedBuildingName);
  }

  return true;
}

function handleSearchSelect(rawValue, opts = {}) {
  const { autoRoute = false } = opts;
  pendingSearchQuery = rawValue; // remember latest search even if GLB not loaded yet

  if (!loadedModel) return; // model not ready yet

  const searchText = String(rawValue || "").trim();
  const q = normalizeQuery(rawValue);
  if (!q) {
    // if user cleared search, hide card + selection
    hideCardAndClear();
    return;
  }

  const candidates = collectSearchCandidates(q);
  if (!candidates.length) {
    showSearchFeedback("Search", `No rooms or destinations matched "${searchText}".`);
    return;
  }

  const exactStandaloneRooms = getExactStandaloneRoomCandidates(q, candidates);
  if (exactStandaloneRooms.length > 1) {
    showRoomSearchResults(searchText, exactStandaloneRooms);
    return;
  }

  // Prefer rooms/buildings whose parent building has a published route.
  const prioritized = exactStandaloneRooms.length === 1
    ? exactStandaloneRooms
    : [
        ...candidates.filter((c) => c.hasRoute),
        ...candidates.filter((c) => !c.hasRoute)
      ];

  let selected = null;
  for (const candidate of prioritized) {
    if (selectBuildingByName(candidate.buildingName, { showInfoCard: candidate.type !== "room" })) {
      selected = candidate;
      break;
    }
  }
  if (!selected) {
    showSearchFeedback("Search", `The best match for "${searchText}" is not available in the current map model.`);
    return;
  }

  if (selected.type === "room" && selected.roomName) {
    showRoomCard(selected, { autoRoute });
    return;
    const roomMeta = getDbRoomMeta(selected.buildingName, selected.roomName);
    const buildingMeta = getDbBuildingMeta(selected.buildingName);
    const hasIndoorGuide = !!String(roomMeta?.indoorGuideText || "").trim();
    setSelectedGuideTarget({
      type: "room",
      buildingName: selected.buildingName,
      buildingUid: buildingMeta?.buildingUid || "",
      objectName: buildingMeta?.objectName || "",
      roomName: selected.roomName
    });
    hideDirectionsPanel();
    const routeReady = !!getRouteEntryForBuilding(selected.buildingName);
    cardInfo.textContent = routeReady
      ? `Matched ${selected.roomName} in ${getDisplayBuildingName(selected.buildingName)}. Select "Get Directions" to show the route${hasIndoorGuide ? " and indoor guide" : ""}.`
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
  const { prefix } = splitEntityPrefix(String(name || ""));
  return (
    prefix === "OB" ||
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
  const aliases = [
    entry?.name,
    entry?.objectName,
    entry?.displayName
  ];
  const dbMeta = getDbBuildingMeta(entry?.name) || getDbBuildingMeta(entry?.objectName) || getDbBuildingMeta(entry?.displayName);
  if (dbMeta) {
    aliases.push(dbMeta?.name, dbMeta?.objectName);
  }
  for (const alias of aliases) {
    for (const key of getBuildingLookupKeys(alias)) {
      if (!buildingEntriesByKey.has(key)) {
        buildingEntriesByKey.set(key, entry);
      }
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
    const dbMeta = getDbBuildingMeta(root.name);
    const displayName = String(dbMeta?.name || root.name || "").trim();
    const objectName = String(dbMeta?.objectName || root.name || "").trim();

    const element = document.createElement("div");
    element.className = "map-building-label";
    element.textContent = formatBuildingLabel(displayName);
    buildingLabelLayer.appendChild(element);

    const entry = {
      name: root.name,
      objectName,
      displayName,
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
  if (cardRoomsTitle) cardRoomsTitle.textContent = "Rooms";
  cardRoomsWrap.classList.add("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = "Loading rooms...";
  cardRoomsList.replaceChildren();
}

function setCardRoomsLoading(message = "Loading rooms...", title = "Rooms") {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  if (cardRoomsTitle) cardRoomsTitle.textContent = String(title || "Rooms");
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = message;
  cardRoomsList.replaceChildren();
}

function setCardRoomsMessage(message, title = "Rooms") {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  if (cardRoomsTitle) cardRoomsTitle.textContent = String(title || "Rooms");
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsStatus.classList.remove("hidden");
  cardRoomsStatus.textContent = message;
  cardRoomsList.replaceChildren();
}

function buildCardRoomGuideLines(room, displayBuildingName, maxLines = 0) {
  const roomName = String(room?.roomName || room?.name || "").trim();
  if (!roomName) return [];

  const lines = buildRoomSupplementSteps(
    { roomName },
    {
      roomName,
      roomNumber: String(room?.roomNumber || "").trim(),
      roomType: String(room?.roomType || "").trim(),
      floorNumber: String(room?.floorNumber || "").trim(),
      description: String(room?.description || "").trim(),
      indoorGuideText: String(room?.indoorGuideText || "").trim()
    },
    displayBuildingName
  )
    .map((step) => String(step?.text || "").trim())
    .filter(Boolean);

  return maxLines > 0 ? lines.slice(0, maxLines) : lines;
}

function createCardRoomItem(room, opts = {}) {
  const {
    title = "",
    subtitle = "",
    includeDescription = true,
    includeGuide = false,
    guideLineLimit = 0,
    statusText = "",
    actionLabel = "",
    onSelect = null
  } = opts;

  const roomName = String(room?.roomName || room?.name || "").trim() || "Unnamed Room";
  const displayBuildingName = getDisplayBuildingName(room?.buildingName) || String(room?.buildingName || "").trim();
  const roomNumber = String(room?.roomNumber || "").trim();
  const primaryTitle = String(title || roomName).trim() || roomName;
  const secondaryTitle = String(subtitle || "").trim();
  const interactive = typeof onSelect === "function";
  const item = document.createElement(interactive ? "button" : "div");

  if (interactive) {
    item.type = "button";
    item.className = "card-room-item card-room-item--interactive";
    item.addEventListener("click", () => onSelect(room));
  } else {
    item.className = "card-room-item";
  }

  const head = document.createElement("div");
  head.className = "card-room-head";

  const name = document.createElement("div");
  name.className = "card-room-name";
  name.textContent = primaryTitle;

  const number = document.createElement("div");
  number.className = "card-room-number";
  number.textContent = roomNumber || "-";

  head.appendChild(name);
  head.appendChild(number);
  item.appendChild(head);

  if (secondaryTitle) {
    const subtitleEl = document.createElement("div");
    subtitleEl.className = "card-room-subtitle";
    subtitleEl.textContent = secondaryTitle;
    item.appendChild(subtitleEl);
  }

  const metaParts = [];
  if (String(room?.floorNumber || "").trim()) metaParts.push(`Floor ${String(room.floorNumber).trim()}`);
  if (String(room?.roomType || "").trim()) metaParts.push(String(room.roomType).trim());
  if (includeDescription && String(room?.description || "").trim()) metaParts.push(String(room.description).trim());

  if (metaParts.length) {
    const meta = document.createElement("div");
    meta.className = "card-room-meta";
    meta.textContent = metaParts.join(" - ");
    item.appendChild(meta);
  }

  if (includeGuide) {
    const guideLines = buildCardRoomGuideLines(room, displayBuildingName, guideLineLimit);
    if (guideLines.length) {
      const guide = document.createElement("div");
      guide.className = "card-room-guide";
      guide.textContent = guideLines.join("\n");
      item.appendChild(guide);
    }
  }

  if (statusText) {
    const status = document.createElement("div");
    status.className = "card-room-status";
    status.textContent = statusText;
    item.appendChild(status);
  }

  if (actionLabel) {
    const action = document.createElement("div");
    action.className = "card-room-action";
    action.textContent = actionLabel;
    item.appendChild(action);
  }

  return item;
}

function renderCardRoomItems(items, title = "Rooms", emptyMessage = "No room details available.") {
  if (!cardRoomsWrap || !cardRoomsStatus || !cardRoomsList) return;
  if (cardRoomsTitle) cardRoomsTitle.textContent = String(title || "Rooms");
  cardRoomsWrap.classList.remove("hidden");
  cardRoomsList.replaceChildren();

  if (!Array.isArray(items) || !items.length) {
    cardRoomsStatus.classList.remove("hidden");
    cardRoomsStatus.textContent = emptyMessage;
    return;
  }

  cardRoomsStatus.classList.add("hidden");
  for (const item of items) {
    if (item) cardRoomsList.appendChild(item);
  }
}

function renderCardRoomsList(rooms) {
  const items = Array.isArray(rooms)
    ? rooms.map((room) => createCardRoomItem(room))
    : [];
  renderCardRoomItems(items, "Rooms", "No rooms found for this building in the published model.");
  return;
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
  const entityType = String(meta?.entityType || "building").trim() || "building";
  const roomsTitle = entityType === "facility" ? "Linked Rooms" : "Rooms";
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

  setCardRoomsLoading("Loading details...", roomsTitle);

  try {
    const url = new URL(BUILDING_DETAILS_ENDPOINT, window.location.href);
    if (currentLiveModelFile) url.searchParams.set("model", currentLiveModelFile);
    if (meta?.id != null) url.searchParams.set("buildingId", String(meta.id));
    url.searchParams.set("name", displayName);

    const res = await fetch(url.toString(), { cache: "no-store" });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
    if (requestSeq !== cardDetailsRequestSeq) return;

    const payloadBuilding = data?.building && typeof data.building === "object" ? data.building : null;
    if (payloadBuilding) {
      registerDbBuildingMeta({
        id: payloadBuilding?.id ?? meta?.id ?? null,
        buildingUid: payloadBuilding?.buildingUid ?? meta?.buildingUid ?? "",
        name: String(payloadBuilding?.name || displayName).trim(),
        objectName: String(payloadBuilding?.objectName || meta?.objectName || "").trim(),
        entityType: String(payloadBuilding?.entityType || entityType).trim() || entityType,
        description: String(payloadBuilding?.description || meta?.description || "").trim(),
        imagePath: String(payloadBuilding?.imagePath || meta?.imagePath || "").trim(),
        modelFile: String(payloadBuilding?.modelFile || meta?.modelFile || "").trim(),
        location: String(payloadBuilding?.location || meta?.location || "").trim(),
        contactInfo: String(payloadBuilding?.contactInfo || meta?.contactInfo || "").trim()
      });
    }

    const rooms = Array.isArray(data.rooms) ? data.rooms : [];
    rooms.forEach((room) => {
      const roomName = String(room?.name || "").trim();
      if (!roomName || !displayName) return;
      registerDbRoomMeta({
        id: room?.id ?? null,
        roomName,
        buildingName: displayName,
        roomNumber: String(room?.roomNumber || "").trim(),
        roomType: String(room?.roomType || "").trim(),
        floorNumber: String(room?.floorNumber || "").trim(),
        description: String(room?.description || "").trim(),
        indoorGuideText: String(room?.indoorGuideText || "").trim(),
        modelFile: String(room?.modelFile || "").trim()
      });
    });
    cardRoomsCache.set(cacheKey, rooms);
    renderCardRoomItems(
      Array.isArray(rooms) ? rooms.map((room) => createCardRoomItem(room)) : [],
      roomsTitle,
      entityType === "facility"
        ? "No linked rooms found for this facility."
        : "No rooms found for this destination in the published model."
    );
  } catch (err) {
    if (requestSeq !== cardDetailsRequestSeq) return;
    setCardRoomsMessage(`Details unavailable: ${err?.message || err}`, roomsTitle);
  }
}

function showRoomCard(room, opts = {}) {
  const { autoRoute = false } = opts;
  const roomName = String(room?.roomName || room?.name || "").trim();
  const buildingName = String(room?.buildingName || "").trim();
  if (!roomName || !buildingName) return;

  const roomMeta = getDbRoomMeta(buildingName, roomName) || room;
  const buildingMeta = getDbBuildingMeta(buildingName);
  const displayBuildingName = getDisplayBuildingName(buildingName) || buildingName;
  const routeReady = !!getRouteEntryForBuilding(buildingName);
  const hasIndoorGuide = !!String(roomMeta?.indoorGuideText || "").trim();
  const roomSupportLabel = hasIndoorGuide ? "saved room guide" : "room-level fallback guidance";
  const statusText = routeReady
    ? `${displayBuildingName} has a published route. Get Directions leads to the building, then shows the ${roomSupportLabel}.`
    : `No published route is available for ${displayBuildingName} yet, but the room details below still show ${roomSupportLabel}.`;

  setSelectedGuideTarget({
    type: "room",
    buildingName,
    buildingUid: buildingMeta?.buildingUid || "",
    objectName: buildingMeta?.objectName || "",
    roomName
  });

  hideDirectionsPanel();
  infoCard.classList.remove("hidden");
  cardTitle.textContent = roomName;
  cardInfo.textContent = `${roomName} is in ${displayBuildingName}. ${statusText}`;
  directionsBtn.disabled = !routeReady;

  const roomCard = createCardRoomItem(
    {
      ...roomMeta,
      roomName,
      buildingName,
      roomNumber: String(roomMeta?.roomNumber || room?.roomNumber || "").trim(),
      roomType: String(roomMeta?.roomType || room?.roomType || "").trim(),
      floorNumber: String(roomMeta?.floorNumber || room?.floorNumber || "").trim(),
      description: String(roomMeta?.description || room?.description || "").trim(),
      indoorGuideText: String(roomMeta?.indoorGuideText || room?.indoorGuideText || "").trim()
    },
    {
      title: roomName,
      subtitle: displayBuildingName,
      includeGuide: true,
      statusText: routeReady ? "Get Directions available for this room." : "Indoor guidance only. Building route is not published yet."
    }
  );
  renderCardRoomItems([roomCard], "Room Details");

  if (autoRoute && routeReady) {
    activateDirectionsForSelectedBuilding();
  }
}

function showRoomSearchResults(searchText, rooms) {
  const matches = Array.isArray(rooms) ? [...rooms] : [];
  matches.sort((a, b) => {
    if (Number(Boolean(b?.hasRoute)) !== Number(Boolean(a?.hasRoute))) {
      return Number(Boolean(b?.hasRoute)) - Number(Boolean(a?.hasRoute));
    }
    return getDisplayBuildingName(a?.buildingName).localeCompare(getDisplayBuildingName(b?.buildingName));
  });

  const roomLabel = String(matches[0]?.roomName || searchText || "Room Search").trim() || "Room Search";
  hideCardAndClear();
  infoCard.classList.remove("hidden");
  cardTitle.textContent = roomLabel;
  cardInfo.textContent = `Multiple rooms matched "${searchText}". Choose the correct building below.`;
  directionsBtn.disabled = true;

  const items = matches.map((room) => createCardRoomItem(room, {
    title: getDisplayBuildingName(room?.buildingName) || String(room?.buildingName || "").trim() || "Unknown Building",
    subtitle: String(room?.roomName || "").trim(),
    includeDescription: false,
    includeGuide: true,
    guideLineLimit: 2,
    statusText: room?.hasRoute
      ? "Get Directions is available after you open this room."
      : "No published building route yet. Room guidance is still available.",
    actionLabel: "Open room details",
    onSelect: (selectedRoom) => {
      if (!selectBuildingByName(selectedRoom.buildingName, { showInfoCard: false })) {
        showSearchFeedback("Search", `The room in ${getDisplayBuildingName(selectedRoom.buildingName) || selectedRoom.buildingName} is not available in the current map model.`);
        return;
      }
      showRoomCard(selectedRoom);
    }
  }));
  renderCardRoomItems(items, "Matches", "No matching rooms were found.");
}

function showSearchFeedback(title, message) {
  hideCardAndClear();
  infoCard.classList.remove("hidden");
  cardTitle.textContent = String(title || "Search");
  cardInfo.textContent = String(message || "").trim() || "Search feedback unavailable.";
}

function showCard(buildingName) {
  const meta = getDbBuildingMeta(buildingName);
  const entityType = String(meta?.entityType || "building").trim() || "building";
  const typeLabel = getEntityTypeLabel(entityType);
  if (!selectedGuideTarget || normalizeQuery(selectedGuideTarget.buildingName) !== normalizeQuery(buildingName)) {
    setSelectedGuideTarget({
      type: entityType,
      buildingName,
      buildingUid: meta?.buildingUid || "",
      objectName: meta?.objectName || "",
      roomName: ""
    });
  }
  const displayName = getDisplayBuildingName(buildingName) || buildingName;
  const description = String(meta?.description || "").trim();
  const location = String(meta?.location || "").trim();
  const contactInfo = String(meta?.contactInfo || "").trim();
  const routeAvailable = !!getRouteEntryForTarget({
    buildingName,
    buildingUid: meta?.buildingUid || "",
    objectName: meta?.objectName || ""
  });
  const detailParts = [description, location && entityType === "facility" ? `Location: ${location}` : "", contactInfo && entityType === "facility" ? `Contact: ${contactInfo}` : ""]
    .filter(Boolean);

  infoCard.classList.remove("hidden");
  cardTitle.textContent = displayName;
  cardInfo.textContent = detailParts.length
    ? `${detailParts.join(" ")} ${routeAvailable ? `Select "Get Directions" to route to this ${typeLabel}.` : `No published route for this ${typeLabel}.`}`
    : (routeAvailable ? `Select "Get Directions" to route to this ${typeLabel}.` : `No published route for this ${typeLabel}.`);
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
  const directionsTarget = buildDirectionsTarget();
  const selectedName = String(selectedBuildingName || directionsTarget?.buildingName || "").trim();
  if (!loadedModel || !directionsTarget?.buildingName) return;

  const routeEntry = getRouteEntryForTarget(directionsTarget);
  if (!routeEntry) {
    console.warn("No route configured for:", selectedName || directionsTarget.buildingName);
    window.tntsReportClientError?.("route_missing", "No route configured for selected building", {
      buildingName: selectedName || directionsTarget.buildingName,
    });
    hideDirectionsPanel();
    if (selectedName) showCard(selectedName);
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
    window.tntsReportClientError?.("route_incomplete", "Route points are incomplete", {
      buildingName: selectedName || directionsTarget.buildingName,
      pointCount: points.length,
    });
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
  showDirectionsPanelForSelection(directionsTarget, routeEntry, alignedPoints, routeDistance);
  clearRouteBtn.disabled = false;
}

directionsBtn.addEventListener("click", () => {
  activateDirectionsForSelectedBuilding();
});

clearRouteBtn.addEventListener("click", () => {
  activeEventAutoRoute = false;
  clearRouteOnly();
  hideDirectionsPanel();
  clearRouteBtn.disabled = true;
});

directionsCloseBtn?.addEventListener("click", (event) => {
  event.preventDefault();
  event.stopPropagation();
  activeEventAutoRoute = false;
  clearRouteOnly();
  hideDirectionsPanel();
  clearRouteBtn.disabled = true;
});

function formatEventScheduleLabel(schedule) {
  return String(schedule || "")
    .split("_")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function dismissActiveEventContext() {
  activeEventPayload = null;
  activeEventAutoRoute = false;
  clearEventAreaHighlight();
  hideEventFocusCard();
  clearEventRequestParamsFromUrl();
}

function renderEventPayloadWithFallbackMessage(message) {
  if (!activeEventPayload) return;
  renderEventFocusPayload({
    ...activeEventPayload,
    canMap: false,
    canRoute: false,
    health: "needs_review",
    healthLabel: "Needs Review",
    healthMessage: message || activeEventPayload.healthMessage
  });
}

function applyActiveEventToMap({ autoRoute = activeEventAutoRoute, recenter = true } = {}) {
  clearEventAreaHighlight();

  if (!loadedModel || !activeEventPayload) return;
  const target = activeEventPayload.resolvedTarget;
  let runtimePayload = { ...activeEventPayload };

  if (!activeEventPayload.canMap || !target) {
    renderEventFocusPayload(runtimePayload);
    return;
  }

  if (target.type === "specific_area") {
    hideCardAndClear();
    const rawPoint = new THREE.Vector3(Number(target.x) || 0, Number(target.y) || 0, Number(target.z) || 0);
    const alignedPoint = alignPointToModelSurface(rawPoint);
    const anchorBuildingName = String(target.buildingName || "").trim();
    const anchorBuildingMeta = anchorBuildingName ? getDbBuildingMeta(anchorBuildingName) : null;
    const anchorTarget = anchorBuildingName ? {
      type: String(target.anchorType || anchorBuildingMeta?.entityType || "building").trim() || "building",
      buildingName: anchorBuildingName,
      buildingUid: String(target.buildingUid || anchorBuildingMeta?.buildingUid || "").trim(),
      objectName: String(target.objectName || anchorBuildingMeta?.objectName || "").trim(),
      roomName: ""
    } : null;
    const runtimeCanRoute = !!getRouteEntryForTarget(anchorTarget);
    if (runtimePayload.canRoute !== runtimeCanRoute) {
      runtimePayload = {
        ...runtimePayload,
        canRoute: runtimeCanRoute,
        health: runtimeCanRoute ? runtimePayload.health : "limited",
        healthLabel: runtimeCanRoute ? runtimePayload.healthLabel : "Limited",
        healthMessage: runtimeCanRoute
          ? "Directions are available through the linked destination. The exact event spot will still open as a highlight."
          : (anchorBuildingName
            ? "This event pin is valid, but no published route is available for its linked destination right now."
            : "This event will open as a map highlight only.")
      };
    }
    renderEventFocusPayload({
      ...runtimePayload,
      canMap: true,
      canRoute: runtimeCanRoute
    });

    let selectedAnchor = false;
    if (anchorBuildingName) {
      const preferredAnchorName = String(target.objectName || anchorBuildingMeta?.objectName || "").trim();
      selectedAnchor = (preferredAnchorName
        ? selectBuildingByName(preferredAnchorName, { showInfoCard: false })
        : false) || selectBuildingByName(anchorBuildingName, { showInfoCard: false });
      if (selectedAnchor) {
        setSelectedGuideTarget({
          type: anchorTarget?.type || String(target.anchorType || anchorBuildingMeta?.entityType || getDestinationEntityType(anchorBuildingName) || "building").trim() || "building",
          buildingName: anchorBuildingName,
          buildingUid: anchorTarget?.buildingUid || "",
          objectName: anchorTarget?.objectName || "",
          roomName: ""
        });
      }
    }

    renderEventAreaHighlight(alignedPoint, Number(target.radius) || 8);
    if (recenter) {
      focusActiveViewOnPoint(alignedPoint, { distanceScale: 0.16 });
    }
    if (autoRoute && runtimeCanRoute && selectedAnchor) {
      activateDirectionsForSelectedBuilding();
    }
    return;
  }

  const buildingName = String(target.buildingName || "").trim();
  if (!buildingName) {
    renderEventPayloadWithFallbackMessage("The linked event destination is incomplete and cannot be focused on the map.");
    return;
  }

  const runtimeCanRoute = !!getRouteEntryForBuilding(buildingName);
  if (runtimePayload.canRoute !== runtimeCanRoute) {
    runtimePayload = {
      ...runtimePayload,
      canRoute: runtimeCanRoute,
      health: runtimeCanRoute ? runtimePayload.health : "limited",
      healthLabel: runtimeCanRoute ? runtimePayload.healthLabel : "Limited",
      healthMessage: runtimeCanRoute
        ? runtimePayload.healthMessage
        : "This event can open on the map, but no published route is available right now."
    };
  }
  renderEventFocusPayload(runtimePayload);

  const selected = selectBuildingByName(buildingName);
  if (!selected) {
    renderEventPayloadWithFallbackMessage("The linked destination could not be found in the current map model.");
    return;
  }

  const buildingEntry = getBuildingEntryByName(buildingName);
  if (recenter && buildingEntry?.object) {
    focusActiveViewOnObject(buildingEntry.object);
  }

  if (target.type === "room") {
    const buildingMeta = getDbBuildingMeta(buildingName);
    setSelectedGuideTarget({
      type: "room",
      buildingName,
      buildingUid: buildingMeta?.buildingUid || "",
      objectName: buildingMeta?.objectName || "",
      roomName: String(target.roomName || "").trim()
    });

    if (cardInfo) {
      cardInfo.textContent = runtimePayload.canRoute
        ? `Event is linked to ${String(target.roomName || "this room").trim()}. Select "Get Directions" to show the route.`
        : "This room can open on the map, but no published route is available for its building right now.";
    }
  } else {
    const buildingMeta = getDbBuildingMeta(buildingName);
    setSelectedGuideTarget({
      type: String(target.type || buildingMeta?.entityType || "building").trim() || "building",
      buildingName,
      buildingUid: buildingMeta?.buildingUid || "",
      objectName: buildingMeta?.objectName || "",
      roomName: ""
    });
  }

  if (autoRoute && runtimePayload.canRoute) {
    activateDirectionsForSelectedBuilding();
  }
}

async function loadRequestedEvent() {
  if (requestedEventId <= 0) return;
  if (activeEventPayload) {
    applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: false });
    return;
  }
  if (activeEventLoadPromise) return activeEventLoadPromise;

  activeEventLoadPromise = (async () => {
    try {
      const url = new URL(EVENT_DETAILS_ENDPOINT, window.location.href);
      url.searchParams.set("event", String(requestedEventId));
      url.searchParams.set("v", String(Date.now()));

      const response = await fetch(url, { cache: "no-store" });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.error || `Event fetch failed (${response.status})`);
      }

      if (!payload?.found || !payload?.event) {
        clearEventRequestParamsFromUrl();
        showEventNoticeCard("Event unavailable", payload?.message || "This event is unavailable or no longer public.");
        return;
      }

      activeEventPayload = {
        ...payload.event,
        schedule: String(payload.event.schedule || "").trim(),
        scheduleLabel: String(payload.event.scheduleLabel || formatEventScheduleLabel(payload.event.schedule || "")).trim(),
        health: String(payload.event.health || "limited").trim(),
        healthLabel: String(payload.event.healthLabel || "Limited").trim(),
      };
      clearEventRequestParamsFromUrl();
      renderEventFocusPayload(activeEventPayload);
      applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: true });
    } catch (error) {
      console.error("Event context failed to load:", error);
      window.tntsReportClientError?.("event_context_failed", "Event context failed to load", {
        eventId: eventIdFromUrl,
        error: {
          message: String(error?.message || error || ""),
          stack: String(error?.stack || ""),
        },
      });
      clearEventRequestParamsFromUrl();
      showEventNoticeCard("Event unavailable", error?.message || "The requested event could not be loaded.");
    } finally {
      activeEventLoadPromise = null;
    }
  })();

  return activeEventLoadPromise;
}

eventFocusCloseBtn?.addEventListener("click", () => {
  dismissActiveEventContext();
});

eventFocusMapBtn?.addEventListener("click", () => {
  if (!activeEventPayload?.canMap) return;
  applyActiveEventToMap({ autoRoute: false, recenter: true });
});

eventFocusRouteBtn?.addEventListener("click", () => {
  if (!activeEventPayload?.canRoute) return;
  activeEventAutoRoute = true;
  applyActiveEventToMap({ autoRoute: true, recenter: true });
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

  if (activeEventPayload) {
    applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: false });
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
  setPublishedRoadnet(payload?.roads || null);
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
  if (activeEventPayload && !shouldReload) {
    applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: false });
  }
}

async function bootLiveMap() {
  try {
    const payload = await fetchLiveMapPayload();
    await loadLiveMap(payload, { forceReloadModel: true });
  } catch (err) {
    console.warn("Live map unavailable, using fallback:", err);
    window.tntsReportClientError?.("live_map_fallback", "Live map unavailable, using fallback", {
      error: {
        message: String(err?.message || err || ""),
        stack: String(err?.stack || ""),
      },
    });
    rebuildRouteCatalog([]);
    clearPublishedRoadnet();
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
      if (activeEventPayload) {
        applyActiveEventToMap({ autoRoute: activeEventAutoRoute, recenter: false });
      }
    }
  } catch (_) {
    // Keep current map/routes when offline or temporarily unavailable.
  } finally {
    livePollBusy = false;
  }
}

bootLiveMap()
  .then(() => loadRequestedEvent())
  .catch((error) => {
    console.error("LIVE MAP BOOT ERROR:", error);
    window.tntsReportClientError?.("live_map_boot_error", "Live map boot error", {
      error: {
        message: String(error?.message || error || ""),
        stack: String(error?.stack || ""),
      },
    });
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
  return !!event.target?.closest?.("#info-card, #directions-panel, #event-focus-card, #map-view-controls, .topbar, .bottom-nav");
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
    buildingUid: buildingEntry.buildingUid || getDbBuildingMeta(buildingEntry.name)?.buildingUid || "",
    objectName: buildingEntry.objectName || buildingEntry.name,
    roomName: ""
  });
  if (!previousSelectedBuilding || normalizeQuery(previousSelectedBuilding) !== normalizeQuery(selectedBuildingName)) {
    clearRouteOnly();
    hideDirectionsPanel();
    clearRouteBtn.disabled = true;
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
  updateEventAreaHighlightAnimation(performance.now());
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
