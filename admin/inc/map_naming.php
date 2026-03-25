<?php

function map_naming_trim(string $value): string {
  return trim((string)$value);
}

function map_naming_strip_blender_suffix(string $name): string {
  $value = map_naming_trim($name);
  if ($value === "") return "";
  return preg_replace('/\.\d+$/', '', $value);
}

function map_naming_normalize_token(string $value): string {
  $value = map_naming_trim($value);
  if ($value === "") return "";
  $value = preg_replace('/[\\\\\/]+/', '_', $value);
  $value = preg_replace('/[\s._-]+/', '_', (string)$value);
  $value = trim((string)$value, "_");
  return mb_strtoupper((string)$value);
}

function map_naming_humanize_token(string $token): string {
  $safe = map_naming_trim($token);
  if ($safe === "") return "";
  if (preg_match('/[0-9]/', $safe) || preg_match('/^[A-Z]{1,5}$/', $safe)) {
    return $safe;
  }
  if (preg_match('/^[A-Z][a-z0-9]+$/', $safe)) {
    return $safe;
  }
  $lower = mb_strtolower($safe, 'UTF-8');
  return mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');
}

function map_naming_humanize_label(string $label): string {
  $safe = map_naming_trim($label);
  if ($safe === "") return "";
  $safe = preg_replace('/[\\\\\/]+/', '_', $safe);
  $safe = preg_replace('/[\s.-]+/', '_', (string)$safe);
  $parts = array_values(array_filter(explode('_', trim((string)$safe, '_')), static function($part): bool {
    return trim((string)$part) !== "";
  }));
  if (!$parts) return "";
  return implode(' ', array_map('map_naming_humanize_token', $parts));
}

function map_naming_is_generic_name(string $name): bool {
  $raw = mb_strtolower(map_naming_trim($name), 'UTF-8');
  if ($raw === '') return true;
  $base = preg_replace('/([._-]\d+)+$/', '', $raw);
  return in_array($base, ['scene', 'auxscene', 'root', 'rootnode', 'gltf', 'model', 'group', 'collection', 'scenecollection', 'mastercollection'], true);
}

function map_naming_is_ground_name(string $name): bool {
  $safe = mb_strtolower(map_naming_trim($name), 'UTF-8');
  if ($safe === '') return false;
  if ($safe === 'ground' || $safe === 'cground') return true;
  return (bool)preg_match('/(^|[^a-z0-9])c?ground($|[^a-z0-9])/i', $safe);
}

function map_naming_is_road_name(string $name): bool {
  $safe = map_naming_trim($name);
  if ($safe === '') return false;
  return (bool)preg_match('/^road(?:[._-]\d+)?$/i', $safe);
}

function map_naming_is_waypoint_name(string $name): bool {
  return (bool)preg_match('/^KIOSK_START(?:[._-]?\d+)?$/i', map_naming_trim($name));
}

function map_naming_split_prefix(string $name): array {
  $raw = map_naming_strip_blender_suffix($name);
  if ($raw === '') {
    return ['', ''];
  }
  if (!preg_match('/^([A-Za-z]+)[_\-\s]+(.+)$/', $raw, $m)) {
    return ['', $raw];
  }
  $prefix = mb_strtoupper(trim((string)$m[1]), 'UTF-8');
  $label = trim((string)$m[2]);
  if ($prefix === 'LANDMARK') $prefix = 'LMK';
  return [$prefix, $label];
}

function map_naming_classify_top_level(string $name): array {
  $raw = map_naming_trim($name);
  $base = map_naming_strip_blender_suffix($raw);
  [$prefix, $label] = map_naming_split_prefix($base);

  $out = [
    'raw_name' => $raw,
    'base_name' => $base,
    'prefix' => $prefix,
    'label' => $label,
    'include' => false,
    'bucket' => 'ignore',
    'entity_type' => '',
    'display_name' => '',
  ];

  if ($base === '' || map_naming_is_generic_name($base) || map_naming_is_ground_name($base) || map_naming_is_road_name($base) || map_naming_is_waypoint_name($base)) {
    return $out;
  }

  if ($prefix === 'OB') {
    return $out;
  }

  if ($prefix === 'FAC') {
    $out['include'] = true;
    $out['bucket'] = 'facilities';
    $out['entity_type'] = 'facility';
    $out['display_name'] = map_naming_humanize_label($label !== '' ? $label : $base);
    return $out;
  }

  $entityType = '';
  if ($prefix === 'BLD' || $prefix === '') {
    $entityType = 'building';
  } elseif ($prefix === 'VENUE') {
    $entityType = 'venue';
  } elseif ($prefix === 'AREA') {
    $entityType = 'area';
  } elseif ($prefix === 'LMK') {
    $entityType = 'landmark';
  } else {
    $entityType = 'building';
  }

  $out['include'] = true;
  $out['bucket'] = 'buildings';
  $out['entity_type'] = $entityType;
  $out['display_name'] = map_naming_humanize_label($label !== '' ? $label : $base);
  return $out;
}

