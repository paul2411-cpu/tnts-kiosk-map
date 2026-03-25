function trim(value) {
  return String(value || "").trim();
}

export function stripBlenderNumericSuffix(name) {
  const safe = trim(name);
  if (!safe) return "";
  return safe.replace(/\.\d+$/, "");
}

export function normalizeMachineToken(value) {
  const safe = trim(value);
  if (!safe) return "";
  return safe
    .replace(/[\\/]+/g, "_")
    .replace(/[\s._-]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .toUpperCase();
}

function humanizeToken(token) {
  const safe = trim(token);
  if (!safe) return "";
  if (/[0-9]/.test(safe) || /^[A-Z]{1,5}$/.test(safe)) return safe;
  if (/^[A-Z][a-z0-9]+$/.test(safe)) return safe;
  const lower = safe.toLowerCase();
  return lower.charAt(0).toUpperCase() + lower.slice(1);
}

export function humanizeEntityLabel(label) {
  const safe = trim(label);
  if (!safe) return "";
  const parts = safe
    .replace(/[\\/]+/g, "_")
    .replace(/[\s.-]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .split("_")
    .filter(Boolean);
  return parts.map(humanizeToken).join(" ");
}

export function isGenericSceneName(name) {
  const raw = trim(name).toUpperCase();
  if (!raw) return true;
  const flat = raw.replace(/([._-]\d+)+$/g, "");
  return ["SCENE", "AUXSCENE", "ROOT", "ROOTNODE", "GLTF", "MODEL", "GROUP", "COLLECTION", "SCENECOLLECTION", "MASTERCOLLECTION"].includes(flat);
}

export function isGroundLikeName(name) {
  const safe = trim(name).toLowerCase();
  if (!safe) return false;
  if (safe === "ground" || safe === "cground") return true;
  return /(^|[^a-z0-9])c?ground($|[^a-z0-9])/i.test(safe);
}

export function isRoadLikeObjectName(name) {
  return /^road(?:[._-]\d+)?$/i.test(trim(name));
}

export function isWaypointLikeName(name) {
  return /^KIOSK_START(?:[._-]?\d+)?$/i.test(trim(name));
}

export function splitEntityPrefix(name) {
  const base = stripBlenderNumericSuffix(name);
  const match = /^([A-Za-z]+)[_\-\s]+(.+)$/.exec(base);
  if (!match) return { prefix: "", label: base };
  let prefix = trim(match[1]).toUpperCase();
  if (prefix === "LANDMARK") prefix = "LMK";
  return { prefix, label: trim(match[2]) };
}

export function classifyTopLevelObjectName(name) {
  const rawName = trim(name);
  const baseName = stripBlenderNumericSuffix(rawName);
  const { prefix, label } = splitEntityPrefix(baseName);
  const ignored = {
    rawName,
    baseName,
    prefix,
    label,
    include: false,
    bucket: "ignore",
    entityType: "",
    displayName: ""
  };

  if (!baseName || isGenericSceneName(baseName) || isGroundLikeName(baseName) || isRoadLikeObjectName(baseName) || isWaypointLikeName(baseName)) {
    return ignored;
  }
  if (prefix === "OB") return ignored;
  if (prefix === "FAC") {
    return {
      ...ignored,
      include: true,
      bucket: "facilities",
      entityType: "facility",
      displayName: humanizeEntityLabel(label || baseName)
    };
  }

  let entityType = "building";
  if (prefix === "VENUE") entityType = "venue";
  else if (prefix === "AREA") entityType = "area";
  else if (prefix === "LMK") entityType = "landmark";

  return {
    ...ignored,
    include: true,
    bucket: "buildings",
    entityType,
    displayName: humanizeEntityLabel(label || baseName)
  };
}

function parseLegacyRoomDigits(name) {
  const source = trim(name);
  if (!source || !/^ROOM(?:$|[\s._-]|\d)/i.test(source)) return null;
  let tail = source.replace(/^ROOM/i, "");
  tail = tail.replace(/^[\s._-]+/, "");
  tail = tail.replace(/\s+/g, "");
  if (!tail || !/^[0-9._-]+$/.test(tail)) return null;

  let parsed = null;
  let match = /^(\d+)[._-](\d{3})$/.exec(tail);
  if (match) {
    parsed = { kind: "explicit_suffix", baseDigits: match[1], suffixDigits: match[2], originalDigits: `${match[1]}${match[2]}` };
  } else {
    match = /^(\d+)[._-](\d{1,3})$/.exec(tail);
    if (match) {
      parsed = { kind: "loader_suffix", baseDigits: match[1], suffixDigits: match[2], originalDigits: `${match[1]}${match[2]}` };
    } else {
      match = /^(\d+)$/.exec(tail);
      if (!match) return null;
      const digits = match[1];
      parsed = digits.length <= 3
        ? { kind: "plain", baseDigits: digits, suffixDigits: "", originalDigits: digits }
        : { kind: "compact_suffix", baseDigits: digits.slice(0, -3), suffixDigits: digits.slice(-3), originalDigits: digits };
    }
  }
  return parsed;
}

function inspectRoomObjectName(name) {
  const rawName = trim(name);
  if (!rawName) return null;

  const { prefix, label } = splitEntityPrefix(rawName);
  if (prefix === "RM") {
    const canonicalObjectName = stripBlenderNumericSuffix(rawName);
    const cleaned = normalizeMachineToken(label.replace(/\.\d+$/, ""));
    if (!cleaned) return null;
    const isNumeric = /^\d+$/.test(cleaned);
    return {
      rawName,
      objectName: rawName,
      canonicalObjectName,
      roomName: isNumeric ? `ROOM ${cleaned}` : humanizeEntityLabel(cleaned),
      roomNumber: isNumeric ? cleaned : "",
      sourceKind: "rm",
      legacyParsed: null
    };
  }

  const legacyParsed = parseLegacyRoomDigits(rawName);
  if (!legacyParsed) return null;
  return {
    rawName,
    objectName: rawName,
    canonicalObjectName: "",
    roomName: "",
    roomNumber: "",
    sourceKind: "legacy_room",
    legacyParsed
  };
}

function rememberCanonicalRoomDigits(digits, buildingName, canonicalBaseSet, canonicalByBuilding) {
  const safeDigits = trim(digits);
  if (!safeDigits) return;
  canonicalBaseSet.add(safeDigits);
  const safeBuildingName = trim(buildingName);
  if (!safeBuildingName) return;
  if (!canonicalByBuilding.has(safeBuildingName)) {
    canonicalByBuilding.set(safeBuildingName, new Set());
  }
  canonicalByBuilding.get(safeBuildingName).add(safeDigits);
}

export function buildRoomNormalizationContext(roomEntries = []) {
  const canonicalBaseSet = new Set();
  const canonicalByBuilding = new Map();
  const compactByBaseCount = new Map();

  for (const entry of Array.isArray(roomEntries) ? roomEntries : []) {
    const inspected = inspectRoomObjectName(entry?.name || "");
    if (!inspected) continue;
    const buildingName = trim(entry?.buildingName || "");

    if (inspected.sourceKind === "rm") {
      if (inspected.roomNumber) {
        rememberCanonicalRoomDigits(inspected.roomNumber, buildingName, canonicalBaseSet, canonicalByBuilding);
      }
      continue;
    }

    const parsed = inspected.legacyParsed;
    if (!parsed) continue;
    if (parsed.kind === "plain" || parsed.kind === "explicit_suffix") {
      rememberCanonicalRoomDigits(parsed.baseDigits || parsed.originalDigits || "", buildingName, canonicalBaseSet, canonicalByBuilding);
      continue;
    }

    if (parsed.kind === "compact_suffix") {
      const baseDigits = trim(parsed.baseDigits || "");
      if (baseDigits) {
        compactByBaseCount.set(baseDigits, (compactByBaseCount.get(baseDigits) || 0) + 1);
      }
    }
  }

  return { canonicalBaseSet, canonicalByBuilding, compactByBaseCount };
}

function resolveLegacyRoomDigits(parsed, context = {}) {
  if (!parsed) return "";
  const baseDigits = String(parsed.baseDigits || "");
  const originalDigits = String(parsed.originalDigits || baseDigits);
  const suffixDigits = String(parsed.suffixDigits || "");
  if (parsed.kind === "plain" || parsed.kind === "explicit_suffix") {
    return baseDigits || originalDigits;
  }
  const canonicalBaseSet = context.canonicalBaseSet || new Set();
  const canonicalByBuilding = context.canonicalByBuilding || new Map();
  const buildingName = String(context.buildingName || "");

  if (parsed.kind === "compact_suffix") {
    const suffixNum = Number(suffixDigits);
    const compactLooksLikeDuplicate = baseDigits.length >= 1 && baseDigits.length <= 4 && suffixNum >= 1 && suffixNum <= 999;
    const crossBuildingEvidence = canonicalBaseSet.has(baseDigits);
    const sameBuildingEvidence = canonicalByBuilding.get(buildingName)?.has(baseDigits) || false;
    if (compactLooksLikeDuplicate && (crossBuildingEvidence || sameBuildingEvidence)) {
      return baseDigits;
    }
  }
  if (parsed.kind === "loader_suffix") {
    const suffixNum = Number(suffixDigits);
    const loaderLooksLikeDuplicate = baseDigits.length >= 1 && baseDigits.length <= 4 && suffixNum >= 1 && suffixNum <= 999;
    const crossBuildingEvidence = canonicalBaseSet.has(baseDigits);
    const sameBuildingEvidence = canonicalByBuilding.get(buildingName)?.has(baseDigits) || false;
    if (loaderLooksLikeDuplicate && (crossBuildingEvidence || sameBuildingEvidence)) {
      return baseDigits;
    }
  }
  return originalDigits || baseDigits;
}

export function parseRoomObjectName(name, context = {}) {
  const inspected = inspectRoomObjectName(name);
  if (!inspected) return null;
  if (inspected.sourceKind === "rm") {
    return inspected;
  }

  const parsed = inspected.legacyParsed;
  if (!parsed) return null;
  const digits = resolveLegacyRoomDigits(parsed, context);
  if (!digits) return null;
  return {
    rawName: inspected.rawName,
    objectName: inspected.objectName,
    canonicalObjectName: `ROOM ${digits}`,
    roomName: `ROOM ${digits}`,
    roomNumber: digits,
    sourceKind: "legacy_room"
  };
}
