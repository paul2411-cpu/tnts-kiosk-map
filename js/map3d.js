// js/map3d.js
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";

const mapStage = document.getElementById("map-stage");
if (!mapStage) throw new Error("Missing #map-stage");

const infoCard = document.getElementById("info-card");
const cardTitle = document.getElementById("card-title");
const cardInfo = document.getElementById("card-info");
const closeBtn = document.getElementById("close-btn");
const directionsBtn = document.getElementById("directions-btn");
const clearRouteBtn = document.getElementById("clear-route-btn");


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
        handleSearchSelect(kbTargetInput.value);
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
        handleSearchSelect(kbTargetInput.value);
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
      handleSearchSelect(input.value);
      hideKeyboard();
    }
  });

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
const camera = new THREE.PerspectiveCamera(
  55,
  1,      // temporary, fixed on resize
  0.001,  // near
  100000  // far
);
camera.position.set(0, 5, 10);

// --- Renderer (attach to mapStage, not body)
const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
mapStage.appendChild(renderer.domElement);
renderer.domElement.style.width = "100%";
renderer.domElement.style.height = "100%";
renderer.domElement.style.display = "block";
renderer.domElement.style.touchAction = "none";

// --- Controls
const controls = new OrbitControls(camera, renderer.domElement);

controls.enableDamping = true;
controls.dampingFactor = 0.1;

controls.enableRotate = true;
controls.enablePan = true;
controls.screenSpacePanning = true;

// ✅ FOV-ONLY ZOOM: Disable OrbitControls dolly/zoom completely
controls.enableZoom = false;
controls.zoomToCursor = false;

// Speeds (rotate/pan only now)
controls.rotateSpeed = 0.5;
controls.panSpeed = 0.5;

// Prevent middle mouse from dollying (since zoom is disabled anyway)
controls.mouseButtons = {
  LEFT: THREE.MOUSE.ROTATE,
  MIDDLE: THREE.MOUSE.PAN,
  RIGHT: THREE.MOUSE.PAN,
};

// Optional: remove touch pinch-dolly, keep rotate + pan
controls.touches = {
  ONE: THREE.TOUCH.ROTATE,
  TWO: THREE.TOUCH.PAN,
};

// --- Resize to container
function resizeToContainer() {
  const rect = mapStage.getBoundingClientRect();
  const w = Math.floor(rect.width);
  const h = Math.floor(rect.height);

  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}
resizeToContainer();

const ro = new ResizeObserver(() => resizeToContainer());
ro.observe(mapStage);
window.addEventListener("resize", () => resizeToContainer());

// --- Mouse coords relative to canvas rect
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

function setMouseFromEvent(event) {
  const rect = renderer.domElement.getBoundingClientRect();
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
}

window.addEventListener("mousemove", (event) => {
  setMouseFromEvent(event);
});

// ==========================
// ✅ FOV-ONLY WHEEL ZOOM
// ==========================
const FOV_MIN = 18;   // smaller = more zoom in
const FOV_MAX = 75;   // larger = more zoom out
const FOV_STEP = 0.03; // wheel sensitivity (trackpad friendly)

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

function onWheelFovZoom(e) {
  // IMPORTANT: stop OrbitControls / page scroll from handling it
  e.preventDefault();
  e.stopPropagation();

  // deltaY > 0 = scroll down = zoom out => increase FOV
  // deltaY < 0 = scroll up   = zoom in  => decrease FOV
  const nextFov = camera.fov + e.deltaY * FOV_STEP;
  camera.fov = clamp(nextFov, FOV_MIN, FOV_MAX);
  camera.updateProjectionMatrix();

  logCameraState("WHEEL_FOV");
}

// passive:false is REQUIRED so preventDefault() works
renderer.domElement.addEventListener("wheel", onWheelFovZoom, { passive: false });

// ==========================
// ✅ PINCH (TOUCH) FOV ZOOM
// ==========================
const PINCH_FOV_STEP = 0.05; // sensitivity (pixels -> fov delta)
const PINCH_START_THRESHOLD = 2; // px to decide pinch vs two-finger pan

const activeTouches = new Map(); // pointerId -> {x,y}
let pinchLastDist = 0;
let isPinching = false;

function getTouchDist() {
  const pts = Array.from(activeTouches.values());
  if (pts.length < 2) return 0;
  const a = pts[0];
  const b = pts[1];
  const dx = a.x - b.x;
  const dy = a.y - b.y;
  return Math.hypot(dx, dy);
}

