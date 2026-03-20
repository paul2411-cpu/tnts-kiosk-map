<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/db.php";
require_once __DIR__ . "/inc/announcements.php";
require_once __DIR__ . "/inc/layout.php";

announcements_ensure_schema($conn);
$ROOT = dirname(__DIR__);

function announcements_admin_redirect(array $query = []): void {
  $url = "announcement.php";
  if ($query) $url .= "?" . http_build_query($query);
  header("Location: " . $url);
  exit;
}

function announcements_admin_alert_type(array $flash): string {
  $type = trim((string)($flash["type"] ?? "info"));
  return in_array($type, ["success", "error"], true) ? $type : "info";
}

if (empty($_SESSION["admin_announcements_csrf"])) {
  try {
    $_SESSION["admin_announcements_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $_) {
    $_SESSION["admin_announcements_csrf"] = sha1(uniqid((string)mt_rand(), true));
  }
}
$announcementsCsrf = (string)$_SESSION["admin_announcements_csrf"];

$flash = $_SESSION["admin_announcements_flash"] ?? null;
unset($_SESSION["admin_announcements_flash"]);

$form = [
  "announcement_id" => 0,
  "title" => "",
  "content" => "",
  "banner_path" => "",
  "importance_level" => "normal",
  "schedule_mode" => "date_only",
  "schedule_start_date" => "",
  "schedule_end_date" => "",
  "schedule_start_at" => "",
  "schedule_end_at" => "",
];
$persistedBannerPath = "";
$formErrors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postedCsrf = (string)($_POST["announcements_csrf"] ?? "");
  if ($postedCsrf === "" || !hash_equals($announcementsCsrf, $postedCsrf)) {
    $_SESSION["admin_announcements_flash"] = [
      "type" => "error",
      "message" => "The announcement action could not be verified. Please refresh and try again.",
    ];
    announcements_admin_redirect();
  }

  $action = trim((string)($_POST["action"] ?? "save"));
  $announcementId = (int)($_POST["announcement_id"] ?? 0);

  if ($action === "delete") {
    if ($announcementId <= 0) {
      $_SESSION["admin_announcements_flash"] = [
        "type" => "error",
        "message" => "The selected announcement could not be deleted.",
      ];
      announcements_admin_redirect();
    }

    $existingRow = announcements_load_row_by_id($conn, $announcementId);
    $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ? LIMIT 1");
    if (!$stmt) {
      $_SESSION["admin_announcements_flash"] = [
        "type" => "error",
        "message" => "The announcement could not be deleted right now.",
      ];
      announcements_admin_redirect();
    }

    $stmt->bind_param("i", $announcementId);
    $ok = $stmt->execute();
    $stmt->close();

    $_SESSION["admin_announcements_flash"] = [
      "type" => $ok ? "success" : "error",
      "message" => $ok
        ? "Announcement #{$announcementId} was deleted."
        : "The announcement could not be deleted right now.",
    ];
    if ($ok && is_array($existingRow)) {
      announcements_delete_local_banner($ROOT, (string)($existingRow["banner_path"] ?? ""));
    }
    announcements_admin_redirect();
  }

  $previousBannerPath = trim((string)($_POST["existing_banner_path"] ?? ""));
  $persistedBannerPath = $previousBannerPath;
  $uploadedBannerPath = "";
  $removeBannerRequested = !empty($_POST["remove_banner"]);

  $form = [
    "announcement_id" => $announcementId,
    "title" => announcements_trimmed($_POST["title"] ?? "", 150),
    "content" => announcements_trimmed($_POST["content"] ?? "", 12000),
    "banner_path" => $previousBannerPath,
    "importance_level" => announcements_importance_normalize($_POST["importance_level"] ?? "normal"),
    "schedule_mode" => announcements_schedule_mode_normalize($_POST["schedule_mode"] ?? "date_only"),
    "schedule_start_date" => trim((string)($_POST["schedule_start_date"] ?? "")),
    "schedule_end_date" => trim((string)($_POST["schedule_end_date"] ?? "")),
    "schedule_start_at" => trim((string)($_POST["schedule_start_at"] ?? "")),
    "schedule_end_at" => trim((string)($_POST["schedule_end_at"] ?? "")),
  ];

  if ($form["title"] === "") $formErrors[] = "A title is required.";
  if ($form["content"] === "") $formErrors[] = "Announcement content is required.";

  $startDateDb = "";
  $endDateDb = "";
  $startAtDb = "";
  $endAtDb = "";
  $legacyExpiryDate = "";

  if ($form["schedule_mode"] === "date_only") {
    if ($form["schedule_start_date"] !== "" && announcements_date_input_value($form["schedule_start_date"]) === "") {
      $formErrors[] = "The date-only start date is invalid.";
    } else {
      $startDateDb = announcements_date_input_value($form["schedule_start_date"]);
      $form["schedule_start_date"] = $startDateDb;
    }

    if ($form["schedule_end_date"] !== "" && announcements_date_input_value($form["schedule_end_date"]) === "") {
      $formErrors[] = "The date-only end date is invalid.";
    } else {
      $endDateDb = announcements_date_input_value($form["schedule_end_date"]);
      $form["schedule_end_date"] = $endDateDb;
    }

    if ($startDateDb !== "" && $endDateDb !== "" && $endDateDb < $startDateDb) {
      $formErrors[] = "The date-only end date must be on or after the start date.";
    }

    $legacyExpiryDate = $endDateDb;
    $form["schedule_start_at"] = announcements_datetime_input_value($form["schedule_start_at"]);
    $form["schedule_end_at"] = announcements_datetime_input_value($form["schedule_end_at"]);
  } else {
    if ($form["schedule_start_at"] !== "" && announcements_datetime_storage_value($form["schedule_start_at"]) === "") {
      $formErrors[] = "The specific start time is invalid.";
    } else {
      $startAtDb = announcements_datetime_storage_value($form["schedule_start_at"]);
      $form["schedule_start_at"] = announcements_datetime_input_value($form["schedule_start_at"]);
    }

    if ($form["schedule_end_at"] !== "" && announcements_datetime_storage_value($form["schedule_end_at"]) === "") {
      $formErrors[] = "The specific end time is invalid.";
    } else {
      $endAtDb = announcements_datetime_storage_value($form["schedule_end_at"]);
      $form["schedule_end_at"] = announcements_datetime_input_value($form["schedule_end_at"]);
    }

    if ($startAtDb === "" && $endAtDb === "") {
      $formErrors[] = "Specific-time announcements need at least a start time, an end time, or both.";
    }
    if ($startAtDb !== "" && $endAtDb !== "" && $endAtDb < $startAtDb) {
      $formErrors[] = "The specific end time must be after the start time.";
    }

    $legacyExpiryDate = $endAtDb !== "" ? substr($endAtDb, 0, 10) : "";
    $form["schedule_start_date"] = announcements_date_input_value($form["schedule_start_date"]);
    $form["schedule_end_date"] = announcements_date_input_value($form["schedule_end_date"]);
  }

  if (isset($_FILES["banner_file"]) && (int)($_FILES["banner_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $upload = announcements_store_banner_upload($_FILES["banner_file"], $ROOT);
    if (!$upload["ok"]) $formErrors[] = (string)$upload["error"];
    else {
      $uploadedBannerPath = (string)$upload["path"];
      $form["banner_path"] = $uploadedBannerPath;
    }
  }

  if ($formErrors && $uploadedBannerPath !== "" && $uploadedBannerPath !== $previousBannerPath) {
    announcements_delete_local_banner($ROOT, $uploadedBannerPath);
    $uploadedBannerPath = "";
    $form["banner_path"] = $previousBannerPath;
  }

  if (!$formErrors) {
    if ($removeBannerRequested && $uploadedBannerPath === "") {
      $form["banner_path"] = "";
    }

    if ($announcementId > 0) {
      $stmt = $conn->prepare(
        "UPDATE announcements
         SET
           title = ?,
           content = ?,
           expiry_date = NULLIF(?, ''),
           banner_path = NULLIF(?, ''),
           importance_level = ?,
           schedule_mode = ?,
           start_date = NULLIF(?, ''),
           end_date = NULLIF(?, ''),
           start_at = NULLIF(?, ''),
           end_at = NULLIF(?, '')
         WHERE announcement_id = ?
         LIMIT 1"
      );
      if ($stmt) {
        $stmt->bind_param(
          "ssssssssssi",
          $form["title"],
          $form["content"],
          $legacyExpiryDate,
          $form["banner_path"],
          $form["importance_level"],
          $form["schedule_mode"],
          $startDateDb,
          $endDateDb,
          $startAtDb,
          $endAtDb,
          $announcementId
        );
      }
      $ok = $stmt ? $stmt->execute() : false;
      if ($stmt) $stmt->close();

      if ($ok) {
        if ($previousBannerPath !== "" && $previousBannerPath !== $form["banner_path"]) {
          announcements_delete_local_banner($ROOT, $previousBannerPath);
        }
        $_SESSION["admin_announcements_flash"] = [
          "type" => "success",
          "message" => "Announcement #{$announcementId} was updated.",
        ];
        announcements_admin_redirect(["edit" => $announcementId]);
      }

      if ($uploadedBannerPath !== "" && $uploadedBannerPath !== $previousBannerPath) {
        announcements_delete_local_banner($ROOT, $uploadedBannerPath);
      }
      $formErrors[] = "The announcement could not be updated right now.";
    } else {
      $stmt = $conn->prepare(
        "INSERT INTO announcements (
          title,
          content,
          expiry_date,
          banner_path,
          importance_level,
          schedule_mode,
          start_date,
          end_date,
          start_at,
          end_at
        ) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))"
      );
      if ($stmt) {
        $stmt->bind_param(
          "ssssssssss",
          $form["title"],
          $form["content"],
          $legacyExpiryDate,
          $form["banner_path"],
          $form["importance_level"],
          $form["schedule_mode"],
          $startDateDb,
          $endDateDb,
          $startAtDb,
          $endAtDb
        );
      }
      $ok = $stmt ? $stmt->execute() : false;
      $newAnnouncementId = $stmt ? (int)$stmt->insert_id : 0;
      if ($stmt) $stmt->close();

      if ($ok) {
        $_SESSION["admin_announcements_flash"] = [
          "type" => "success",
          "message" => "Announcement #{$newAnnouncementId} was created.",
        ];
        announcements_admin_redirect(["edit" => $newAnnouncementId]);
      }

      if ($uploadedBannerPath !== "") {
        announcements_delete_local_banner($ROOT, $uploadedBannerPath);
      }
      $formErrors[] = "The announcement could not be created right now.";
    }
  }
}

