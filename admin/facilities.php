<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Facilities", "facilities");
?>

<div class="card">
  <div class="section-title">Add / Edit Facility (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Facility Name</div>
      <input class="input" placeholder="Enter facility name" />
    </div>
    <div class="row">
      <div class="label">Description</div>
      <textarea class="textarea" placeholder="Enter facility description"></textarea>
    </div>
    <div class="row">
      <div class="label">Floor Number</div>
      <input class="input" placeholder="Enter floor number (optional)" />
    </div>
    <div class="row">
      <div class="label">Location</div>
      <input class="input" placeholder="Enter location (later bind to map)" />
    </div>

    <div class="actions">
      <button type="button" class="btn primary">Add Facility</button>
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
    TOP VIEW IMAGE PLACEHOLDER (Later: click to pick facility location)
  </div>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Facility</th><th>Description</th><th>Floor</th><th>Location</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>—</td><td>No data yet</td><td>—</td><td>—</td><td>—</td>
        <td>
          <button class="btn blue" type="button">Edit</button>
          <button class="btn danger" type="button">Delete</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<?php admin_layout_end(); ?>
