<?php
// /admin/inc/layout.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/auth.php";

function admin_layout_start(string $title, string $active = "") { ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="assets/admin.css" />
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
          <span class="ico">🏠</span><span>Dashboard</span>
        </a>
        <a class="nav-item <?= $active==='buildings'?'active':'' ?>" href="building.php">
          <span class="ico">🏢</span><span>Buildings</span>
        </a>
        <a class="nav-item <?= $active==='rooms'?'active':'' ?>" href="room.php">
          <span class="ico">🚪</span><span>Rooms</span>
        </a>
        <a class="nav-item <?= $active==='facilities'?'active':'' ?>" href="facilities.php">
          <span class="ico">🧰</span><span>Facilities</span>
        </a>
        <a class="nav-item <?= $active==='events'?'active':'' ?>" href="events.php">
          <span class="ico">📅</span><span>Events</span>
        </a>
        <a class="nav-item <?= $active==='announcements'?'active':'' ?>" href="announcement.php">
          <span class="ico">📣</span><span>Announcements</span>
        </a>

        <div class="nav-sep"></div>

        <a class="nav-item <?= $active==='mapeditor'?'active':'' ?>" href="mapEditor.php">
          <span class="ico">🗺️</span><span>Map Editor</span>
        </a>
        <a class="nav-item <?= $active==='admins'?'active':'' ?>" href="adminUser.php">
          <span class="ico">👤</span><span>Admin Users</span>
        </a>
      </nav>

      <div class="sidebar-footer">
        <div class="me">
          <div class="me-name"><?= htmlspecialchars(admin_name()) ?></div>
          <div class="me-role"><?= htmlspecialchars(admin_role()) ?></div>
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
