<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/map_sync.php";
require_once __DIR__ . "/inc/announcements.php";
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
$publishedModelState = admin_layout_public_model_state();
$publicModel = trim((string)($publishedModelState["modelFile"] ?? ""));
$publishedModelLabel = admin_layout_humanize_model_name($publishedModelState["modelFile"] ?? "");
$publishedModelTitle = $publishedModelState["modelFile"] !== "" ? $publishedModelState["modelFile"] : "Publish a map from Map Editor to make it live.";
$publishedSourceLabel = $publishedModelState["hasLiveManifest"] ? "Live Bundle" : "Fallback Bundle";
$publishedBadgeLabel = $publishedModelState["modelFile"] !== ""
  ? ($publishedModelState["hasLiveManifest"] ? "Live Public Map" : "Fallback Public Map")
  : "Awaiting Publish";
$publishedBadgeToneClass = $publishedModelState["modelFile"] !== ""
  ? ($publishedModelState["hasLiveManifest"] ? "dashboard-published-card__badge-live" : "dashboard-published-card__badge-warn")
  : "dashboard-published-card__badge-neutral";
$publishedStatusText = $publishedModelState["modelFile"] !== ""
  ? ($publishedModelState["hasLiveManifest"] ? "Currently shown to users on the live kiosk map." : "Using the best available fallback map.")
  : "No public map has been published yet.";
$publishedSummaryValue = $publishedModelState["publishedAt"] > 0
  ? date("M d, g:i A", $publishedModelState["publishedAt"])
  : ($publishedModelState["hasLiveManifest"] ? "Publish time unavailable" : "Fallback map in use");
$publishedSummaryNote = $publishedModelState["publishedAt"] > 0 ? "Latest public release" : "Waiting for the first publish";
$publishedSourceNote = $publishedModelState["hasLiveManifest"]
  ? "Serving the active kiosk route map"
  : "Used because no live bundle was found";

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

$recentAnnouncements = array_slice(announcements_load_rows($conn, true), 0, 5);

