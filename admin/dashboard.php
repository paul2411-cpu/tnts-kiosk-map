<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/map_sync.php";
require_once __DIR__ . "/inc/layout.php";

function dashboard_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function dashboard_scalar_count(mysqli $conn, string $sql): int {
  $res = $conn->query($sql);
  if (!($res instanceof mysqli_result)) return 0;
  $row = $res->fetch_assoc();
  if (!is_array($row)) return 0;
  return (int)($row["cnt"] ?? 0);
}

function dashboard_count_active_model_or_all(
  mysqli $conn,
  string $table,
  bool $hasPresentFilter,
  bool $hasSourceModel,
  string $publicModel,
  bool $includePresentFilter = true
): int {
  $safeTable = str_replace("`", "``", $table);
  $where = [];
  if ($includePresentFilter && $hasPresentFilter) {
    $where[] = "(is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }

  $countWithModel = null;
  if ($hasSourceModel && trim($publicModel) !== "") {
    $modelWhere = array_merge($where, ["source_model_file = '" . $conn->real_escape_string($publicModel) . "'"]);
    $modelSql = "SELECT COUNT(*) AS cnt FROM `{$safeTable}`";
    if ($modelWhere) $modelSql .= " WHERE " . implode(" AND ", $modelWhere);
    $countWithModel = dashboard_scalar_count($conn, $modelSql);
    if ($countWithModel > 0) return $countWithModel;
  }

  $baseSql = "SELECT COUNT(*) AS cnt FROM `{$safeTable}`";
  if ($where) $baseSql .= " WHERE " . implode(" AND ", $where);
  return dashboard_scalar_count($conn, $baseSql);
}

$hasBuildingPresent = dashboard_has_column($conn, "buildings", "is_present_in_latest");
$hasRoomPresent = dashboard_has_column($conn, "rooms", "is_present_in_latest");
$hasBuildingSource = dashboard_has_column($conn, "buildings", "source_model_file");
$hasRoomSource = dashboard_has_column($conn, "rooms", "source_model_file");
$publicState = map_sync_resolve_public_model(dirname(__DIR__));
$publicModel = trim((string)($publicState["modelFile"] ?? ""));

$buildingCountSql = "SELECT COUNT(*) AS cnt FROM buildings";
$buildingCount = dashboard_count_active_model_or_all(
  $conn,
  "buildings",
  $hasBuildingPresent,
  $hasBuildingSource,
  $publicModel
);

$roomCount = dashboard_count_active_model_or_all(
  $conn,
  "rooms",
  $hasRoomPresent,
  $hasRoomSource,
  $publicModel
);
$facilityCount = dashboard_scalar_count($conn, "SELECT COUNT(*) AS cnt FROM facilities");
$eventCount = dashboard_scalar_count($conn, "SELECT COUNT(*) AS cnt FROM events");

$recentAnnouncements = [];
$annRes = $conn->query("
  SELECT announcement_id, title, date_posted, expiry_date
  FROM announcements
  WHERE expiry_date IS NULL OR expiry_date >= CURDATE()
  ORDER BY date_posted DESC
  LIMIT 5
");
if ($annRes instanceof mysqli_result) {
  while ($row = $annRes->fetch_assoc()) {
    $recentAnnouncements[] = $row;
  }
}

admin_layout_start("Dashboard", "dashboard");
?>

<div class="grid cols-4">
  <div class="card">
    <div class="kpi-title">TOTAL BUILDINGS</div>
    <div class="kpi-value"><?= number_format($buildingCount) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL ROOMS</div>
    <div class="kpi-value"><?= number_format($roomCount) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL FACILITIES</div>
    <div class="kpi-value"><?= number_format($facilityCount) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL EVENTS</div>
    <div class="kpi-value"><?= number_format($eventCount) ?></div>
  </div>
</div>

<div style="margin-top:14px" class="card">
  <div class="section-title">Recent Announcements</div>
  <?php if (!count($recentAnnouncements)): ?>
    <div style="color:#667085;font-weight:800;">No Recent Announcements</div>
  <?php else: ?>
    <div style="display:grid;gap:8px;">
      <?php foreach ($recentAnnouncements as $ann): ?>
        <?php
          $title = htmlspecialchars((string)($ann["title"] ?? ""), ENT_QUOTES, "UTF-8");
          $posted = (string)($ann["date_posted"] ?? "");
          $postedLabel = $posted !== "" ? date("M d, Y h:i A", strtotime($posted)) : "Unknown date";
          $expiry = (string)($ann["expiry_date"] ?? "");
          $expiryLabel = $expiry !== "" ? date("M d, Y", strtotime($expiry)) : "No expiry";
        ?>
        <div style="padding:10px 12px;border:1px solid #eaecf0;border-radius:10px;">
          <div style="font-weight:800; color:#101828;"><?= $title ?></div>
          <div style="font-size:12px; color:#667085; margin-top:3px;">
            Posted: <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?> | Expires: <?= htmlspecialchars($expiryLabel, ENT_QUOTES, "UTF-8") ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php admin_layout_end(); ?>