$editId = isset($_GET["edit"]) && is_numeric($_GET["edit"]) ? (int)$_GET["edit"] : 0;
if (!$formErrors && $editId > 0) {
  $editingRow = announcements_load_row_by_id($conn, $editId);
  if ($editingRow) {
    $schedule = announcements_effective_schedule($editingRow);
    $form = [
      "announcement_id" => (int)($editingRow["announcement_id"] ?? 0),
      "title" => (string)($editingRow["title"] ?? ""),
      "content" => (string)($editingRow["content"] ?? ""),
      "banner_path" => (string)($editingRow["banner_path"] ?? ""),
      "importance_level" => announcements_importance_normalize($editingRow["importance_level"] ?? "normal"),
      "schedule_mode" => announcements_schedule_mode_normalize($editingRow["schedule_mode"] ?? "date_only"),
      "schedule_start_date" => $schedule["start_date"] instanceof DateTimeImmutable ? $schedule["start_date"]->format("Y-m-d") : "",
      "schedule_end_date" => $schedule["end_date"] instanceof DateTimeImmutable ? $schedule["end_date"]->format("Y-m-d") : "",
      "schedule_start_at" => announcements_datetime_input_value($editingRow["start_at"] ?? ""),
      "schedule_end_at" => announcements_datetime_input_value($editingRow["end_at"] ?? ""),
    ];
    $persistedBannerPath = (string)($editingRow["banner_path"] ?? "");
  } else {
    $flash = [
      "type" => "error",
      "message" => "The selected announcement could not be found.",
    ];
  }
}

