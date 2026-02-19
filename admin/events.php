<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Events", "events");
?>

<div class="card">
  <div class="section-title">Add Event (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Title</div>
      <input class="input" placeholder="Enter title" />
    </div>
    <div class="row">
      <div class="label">Description</div>
      <textarea class="textarea" placeholder="Enter description"></textarea>
    </div>
    <div class="row">
      <div class="label">Start Date</div>
      <input class="input" placeholder="YYYY-MM-DD" />
    </div>
    <div class="row">
      <div class="label">End Date</div>
      <input class="input" placeholder="YYYY-MM-DD" />
    </div>

    <div class="actions">
      <button type="button" class="btn primary">Add</button>
      <button type="button" class="btn gray">Cancel</button>
    </div>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Event Title</th><th>Description</th><th>Start</th><th>End</th><th>Action</th>
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