function startPinchTracking() {
  if (activeTouches.size === 2) {
    pinchLastDist = getTouchDist();
    isPinching = false;
  }
}

function applyPinchZoom(delta) {
  const nextFov = camera.fov - delta * PINCH_FOV_STEP;
  camera.fov = clamp(nextFov, FOV_MIN, FOV_MAX);
  camera.updateProjectionMatrix();
}

function onTouchPointerDown(e) {
  if (e.pointerType !== "touch") return;
  activeTouches.set(e.pointerId, { x: e.clientX, y: e.clientY });
  if (activeTouches.size === 2) startPinchTracking();
}

function onTouchPointerMove(e) {
  if (e.pointerType !== "touch") return;
  if (!activeTouches.has(e.pointerId)) return;
  activeTouches.set(e.pointerId, { x: e.clientX, y: e.clientY });

  if (activeTouches.size === 2) {
    const dist = getTouchDist();
    const delta = dist - pinchLastDist;

    if (!isPinching && Math.abs(delta) >= PINCH_START_THRESHOLD) {
      isPinching = true;
      controls.enabled = false;
    }

    if (isPinching) {
      applyPinchZoom(delta);
      e.preventDefault();
      e.stopPropagation();
    }

    pinchLastDist = dist;
  }
}

function onTouchPointerUp(e) {
  if (e.pointerType !== "touch") return;
  activeTouches.delete(e.pointerId);
  if (activeTouches.size < 2) {
    if (isPinching) controls.enabled = true;
    isPinching = false;
    pinchLastDist = 0;
  }
}

renderer.domElement.addEventListener("pointerdown", onTouchPointerDown, { passive: false });
renderer.domElement.addEventListener("pointermove", onTouchPointerMove, { passive: false });
renderer.domElement.addEventListener("pointerup", onTouchPointerUp, { passive: false });
renderer.domElement.addEventListener("pointercancel", onTouchPointerUp, { passive: false });

