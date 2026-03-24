<?php
require_once __DIR__ . "/../admin/inc/app_logger.php";
app_logger_bootstrap(["subsystem" => "public_feedback"]);
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$pageTitle = "Feedback";
$activePage = "feedback";

if (empty($_SESSION["public_feedback_csrf"])) {
  try {
    $_SESSION["public_feedback_csrf"] = bin2hex(random_bytes(32));
  } catch (Throwable $e) {
    $_SESSION["public_feedback_csrf"] = bin2hex(hash("sha256", uniqid((string)mt_rand(), true), true));
  }
}
$feedbackCsrf = (string)$_SESSION["public_feedback_csrf"];

$ratingOptions = [
  "helpful" => [
    "label" => "Helpful",
    "description" => "I found what I needed."
  ],
  "neutral" => [
    "label" => "Neutral",
    "description" => "It partly helped, but could be better."
  ],
  "not_helpful" => [
    "label" => "Not Helpful",
    "description" => "I could not complete what I needed."
  ],
];

$categoryOptions = [
  "map_issue" => [
    "label" => "Map Issue",
    "description" => "Something on the map looked wrong or confusing."
  ],
  "wrong_route" => [
    "label" => "Wrong Route",
    "description" => "The route did not guide me correctly."
  ],
  "not_found" => [
    "label" => "Not Found",
    "description" => "I could not find a building, room, or destination."
  ],
  "outdated_info" => [
    "label" => "Outdated Info",
    "description" => "The information shown looked old or inaccurate."
  ],
  "ui_problem" => [
    "label" => "UI Problem",
    "description" => "The kiosk was hard to use or unclear."
  ],
  "suggestion" => [
    "label" => "Suggestion",
    "description" => "I have an idea that could improve the kiosk."
  ],
  "general" => [
    "label" => "General",
    "description" => "Other comments that do not fit the options above."
  ],
];

$allowedSourcePages = ["map", "facilities", "events", "announcement", "feedback"];

function feedback_trimmed($value, $maxLen): string {
  $text = trim((string)$value);
  if ($text === "") return "";
  return strlen($text) > $maxLen ? substr($text, 0, $maxLen) : $text;
}

function feedback_live_map_version(): string {
  $path = dirname(__DIR__) . "/admin/overlays/map_live.json";
  if (!is_file($path)) return "";

  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === "") return "";

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) return "";

  return feedback_trimmed($decoded["version"] ?? "", 100);
}

$flash = $_SESSION["public_feedback_flash"] ?? null;
unset($_SESSION["public_feedback_flash"]);

$prefillSourcePage = feedback_trimmed($_GET["from"] ?? "feedback", 32);
if (!in_array($prefillSourcePage, $allowedSourcePages, true)) {
  $prefillSourcePage = "feedback";
}

$prefillBuilding = feedback_trimmed($_GET["building"] ?? "", 150);
$prefillRoom = feedback_trimmed($_GET["room"] ?? "", 150);
$prefillTarget = feedback_trimmed($_GET["target"] ?? "", 150);
$prefillMapVersion = feedback_live_map_version();

if ($prefillTarget === "") {
  $prefillTarget = $prefillRoom !== "" ? $prefillRoom : $prefillBuilding;
}

