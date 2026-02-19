<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";

admin_layout_start("Dashboard", "dashboard");
?>

<div class="grid cols-4">
  <div class="card">
    <div class="kpi-title">TOTAL BUILDINGS</div>
    <div class="kpi-value">0</div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL ROOMS</div>
    <div class="kpi-value">0</div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL FACILITIES</div>
    <div class="kpi-value">0</div>
  </div>
  <div class="card">
    <div class="kpi-title">TOTAL EVENTS</div>
    <div class="kpi-value">0</div>
  </div>
</div>

<div style="margin-top:14px" class="card">
  <div class="section-title">Recent Announcements</div>
  <div style="color:#667085;font-weight:800;">No Recent Announcements</div>
</div>

<?php admin_layout_end(); ?>
