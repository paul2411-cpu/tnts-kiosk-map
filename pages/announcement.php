<?php
require_once __DIR__ . "/../admin/inc/db.php";
require_once __DIR__ . "/../admin/inc/announcements.php";

$pageTitle = "Announcements";
$activePage = "announcement";

announcements_ensure_schema($conn);
$announcements = announcements_sort_rows_by_priority(announcements_load_rows($conn, true));
$headlineAnnouncements = [];
$regularAnnouncements = [];
foreach ($announcements as $row) {
  if (announcements_is_headline($row)) $headlineAnnouncements[] = $row;
  else $regularAnnouncements[] = $row;
}

ob_start();
?>
<div class="announcements-page">
  <section class="announcements-hero">
    <div class="announcements-eyebrow">Campus Updates</div>
    <h1 class="announcements-title">Stay informed with the latest TNTS announcements.</h1>
    <p class="announcements-copy">
      Announcements appear automatically when their schedule starts and disappear when their schedule ends.
    </p>
  </section>

  <?php if (!$announcements): ?>
    <section class="announcements-empty-card">
      <div class="announcements-empty-title">No active announcements right now.</div>
      <div class="announcements-empty-copy">
        Check back later for new campus notices, reminders, and updates.
      </div>
    </section>
  <?php else: ?>
    <?php if ($headlineAnnouncements): ?>
      <section class="announcements-featured">
        <div class="announcements-section-label">Headline Announcements</div>
        <div class="announcements-featured-list">
          <?php foreach ($headlineAnnouncements as $row): ?>
            <?php
              $postedLabel = announcements_format_date((string)($row["date_posted"] ?? ""), true);
              $bannerUrl = announcements_banner_url((string)($row["banner_path"] ?? ""));
              $importance = announcements_importance_normalize($row["importance_level"] ?? "headline");
              $importanceLabel = announcements_importance_label($importance);
              $scheduleLabel = announcements_format_schedule($row);
            ?>
            <article class="announcement-card announcement-card--headline">
              <?php if ($bannerUrl !== ""): ?>
                <div class="announcement-card__media announcement-card__media--headline">
                  <img src="<?= htmlspecialchars($bannerUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars((string)($row["title"] ?? "Announcement banner"), ENT_QUOTES, "UTF-8") ?>">
                </div>
              <?php endif; ?>
              <div class="announcement-card__meta">
                <span class="announcement-pill announcement-pill--headline"><?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?></span>
                <span class="announcement-card__posted">Posted <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?></span>
                <span class="announcement-card__schedule"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></span>
              </div>
              <h2 class="announcement-card__title announcement-card__title--headline"><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></h2>
              <div class="announcement-card__content"><?= htmlspecialchars((string)($row["content"] ?? ""), ENT_QUOTES, "UTF-8") ?></div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($regularAnnouncements): ?>
      <section class="announcements-list">
        <div class="announcements-section-label">More Updates</div>
        <?php foreach ($regularAnnouncements as $row): ?>
          <?php
            $postedLabel = announcements_format_date((string)($row["date_posted"] ?? ""), true);
            $bannerUrl = announcements_banner_url((string)($row["banner_path"] ?? ""));
            $importance = announcements_importance_normalize($row["importance_level"] ?? "normal");
            $importanceLabel = announcements_importance_label($importance);
            $scheduleLabel = announcements_format_schedule($row);
          ?>
          <article class="announcement-card">
            <?php if ($bannerUrl !== ""): ?>
              <div class="announcement-card__media">
                <img src="<?= htmlspecialchars($bannerUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars((string)($row["title"] ?? "Announcement banner"), ENT_QUOTES, "UTF-8") ?>">
              </div>
            <?php endif; ?>
            <div class="announcement-card__meta">
              <span class="announcement-pill announcement-pill--<?= htmlspecialchars($importance, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?></span>
              <span class="announcement-card__posted">Posted <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?></span>
              <span class="announcement-card__schedule"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <h2 class="announcement-card__title"><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></h2>
            <div class="announcement-card__content"><?= htmlspecialchars((string)($row["content"] ?? ""), ENT_QUOTES, "UTF-8") ?></div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
  .announcements-page {
    height: 100%;
    overflow: auto;
    padding: 28px 34px 100px;
    box-sizing: border-box;
    background:
      radial-gradient(circle at top right, rgba(122, 0, 0, 0.08), transparent 32%),
      linear-gradient(180deg, #f8f4f1 0%, #f5f6f8 100%);
  }
  .announcements-hero {
    display: grid;
    gap: 10px;
    margin-bottom: 22px;
  }
  .announcements-eyebrow {
    display: inline-flex;
    width: fit-content;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(122, 0, 0, 0.12);
    color: #7a0000;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .announcements-title {
    margin: 0;
    color: #101828;
    font-size: clamp(26px, 4vw, 40px);
    line-height: 1.05;
    letter-spacing: -0.03em;
  }
  .announcements-copy {
    margin: 0;
    max-width: 760px;
    color: #475467;
    font-size: 14px;
    line-height: 1.6;
  }
  .announcements-list {
    display: grid;
    gap: 18px;
  }
  .announcements-featured {
    display: grid;
    gap: 16px;
    margin-bottom: 22px;
  }
  .announcements-featured-list {
    display: grid;
    gap: 18px;
  }
  .announcements-section-label {
    color: #7a0000;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .announcement-card,
  .announcements-empty-card {
    display: grid;
    gap: 14px;
    padding: 22px;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(16, 24, 40, 0.08);
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
  }
  .announcement-card--headline {
    border-color: rgba(122, 0, 0, 0.18);
    box-shadow: 0 26px 56px rgba(122, 0, 0, 0.12);
    background:
      linear-gradient(180deg, rgba(255, 251, 246, 0.98), rgba(255, 255, 255, 0.98)),
      rgba(255, 255, 255, 0.98);
  }
  .announcement-card__media {
    overflow: hidden;
    border-radius: 18px;
    border: 1px solid rgba(16, 24, 40, 0.08);
    background: #eaecf0;
  }
  .announcement-card__media img {
    display: block;
    width: 100%;
    height: 240px;
    object-fit: cover;
  }
  .announcement-card__media--headline img {
    height: 300px;
  }
  .announcement-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }
  .announcement-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    background: #ecfdf3;
    color: #067647;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.05em;
    text-transform: uppercase;
  }
  .announcement-pill--normal { background: #eef2ff; color: #3730a3; }
  .announcement-pill--important { background: #fffaeb; color: #b54708; }
  .announcement-pill--headline { background: #fef3f2; color: #b42318; }
  .announcement-card__posted,
  .announcement-card__schedule {
    color: #667085;
    font-size: 12px;
    font-weight: 800;
  }
  .announcement-card__title,
  .announcements-empty-title {
    margin: 0;
    color: #101828;
    font-size: 24px;
    line-height: 1.15;
  }
  .announcement-card__title--headline {
    font-size: clamp(30px, 5vw, 42px);
    line-height: 1.02;
    letter-spacing: -0.04em;
  }
  .announcement-card__content,
  .announcements-empty-copy {
    color: #475467;
    font-size: 14px;
    line-height: 1.75;
    white-space: pre-wrap;
  }
  @media (max-width: 760px) {
    .announcements-page {
      padding: 22px 18px 100px;
    }
    .announcement-card,
    .announcements-empty-card {
      padding: 18px;
      border-radius: 18px;
    }
  }
</style>
HTML;

include __DIR__ . "/../ui/layout.php";