// -------------------- ROUTES (unchanged)
// --------------------
const ROUTES = {
  "ANTERIO_SORIANO": ["KIOSK_START","KIOSK_START055","KIOSK_START057","ANTERIO_SORIANO"],
  "DELFIN_MONTANO": ["KIOSK_START","KIOSK_START055","KIOSK_START057","KIOSK_START058","DELFIN_MONTANO"],
  "EPIMASCO_VELASCO": ["KIOSK_START","KIOSK_START055","KIOSK_START056","EPIMASCO_VELASCO"],
  "J_REMULLA": ["KIOSK_START","KIOSK_START055","KIOSK_START001","J_REMULLA"],
  "TNTS_FASHION_HUB": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","TNTS_FASHION_HUB"],
  "FERNANDO_CAMPOS": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","KIOSK_START005","FERNANDO_CAMPOS"],
  "SOTA": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","SOTA"],
  "ERINEO_MALIKSI": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","ERINEO_MALIKSI"],
  "ICT": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","ICT"],
  "TESDA_ASSESSMENT": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","TESDA_ASSESSMENT"],
  "ADMIN": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","ADMIN"],
  "CANTEEN001": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START008","CANTEEN001"],
  "AMPHI_STAGE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START008","KIOSK_START009","KIOSK_START010","AMPHI_STAGE"],
  "TRADEAN_HALL": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","KIOSK_START005","KIOSK_START011","KIOSK_START012","KIOSK_START013","TRADEAN_HALL"],
  "FOODTECH_RFS": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","KIOSK_START005","KIOSK_START011","KIOSK_START012","KIOSK_START013","KIOSK_START014","FOODTECH_RFS"],
  "CANTEEN": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","CANTEEN"],
  "DEPED_3": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","DEPED_3"],
  "AUTOMOTIVE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START024","KIOSK_START025","AUTOMOTIVE"],
  "DRESSMAKING_TAILORING": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","KIOSK_START005","KIOSK_START011","KIOSK_START012","KIOSK_START013","KIOSK_START014","KIOSK_START015","DRESSMAKING_TAILORING"],
  "ELECTRICITY_WOOD_TRADE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START004","KIOSK_START005","KIOSK_START011","KIOSK_START012","KIOSK_START013","KIOSK_START014","KIOSK_START015","ELECTRICITY_WOOD_TRADE"],
  "ALUMNI": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","ALUMNI"],
  "COURT_STAGE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","COURT_STAGE"],

  "EMILO_RIEGO": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","KIOSK_START026","KIOSK_START049","KIOSK_START050","EMILO_RIEGO"],
  "LINO_BOCALAN": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","KIOSK_START026","KIOSK_START049","KIOSK_START050","KIOSK_START051","LINO_BOCALAN"],
  "DEPED_1": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START029","DEPED_1"],
  "DEPED_2": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START030","KIOSK_START031","DEPEDE_2"],
  "ELECTRONICS": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","ELECTRONICS"],
  "LUIS_O_FERRER": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","KIOSK_START044","KIOSK_START045","KIOSK_START046","LUIS_O_FERRER"],
  "LUIS_Y_FERRER": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","KIOSK_START044","KIOSK_START045","KIOSK_START046","LUIS_Y_FERRER"],
  "DOMINADOR_CAMERINO": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","KIOSK_START026","KIOSK_START049","KIOSK_START050","KIOSK_START051","KIOSK_START052","DOMINADOR_CAMERINO"],
  "AUS_AID": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","KIOSK_START026","KIOSK_START049","KIOSK_START050","KIOSK_START051","KIOSK_START052","KIOSK_START053","AUS_AID"],
  "FABIAN_PUGEDA": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START026","KIOSK_START026","KIOSK_START049","KIOSK_START050","KIOSK_START051","KIOSK_START052","KIOSK_START053","KIOSK_START054","FABIAN_PUGEDA"],
  "DON_POBLETE_BLDG": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","KIOSK_START044","KIOSK_START045","KIOSK_START046","KIOSK_START047","KIOSK_START048","DON_POBLETE_BLDG"],
  "RODRIGUEZ_BLDG": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START021","KIOSK_START043","KIOSK_START044","KIOSK_START045","KIOSK_START046","KIOSK_START047","KIOSK_START042","RODRIGUEZ_BLDG"],
  "SOFT_TRADE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START029","KIOSK_START033","SOFT_TRADE"],
  "SOFT_TRADE_2": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START030","KIOSK_START031","KIOSK_START035","KIOSK_START032","SOFT_TRADE_2"],
  "WELDING": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START030","KIOSK_START031","KIOSK_START035","KIOSK_START034","KIOSK_START036","WELDING"],
  "PPP_1": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START029","KIOSK_START033","KIOSK_START039","PPP_1"],
  "PPP_2": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START030","KIOSK_START031","KIOSK_START035","KIOSK_START034","KIOSK_START036","KIOSK_START037","PPP_2"],
  "SAMONTE": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START030","KIOSK_START031","KIOSK_START035","KIOSK_START034","KIOSK_START036","KIOSK_START038","SAMONTE"],
  "ARCA": ["KIOSK_START","KIOSK_START055","KIOSK_START001","KIOSK_START006","KIOSK_START002","KIOSK_START003","KIOSK_START007","KIOSK_START017","KIOSK_START018","KIOSK_START023","KIOSK_START019","KIOSK_START020","KIOSK_START022","KIOSK_START027","KIOSK_START028","KIOSK_START029","KIOSK_START033","KIOSK_START039","KIOSK_START040","ARCA"],
};
const CLICKABLE_BUILDINGS = new Set(Object.keys(ROUTES));




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

function findBestBuildingMatch(queryNorm) {
  if (!queryNorm) return null;

  // 1) Exact match (ALUMNI)
  if (CLICKABLE_BUILDINGS.has(queryNorm)) return queryNorm;

  // 2) Exact match ignoring underscores/spaces
  const flat = queryNorm.replace(/_/g, "");
  for (const name of CLICKABLE_BUILDINGS) {
    if (name.replace(/_/g, "") === flat) return name;
  }

  // 3) Partial match (e.g. "ALUMI" → "ALUMNI")
  for (const name of CLICKABLE_BUILDINGS) {
    if (name.includes(queryNorm)) return name;
  }

  // 4) Partial match ignoring underscores
  for (const name of CLICKABLE_BUILDINGS) {
    if (name.replace(/_/g, "").includes(flat)) return name;
  }

  return null;
}

