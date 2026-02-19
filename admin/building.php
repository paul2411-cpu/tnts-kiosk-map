<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Buildings", "buildings");
?>

<div class="card">
  <div class="section-title">Add / Edit Building (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Building Name</div>
      <input class="input" placeholder="Enter building name" />
    </div>
    <div class="row">
      <div class="label">Description</div>
      <textarea class="textarea" placeholder="Enter building description"></textarea>
    </div>
    <div class="row">
      <div class="label">Image Path</div>
      <input class="input" placeholder="e.g. /assets/buildings/ict.jpg" />
    </div>

    <div class="actions">
      <button type="button" class="btn primary">Upload Image</button>
      <button type="button" class="btn gray">Save Building</button>
      <button type="button" class="btn gray">Cancel</button>
    </div>
  </form>
</div>

<div class="map-box">
  <div class="map-head">
    <div class="map-title">Top View Location Picker (Placeholder)</div>
    <div class="actions">
      <button type="button" class="btn gray">Use Current Location</button>
    </div>
  </div>
  <div class="map-placeholder">
    TOP VIEW IMAGE PLACEHOLDER (Later: 3D top view / map screenshot)
  </div>
  <div style="padding:12px;">
    <div class="row" style="grid-template-columns:180px 1fr 220px; gap:10px;">
      <div class="label">Building Coordinates</div>
      <input class="input" placeholder="e.g. x: 10.23, y: 0.00, z: -5.40" />
      <button type="button" class="btn primary">Save Coordinates</button>
    </div>
  </div>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Building Name</th><th>Description</th><th>Image</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>—</td><td>No data yet</td><td>Data gathering later</td><td>—</td>
        <td>
          <button class="btn blue" type="button">Edit</button>
          <button class="btn danger" type="button">Delete</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<?php admin_layout_end(); ?>
