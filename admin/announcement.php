<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Announcements", "announcements");
?>

<div class="card">
  <div class="section-title">Add Announcement (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Title</div>
      <input class="input" placeholder="Enter title" />
    </div>
    <div class="row">
      <div class="label">Content</div>
      <textarea class="textarea" placeholder="Enter announcement content"></textarea>
    </div>
    <div class="row">
      <div class="label">Expiry Date</div>
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
        <th>ID</th><th>Title</th><th>Content</th><th>Posted</th><th>Expiry</th><th>Action</th>
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