function selectBuildingByName(buildingName) {
  if (!loadedModel || !buildingName) return false;

  const anchor =
    getObjectByNameFlexible(loadedModel, buildingName) ||
    loadedModel.getObjectByName(buildingName);

  if (!anchor) return false;

  // Find a mesh under that anchor for outline (if anchor is an Empty)
  let meshForOutline = null;
  anchor.traverse((o) => {
    if (!meshForOutline && o.isMesh && o.geometry) meshForOutline = o;
  });

  // Fallback: if the anchor itself is mesh
  if (!meshForOutline && anchor.isMesh) meshForOutline = anchor;

  if (!meshForOutline) return false;

  selectedBuildingName = buildingName;

  // Clear previous selection visuals
  clearHoverTint();
  clearHoverOutline();
  clearClickOutline();

  setClickOutline(meshForOutline);
  showCard(buildingName);

  return true;
}

function handleSearchSelect(rawValue) {
  pendingSearchQuery = rawValue; // remember latest search even if GLB not loaded yet

  if (!loadedModel) return; // model not ready yet

  const q = normalizeQuery(rawValue);
  if (!q) {
    // if user cleared search, hide card + selection
    hideCardAndClear();
    return;
  }

  const match = findBestBuildingMatch(q);
  if (!match) return; // no match; keep current selection

  selectBuildingByName(match);
}



// --- Interaction state
let loadedModel = null;

let hoveredMesh = null;
let hoveredOriginalMat = null;

let clickOutline = null;
let hoverOutline = null;
let selectedBuildingName = null;

let routeMesh = null;

function clearHoverTint() {
  if (hoveredMesh && hoveredOriginalMat) hoveredMesh.material = hoveredOriginalMat;
  hoveredMesh = null;
  hoveredOriginalMat = null;
}