function map_naming_parse_legacy_room_digits(string $name): ?array {
  $source = map_naming_trim($name);
  if ($source === '') return null;
  if (!preg_match('/^ROOM(?:$|[\s._-]|\d)/i', $source)) return null;

  $tail = preg_replace('/^ROOM/i', '', $source);
  $tail = preg_replace('/^[\s._-]+/', '', (string)$tail);
  $tail = preg_replace('/\s+/', '', (string)$tail);
  if ($tail === '' || !preg_match('/^[0-9._-]+$/', $tail)) return null;

  if (preg_match('/^(\d+)[._-](\d{3})$/', $tail, $m)) {
    return [
      'kind' => 'explicit_suffix',
      'base_digits' => (string)$m[1],
      'suffix_digits' => (string)$m[2],
      'original_digits' => (string)$m[1] . (string)$m[2],
    ];
  }

  if (preg_match('/^(\d+)[._-](\d{1,3})$/', $tail, $m)) {
    return [
      'kind' => 'loader_suffix',
      'base_digits' => (string)$m[1],
      'suffix_digits' => (string)$m[2],
      'original_digits' => (string)$m[1] . (string)$m[2],
    ];
  }

  if (!preg_match('/^(\d+)$/', $tail, $m)) {
    return null;
  }
  $digits = (string)$m[1];
  if (strlen($digits) <= 3) {
    return [
      'kind' => 'plain',
      'base_digits' => $digits,
      'suffix_digits' => '',
      'original_digits' => $digits,
    ];
  }

  return [
    'kind' => 'compact_suffix',
    'base_digits' => substr($digits, 0, -3),
    'suffix_digits' => substr($digits, -3),
    'original_digits' => $digits,
  ];
}

function map_naming_resolve_legacy_room_digits(array $parsed, array $context = []): string {
  $kind = (string)($parsed['kind'] ?? '');
  $baseDigits = (string)($parsed['base_digits'] ?? '');
  $suffixDigits = (string)($parsed['suffix_digits'] ?? '');
  $originalDigits = (string)($parsed['original_digits'] ?? $baseDigits);
  $canonicalBaseSet = $context['canonical_base_set'] ?? [];
  $canonicalByBuilding = $context['canonical_by_building'] ?? [];
  $buildingName = (string)($context['building_name'] ?? '');

  if ($kind === 'plain' || $kind === 'explicit_suffix') {
    return $baseDigits !== '' ? $baseDigits : $originalDigits;
  }
  if ($kind === 'compact_suffix') {
    $suffixNum = (int)$suffixDigits;
    $compactLooksLikeDuplicate = strlen($baseDigits) >= 1 && strlen($baseDigits) <= 4 && $suffixNum >= 1 && $suffixNum <= 999;
    $crossBuildingEvidence = !empty($canonicalBaseSet[$baseDigits]);
    $sameBuildingEvidence = !empty($canonicalByBuilding[$buildingName][$baseDigits]);
    if ($compactLooksLikeDuplicate && ($crossBuildingEvidence || $sameBuildingEvidence)) {
      return $baseDigits;
    }
  }
  if ($kind === 'loader_suffix') {
    $suffixNum = (int)$suffixDigits;
    $loaderLooksLikeDuplicate = strlen($baseDigits) >= 1 && strlen($baseDigits) <= 4 && $suffixNum >= 1 && $suffixNum <= 999;
    $crossBuildingEvidence = !empty($canonicalBaseSet[$baseDigits]);
    $sameBuildingEvidence = !empty($canonicalByBuilding[$buildingName][$baseDigits]);
    if ($loaderLooksLikeDuplicate && ($crossBuildingEvidence || $sameBuildingEvidence)) {
      return $baseDigits;
    }
  }
  return $originalDigits !== '' ? $originalDigits : $baseDigits;
}

function map_naming_parse_room_object(string $name, array $context = []): ?array {
  $raw = map_naming_trim($name);
  if ($raw === '') return null;

  [$prefix, $label] = map_naming_split_prefix($raw);
  if ($prefix === 'RM') {
    $canonicalObjectName = map_naming_strip_blender_suffix($raw);
    $label = preg_replace('/\.\d+$/', '', (string)$label);
    $label = trim((string)$label, " _-.");
    if ($label === '') return null;
    $normalizedLabel = map_naming_normalize_token($label);
    if ($normalizedLabel === '') return null;
    $roomNumber = '';
    $roomName = map_naming_humanize_label($normalizedLabel);
    if (preg_match('/^\d+$/', $normalizedLabel)) {
      $roomNumber = $normalizedLabel;
      $roomName = 'ROOM ' . $roomNumber;
    }
    return [
      'raw_name' => $raw,
      'model_object_name' => $raw,
      'canonical_object_name' => $canonicalObjectName,
      'room_name' => $roomName,
      'room_number' => $roomNumber,
      'lookup_key' => mb_strtolower($roomName),
      'source_kind' => 'rm',
    ];
  }

  $parsed = map_naming_parse_legacy_room_digits($raw);
  if (!$parsed) return null;
  $digits = map_naming_resolve_legacy_room_digits($parsed, $context);
  if ($digits === '') return null;
  return [
    'raw_name' => $raw,
    'model_object_name' => $raw,
    'canonical_object_name' => 'ROOM ' . $digits,
    'room_name' => 'ROOM ' . $digits,
    'room_number' => $digits,
    'lookup_key' => mb_strtolower('ROOM ' . $digits),
    'source_kind' => 'legacy_room',
  ];
}
