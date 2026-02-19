<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/layout.php";
admin_layout_start("Admin Users", "admins");
?>

<div class="card">
  <div class="section-title">Add Admin User (UI Only)</div>

  <form class="form">
    <div class="row">
      <div class="label">Full Name</div>
      <input class="input" placeholder="Enter full name" />
    </div>
    <div class="row">
      <div class="label">Username</div>
      <input class="input" placeholder="Enter username" />
    </div>
    <div class="row">
      <div class="label">Password</div>
      <input class="input" type="password" placeholder="Enter password" />
    </div>
    <div class="row">
      <div class="label">Role</div>
      <select class="select">
        <option>staff</option>
        <option>superadmin</option>
      </select>
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
        <th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Last Login</th><th>Action</th>
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
