<?php
require_once __DIR__ . "/inc/auth.php";
require_admin_permission("manage_feedback", "You do not have access to review feedback.");
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/layout.php";
app_logger_set_default_subsystem("admin_feedback");

$statusOptions = [
  "new" => "New",
  "reviewed" => "Reviewed",
  "resolved" => "Resolved",
];

$ratingLabels = [
  "helpful" => "Helpful",
  "neutral" => "Neutral",
  "not_helpful" => "Not Helpful",
];

$categoryLabels = [
  "map_issue" => "Map Issue",
  "wrong_route" => "Wrong Route",
  "not_found" => "Not Found",
  "outdated_info" => "Outdated Info",
  "ui_problem" => "UI Problem",
  "suggestion" => "Suggestion",
  "general" => "General",
];

if (empty($_SESSION["admin_feedback_csrf"])) {
  try {
    $_SESSION["admin_feedback_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $e) {
    $_SESSION["admin_feedback_csrf"] = sha1(uniqid((string)mt_rand(), true));
  }
}
$feedbackCsrf = (string)$_SESSION["admin_feedback_csrf"];

$statusFilter = trim((string)($_GET["status"] ?? "all"));
if ($statusFilter !== "all" && !isset($statusOptions[$statusFilter])) {
  $statusFilter = "all";
}

$categoryFilter = trim((string)($_GET["category"] ?? "all"));
if ($categoryFilter !== "all" && !isset($categoryLabels[$categoryFilter])) {
  $categoryFilter = "all";
}

$flash = $_SESSION["admin_feedback_flash"] ?? null;
unset($_SESSION["admin_feedback_flash"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postedCsrf = (string)($_POST["feedback_csrf"] ?? "");
  $feedbackId = (int)($_POST["feedback_id"] ?? 0);
  $nextStatus = trim((string)($_POST["next_status"] ?? ""));

  $redirectStatus = trim((string)($_POST["status_filter"] ?? "all"));
  if ($redirectStatus !== "all" && !isset($statusOptions[$redirectStatus])) {
    $redirectStatus = "all";
  }

  $redirectCategory = trim((string)($_POST["category_filter"] ?? "all"));
  if ($redirectCategory !== "all" && !isset($categoryLabels[$redirectCategory])) {
    $redirectCategory = "all";
  }

  if ($postedCsrf === "" || !hash_equals($feedbackCsrf, $postedCsrf)) {
    $_SESSION["admin_feedback_flash"] = [
      "type" => "error",
      "message" => "The action could not be verified. Please try again.",
    ];
  } elseif ($feedbackId <= 0 || !isset($statusOptions[$nextStatus])) {
    $_SESSION["admin_feedback_flash"] = [
      "type" => "error",
      "message" => "Invalid feedback action.",
    ];
  } else {
    $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : 0;

    if ($nextStatus === "new") {
      $stmt = $conn->prepare(
        "UPDATE feedback
         SET status = 'new', reviewed_by_admin_id = NULL, reviewed_at = NULL
         WHERE feedback_id = ?"
      );
      if ($stmt) {
        $stmt->bind_param("i", $feedbackId);
      }
    } else {
      $stmt = $conn->prepare(
        "UPDATE feedback
         SET status = ?, reviewed_by_admin_id = ?, reviewed_at = NOW()
         WHERE feedback_id = ?"
      );
      if ($stmt) {
        $stmt->bind_param("sii", $nextStatus, $adminId, $feedbackId);
      }
    }

    if (!$stmt) {
      app_log("error", "Admin feedback update statement preparation failed", [
        "feedbackId" => $feedbackId,
        "nextStatus" => $nextStatus,
        "dbError" => $conn->error,
      ], [
        "subsystem" => "admin_feedback",
        "event" => "prepare_failed",
      ]);
      $_SESSION["admin_feedback_flash"] = [
        "type" => "error",
        "message" => "The feedback record could not be updated.",
      ];
    } else {
      $ok = $stmt->execute();
      if ($ok) {
        app_log("info", "Admin feedback status updated", [
          "feedbackId" => $feedbackId,
          "nextStatus" => $nextStatus,
          "adminId" => $adminId,
        ], [
          "subsystem" => "admin_feedback",
          "event" => "status_updated",
        ]);
      } else {
        app_log("error", "Admin feedback update failed", [
          "feedbackId" => $feedbackId,
          "nextStatus" => $nextStatus,
          "adminId" => $adminId,
          "dbError" => $stmt->error,
        ], [
          "subsystem" => "admin_feedback",
          "event" => "execute_failed",
        ]);
      }
      $stmt->close();

      $_SESSION["admin_feedback_flash"] = [
        "type" => $ok ? "success" : "error",
        "message" => $ok
          ? "Feedback #{$feedbackId} was marked as {$statusOptions[$nextStatus]}."
          : "The feedback record could not be updated.",
      ];
    }
  }

  $redirectQuery = [];
  if ($redirectStatus !== "all") {
    $redirectQuery["status"] = $redirectStatus;
  }
  if ($redirectCategory !== "all") {
    $redirectQuery["category"] = $redirectCategory;
  }

  $redirect = "feedback.php";
  if ($redirectQuery) {
    $redirect .= "?" . http_build_query($redirectQuery);
  }

  header("Location: " . $redirect);
  exit;
}

$counts = [
  "total_count" => 0,
  "new_count" => 0,
  "reviewed_count" => 0,
  "resolved_count" => 0,
];

$countRes = $conn->query(
  "SELECT
      COUNT(*) AS total_count,
      SUM(status = 'new') AS new_count,
      SUM(status = 'reviewed') AS reviewed_count,
      SUM(status = 'resolved') AS resolved_count
   FROM feedback"
);
if ($countRes instanceof mysqli_result) {
  $row = $countRes->fetch_assoc();
  if (is_array($row)) {
    $counts = [
      "total_count" => (int)($row["total_count"] ?? 0),
      "new_count" => (int)($row["new_count"] ?? 0),
      "reviewed_count" => (int)($row["reviewed_count"] ?? 0),
      "resolved_count" => (int)($row["resolved_count"] ?? 0),
    ];
  }
}

$where = [];

if ($statusFilter !== "all") {
  $where[] = "f.status = ?";
}

if ($categoryFilter !== "all") {
  $where[] = "f.category = ?";
}

$sql = "
  SELECT
    f.feedback_id,
    f.rating,
    f.category,
    f.message,
    f.target_name,
    f.source_page,
    f.selected_building,
    f.selected_room,
    f.map_version,
    f.status,
    f.created_at,
    f.reviewed_at,
    COALESCE(a.full_name, a.username) AS reviewer_name
  FROM feedback f
  LEFT JOIN admin_users a ON a.admin_id = f.reviewed_by_admin_id
";
if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY f.created_at DESC, f.feedback_id DESC LIMIT 250";

$feedbackRows = [];
$loadError = "";
$stmt = $conn->prepare($sql);
if (!$stmt) {
  $loadError = "Feedback records could not be loaded.";
} else {
  if ($statusFilter !== "all" && $categoryFilter !== "all") {
    $stmt->bind_param("ss", $statusFilter, $categoryFilter);
  } elseif ($statusFilter !== "all") {
    $stmt->bind_param("s", $statusFilter);
  } elseif ($categoryFilter !== "all") {
    $stmt->bind_param("s", $categoryFilter);
  }

  if ($stmt->execute()) {
    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
      while ($row = $result->fetch_assoc()) {
        $feedbackRows[] = $row;
      }
    }
  } else {
    $loadError = "Feedback records could not be loaded.";
  }

  $stmt->close();
}

admin_layout_start("Feedback", "feedback");
?>

<style>
  .feedback-admin-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .feedback-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: end;
  }

  .feedback-filter-field {
    min-width: 180px;
  }

  .feedback-filter-label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 900;
    color: #344054;
  }

  .feedback-alert {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid #d0d5dd;
    font-size: 13px;
    font-weight: 800;
  }

  .feedback-alert.success {
    background: #ecfdf3;
    border-color: #abefc6;
    color: #067647;
  }

  .feedback-alert.error {
    background: #fef3f2;
    border-color: #fecdca;
    color: #b42318;
  }

  .feedback-kpis {
    margin-bottom: 14px;
  }

  .feedback-kpi-card .kpi-title {
    text-transform: uppercase;
  }

  .feedback-meta {
    color: #667085;
    font-size: 12px;
    line-height: 1.55;
  }

  .feedback-title-cell {
    display: grid;
    gap: 6px;
  }

  .feedback-target {
    color: #101828;
    font-size: 14px;
    font-weight: 900;
  }

  .feedback-message {
    color: #475467;
    font-size: 13px;
    line-height: 1.55;
    white-space: pre-wrap;
  }

  .feedback-context {
    display: grid;
    gap: 4px;
    color: #475467;
    font-size: 12px;
    line-height: 1.5;
  }

  .feedback-chip,
  .feedback-status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    white-space: nowrap;
  }

  .feedback-chip {
    background: #f2f4f7;
    color: #344054;
    border: 1px solid #d0d5dd;
  }

  .feedback-status-pill.status-new {
    background: #fff7ed;
    color: #b54708;
    border: 1px solid #fed7aa;
  }

  .feedback-status-pill.status-reviewed {
    background: #eff8ff;
    color: #175cd3;
    border: 1px solid #b2ddff;
  }

  .feedback-status-pill.status-resolved {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
  }

  .feedback-actions-stack {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .feedback-inline-form {
    display: inline-flex;
  }

  .feedback-small-btn {
    min-height: 34px;
    padding: 0 10px;
    border-radius: 10px;
    font-size: 12px;
  }

  .feedback-empty {
    padding: 28px 18px;
    text-align: center;
    color: #667085;
    font-weight: 800;
  }

  @media (max-width: 900px) {
    .feedback-admin-toolbar,
    .feedback-filter-form {
      flex-direction: column;
      align-items: stretch;
    }

    .feedback-filter-field {
      min-width: 100%;
    }
  }
</style>

<?php if (is_array($flash) && !empty($flash["message"])): ?>
  <div class="feedback-alert <?= htmlspecialchars((string)($flash["type"] ?? "success")) ?>">
    <?= htmlspecialchars((string)$flash["message"]) ?>
  </div>
<?php endif; ?>

<?php if ($loadError !== ""): ?>
  <div class="feedback-alert error"><?= htmlspecialchars($loadError) ?></div>
<?php endif; ?>

<div class="grid cols-4 feedback-kpis">
  <div class="card feedback-kpi-card">
    <div class="kpi-title">Total Feedback</div>
    <div class="kpi-value"><?= number_format($counts["total_count"]) ?></div>
  </div>
  <div class="card feedback-kpi-card">
    <div class="kpi-title">New</div>
    <div class="kpi-value"><?= number_format($counts["new_count"]) ?></div>
  </div>
  <div class="card feedback-kpi-card">
    <div class="kpi-title">Reviewed</div>
    <div class="kpi-value"><?= number_format($counts["reviewed_count"]) ?></div>
  </div>
  <div class="card feedback-kpi-card">
    <div class="kpi-title">Resolved</div>
    <div class="kpi-value"><?= number_format($counts["resolved_count"]) ?></div>
  </div>
</div>

<div class="card">
  <div class="feedback-admin-toolbar">
    <div>
      <div class="section-title" style="margin-bottom:4px;">Feedback Inbox</div>
      <div class="feedback-meta">Review map issues, navigation problems, missing destinations, and general kiosk suggestions.</div>
    </div>

    <form class="feedback-filter-form" method="get">
      <label class="feedback-filter-field">
        <span class="feedback-filter-label">Status</span>
        <select class="select" name="status">
          <option value="all">All statuses</option>
          <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? "selected" : "" ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="feedback-filter-field">
        <span class="feedback-filter-label">Category</span>
        <select class="select" name="category">
          <option value="all">All categories</option>
          <?php foreach ($categoryLabels as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $categoryFilter === $value ? "selected" : "" ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="btn primary" type="submit">Apply Filters</button>
        <a class="btn gray" href="feedback.php">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-wrap" style="margin-top:0;">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Rating / Type</th>
          <th>Feedback</th>
          <th>Context</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$feedbackRows): ?>
          <tr>
            <td colspan="6" class="feedback-empty">No feedback records match the current filters.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($feedbackRows as $row): ?>
            <?php
              $feedbackId = (int)($row["feedback_id"] ?? 0);
              $createdAt = (string)($row["created_at"] ?? "");
              $createdLabel = $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "Unknown";
              $reviewedAt = (string)($row["reviewed_at"] ?? "");
              $reviewedLabel = $reviewedAt !== "" ? date("M d, Y h:i A", strtotime($reviewedAt)) : "";

              $rating = (string)($row["rating"] ?? "");
              $category = (string)($row["category"] ?? "");
              $status = (string)($row["status"] ?? "new");

              $ratingLabel = $ratingLabels[$rating] ?? $rating;
              $categoryLabel = $categoryLabels[$category] ?? $category;
              $statusLabel = $statusOptions[$status] ?? ucfirst($status);

              $targetName = trim((string)($row["target_name"] ?? ""));
              $message = trim((string)($row["message"] ?? ""));
              $sourcePage = trim((string)($row["source_page"] ?? ""));
              $selectedBuilding = trim((string)($row["selected_building"] ?? ""));
              $selectedRoom = trim((string)($row["selected_room"] ?? ""));
              $mapVersion = trim((string)($row["map_version"] ?? ""));
              $reviewerName = trim((string)($row["reviewer_name"] ?? ""));
            ?>
            <tr>
              <td>
                <div class="feedback-title-cell">
                  <div><strong>#<?= number_format($feedbackId) ?></strong></div>
                  <div class="feedback-meta"><?= htmlspecialchars($createdLabel) ?></div>
                </div>
              </td>
              <td>
                <div class="feedback-title-cell">
                  <span class="feedback-chip"><?= htmlspecialchars($ratingLabel) ?></span>
                  <div><strong><?= htmlspecialchars($categoryLabel) ?></strong></div>
                </div>
              </td>
              <td>
                <div class="feedback-title-cell">
                  <div class="feedback-target">
                    <?= htmlspecialchars($targetName !== "" ? $targetName : "No destination provided") ?>
                  </div>
                  <div class="feedback-message">
                    <?= htmlspecialchars($message !== "" ? $message : "No additional comment.") ?>
                  </div>
                </div>
              </td>
              <td>
                <div class="feedback-context">
                  <div>Page: <?= htmlspecialchars($sourcePage !== "" ? ucfirst($sourcePage) : "Unknown") ?></div>
                  <?php if ($selectedBuilding !== ""): ?>
                    <div>Building: <?= htmlspecialchars($selectedBuilding) ?></div>
                  <?php endif; ?>
                  <?php if ($selectedRoom !== ""): ?>
                    <div>Room: <?= htmlspecialchars($selectedRoom) ?></div>
                  <?php endif; ?>
                  <?php if ($mapVersion !== ""): ?>
                    <div>Map Version: <?= htmlspecialchars($mapVersion) ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="feedback-title-cell">
                  <span class="feedback-status-pill status-<?= htmlspecialchars($status) ?>">
                    <?= htmlspecialchars($statusLabel) ?>
                  </span>
                  <?php if ($reviewedLabel !== "" || $reviewerName !== ""): ?>
                    <div class="feedback-meta">
                      <?= htmlspecialchars(trim(($reviewerName !== "" ? $reviewerName : "Admin") . ($reviewedLabel !== "" ? " | " . $reviewedLabel : ""))) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="feedback-actions-stack">
                  <?php if ($status !== "reviewed"): ?>
                    <form class="feedback-inline-form" method="post">
                      <input type="hidden" name="feedback_csrf" value="<?= htmlspecialchars($feedbackCsrf) ?>">
                      <input type="hidden" name="feedback_id" value="<?= $feedbackId ?>">
                      <input type="hidden" name="next_status" value="reviewed">
                      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                      <input type="hidden" name="category_filter" value="<?= htmlspecialchars($categoryFilter) ?>">
                      <button class="btn blue feedback-small-btn" type="submit">Mark Reviewed</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($status !== "resolved"): ?>
                    <form class="feedback-inline-form" method="post">
                      <input type="hidden" name="feedback_csrf" value="<?= htmlspecialchars($feedbackCsrf) ?>">
                      <input type="hidden" name="feedback_id" value="<?= $feedbackId ?>">
                      <input type="hidden" name="next_status" value="resolved">
                      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                      <input type="hidden" name="category_filter" value="<?= htmlspecialchars($categoryFilter) ?>">
                      <button class="btn primary feedback-small-btn" type="submit">Resolve</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($status !== "new"): ?>
                    <form class="feedback-inline-form" method="post">
                      <input type="hidden" name="feedback_csrf" value="<?= htmlspecialchars($feedbackCsrf) ?>">
                      <input type="hidden" name="feedback_id" value="<?= $feedbackId ?>">
                      <input type="hidden" name="next_status" value="new">
                      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                      <input type="hidden" name="category_filter" value="<?= htmlspecialchars($categoryFilter) ?>">
                      <button class="btn gray feedback-small-btn" type="submit">Reopen</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_layout_end(); ?>
