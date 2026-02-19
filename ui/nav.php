<?php
// ui/nav.php
// expects: $activePage = "map" | "facilities" | "events" | "announcement" | "feedback"
function navClass($name, $activePage) {
  return $name === $activePage ? "nav-item active" : "nav-item";
}
?>
<nav class="bottom-nav">
  <a class="<?= navClass("map", $activePage) ?>" href="../pages/map.php">MAP</a>
  <a class="<?= navClass("facilities", $activePage) ?>" href="../pages/facilities.php">FACILITIES</a>
  <a class="<?= navClass("events", $activePage) ?>" href="../pages/events.php">EVENTS</a>
  <a class="<?= navClass("announcement", $activePage) ?>" href="../pages/announcement.php">ANNOUNCEMENT</a>
  <a class="<?= navClass("feedback", $activePage) ?>" href="../pages/feedback.php">FEEDBACK</a>
</nav>
