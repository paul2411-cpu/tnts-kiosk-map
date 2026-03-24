<?php
require_once __DIR__ . "/inc/auth.php";
require_admin();
require_once __DIR__ . "/inc/app_logger.php";
app_logger_bootstrap(["subsystem" => "error_logs"]);
require_once __DIR__ . "/inc/layout.php";

$dateOptions = app_logger_list_dates();
$selectedDate = trim((string)($_GET["date"] ?? ""));
if ($selectedDate !== "" && !in_array($selectedDate, $dateOptions, true)) {
  $selectedDate = "";
}

$levelOptions = ["", "critical", "error", "warning", "info", "debug"];
$selectedLevel = strtolower(trim((string)($_GET["level"] ?? "")));
if (!in_array($selectedLevel, $levelOptions, true)) {
  $selectedLevel = "";
}

$selectedSubsystem = strtolower(trim((string)($_GET["subsystem"] ?? "")));
$query = trim((string)($_GET["q"] ?? ""));
$limit = isset($_GET["limit"]) ? (int)$_GET["limit"] : 150;
if ($limit <= 0) $limit = 150;
if ($limit > 500) $limit = 500;

$entries = app_logger_read_entries([
  "date" => $selectedDate,
  "level" => $selectedLevel,
  "subsystem" => $selectedSubsystem,
  "q" => $query,
], $limit);

