<?php
$pageTitle = "Announcement";
$activePage = "announcement";

ob_start();
?>
  <div style="padding: 28px;">
    <h1>Announcement</h1>
    <p>UI placeholder for announcement page (database later).</p>
  </div>
<?php
$content = ob_get_clean();

include __DIR__ . "/../ui/layout.php";
