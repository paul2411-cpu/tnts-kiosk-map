function normalizeToken(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_");
}

export function buildGuideKey(type, data = {}) {
  const safeType = normalizeToken(type) || "building";
  const buildingUid = String(data.buildingUid || data.uid || data.destinationUid || "").trim();
  const buildingName = String(data.buildingName || data.name || "").trim();
  const objectName = String(data.objectName || data.modelObjectName || "").trim();
  const roomName = String(data.roomName || "").trim();
  const buildingToken = buildingUid
    ? `uid_${normalizeToken(buildingUid)}`
    : normalizeToken(buildingName || objectName);

  if (safeType === "room") {
    return `room::${buildingToken}::${normalizeToken(roomName)}`;
  }
  return `${safeType}::${buildingToken}`;
}

export function toGuidePoint(raw) {
  if (Array.isArray(raw) && raw.length >= 3) {
    const x = Number(raw[0]);
    const y = Number(raw[1]);
    const z = Number(raw[2]);
    if (Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z)) {
      return { x, y, z };
    }
    return null;
  }

  const x = Number(raw?.x);
  const y = Number(raw?.y);
  const z = Number(raw?.z);
  if (Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z)) {
    return { x, y, z };
  }
  return null;
}

function distance2d(a, b) {
  return Math.hypot((b.x - a.x), (b.z - a.z));
}

function signedTurnDegrees(a, b, c) {
  const v1x = b.x - a.x;
  const v1z = b.z - a.z;
  const v2x = c.x - b.x;
  const v2z = c.z - b.z;

  const len1 = Math.hypot(v1x, v1z);
  const len2 = Math.hypot(v2x, v2z);
  if (len1 <= 1e-6 || len2 <= 1e-6) return 0;

  const n1x = v1x / len1;
  const n1z = v1z / len1;
  const n2x = v2x / len2;
  const n2z = v2z / len2;

  const dot = Math.max(-1, Math.min(1, (n1x * n2x) + (n1z * n2z)));
  const cross = (n1x * n2z) - (n1z * n2x);
  const angle = Math.acos(dot) * (180 / Math.PI);
  return cross < 0 ? -angle : angle;
}

function simplifyRoutePoints(points) {
  const out = [];
  for (const raw of (points || [])) {
    const point = toGuidePoint(raw);
    if (!point) continue;
    const last = out[out.length - 1];
    if (last && distance2d(last, point) < 0.5 && Math.abs(last.y - point.y) < 0.5) continue;
    out.push(point);
  }

  if (out.length < 3) return out;

  const simplified = [out[0]];
  for (let i = 1; i < out.length - 1; i++) {
    const prev = simplified[simplified.length - 1];
    const cur = out[i];
    const next = out[i + 1];
    const angle = Math.abs(signedTurnDegrees(prev, cur, next));
    const prevLen = distance2d(prev, cur);
    const nextLen = distance2d(cur, next);
    const nearlyStraight = angle < 12;
    const tinySegment = prevLen < 4 || nextLen < 4;
    if (nearlyStraight && tinySegment) continue;
    simplified.push(cur);
  }
  simplified.push(out[out.length - 1]);
  return simplified;
}

function classifyTurn(angle) {
  const abs = Math.abs(angle);
  if (abs < 20) return "straight";
  if (abs >= 150) return "u_turn";
  if (abs >= 75) return angle > 0 ? "left" : "right";
  return angle > 0 ? "slight_left" : "slight_right";
}

function landmarkTypeBonus(type) {
  const key = normalizeToken(type);
  if (key === "landmark") return 12;
  if (key === "building") return 8;
  if (key === "room") return 5;
  return 0;
}

