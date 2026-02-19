<?php
// Admin Dashboard UI Mockup
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background-color: white; color: black; }
        .sidebar { width: 260px; background-color: maroon; height: 100vh; position: fixed; top: 0; left: 0; padding-top: 20px; }
        .sidebar a { color: white; padding: 12px 20px; display: block; font-size: 16px; text-decoration: none; }
        .sidebar a:hover { background-color: #5a0a0a; }
        .main { margin-left: 260px; padding: 20px; }
        .card { border: 1px solid #ccc; }
        .btn-maroon { background-color: maroon; color: white; }
        .btn-maroon:hover { background-color: #5a0a0a; color: white; }
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="text-center text-white">Admin Panel</h4>
    <a href="#" onclick="loadPage('dashboard')"><i class="fa fa-home"></i> Dashboard</a>
    <a href="#" onclick="loadPage('buildings')"><i class="fa fa-building"></i> Buildings</a>
    <a href="#" onclick="loadPage('rooms')"><i class="fa fa-door-open"></i> Rooms</a>
    <a href="#" onclick="loadPage('facilities')"><i class="fa fa-list"></i> Facilities</a>
    <a href="#" onclick="loadPage('events')"><i class="fa fa-calendar"></i> Events</a>
    <a href="#" onclick="loadPage('announcements')"><i class="fa fa-bullhorn"></i> Announcements</a>
    <a href="#" onclick="loadPage('users')"><i class="fa fa-users"></i> Admin Users</a>
</div>

<div class="main">
    <div id="contentArea">
        <h2>Dashboard</h2>
<div class='row'>
    <div class='col-md-3'>
        <div class='card p-3 text-center'>
            <h5>Total Buildings</h5>
            <p>0</p>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card p-3 text-center'>
            <h5>Total Rooms</h5>
            <p>0</p>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card p-3 text-center'>
            <h5>Total Facilities</h5>
            <p>0</p>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card p-3 text-center'>
            <h5>Total Events</h5>
            <p>0</p>
        </div>
    </div>
</div>
<div class='card p-3 mt-4'>
    <h5>Recent Announcements</h5>
    <p>No announcements posted.</p>
</div>
    </div>
</div>

<script>
function loadPage(section) {
    let content = document.getElementById('contentArea');

    if (section === 'dashboard') {
        content.innerHTML = `<h2>Dashboard</h2><p>Overview of system records.</p>`;
    }

    if (section === 'buildings') {
        content.innerHTML = `
            <h2>Buildings</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add Building</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add Building', [ {label: 'Building Name', placeholder: 'Enter name'}, {label: 'Description', placeholder: 'Enter description'}, {label: 'Image Path', placeholder: 'Enter image path'} ])">Open Form</button> List of buildings</div>
        `;
    }

    if (section === 'rooms') {
        content.innerHTML = `
            <h2>Rooms</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add Room</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add Room', [ {label: 'Room Name', placeholder: 'Enter room name'}, {label: 'Room Type', placeholder: 'Enter type'}, {label: 'Floor Number', placeholder: 'Enter floor'}, {label: 'Description', placeholder: 'Enter description'} ])">Open Form</button> List of rooms</div>
        `;
    }

    if (section === 'facilities') {
        content.innerHTML = `
            <h2>Facilities</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add Facility</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add Facility', [ {label: 'Facility Name', placeholder: 'Enter name'}, {label: 'Description', placeholder: 'Enter description'}, {label: 'Logo Path', placeholder: 'Enter logo path'}, {label: 'Location', placeholder: 'Enter location'} ])">Open Form</button> List of facilities</div>
        `;
    }

    if (section === 'events') {
        content.innerHTML = `
            <h2>Events</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add Event</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add Event', [ {label: 'Title', placeholder: 'Enter title'}, {label: 'Description', placeholder: 'Enter description'}, {label: 'Start Date', placeholder: 'YYYY-MM-DD'}, {label: 'End Date', placeholder: 'YYYY-MM-DD'} ])">Open Form</button> List of events</div>
        `;
    }

    if (section === 'announcements') {
        content.innerHTML = `
            <h2>Announcements</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add Announcement</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add Announcement', [ {label: 'Title', placeholder: 'Enter title'}, {label: 'Content', placeholder: 'Enter content'}, {label: 'Expiry Date', placeholder: 'YYYY-MM-DD'} ])">Open Form</button> List of announcements</div>
        `;
    }

    if (section === 'users') {
        content.innerHTML = `
            <h2>Admin Users</h2>
            <button class='btn btn-maroon mb-3'><i class='fa fa-plus'></i> Add User</button>
            <div class='card p-3'><button class='btn btn-maroon' onclick="showForm('Add User', [ {label: 'Username', placeholder: 'Enter username'}, {label: 'Full Name', placeholder: 'Enter full name'}, {label: 'Role', placeholder: 'superadmin or staff'} ])">Open Form</button> List of admin accounts</div>
        `;
    }
}
function showForm(title, fields) {
    let html = `<div class='card p-4'><h4>${title}</h4>`;
    fields.forEach(f => {
        html += `<label class='mt-2'>${f.label}</label><input type='text' class='form-control' placeholder='${f.placeholder}'>`;
    });
    html += `<button class='btn btn-maroon mt-3'>Save</button></div>`;
    document.getElementById('contentArea').innerHTML = html;
}
</script>

</body>
</html>