$GLOBALS["admin_extra_head"] = <<<'HTML'
<style>
  .dashboard-map-status{
    margin-bottom:18px;
  }
  .dashboard-published-card{
    position:relative;
    overflow:hidden;
    border-radius:26px;
    border:1px solid #f0d7c6;
    background:
      radial-gradient(circle at top right, rgba(251, 146, 60, 0.22), transparent 30%),
      radial-gradient(circle at bottom left, rgba(244, 63, 94, 0.14), transparent 28%),
      linear-gradient(135deg, #fff7ed 0%, #ffffff 48%, #fff1f2 100%);
    box-shadow: 0 26px 50px rgba(15, 23, 42, 0.1);
    padding:26px 28px;
  }
  .dashboard-published-card__top{
    position:relative;
    z-index:1;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
  }
  .dashboard-published-card__eyebrow{
    font-size:11px;
    font-weight:900;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:#9a3412;
  }
  .dashboard-published-card__title{
    margin:8px 0 0;
    font-size:31px;
    line-height:1.05;
    font-weight:950;
    color:#111827;
  }
  .dashboard-published-card__badge{
    display:inline-flex;
    align-items:center;
    min-height:36px;
    padding:0 14px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    white-space:nowrap;
  }
  .dashboard-published-card__badge-live{
    background:#dcfce7;
    border-color:#86efac;
    color:#166534;
  }
  .dashboard-published-card__badge-warn{
    background:#fef3c7;
    border-color:#fcd34d;
    color:#92400e;
  }
  .dashboard-published-card__badge-neutral{
    background:#e2e8f0;
    border-color:#cbd5e1;
    color:#334155;
  }
  .dashboard-published-card__hero{
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns:92px minmax(0, 1fr);
    gap:18px;
    align-items:center;
    margin-top:20px;
  }
  .dashboard-published-card__icon{
    position:relative;
    width:92px;
    height:92px;
    border-radius:26px;
    background:linear-gradient(145deg, #7c2d12 0%, #c2410c 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.22), 0 16px 30px rgba(124, 45, 18, .2);
  }
  .dashboard-published-card__icon::before{
    content:"";
    position:absolute;
    inset:16px;
    border-radius:18px;
    background:
      linear-gradient(90deg, rgba(255,255,255,.18) 1px, transparent 1px) 0 0 / 18px 18px,
      linear-gradient(rgba(255,255,255,.18) 1px, transparent 1px) 0 0 / 18px 18px;
  }
  .dashboard-published-card__icon::after{
    content:"";
    position:absolute;
    left:50%;
    top:50%;
    width:18px;
    height:18px;
    transform:translate(-50%, -50%);
    border-radius:999px;
    background:#fff7ed;
    border:4px solid #fdba74;
    box-shadow:0 0 0 8px rgba(255,255,255,.14);
  }
  .dashboard-published-card__file-label{
    font-size:11px;
    font-weight:900;
    letter-spacing:.1em;
    text-transform:uppercase;
    color:#7c2d12;
  }
  .dashboard-published-card__file{
    margin-top:8px;
    font-size:13px;
    line-height:1.55;
    color:#64748b;
    word-break:break-word;
  }
  .dashboard-published-card__summary{
    margin:12px 0 0;
    font-size:14px;
    line-height:1.7;
    font-weight:700;
    color:#334155;
    max-width:72ch;
  }
  .dashboard-published-card__facts{
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
    margin-top:22px;
  }
  .dashboard-published-card__fact{
    padding:14px 16px;
    border-radius:18px;
    border:1px solid #f2dfd2;
    background:rgba(255,255,255,.78);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.7);
  }
  .dashboard-published-card__fact-label{
    font-size:10px;
    font-weight:900;
    letter-spacing:.1em;
    text-transform:uppercase;
    color:#7c2d12;
  }
  .dashboard-published-card__fact-value{
    margin-top:8px;
    font-size:19px;
    line-height:1.3;
    font-weight:900;
    color:#111827;
    word-break:break-word;
  }
  .dashboard-published-card__fact-note{
    margin-top:6px;
    font-size:12px;
    line-height:1.5;
    color:#64748b;
  }
  @media (max-width: 900px){
    .dashboard-published-card{
      padding:22px 20px;
    }
    .dashboard-published-card__title{
      font-size:25px;
    }
    .dashboard-published-card__hero{
      grid-template-columns:1fr;
    }
    .dashboard-published-card__icon{
      width:78px;
      height:78px;
    }
    .dashboard-published-card__facts{
      grid-template-columns:1fr;
    }
    .dashboard-published-card__badge{
      white-space:normal;
    }
  }
</style>
HTML;

admin_layout_start("Dashboard", "dashboard");
?>

<div class="dashboard-map-status">
  <section class="dashboard-published-card" aria-label="Published map">
    <div class="dashboard-published-card__top">
      <div>
        <div class="dashboard-published-card__eyebrow">Currently Published</div>
        <h2 class="dashboard-published-card__title"><?= htmlspecialchars($publishedModelLabel !== "" ? $publishedModelLabel : "No Published Map", ENT_QUOTES, "UTF-8") ?></h2>
      </div>
      <div class="dashboard-published-card__badge <?= htmlspecialchars($publishedBadgeToneClass, ENT_QUOTES, "UTF-8") ?>">
        <?= htmlspecialchars($publishedBadgeLabel, ENT_QUOTES, "UTF-8") ?>
      </div>
    </div>

    <div class="dashboard-published-card__hero">
      <div class="dashboard-published-card__icon" aria-hidden="true"></div>
      <div>
        <div class="dashboard-published-card__file-label">Active Bundle File</div>
        <div class="dashboard-published-card__file"><?= htmlspecialchars($publishedModelTitle, ENT_QUOTES, "UTF-8") ?></div>
        <p class="dashboard-published-card__summary"><?= htmlspecialchars($publishedStatusText, ENT_QUOTES, "UTF-8") ?></p>
      </div>
    </div>

    <div class="dashboard-published-card__facts">
      <article class="dashboard-published-card__fact">
        <div class="dashboard-published-card__fact-label">Published Now</div>
        <div class="dashboard-published-card__fact-value"><?= htmlspecialchars($publishedSummaryValue, ENT_QUOTES, "UTF-8") ?></div>
        <div class="dashboard-published-card__fact-note"><?= htmlspecialchars($publishedSummaryNote, ENT_QUOTES, "UTF-8") ?></div>
      </article>
      <article class="dashboard-published-card__fact">
        <div class="dashboard-published-card__fact-label">Bundle Source</div>
        <div class="dashboard-published-card__fact-value"><?= htmlspecialchars($publishedSourceLabel, ENT_QUOTES, "UTF-8") ?></div>
        <div class="dashboard-published-card__fact-note"><?= htmlspecialchars($publishedSourceNote, ENT_QUOTES, "UTF-8") ?></div>
      </article>
    </div>
  </section>
</div>

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
          $scheduleLabel = announcements_format_schedule($ann);
        ?>
        <div style="padding:10px 12px;border:1px solid #eaecf0;border-radius:10px;">
          <div style="font-weight:800; color:#101828;"><?= $title ?></div>
          <div style="font-size:12px; color:#667085; margin-top:3px;">
            Posted: <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?> | Schedule: <?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php admin_layout_end(); ?>
