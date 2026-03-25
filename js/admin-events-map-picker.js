import * as THREE from "../three.js-master/build/three.module.js";
import { GLTFLoader } from "../three.js-master/examples/jsm/loaders/GLTFLoader.js";
import { OrbitControls } from "../three.js-master/examples/jsm/controls/OrbitControls.js";

let pickerInstance = null;

function isElementVisible(element) {
  if (!element || !element.isConnected) return false;
  const rect = element.getBoundingClientRect();
  return rect.width > 0 && rect.height > 0;
}

function createAdminEventsMapPicker() {
  const configEl = document.getElementById("events-admin-picker-config");
  const stage = document.getElementById("event-area-picker");
  const coordsEl = document.getElementById("event-area-coords");
  const statusEl = document.getElementById("event-area-status");
  const clearBtn = document.getElementById("event-area-clear");
  const radiusInput = document.getElementById("map_radius");
  const xInput = document.getElementById("map_point_x");
  const yInput = document.getElementById("map_point_y");
  const zInput = document.getElementById("map_point_z");
  const modelFileInput = document.getElementById("map_model_file");
  const anchorSelect = document.getElementById("specific_area_anchor_building_id");

  if (!configEl || !stage || !coordsEl || !statusEl || !clearBtn || !radiusInput || !xInput || !yInput || !zInput || !modelFileInput) {
    return null;
  }

  let config = { enabled: false, modelUrl: "", modelFile: "" };
  try {
    config = JSON.parse(configEl.textContent || "{}");
  } catch (_) {}

  const routeAnchors = Array.isArray(config.routeAnchors) ? config.routeAnchors : [];

  function normalizeKey(value) {
    return String(value || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_]+/g, "");
  }

  function isRouteAnchorValue(value) {
    const safeValue = String(value || "").trim();
    if (!safeValue) return false;
    return routeAnchors.some((anchor) => String(anchor?.id || "") === safeValue);
  }

  function findRouteAnchorByRootName(rootName) {
    const normalized = normalizeKey(rootName);
    if (!normalized) return null;
    return routeAnchors.find((anchor) => {
      const candidates = [
        anchor?.objectName,
        anchor?.name,
        anchor?.buildingUid
      ];
      return candidates.some((candidate) => normalizeKey(candidate) === normalized);
    }) || null;
  }

  function markAnchorSelectionMode(mode, anchorId = "") {
    if (!anchorSelect) return;
    anchorSelect.dataset.autoDetected = mode === "auto" ? "1" : "0";
    anchorSelect.dataset.autoAnchorId = String(anchorId || "");
  }

  function setAnchorSelection(anchor) {
    if (!anchorSelect) return false;
    const anchorId = String(anchor?.id || "").trim();
    if (!anchorId) return false;
    const option = Array.from(anchorSelect.options || []).find((entry) => entry.value === anchorId);
    if (!option) return false;
    anchorSelect.value = anchorId;
    markAnchorSelectionMode("auto", anchorId);
    return true;
  }

  function clearAutoAnchorSelection() {
    if (!anchorSelect) return;
    if (anchorSelect.dataset.autoDetected !== "1") return;
    anchorSelect.value = "";
    markAnchorSelectionMode("manual", "");
  }

  function getTopLevelNamedRoot(object) {
    let node = object || null;
    let candidate = null;
    while (node && node !== loadedModel) {
      if (node.parent === loadedModel) {
        return node;
      }
      if (node.name) candidate = node;
      node = node.parent || null;
    }
    return candidate;
  }

  if (!config.enabled || !config.modelUrl) {
    statusEl.textContent = "Map preview is unavailable until a public map is published.";
    clearBtn.disabled = true;
    return null;
  }

  if (!isElementVisible(stage)) {
    return null;
  }

  stage.style.cursor = "crosshair";

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0xf8fafc);

  let renderer = null;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  } catch (error) {
    console.error("Event area picker could not create a WebGL renderer:", error);
    statusEl.textContent = "The map preview could not start on this browser.";
    clearBtn.disabled = true;
    return null;
  }

  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.domElement.style.width = "100%";
  renderer.domElement.style.height = "100%";
  renderer.domElement.style.display = "block";
  stage.replaceChildren(renderer.domElement);

  const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100000);
  camera.position.set(20, 20, 20);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.screenSpacePanning = false;
  controls.maxPolarAngle = Math.PI / 2.05;
  controls.minDistance = 2;
  controls.maxDistance = 5000;

  scene.add(new THREE.AmbientLight(0xffffff, 1.8));
  const sun = new THREE.DirectionalLight(0xffffff, 1.15);
  sun.position.set(25, 50, 15);
  scene.add(sun);

  const loader = new GLTFLoader();
  const raycaster = new THREE.Raycaster();
  const mouse = new THREE.Vector2();
  const box = new THREE.Box3();
  const sphere = new THREE.Sphere();
  const fitDirection = new THREE.Vector3(1, 1.3, 1).normalize();

  let loadedModel = null;
  let areaGroup = null;
  let animationFrameId = 0;

  function setStatus(message) {
    statusEl.textContent = message;
  }

  function formatCoord(value) {
    return Number.isFinite(value) ? value.toFixed(2) : "0.00";
  }

  function updateCoordsLabel(point = null) {
    if (!point) {
      coordsEl.textContent = "No event area selected yet.";
      return;
    }
    coordsEl.textContent = `X ${formatCoord(point.x)} | Y ${formatCoord(point.y)} | Z ${formatCoord(point.z)}`;
  }

  function parsePointInputs() {
    const x = Number.parseFloat(xInput.value);
    const y = Number.parseFloat(yInput.value);
    const z = Number.parseFloat(zInput.value);
    if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(z)) return null;
    return new THREE.Vector3(x, y, z);
  }

  function getRadiusValue() {
    const parsed = Number.parseFloat(radiusInput.value);
    if (!Number.isFinite(parsed) || parsed <= 0) return 8;
    return parsed;
  }

  function ensureAreaGroup() {
    if (areaGroup) return areaGroup;

    const ringGeo = new THREE.RingGeometry(5.2, 6.2, 64);
    const ringMat = new THREE.MeshBasicMaterial({
      color: 0x9f1239,
      transparent: true,
      opacity: 0.7,
      side: THREE.DoubleSide,
      depthWrite: false,
    });
    const ring = new THREE.Mesh(ringGeo, ringMat);
    ring.rotation.x = -Math.PI / 2;

    const coreGeo = new THREE.SphereGeometry(0.9, 24, 16);
    const coreMat = new THREE.MeshStandardMaterial({
      color: 0xdc2626,
      emissive: 0x7f1d1d,
      emissiveIntensity: 0.45,
    });
    const core = new THREE.Mesh(coreGeo, coreMat);
    core.position.y = 1.1;

    const pillarGeo = new THREE.CylinderGeometry(0.08, 0.08, 2.2, 16);
    const pillarMat = new THREE.MeshStandardMaterial({ color: 0x111827 });
    const pillar = new THREE.Mesh(pillarGeo, pillarMat);
    pillar.position.y = 1.1;

    areaGroup = new THREE.Group();
    areaGroup.add(ring, core, pillar);
    scene.add(areaGroup);
    return areaGroup;
  }

  function clearAreaSelection() {
    xInput.value = "";
    yInput.value = "";
    zInput.value = "";
    updateCoordsLabel(null);
    if (areaGroup) areaGroup.visible = false;
    clearAutoAnchorSelection();
    setStatus("Select a point on the map preview.");
  }

  function syncAreaMarker() {
    const point = parsePointInputs();
    if (!point) {
      if (areaGroup) areaGroup.visible = false;
      updateCoordsLabel(null);
      return;
    }

    const radius = Math.max(2, getRadiusValue());
    const group = ensureAreaGroup();
    group.visible = true;
    group.position.copy(point);

    const ring = group.children[0];
    if (ring?.geometry) ring.geometry.dispose();
    ring.geometry = new THREE.RingGeometry(radius * 0.82, radius, 64);
    ring.rotation.x = -Math.PI / 2;

    updateCoordsLabel(point);
  }

  function setAreaPoint(point, hit = null) {
    xInput.value = point.x.toFixed(4);
    yInput.value = point.y.toFixed(4);
    zInput.value = point.z.toFixed(4);
    modelFileInput.value = String(config.modelFile || "");
    syncAreaMarker();

    const hitRoot = getTopLevelNamedRoot(hit?.object || null);
    const matchedAnchor = hitRoot ? findRouteAnchorByRootName(hitRoot.name) : null;
    const anchorApplied = matchedAnchor ? setAnchorSelection(matchedAnchor) : false;

    if (anchorApplied) {
      const anchorName = String(matchedAnchor?.name || hitRoot?.name || "linked destination").trim();
      setStatus(`Area pinned and linked to ${anchorName}. Save the event to keep this highlight.`);
    } else {
      clearAutoAnchorSelection();
      setStatus("Area pinned. Save the event to keep this highlight.");
    }
  }

  function resize() {
    if (!isElementVisible(stage)) return;
    const rect = stage.getBoundingClientRect();
    const width = Math.max(1, Math.floor(rect.width));
    const height = Math.max(1, Math.floor(rect.height));
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
  }

  function fitCameraToModel() {
    if (!loadedModel || !isElementVisible(stage)) return;
    resize();

    box.setFromObject(loadedModel);
    box.getBoundingSphere(sphere);
    const radius = Math.max(sphere.radius, 1);
    const verticalFov = THREE.MathUtils.degToRad(camera.fov);
    const horizontalFov = 2 * Math.atan(Math.tan(verticalFov / 2) * camera.aspect);
    const fitDistance = Math.max(
      radius / Math.tan(verticalFov / 2),
      radius / Math.tan(horizontalFov / 2)
    ) * 1.15;

    camera.near = Math.max(0.1, fitDistance / 200);
    camera.far = Math.max(1000, fitDistance * 40);
    camera.position.copy(sphere.center).add(fitDirection.clone().multiplyScalar(fitDistance));
    controls.target.copy(sphere.center);
    controls.minDistance = Math.max(2, fitDistance * 0.2);
    controls.maxDistance = fitDistance * 6;
    camera.updateProjectionMatrix();
    controls.update();
  }

  function pickSurface(event) {
    if (!loadedModel) return null;
    const rect = renderer.domElement.getBoundingClientRect();
    mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(mouse, camera);
    const intersections = raycaster.intersectObjects(loadedModel.children, true);
    return intersections.length ? intersections[0] : null;
  }

  renderer.domElement.addEventListener("click", (event) => {
    const hit = pickSurface(event);
    if (!hit?.point) return;
    setAreaPoint(hit.point, hit);
  });

  clearBtn.addEventListener("click", clearAreaSelection);
  radiusInput.addEventListener("input", syncAreaMarker);
  anchorSelect?.addEventListener("change", () => {
    if (!anchorSelect) return;
    if (anchorSelect.dataset.autoDetected === "1" && anchorSelect.dataset.autoAnchorId === String(anchorSelect.value || "")) {
      return;
    }
    markAnchorSelectionMode("manual", "");
  });

  const resizeObserver = new ResizeObserver(() => {
    resize();
    if (loadedModel) fitCameraToModel();
  });
  resizeObserver.observe(stage);
  window.addEventListener("resize", resize);

  setStatus("Loading public map preview...");

  loader.load(
    config.modelUrl,
    (gltf) => {
      loadedModel = gltf.scene;
      scene.add(loadedModel);
      fitCameraToModel();
      syncAreaMarker();
      if (parsePointInputs()) {
        setStatus("Existing event area loaded. Click the preview to move it.");
      } else {
        setStatus("Click the map preview to place the event area.");
      }
    },
    undefined,
    (error) => {
      console.error("Event area picker failed to load the map:", error);
      setStatus("The map preview could not be loaded.");
    }
  );

  function animate(time = 0) {
    animationFrameId = window.requestAnimationFrame(animate);
    controls.update();
    if (areaGroup?.visible) {
      const pulse = 1 + (Math.sin(time / 340) * 0.04);
      areaGroup.children[0].scale.setScalar(pulse);
    }
    renderer.render(scene, camera);
  }

  resize();
  animate();

  return {
    resize,
    refit: fitCameraToModel,
    destroy() {
      if (animationFrameId) {
        window.cancelAnimationFrame(animationFrameId);
      }
      resizeObserver.disconnect();
      window.removeEventListener("resize", resize);
      renderer.dispose();
    }
  };
}

function ensurePickerReady() {
  if (!pickerInstance) {
    pickerInstance = createAdminEventsMapPicker();
    return;
  }

  pickerInstance.resize();
  pickerInstance.refit();
}

window.addEventListener("events:location-mode-change", (event) => {
  const mode = String(event?.detail?.mode || "");
  if (mode !== "specific_area") return;
  window.requestAnimationFrame(() => {
    ensurePickerReady();
  });
});

window.requestAnimationFrame(() => {
  const modeSelect = document.getElementById("location_mode");
  if (modeSelect && modeSelect.value === "specific_area") {
    ensurePickerReady();
  }
});
