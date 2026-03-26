<?php
// /admin/inc/layout.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/app_logger.php";
require_once __DIR__ . "/map_sync.php";
app_logger_bootstrap(["subsystem" => "admin_ui"]);

function admin_layout_humanize_model_name(string $modelFile): string {
  $safeFile = trim(basename($modelFile));
  if ($safeFile === "") return "No Published Map";

  $name = preg_replace('/\.glb$/i', '', $safeFile);
  $name = preg_replace('/_(\d{8}_\d{6}|\d{14})(?:_[a-z0-9]+)?$/i', '', $name);
  $name = preg_replace('/^tnts[_-]*/i', '', $name);
  $name = preg_replace('/[_-]+/', ' ', $name);
  $name = trim(preg_replace('/\s+/', ' ', (string)$name));
  $lower = strtolower($name);

  if ($lower === "") return "Published Map";
  if (str_contains($lower, "map export") || $lower === "export") return "Exported Map";
  if (str_contains($lower, "navigation")) return "Navigation Map";
  if (str_contains($lower, "campus")) return "Campus Map";

  return ucwords($name);
}

function admin_layout_public_model_state(): array {
  static $state = null;
  if (is_array($state)) return $state;

  $projectRoot = dirname(__DIR__, 2);
  $publicState = map_sync_resolve_public_model($projectRoot);
  $liveJson = is_array($publicState["liveJson"] ?? null) ? $publicState["liveJson"] : [];
  $modelFile = trim((string)($publicState["modelFile"] ?? ""));
  $version = trim((string)($liveJson["version"] ?? ""));
  $publishedAtRaw = $liveJson["publishedAt"] ?? null;
  $publishedAt = is_numeric($publishedAtRaw) ? (int)$publishedAtRaw : 0;

  $state = [
    "modelFile" => $modelFile,
    "version" => $version,
    "publishedAt" => $publishedAt,
    "publishedLabel" => $publishedAt > 0 ? date("M d, Y h:i A", $publishedAt) : "Not available",
    "hasLiveManifest" => !empty($publicState["hasLiveManifest"]),
  ];
  return $state;
}

function admin_layout_start(string $title, string $active = "") { ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="assets/admin.css" />
  <?= isset($GLOBALS["admin_extra_head"]) ? (string)$GLOBALS["admin_extra_head"] : "" ?>
  <?= app_logger_client_bootstrap([
    "endpoint" => "../api/client_error_log.php",
    "scriptSrc" => "../js/app-error-tracker.js",
    "subsystem" => "admin_ui",
    "page" => $active !== "" ? $active : $title,
  ]) ?>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-title">ADMIN PANEL</div>
        <div class="brand-sub">TNTS Kiosk</div>
      </div>

      <nav class="nav">
        <a class="nav-item <?= $active==='dashboard'?'active':'' ?>" href="dashboard.php">
          <span class="ico">&#x1F3E0;</span><span>Dashboard</span>
        </a>
        <?php if (admin_has_permission("manage_buildings")): ?>
          <a class="nav-item <?= $active==='buildings'?'active':'' ?>" href="building.php">
            <span class="ico">&#x1F3E2;</span><span>Buildings</span>
          </a>
        <?php endif; ?>
        <?php if (admin_has_permission("manage_rooms")): ?>
          <a class="nav-item <?= $active==='rooms'?'active':'' ?>" href="room.php">
            <span class="ico">&#x1F6AA;</span><span>Rooms</span>
          </a>
        <?php endif; ?>
        <?php if (admin_has_permission("manage_facilities")): ?>
          <a class="nav-item <?= $active==='facilities'?'active':'' ?>" href="facilities.php">
            <span class="ico">&#x1F9F0;</span><span>Facilities</span>
          </a>
        <?php endif; ?>
        <?php if (admin_has_permission("manage_events")): ?>
          <a class="nav-item <?= $active==='events'?'active':'' ?>" href="events.php">
            <span class="ico">&#x1F4C5;</span><span>Events</span>
          </a>
        <?php endif; ?>
        <?php if (admin_has_permission("manage_announcements")): ?>
          <a class="nav-item <?= $active==='announcements'?'active':'' ?>" href="announcement.php">
            <span class="ico">&#x1F4E3;</span><span>Announcements</span>
          </a>
        <?php endif; ?>
        <?php if (admin_has_permission("manage_feedback")): ?>
          <a class="nav-item <?= $active==='feedback'?'active':'' ?>" href="feedback.php">
            <span class="ico">&#x1F4AC;</span><span>Feedback</span>
          </a>
        <?php endif; ?>

        <div class="nav-sep"></div>

        <?php if (admin_has_permission("manage_map")): ?>
          <a class="nav-item <?= $active==='mapeditor'?'active':'' ?>" href="mapEditor.php">
            <span class="ico">&#x1F5FA;&#xFE0F;</span><span>Map Editor</span>
          </a>
        <?php endif; ?>
        <?php if (admin_is_superadmin()): ?>
          <a class="nav-item <?= $active==='admins'?'active':'' ?>" href="adminUser.php">
            <span class="ico">&#x1F464;</span><span>Admin Users</span>
          </a>
        <?php endif; ?>
      </nav>

      <div class="sidebar-footer">
        <div class="me">
          <div class="me-name"><?= htmlspecialchars(admin_name()) ?></div>
          <div class="me-role"><?= htmlspecialchars(admin_role_label()) ?></div>
        </div>
        <a class="btn ghost" href="logout.php">Logout</a>
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div class="topbar-left">
          <h1 class="page-title"><?= htmlspecialchars($title) ?></h1>
        </div>
        <div class="topbar-right">
          <div class="pill">Secure Admin</div>
        </div>
      </header>

      <section class="content">
<?php }

function admin_layout_end() { ?>
      </section>
    </main>
  </div>
</body>
</html>
<?php }