function applyHoverTint(mesh) {
  clearHoverTint();
  hoveredMesh = mesh;
  hoveredOriginalMat = mesh.material;

  const mat = mesh.material.clone();
  if (mat.color) mat.color.set(0xaaaaaa);
  mesh.material = mat;
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

function setClickOutline(mesh) {
  clearClickOutline();
  if (!mesh || !mesh.geometry) return;

  const edges = new THREE.EdgesGeometry(mesh.geometry);
  const mat = new THREE.LineBasicMaterial({ color: 0x000000 });
  clickOutline = new THREE.LineSegments(edges, mat);
  mesh.add(clickOutline);
  clickOutline.raycast = () => null;
}

function clearRouteOnly() {
  if (routeMesh) {
    scene.remove(routeMesh);
    routeMesh.geometry.dispose();
    routeMesh.material.dispose();
    routeMesh = null;
  }
}

function showCard(buildingName) {
  infoCard.classList.remove("hidden");
  cardTitle.textContent = buildingName;
  cardInfo.textContent = "Select “Get Directions” to show the route.";
  directionsBtn.disabled = false;
}

function hideCardAndClear() {
  infoCard.classList.add("hidden");
  selectedBuildingName = null;
  directionsBtn.disabled = true;
  clearRouteBtn.disabled = true;

  clearRouteOnly();
  clearClickOutline();
}

closeBtn.addEventListener("click", () => hideCardAndClear());

directionsBtn.addEventListener("click", () => {
  if (!loadedModel || !selectedBuildingName) return;

  const routeNames = ROUTES[selectedBuildingName];
  if (!routeNames) {
    console.warn("No route configured for:", selectedBuildingName);
    return;
  }

  const points = resolveRoutePoints(routeNames);

  if (points.length < 2) {
    console.warn("Route points < 2. Check console ROUTE DEBUG MISSING list.");
    return;
  }

  drawRibbonPath(points);
  clearRouteBtn.disabled = false;
});

clearRouteBtn.addEventListener("click", () => {
  clearRouteOnly();
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

function findBuildingNameFromHit(hitObject) {
  let o = hitObject;
  while (o) {
    if (o.name && CLICKABLE_BUILDINGS.has(o.name)) return o.name;
    o = o.parent;
  }
  return null;
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

function drawRibbonPath(points) {
  clearRouteOnly();
  if (!points || points.length < 2) return;

  const lift = 0.12;
  const thickness = 1.2;

  const positions = [];
  const indices = [];
  let vi = 0;

  for (let i = 0; i < points.length - 1; i++) {
    const a = points[i].clone(); a.y += lift;
    const b = points[i + 1].clone(); b.y += lift;

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

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
  geometry.setIndex(indices);
  geometry.computeVertexNormals();

  const material = new THREE.MeshBasicMaterial({
    color: 0xff0000,
    transparent: true,
    opacity: 1.0,
    side: THREE.DoubleSide,
    depthWrite: false,
    depthTest: true,
  });

  routeMesh = new THREE.Mesh(geometry, material);
  routeMesh.renderOrder = 999;
  scene.add(routeMesh);
}

// --- Load model
const MODEL_URL = "/tnts/models/tnts_map.glb";

const loader = new GLTFLoader();
console.log("MODEL_URL RESOLVED:", new URL(MODEL_URL, import.meta.url).href);

loader.load(
  MODEL_URL,
  (gltf) => {
    loadedModel = gltf.scene;
    scene.add(loadedModel);

    // 1. Calculate Bounding Box
    const box = new THREE.Box3().setFromObject(loadedModel);
    const size = box.getSize(new THREE.Vector3()).length();
    const center = box.getCenter(new THREE.Vector3());

    // 2. Center Controls
    controls.target.copy(center);

    // 3. Fix Camera Position
    const STARTUP_CAMERA_CLOSENESS = 0.5;
    const direction = new THREE.Vector3(1, 0.8, 1).normalize();
    camera.position.copy(center).add(direction.multiplyScalar(size * STARTUP_CAMERA_CLOSENESS));

    // 4. Update Planes
    camera.near = Math.max(0.01, size / 1000);
    camera.far  = size * 100;
    camera.updateProjectionMatrix();

    // 5. OrbitControls limits
    controls.minDistance = 0.0;
    controls.maxDistance = size * 5.0;

    // 6. Update controls
    controls.update();

    directionsBtn.disabled = false;
    clearRouteBtn.disabled = true;

    // ==========================
    // ✅ APPLY SEARCH AFTER MODEL LOAD
    // ==========================
    if (typeof pendingSearchQuery !== "undefined" && pendingSearchQuery.trim()) {
      handleSearchSelect(pendingSearchQuery);
    }

    console.log("GLB loaded. Size:", size, "Center:", center);
  },
  undefined,
  (error) => {
    console.error("GLB LOAD ERROR:", error);
    alert("GLB failed to load. Check MODEL_URL path: " + MODEL_URL);
  }
);


// --- Click selection
window.addEventListener("click", (event) => {
  if (event.target.closest("#info-card")) return;
  if (!loadedModel) return;

  setMouseFromEvent(event);

  raycaster.setFromCamera(mouse, camera);
  const intersects = raycaster.intersectObjects(loadedModel.children, true);

  if (intersects.length === 0) {
    hideCardAndClear();
    return;
  }

  const hit = intersects[0].object;
  const buildingName = findBuildingNameFromHit(hit);
  if (!buildingName) {
    hideCardAndClear();
    return;
  }

  selectedBuildingName = buildingName;

  const outlineTargetMesh = findBestOutlineMesh(hit);
  if (outlineTargetMesh) setClickOutline(outlineTargetMesh);

  showCard(buildingName);
});

// --- Hover
function animate() {
  requestAnimationFrame(animate);

  if (loadedModel) {
    raycaster.setFromCamera(mouse, camera);
    const intersects = raycaster.intersectObjects(loadedModel.children, true);

    if (intersects.length > 0) {
      const hit = intersects[0].object;
      const buildingName = findBuildingNameFromHit(hit);

      if (buildingName) {
        const mesh = findBestOutlineMesh(hit);
        if (mesh && hoveredMesh !== mesh) {
          applyHoverTint(mesh);
          setHoverOutline(mesh);
        }
      } else {
        clearHoverTint();
        clearHoverOutline();
      }
    } else {
      clearHoverTint();
      clearHoverOutline();
    }
  }

  controls.update();
  renderer.render(scene, camera);
}

// --- CAMERA DEBUG (DEVTOOLS)
window.__camDebug = { camera, controls };

function logCameraState(tag = "") {
  const camPos = camera.position;
  const target = controls.target;
  const distance = camPos.distanceTo(target);

  console.log(
    `%c[CAMERA ${tag}]`,
    "color:#00aaff;font-weight:bold",
    {
      pos: { x: camPos.x.toFixed(3), y: camPos.y.toFixed(3), z: camPos.z.toFixed(3) },
      target: { x: target.x.toFixed(3), y: target.y.toFixed(3), z: target.z.toFixed(3) },
      distance: distance.toFixed(3),
      fov: camera.fov.toFixed(2),
      minDistance: controls.minDistance,
      maxDistance: controls.maxDistance,
    }
  );
}

animate();