function selectLandmark(turnPoint, prevPoint, nextPoint, landmarks, opts = {}) {
  const radius = Math.max(20, Number(opts.landmarkRadius) || 80);
  const destinationName = normalizeToken(opts.destinationName || "");
  if (!turnPoint || !prevPoint || !nextPoint || !Array.isArray(landmarks) || !landmarks.length) return null;

  const dirX = nextPoint.x - turnPoint.x;
  const dirZ = nextPoint.z - turnPoint.z;
  const dirLen = Math.hypot(dirX, dirZ) || 1;
  const nx = dirX / dirLen;
  const nz = dirZ / dirLen;

  let best = null;
  for (const raw of landmarks) {
    const point = toGuidePoint(raw);
    const name = String(raw?.name || "").trim();
    if (!point || !name) continue;
    if (normalizeToken(name) === destinationName) continue;

    const dx = point.x - turnPoint.x;
    const dz = point.z - turnPoint.z;
    const dist = Math.hypot(dx, dz);
    if (!Number.isFinite(dist) || dist > radius) continue;

    const dot = ((dx * nx) + (dz * nz)) / (dist || 1);
    if (dot < -0.35) continue;

    const score = (radius - dist) + (dot * 18) + landmarkTypeBonus(raw?.type);
    if (!best || score > best.score) {
      best = { name, score, distance: dist };
    }
  }

  return best && best.score >= 18 ? best : null;
}

function formatDistance(distance) {
  const rounded = Math.max(1, Math.round(Number(distance) || 0));
  return `${rounded} units`;
}

function buildTurnText(turnKind, landmark) {
  const base = turnKind === "left"
    ? "Turn left"
    : turnKind === "right"
      ? "Turn right"
      : turnKind === "slight_left"
        ? "Keep left"
        : turnKind === "slight_right"
          ? "Keep right"
          : "Turn around";

  if (landmark?.name) {
    return `${base} near ${landmark.name}.`;
  }
  return `${base}.`;
}

export function measureGuideDistance(points) {
  const safePoints = simplifyRoutePoints(points);
  let total = 0;
  for (let i = 1; i < safePoints.length; i++) {
    total += distance2d(safePoints[i - 1], safePoints[i]);
  }
  return total;
}

export function buildRouteSignature(points, distance = NaN) {
  const safePoints = simplifyRoutePoints(points);
  const pointSig = safePoints
    .map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)},${point.z.toFixed(2)}`)
    .join("|");
  const distSig = Number.isFinite(distance) ? Number(distance).toFixed(2) : measureGuideDistance(safePoints).toFixed(2);
  return `${distSig}::${pointSig}`;
}

export function buildGuideStepsFromPoints(points, opts = {}) {
  const safePoints = simplifyRoutePoints(points);
  if (safePoints.length < 2) return [];

  const destinationName = String(opts.destinationName || "").trim();
  const arrivalText = String(opts.arrivalText || "").trim() || (destinationName ? `Arrive at ${destinationName}.` : "Arrive at your destination.");
  const startText = String(opts.startText || "").trim() || "Start at the kiosk.";
  const landmarks = Array.isArray(opts.landmarks) ? opts.landmarks : [];
  const minStraightDistance = Math.max(8, Number(opts.minStraightDistance) || 14);

  const steps = [{ kind: "start", text: startText }];
  let carryStraightDistance = distance2d(safePoints[0], safePoints[1]);
  let turnCount = 0;

  for (let i = 1; i < safePoints.length - 1; i++) {
    const prev = safePoints[i - 1];
    const turn = safePoints[i];
    const next = safePoints[i + 1];
    const angle = signedTurnDegrees(prev, turn, next);
    const turnKind = classifyTurn(angle);
    const nextDistance = distance2d(turn, next);

    if (turnKind === "straight") {
      carryStraightDistance += nextDistance;
      continue;
    }

    if (carryStraightDistance >= minStraightDistance) {
      steps.push({
        kind: turnCount === 0 ? "continue" : "after_turn_continue",
        text: `${turnCount === 0 ? "Go" : "Continue"} straight for ${formatDistance(carryStraightDistance)}.`,
        distance: carryStraightDistance
      });
    }

    const landmark = selectLandmark(turn, prev, next, landmarks, { destinationName });
    steps.push({
      kind: turnKind,
      text: buildTurnText(turnKind, landmark),
      landmarkName: landmark?.name || ""
    });
    carryStraightDistance = nextDistance;
    turnCount += 1;
  }

  if (carryStraightDistance >= minStraightDistance) {
    steps.push({
      kind: turnCount === 0 ? "continue" : "after_turn_continue",
      text: `${turnCount === 0 ? "Go" : "Continue"} straight for ${formatDistance(carryStraightDistance)}.`,
      distance: carryStraightDistance
    });
  }

  steps.push({ kind: "arrive", text: arrivalText });
  return steps;
}