$announcementRows = announcements_load_rows($conn, false);
$announcementDisplayRows = announcements_sort_rows_by_priority($announcementRows);
$counts = [
  "total" => count($announcementRows),
  "active" => 0,
  "upcoming" => 0,
  "headline" => 0,
];
foreach ($announcementRows as $row) {
  if (announcements_is_active($row)) $counts["active"]++;
  elseif (announcements_is_upcoming($row)) $counts["upcoming"]++;
  if (announcements_is_active($row) && announcements_is_headline($row)) $counts["headline"]++;
}

admin_layout_start("Announcements", "announcements");
?>

<style>
  .announcements-alert {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid #d0d5dd;
    font-size: 13px;
    font-weight: 800;
  }
  .announcements-alert.success { background: #ecfdf3; border-color: #abefc6; color: #067647; }
  .announcements-alert.error { background: #fef3f2; border-color: #fecdca; color: #b42318; }
  .announcements-alert.info { background: #eff8ff; border-color: #b2ddff; color: #175cd3; }

  .announcements-layout { display: grid; gap: 16px; margin-top: 14px; }
  .announcements-form-grid { display: grid; gap: 12px; }
  .announcements-inline-grid { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr) 200px 220px; }
  .announcements-schedule-grid { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .announcements-row-stack { display: grid; gap: 8px; }
  .announcements-note { color: #475467; font-size: 12px; line-height: 1.55; }
  .announcements-form-actions { display: flex; gap: 10px; justify-content: space-between; flex-wrap: wrap; }
  .announcements-banner-preview,
  .announcements-schedule-panel {
    display: grid;
    gap: 10px;
    margin-top: 8px;
    padding: 12px;
    border-radius: 16px;
    border: 1px solid #d0d5dd;
    background: #f8fafc;
  }
  .announcements-schedule-panel[hidden] { display: none; }
  .announcements-banner-preview img {
    display: block;
    width: 100%;
    max-width: 420px;
    height: 210px;
    object-fit: cover;
    border-radius: 14px;
    border: 1px solid rgba(16, 24, 40, 0.08);
    background: #eaecf0;
  }
  .announcements-title-cell { display: grid; gap: 4px; }
  .announcements-title-cell strong { font-size: 14px; color: #101828; }
  .announcements-meta,
  .announcements-copy { font-size: 12px; color: #475467; line-height: 1.55; }
  .announcements-copy { white-space: pre-wrap; }
  .announcements-actions { display: flex; flex-wrap: wrap; gap: 8px; }
  .announcements-empty { color: #667085; font-weight: 800; font-size: 13px; }

  .announcements-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.02em;
    white-space: nowrap;
  }
  .announcements-chip.status-active { background: #ecfdf3; color: #067647; }
  .announcements-chip.status-upcoming { background: #eff8ff; color: #175cd3; }
  .announcements-chip.status-expired { background: #fef3f2; color: #b42318; }
  .announcements-chip.importance-normal { background: #eef2ff; color: #3730a3; }
  .announcements-chip.importance-important { background: #fffaeb; color: #b54708; }
  .announcements-chip.importance-headline { background: #fef3f2; color: #b42318; }

  @media (max-width: 900px) {
    .announcements-inline-grid,
    .announcements-schedule-grid { grid-template-columns: 1fr; }
  }
</style>

<?php if (is_array($flash)): ?>
  <div class="announcements-alert <?= htmlspecialchars(announcements_admin_alert_type($flash), ENT_QUOTES, "UTF-8") ?>">
    <?= htmlspecialchars((string)($flash["message"] ?? ""), ENT_QUOTES, "UTF-8") ?>
  </div>
<?php endif; ?>

<?php if ($formErrors): ?>
  <div class="announcements-alert error">
    <?= htmlspecialchars(implode(" ", $formErrors), ENT_QUOTES, "UTF-8") ?>
  </div>
<?php endif; ?>

<div class="grid cols-4">
  <div class="card">
    <div class="kpi-title">TOTAL ANNOUNCEMENTS</div>
    <div class="kpi-value"><?= number_format($counts["total"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">ACTIVE NOW</div>
    <div class="kpi-value"><?= number_format($counts["active"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">UPCOMING</div>
    <div class="kpi-value"><?= number_format($counts["upcoming"]) ?></div>
  </div>
  <div class="card">
    <div class="kpi-title">HEADLINES</div>
    <div class="kpi-value"><?= number_format($counts["headline"]) ?></div>
  </div>
</div>

<div class="announcements-layout">
  <div class="card">
    <div class="section-title"><?= (int)$form["announcement_id"] > 0 ? "Edit Announcement" : "Create Announcement" ?></div>
    <div class="announcements-note" style="margin-bottom: 12px;">
      Choose whether the announcement runs for whole days or exact times. Headline announcements are featured at the top of the public page.
    </div>

    <form method="post" class="announcements-form-grid" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="announcements_csrf" value="<?= htmlspecialchars($announcementsCsrf, ENT_QUOTES, "UTF-8") ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="announcement_id" value="<?= (int)$form["announcement_id"] ?>">
      <input type="hidden" name="existing_banner_path" value="<?= htmlspecialchars($persistedBannerPath, ENT_QUOTES, "UTF-8") ?>">

      <div class="announcements-inline-grid">
        <div class="announcements-row-stack">
          <div class="label">Title</div>
          <input class="input" type="text" name="title" maxlength="150" placeholder="Example: Registration period extended" value="<?= htmlspecialchars((string)$form["title"], ENT_QUOTES, "UTF-8") ?>">
        </div>
        <div class="announcements-row-stack">
          <div class="label">Importance</div>
          <select class="select" name="importance_level">
            <?php foreach (announcements_importance_options() as $importanceValue => $importanceLabel): ?>
              <option value="<?= htmlspecialchars($importanceValue, ENT_QUOTES, "UTF-8") ?>" <?= $form["importance_level"] === $importanceValue ? "selected" : "" ?>>
                <?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="announcements-row-stack">
          <div class="label">Schedule Type</div>
          <select class="select" name="schedule_mode" data-schedule-mode>
            <?php foreach (announcements_schedule_mode_options() as $modeValue => $modeLabel): ?>
              <option value="<?= htmlspecialchars($modeValue, ENT_QUOTES, "UTF-8") ?>" <?= $form["schedule_mode"] === $modeValue ? "selected" : "" ?>>
                <?= htmlspecialchars($modeLabel, ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="announcements-row-stack">
        <div class="label">Schedule Window</div>
        <div class="announcements-note">
          Date-only announcements use whole days. Specific-time announcements start and end at the exact time you set.
        </div>

        <div class="announcements-schedule-panel" data-schedule-panel="date_only">
          <div class="announcements-schedule-grid">
            <div class="announcements-row-stack">
              <div class="label">Start Date</div>
              <input class="input" type="date" name="schedule_start_date" value="<?= htmlspecialchars((string)$form["schedule_start_date"], ENT_QUOTES, "UTF-8") ?>">
            </div>
            <div class="announcements-row-stack">
              <div class="label">End Date</div>
              <input class="input" type="date" name="schedule_end_date" value="<?= htmlspecialchars((string)$form["schedule_end_date"], ENT_QUOTES, "UTF-8") ?>">
            </div>
          </div>
          <div class="announcements-note">Leave both blank to keep the announcement visible until you manually change it. Use the same start and end date for a one-day post.</div>
        </div>

        <div class="announcements-schedule-panel" data-schedule-panel="timed" hidden>
          <div class="announcements-schedule-grid">
            <div class="announcements-row-stack">
              <div class="label">Start Time</div>
              <input class="input" type="datetime-local" name="schedule_start_at" value="<?= htmlspecialchars((string)$form["schedule_start_at"], ENT_QUOTES, "UTF-8") ?>">
            </div>
            <div class="announcements-row-stack">
              <div class="label">End Time</div>
              <input class="input" type="datetime-local" name="schedule_end_at" value="<?= htmlspecialchars((string)$form["schedule_end_at"], ENT_QUOTES, "UTF-8") ?>">
            </div>
          </div>
          <div class="announcements-note">Set both for a fixed window, or just one side if the announcement only starts later or ends at a deadline.</div>
        </div>
      </div>

      <div class="announcements-row-stack">
        <div class="label">Content</div>
        <textarea class="textarea" name="content" placeholder="Write the announcement shown on the public kiosk."><?= htmlspecialchars((string)$form["content"], ENT_QUOTES, "UTF-8") ?></textarea>
      </div>

      <div class="announcements-row-stack">
        <div class="label">Banner Image</div>
        <input class="input" type="file" name="banner_file" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="announcements-note">Optional. Supported formats: JPG, PNG, WEBP, and GIF up to 6 MB.</div>
        <?php if (trim((string)$form["banner_path"]) !== ""): ?>
          <div class="announcements-banner-preview">
            <img src="<?= htmlspecialchars(announcements_banner_url((string)$form["banner_path"]), ENT_QUOTES, "UTF-8") ?>" alt="Current announcement banner">
            <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:800; color:#344054;">
              <input type="checkbox" name="remove_banner" value="1">
              Remove current banner
            </label>
          </div>
        <?php endif; ?>
      </div>

      <div class="announcements-form-actions">
        <div class="actions">
          <button class="btn primary" type="submit"><?= (int)$form["announcement_id"] > 0 ? "Save Announcement" : "Create Announcement" ?></button>
          <a class="btn gray" href="announcement.php">Reset Form</a>
        </div>
      </div>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Announcement</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Schedule</th>
          <th>Posted</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$announcementRows): ?>
          <tr>
            <td colspan="6" class="announcements-empty">No announcements have been created yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($announcementDisplayRows as $row): ?>
            <?php
              $announcementId = (int)($row["announcement_id"] ?? 0);
              $importance = announcements_importance_normalize($row["importance_level"] ?? "normal");
              $importanceLabel = announcements_importance_label($importance);
              $status = announcements_status($row);
              $statusLabel = announcements_status_label($row);
              $postedLabel = announcements_format_date((string)($row["date_posted"] ?? ""), true);
              $scheduleLabel = announcements_format_schedule($row);
              $scheduleModeLabel = announcements_schedule_mode_label($row["schedule_mode"] ?? "date_only");
              $contentPreview = announcements_content_preview((string)($row["content"] ?? ""), 220);
            ?>
            <tr>
              <td>
                <div class="announcements-title-cell">
                  <strong><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></strong>
                  <?php if ($contentPreview !== ""): ?>
                    <div class="announcements-copy"><?= htmlspecialchars($contentPreview, ENT_QUOTES, "UTF-8") ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <span class="announcements-chip <?= htmlspecialchars(announcements_importance_class($importance), ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?>
                </span>
              </td>
              <td>
                <span class="announcements-chip status-<?= htmlspecialchars($status, ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars($statusLabel, ENT_QUOTES, "UTF-8") ?>
                </span>
              </td>
              <td>
                <div class="announcements-meta"><?= htmlspecialchars($scheduleModeLabel, ENT_QUOTES, "UTF-8") ?></div>
                <div class="announcements-meta"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></div>
              </td>
              <td>
                <div class="announcements-meta"><?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?></div>
              </td>
              <td>
                <div class="announcements-actions">
                  <a class="btn blue" href="announcement.php?edit=<?= $announcementId ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Delete this announcement?');">
                    <input type="hidden" name="announcements_csrf" value="<?= htmlspecialchars($announcementsCsrf, ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="announcement_id" value="<?= $announcementId ?>">
                    <button class="btn danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  (() => {
    const modeSelect = document.querySelector("[data-schedule-mode]");
    const panels = Array.from(document.querySelectorAll("[data-schedule-panel]"));
    if (!modeSelect || !panels.length) return;

    const syncPanels = () => {
      const mode = modeSelect.value || "date_only";
      panels.forEach((panel) => {
        panel.hidden = panel.getAttribute("data-schedule-panel") !== mode;
      });
    };

    modeSelect.addEventListener("change", syncPanels);
    syncPanels();
  })();
</script>

<?php admin_layout_end(); ?>
