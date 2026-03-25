<?php
// ui/layout.php
require_once __DIR__ . "/../admin/inc/app_logger.php";
app_logger_bootstrap(["subsystem" => "public_ui"]);
if (!isset($pageTitle)) $pageTitle = "TNTS";
if (!isset($activePage)) $activePage = "";
if (!isset($extraHead)) $extraHead = "";
if (!isset($content)) $content = "";
if (!isset($extraScripts)) $extraScripts = "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <link rel="stylesheet" href="../css/app.css">
  <link rel="stylesheet" href="../css/nav.css">
  <link rel="stylesheet" href="../css/map.css">
  <link rel="stylesheet" href="../css/public-panels.css">

  <?= $extraHead ?>
  <?= app_logger_client_bootstrap([
    "endpoint" => "../api/client_error_log.php",
    "scriptSrc" => "../js/app-error-tracker.js",
    "subsystem" => "public_ui",
    "page" => $activePage !== "" ? $activePage : $pageTitle,
  ]) ?>
  <script src="../js/on-screen-keyboard.js"></script>
</head>
<body>

  <div class="app-shell">
    <!-- TOP BAR -->
    <header class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">MAPS</div>
      </div>

      <div class="topbar-right">
        <div class="searchbox">
          <input id="search-input" type="text" placeholder="Type to Search here...." />
          <button type="button" class="searchbtn" aria-label="Search"><span aria-hidden="true">&#128269;</span></button>
        </div>
      </div>
    </header>

    <!-- MAIN CONTENT (map area OR other pages) -->
    <main class="main-area">
      <?= $content ?>
    </main>

    <!-- BOTTOM NAV -->
    <?php include __DIR__ . "/nav.php"; ?>
  </div>

  <?= $extraScripts ?>
</body>
</html>