$recentForFilters = app_logger_read_entries([], 500);
$subsystemOptions = [];
foreach ($recentForFilters as $entry) {
  $name = strtolower(trim((string)($entry["subsystem"] ?? "")));
  if ($name === "") continue;
  $subsystemOptions[$name] = $name;
}
ksort($subsystemOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($selectedSubsystem !== "" && !isset($subsystemOptions[$selectedSubsystem])) {
  $subsystemOptions[$selectedSubsystem] = $selectedSubsystem;
  ksort($subsystemOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

$stats = [
  "critical" => 0,
  "error" => 0,
  "warning" => 0,
  "info" => 0,
  "debug" => 0,
];
foreach ($entries as $entry) {
  $level = strtolower(trim((string)($entry["level"] ?? "")));
  if (!isset($stats[$level])) continue;
  $stats[$level]++;
}

$GLOBALS["admin_extra_head"] = <<<HTML
<style>
  .log-toolbar {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
  }
  .log-toolbar label {
    display: grid;
    gap: 6px;
    color: #475467;
    font-size: 12px;
    font-weight: 800;
  }
  .log-toolbar input,
  .log-toolbar select {
    min-height: 42px;
    border-radius: 12px;
    border: 1px solid #d0d5dd;
    padding: 0 12px;
    font: inherit;
  }
  .log-toolbar .wide {
    grid-column: span 2;
  }
  .log-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 12px;
  }
  .log-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin-top: 14px;
  }
  .log-stat {
    border: 1px solid #eaecf0;
    border-radius: 14px;
    padding: 14px;
    background: #fff;
  }
  .log-stat__label {
    color: #667085;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .log-stat__value {
    margin-top: 6px;
    color: #101828;
    font-size: 26px;
    font-weight: 900;
  }
  .log-list {
    display: grid;
    gap: 12px;
  }
  .log-entry {
    border: 1px solid #eaecf0;
    border-radius: 16px;
    background: #fff;
    padding: 16px;
  }
  .log-entry__top {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }
  .log-entry__badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .log-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 26px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.05em;
    text-transform: uppercase;
  }
  .log-pill--critical { background: #7a0000; color: #fff; }
  .log-pill--error { background: #f04438; color: #fff; }
  .log-pill--warning { background: #f79009; color: #fff; }
  .log-pill--info { background: #175cd3; color: #fff; }
  .log-pill--debug { background: #344054; color: #fff; }
  .log-pill--subsystem { background: #f2f4f7; color: #344054; }
  .log-entry__message {
    margin-top: 12px;
    color: #101828;
    font-size: 16px;
    font-weight: 800;
    line-height: 1.5;
  }
  .log-entry__meta {
    margin-top: 12px;
    color: #667085;
    font-size: 13px;
    line-height: 1.6;
    display: grid;
    gap: 4px;
  }
  .log-entry details {
    margin-top: 12px;
    border: 1px solid #eaecf0;
    border-radius: 12px;
    padding: 12px;
    background: #fcfcfd;
  }
  .log-entry summary {
    cursor: pointer;
    font-weight: 800;
    color: #344054;
  }
  .log-entry pre {
    margin: 10px 0 0;
    white-space: pre-wrap;
    word-break: break-word;
    color: #101828;
    font-size: 12px;
    line-height: 1.5;
  }
  .log-empty {
    padding: 18px;
    border-radius: 16px;
    border: 1px dashed #d0d5dd;
    color: #667085;
    font-weight: 700;
    background: #fff;
  }
  @media (max-width: 1100px) {
    .log-toolbar,
    .log-stats {
      grid-template-columns: 1fr 1fr;
    }
    .log-toolbar .wide {
      grid-column: span 2;
    }
  }
  @media (max-width: 640px) {
    .log-toolbar,
    .log-stats {
      grid-template-columns: 1fr;
    }
    .log-toolbar .wide {
      grid-column: span 1;
    }
  }
</style>
HTML;

admin_layout_start("Error Logs", "errorlogs");
?>

<div class="card">
  <div class="section-title">Project Error History</div>
  <div style="color:#667085;font-weight:800;line-height:1.7;">
    Structured diagnostics from admin actions, APIs, publish/import flows, and browser-reported runtime failures.
    Use the filters below to isolate a model, subsystem, or a specific failure message.
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <form method="get" class="log-toolbar">
    <label>
      Date
      <select name="date">
        <option value="">All recent dates</option>
        <?php foreach ($dateOptions as $date): ?>
          <option value="<?= htmlspecialchars($date) ?>" <?= $selectedDate === $date ? "selected" : "" ?>><?= htmlspecialchars($date) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Level
      <select name="level">
        <option value="">All levels</option>
        <?php foreach (array_slice($levelOptions, 1) as $level): ?>
          <option value="<?= htmlspecialchars($level) ?>" <?= $selectedLevel === $level ? "selected" : "" ?>><?= htmlspecialchars(strtoupper($level)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Subsystem
      <select name="subsystem">
        <option value="">All subsystems</option>
        <?php foreach ($subsystemOptions as $subsystem): ?>
          <option value="<?= htmlspecialchars($subsystem) ?>" <?= $selectedSubsystem === $subsystem ? "selected" : "" ?>><?= htmlspecialchars($subsystem) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Limit
      <select name="limit">
        <?php foreach ([50, 100, 150, 250, 500] as $option): ?>
          <option value="<?= $option ?>" <?= $limit === $option ? "selected" : "" ?>><?= $option ?> entries</option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="wide">
      Search
      <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="message, model file, subsystem, request id" />
    </label>

    <div class="log-actions">
      <button class="btn primary" type="submit">Filter Logs</button>
      <a class="btn ghost" href="errorLogs.php">Reset Filters</a>
    </div>
  </form>

  <div class="log-stats">
    <?php foreach ($stats as $level => $count): ?>
      <div class="log-stat">
        <div class="log-stat__label"><?= htmlspecialchars(strtoupper($level)) ?></div>
        <div class="log-stat__value"><?= (int)$count ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div class="section-title">Recent Entries</div>
  <div class="log-list">
    <?php if (!$entries): ?>
      <div class="log-empty">No log entries matched the current filters.</div>
    <?php else: ?>
      <?php foreach ($entries as $entry): ?>
        <?php
          $level = strtolower(trim((string)($entry["level"] ?? "info")));
          $subsystem = trim((string)($entry["subsystem"] ?? ""));
          $request = is_array($entry["request"] ?? null) ? $entry["request"] : [];
          $context = is_array($entry["context"] ?? null) ? $entry["context"] : [];
          $requestJson = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ?>
        <article class="log-entry">
          <div class="log-entry__top">
            <div class="log-entry__badges">
              <span class="log-pill log-pill--<?= htmlspecialchars($level) ?>"><?= htmlspecialchars(strtoupper($level)) ?></span>
              <?php if ($subsystem !== ""): ?>
                <span class="log-pill log-pill--subsystem"><?= htmlspecialchars($subsystem) ?></span>
              <?php endif; ?>
              <?php if (!empty($entry["event"])): ?>
                <span class="log-pill log-pill--subsystem"><?= htmlspecialchars((string)$entry["event"]) ?></span>
              <?php endif; ?>
            </div>
            <div style="color:#667085;font-size:12px;font-weight:800;">
              <?= htmlspecialchars((string)($entry["ts"] ?? "")) ?>
            </div>
          </div>

          <div class="log-entry__message"><?= htmlspecialchars((string)($entry["message"] ?? "")) ?></div>

          <div class="log-entry__meta">
            <div>Request ID: <strong><?= htmlspecialchars((string)($request["requestId"] ?? "")) ?></strong></div>
            <div>Route: <strong><?= htmlspecialchars((string)($request["method"] ?? "")) ?></strong> <?= htmlspecialchars((string)($request["uri"] ?? "")) ?></div>
            <?php if (!empty($request["adminName"])): ?>
              <div>Admin: <strong><?= htmlspecialchars((string)$request["adminName"]) ?></strong><?php if (!empty($request["adminRole"])): ?> (<?= htmlspecialchars((string)$request["adminRole"]) ?>)<?php endif; ?></div>
            <?php endif; ?>
            <div>Log File: <strong><?= htmlspecialchars((string)($entry["file"] ?? "")) ?></strong></div>
          </div>

          <details>
            <summary>Request Context</summary>
            <pre><?= htmlspecialchars((string)($requestJson ?: "{}")) ?></pre>
          </details>

          <details>
            <summary>Event Context</summary>
            <pre><?= htmlspecialchars((string)($contextJson ?: "{}")) ?></pre>
          </details>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php admin_layout_end(); ?>