$form = [
  "rating" => "",
  "category" => "",
  "target_name" => $prefillTarget,
  "message" => "",
  "source_page" => $prefillSourcePage,
  "selected_building" => $prefillBuilding,
  "selected_room" => $prefillRoom,
  "map_version" => $prefillMapVersion,
];

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $form = [
    "rating" => feedback_trimmed($_POST["rating"] ?? "", 32),
    "category" => feedback_trimmed($_POST["category"] ?? "", 32),
    "target_name" => feedback_trimmed($_POST["target_name"] ?? "", 150),
    "message" => feedback_trimmed($_POST["message"] ?? "", 2000),
    "source_page" => feedback_trimmed($_POST["source_page"] ?? "feedback", 32),
    "selected_building" => feedback_trimmed($_POST["selected_building"] ?? "", 150),
    "selected_room" => feedback_trimmed($_POST["selected_room"] ?? "", 150),
    "map_version" => feedback_trimmed($_POST["map_version"] ?? feedback_live_map_version(), 100),
  ];

  $postedCsrf = (string)($_POST["feedback_csrf"] ?? "");
  if ($postedCsrf === "" || !hash_equals($feedbackCsrf, $postedCsrf)) {
    $errors[] = "Your session expired. Please try again.";
  }
  if (!isset($ratingOptions[$form["rating"]])) {
    $errors[] = "Please choose whether the kiosk was helpful.";
  }
  if (!isset($categoryOptions[$form["category"]])) {
    $errors[] = "Please choose the kind of feedback you want to send.";
  }
  if (!in_array($form["source_page"], $allowedSourcePages, true)) {
    $form["source_page"] = "feedback";
  }
  if ($form["target_name"] === "") {
    $form["target_name"] = $form["selected_room"] !== "" ? $form["selected_room"] : $form["selected_building"];
  }

  if (!$errors) {
    require_once __DIR__ . "/../admin/inc/db.php";

    $stmt = $conn->prepare(
      "INSERT INTO feedback (
        rating,
        category,
        message,
        target_name,
        source_page,
        selected_building,
        selected_room,
        map_version
      ) VALUES (
        ?,
        ?,
        NULLIF(?, ''),
        NULLIF(?, ''),
        ?,
        NULLIF(?, ''),
        NULLIF(?, ''),
        NULLIF(?, '')
      )"
    );

    if (!$stmt) {
      app_log("error", "Public feedback statement preparation failed", [
        "dbError" => $conn->error,
        "category" => $form["category"],
        "sourcePage" => $form["source_page"],
      ], [
        "subsystem" => "public_feedback",
        "event" => "prepare_failed",
      ]);
      $errors[] = "Feedback could not be prepared right now. Please try again.";
    } else {
      $stmt->bind_param(
        "ssssssss",
        $form["rating"],
        $form["category"],
        $form["message"],
        $form["target_name"],
        $form["source_page"],
        $form["selected_building"],
        $form["selected_room"],
        $form["map_version"]
      );

      if ($stmt->execute()) {
        app_log("info", "Public feedback saved", [
          "category" => $form["category"],
          "rating" => $form["rating"],
          "sourcePage" => $form["source_page"],
          "mapVersion" => $form["map_version"],
        ], [
          "subsystem" => "public_feedback",
          "event" => "feedback_saved",
        ]);
        $_SESSION["public_feedback_flash"] = [
          "ok" => true,
          "rating" => $ratingOptions[$form["rating"]]["label"],
          "category" => $categoryOptions[$form["category"]]["label"],
        ];
        header("Location: feedback.php?submitted=1");
        exit;
      }

      app_log("error", "Public feedback save failed", [
        "dbError" => $stmt->error,
        "category" => $form["category"],
        "rating" => $form["rating"],
        "sourcePage" => $form["source_page"],
      ], [
        "subsystem" => "public_feedback",
        "event" => "execute_failed",
      ]);
      $errors[] = "Feedback could not be saved right now. Please try again.";
      $stmt->close();
    }
  }
}

$showSuccess = is_array($flash) && !empty($flash["ok"]);

