<?php
$pageTitle = "Feedback";
$activePage = "feedback";

ob_start();
?>
  <div style="padding: 28px;">
    <h1>Feedback</h1>
    <p>UI placeholder for feedback page (database later).</p>
  </div>
<?php
$content = ob_get_clean();

include __DIR__ . "/../ui/layout.php";
