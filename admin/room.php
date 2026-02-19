<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Rooms", "rooms");
?>

<div class="card">
  <div class="section-title">Add / Edit Room (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Room Number</div>
      <input class="input" placeholder="Enter room number" />
    </div>
    <div class="row">
      <div class="label">Room Type</div>
      <input class="input" placeholder="Enter room type (Lab, Office, etc.)" />
    </div>
    <div class="row">
      <div class="label">Floor Number</div>
      <input class="input" placeholder="Enter floor number" />
    </div>
    <div class="row">
      <div class="label">Building</div>
      <select class="select">
        <option>Choose building (later from DB)</option>
      </select>
    </div>
    <div class="row">
      <div class="label">Description</div>
      <textarea class="textarea" placeholder="Enter room description"></textarea>
    </div>

    <div class="actions">
      <button type="button" class="btn primary">Save Room</button>
      <button type="button" class="btn gray">Cancel</button>
    </div>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Room</th><th>Type</th><th>Floor</th><th>Building</th><th>Action</th>
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
