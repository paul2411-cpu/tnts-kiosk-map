<?php
// /admin/inc/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

function require_admin() {
  if (empty($_SESSION["admin_logged_in"])) {
    header("Location: login.php");
    exit;
  }
}

function admin_name() {
  return $_SESSION["admin_full_name"] ?? $_SESSION["admin_username"] ?? "Admin";
}

function admin_role() {
  return $_SESSION["admin_role"] ?? "staff";
}