ob_start();
?>
<div class="feedback-page">
  <section class="feedback-shell">
    <div class="feedback-intro">
      <div class="feedback-kicker">Public Kiosk</div>
      <h1 class="feedback-title">Feedback</h1>
      <p class="feedback-copy">
        Help us improve the TNTS kiosk. Tell us whether the map helped you and what should be fixed.
      </p>

      <div class="feedback-points">
        <div class="feedback-point">
          <div class="feedback-point-title">What this is for</div>
          <div class="feedback-point-copy">Map issues, wrong routes, missing destinations, outdated information, and usability problems.</div>
        </div>
        <div class="feedback-point">
          <div class="feedback-point-title">How long it takes</div>
          <div class="feedback-point-copy">Just a few taps. Only the rating and feedback type are required.</div>
        </div>
        <div class="feedback-point">
          <div class="feedback-point-title">What gets saved</div>
          <div class="feedback-point-copy">Your response, optional destination details, and the current map version for troubleshooting.</div>
        </div>
      </div>
    </div>

    <div class="feedback-panel">
      <?php if ($showSuccess): ?>
        <div class="feedback-success">
          <div class="feedback-success-kicker">Saved</div>
          <h2 class="feedback-success-title">Thank you for your feedback.</h2>
          <p class="feedback-success-copy">
            Your response was recorded as <strong><?= htmlspecialchars((string)$flash["category"]) ?></strong> with a
            <strong><?= htmlspecialchars((string)$flash["rating"]) ?></strong> rating.
          </p>
          <div class="feedback-success-actions">
            <a class="feedback-btn feedback-btn-primary" href="../pages/map.php">Back To Map</a>
            <a class="feedback-btn feedback-btn-secondary" href="../pages/feedback.php">Send Another</a>
          </div>
        </div>
      <?php else: ?>
        <div class="feedback-panel-head">
          <div>
            <div class="feedback-panel-kicker">Send Feedback</div>
            <h2 class="feedback-panel-title">What happened?</h2>
          </div>
          <div class="feedback-panel-note">Required: rating and type</div>
        </div>

        <?php if ($errors): ?>
          <div class="feedback-alert">
            <?= htmlspecialchars(implode(" ", $errors)) ?>
          </div>
        <?php endif; ?>

        <form method="post" class="feedback-form" novalidate>
          <input type="hidden" name="feedback_csrf" value="<?= htmlspecialchars($feedbackCsrf) ?>">
          <input type="hidden" name="source_page" value="<?= htmlspecialchars($form["source_page"]) ?>">
          <input type="hidden" name="selected_building" value="<?= htmlspecialchars($form["selected_building"]) ?>">
          <input type="hidden" name="selected_room" value="<?= htmlspecialchars($form["selected_room"]) ?>">
          <input type="hidden" name="map_version" value="<?= htmlspecialchars($form["map_version"]) ?>">

          <fieldset class="feedback-group">
            <legend class="feedback-legend">Was the kiosk helpful?</legend>
            <div class="feedback-choice-grid feedback-choice-grid-rating">
              <?php foreach ($ratingOptions as $value => $option): ?>
                <label class="feedback-choice">
                  <input
                    class="feedback-choice-input"
                    type="radio"
                    name="rating"
                    value="<?= htmlspecialchars($value) ?>"
                    <?= $form["rating"] === $value ? "checked" : "" ?>
                  >
                  <span class="feedback-choice-card">
                    <span class="feedback-choice-title"><?= htmlspecialchars($option["label"]) ?></span>
                    <span class="feedback-choice-copy"><?= htmlspecialchars($option["description"]) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <fieldset class="feedback-group">
            <legend class="feedback-legend">What kind of feedback is this?</legend>
            <div class="feedback-choice-grid feedback-choice-grid-category">
              <?php foreach ($categoryOptions as $value => $option): ?>
                <label class="feedback-choice">
                  <input
                    class="feedback-choice-input"
                    type="radio"
                    name="category"
                    value="<?= htmlspecialchars($value) ?>"
                    <?= $form["category"] === $value ? "checked" : "" ?>
                  >
                  <span class="feedback-choice-card feedback-choice-card-category">
                    <span class="feedback-choice-title"><?= htmlspecialchars($option["label"]) ?></span>
                    <span class="feedback-choice-copy"><?= htmlspecialchars($option["description"]) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <div class="feedback-field-grid">
            <label class="feedback-field">
              <span class="feedback-field-label">Destination You Were Looking For</span>
              <input
                class="feedback-input"
                type="text"
                name="target_name"
                maxlength="150"
                placeholder="Example: Clinic, Library, Room 204"
                value="<?= htmlspecialchars($form["target_name"]) ?>"
              >
            </label>

            <label class="feedback-field feedback-field-message">
              <span class="feedback-field-label">Short Comment</span>
              <textarea
                class="feedback-textarea"
                name="message"
                id="feedback-message"
                maxlength="2000"
                placeholder="Tell us what happened, what was confusing, or what you want improved."
              ><?= htmlspecialchars($form["message"]) ?></textarea>
              <span class="feedback-field-hint">
                <span id="feedback-message-count"><?= strlen($form["message"]) ?></span>/2000
              </span>
            </label>
          </div>

          <?php if ($form["selected_building"] !== "" || $form["selected_room"] !== "" || $form["map_version"] !== ""): ?>
            <div class="feedback-context">
              <div class="feedback-context-title">Captured Context</div>
              <div class="feedback-context-copy">
                <?php if ($form["selected_building"] !== ""): ?>
                  <span>Building: <?= htmlspecialchars($form["selected_building"]) ?></span>
                <?php endif; ?>
                <?php if ($form["selected_room"] !== ""): ?>
                  <span>Room: <?= htmlspecialchars($form["selected_room"]) ?></span>
                <?php endif; ?>
                <?php if ($form["map_version"] !== ""): ?>
                  <span>Map Version: <?= htmlspecialchars($form["map_version"]) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="feedback-actions">
            <button class="feedback-btn feedback-btn-primary" type="submit">Send Feedback</button>
            <a class="feedback-btn feedback-btn-secondary" href="../pages/map.php">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
  .feedback-page {
    height: 100%;
    overflow: auto;
    padding: 32px 36px;
    box-sizing: border-box;
    background:
      linear-gradient(180deg, #f8fafc 0%, #ffffff 58%),
      radial-gradient(circle at top left, rgba(127, 29, 29, 0.05), rgba(127, 29, 29, 0) 34%);
  }

  .feedback-shell {
    max-width: 1180px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(280px, 0.88fr) minmax(420px, 1fr);
    gap: 28px;
    align-items: start;
  }

  .feedback-intro,
  .feedback-panel {
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e4e7ec;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.10);
  }

  .feedback-intro {
    padding: 30px;
  }

  .feedback-kicker,
  .feedback-panel-kicker,
  .feedback-success-kicker {
    color: #7f1d1d;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.18em;
    text-transform: uppercase;
  }

  .feedback-title {
    margin: 10px 0 12px;
    font-size: 38px;
    line-height: 1.03;
    color: #111827;
    font-weight: 950;
  }

  .feedback-copy {
    margin: 0;
    color: #475467;
    font-size: 16px;
    line-height: 1.6;
  }

  .feedback-points {
    margin-top: 28px;
    display: grid;
    gap: 14px;
  }

  .feedback-point {
    padding: 16px 18px;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #eaecf0;
  }

  .feedback-point-title {
    color: #111827;
    font-size: 13px;
    font-weight: 900;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .feedback-point-copy {
    margin-top: 8px;
    color: #475467;
    font-size: 14px;
    line-height: 1.55;
  }

  .feedback-panel {
    padding: 26px;
  }

  .feedback-panel-head {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
  }

  .feedback-panel-title,
  .feedback-success-title {
    margin: 8px 0 0;
    color: #111827;
    font-size: 28px;
    line-height: 1.1;
    font-weight: 950;
  }

  .feedback-panel-note {
    color: #667085;
    font-size: 12px;
    font-weight: 800;
    text-align: right;
  }

  .feedback-alert {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 16px;
    background: #fef3f2;
    border: 1px solid #fecdca;
    color: #b42318;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.5;
  }

  .feedback-form {
    display: grid;
    gap: 18px;
  }

  .feedback-group {
    margin: 0;
    padding: 0;
    border: 0;
  }

  .feedback-legend {
    margin-bottom: 10px;
    color: #111827;
    font-size: 14px;
    font-weight: 900;
  }

  .feedback-choice-grid {
    display: grid;
    gap: 12px;
  }

  .feedback-choice-grid-rating {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .feedback-choice-grid-category {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .feedback-choice {
    display: block;
  }

  .feedback-choice-input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }

  .feedback-choice-card {
    display: block;
    min-height: 100%;
    padding: 16px 16px 15px;
    border-radius: 18px;
    border: 1px solid #d0d5dd;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease, transform 0.16s ease;
  }

  .feedback-choice-card-category {
    min-height: 108px;
  }

  .feedback-choice-card:hover {
    transform: translateY(-1px);
    border-color: #b42318;
    box-shadow: 0 10px 24px rgba(127, 29, 29, 0.10);
  }

  .feedback-choice-input:checked + .feedback-choice-card {
    border-color: #7f1d1d;
    background: linear-gradient(180deg, #fff7f7 0%, #ffffff 100%);
    box-shadow: 0 12px 26px rgba(127, 29, 29, 0.14);
  }

  .feedback-choice-title {
    display: block;
    color: #111827;
    font-size: 15px;
    font-weight: 900;
  }

  .feedback-choice-copy {
    display: block;
    margin-top: 8px;
    color: #475467;
    font-size: 13px;
    line-height: 1.45;
  }

  .feedback-field-grid {
    display: grid;
    gap: 14px;
  }

  .feedback-field {
    display: grid;
    gap: 8px;
  }

  .feedback-field-label {
    color: #111827;
    font-size: 13px;
    font-weight: 900;
  }

  .feedback-input,
  .feedback-textarea {
    width: 100%;
    border: 1px solid #d0d5dd;
    border-radius: 16px;
    background: #fff;
    color: #111827;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
  }

  .feedback-input {
    min-height: 50px;
    padding: 12px 14px;
  }

  .feedback-textarea {
    min-height: 150px;
    padding: 14px;
    resize: vertical;
    line-height: 1.55;
  }

  .feedback-input:focus,
  .feedback-textarea:focus {
    border-color: #7f1d1d;
    box-shadow: 0 0 0 4px rgba(127, 29, 29, 0.08);
  }

  .feedback-field-hint {
    color: #667085;
    font-size: 12px;
    font-weight: 700;
    text-align: right;
  }

  .feedback-context {
    padding: 14px 16px;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px dashed #d0d5dd;
  }

  .feedback-context-title {
    color: #111827;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.06em;
  }

  .feedback-context-copy {
    margin-top: 7px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    color: #475467;
    font-size: 13px;
    line-height: 1.5;
  }

  .feedback-actions,
  .feedback-success-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }

  .feedback-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 0 20px;
    border: 0;
    border-radius: 16px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
  }

  .feedback-btn-primary {
    background: #7f1d1d;
    color: #fff;
    box-shadow: 0 14px 28px rgba(127, 29, 29, 0.18);
  }

  .feedback-btn-secondary {
    background: #f2f4f7;
    color: #344054;
    border: 1px solid #d0d5dd;
  }

  .feedback-success {
    display: grid;
    gap: 16px;
  }

  .feedback-success-copy {
    margin: 0;
    color: #475467;
    font-size: 15px;
    line-height: 1.6;
  }

  @media (max-width: 980px) {
    .feedback-shell {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 720px) {
    .feedback-page {
      padding: 18px 14px;
    }

    .feedback-intro,
    .feedback-panel {
      padding: 18px;
      border-radius: 18px;
    }

    .feedback-title {
      font-size: 30px;
    }

    .feedback-panel-title,
    .feedback-success-title {
      font-size: 22px;
    }

    .feedback-panel-head {
      align-items: start;
      flex-direction: column;
    }

    .feedback-choice-grid-rating,
    .feedback-choice-grid-category {
      grid-template-columns: 1fr;
    }

    .feedback-btn {
      width: 100%;
    }
  }
</style>
HTML;

$extraScripts = <<<HTML
<script>
  (function () {
    const textarea = document.getElementById("feedback-message");
    const counter = document.getElementById("feedback-message-count");
    if (!textarea || !counter) return;

    const updateCount = () => {
      counter.textContent = String(textarea.value.length);
    };

    textarea.addEventListener("input", updateCount);
    updateCount();
  })();
</script>
HTML;

include __DIR__ . "/../ui/layout.php";
