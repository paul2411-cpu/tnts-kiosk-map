<?php
require_once __DIR__ . "/../admin/inc/db.php";
require_once __DIR__ . "/../admin/inc/announcements.php";

$pageTitle = "Announcements";
$activePage = "announcement";

announcements_ensure_schema($conn);
$announcements = announcements_sort_rows_by_priority(announcements_load_rows($conn, true));
$headlineAnnouncements = [];
$regularAnnouncements = [];
$importantCount = 0;
$normalCount = 0;

foreach ($announcements as $row) {
  $importance = announcements_importance_normalize($row["importance_level"] ?? "normal");
  if ($importance === "important") $importantCount++;
  if ($importance === "normal") $normalCount++;

  if (announcements_is_headline($row)) $headlineAnnouncements[] = $row;
  else $regularAnnouncements[] = $row;
}

$headlineCount = count($headlineAnnouncements);
$totalAnnouncements = count($announcements);

ob_start();
?>
<div class="public-page public-page--announcement">
  <div class="public-page__shell">
    <section class="public-hero">
      <div class="public-hero__eyebrow">Campus Updates</div>
      <h1 class="public-hero__title">Stay informed with the latest TNTS announcements.</h1>
      <p class="public-hero__copy">
        Announcements appear automatically when their schedule starts and disappear when their schedule ends, so this page stays focused on updates that still matter to visitors right now.
      </p>

      <div class="public-stats">
        <article class="public-stat">
          <div class="public-stat__value"><?= $totalAnnouncements ?></div>
          <div class="public-stat__label">Active Updates</div>
          <div class="public-stat__hint">Notices, reminders, and public-facing campus information currently in rotation.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $headlineCount ?></div>
          <div class="public-stat__label">Headline Items</div>
          <div class="public-stat__hint">Priority messages designed to stand out the moment someone opens the page.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $importantCount ?></div>
          <div class="public-stat__label">Important Notices</div>
          <div class="public-stat__hint">High-importance updates that need stronger visual emphasis than normal posts.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $normalCount ?></div>
          <div class="public-stat__label">Standard Updates</div>
          <div class="public-stat__hint">Everyday reminders, routine announcements, and general information for visitors.</div>
        </article>
      </div>
    </section>

    <?php if (!$announcements): ?>
      <section class="public-empty">
        <h2 class="public-empty__title">No active announcements right now.</h2>
        <p class="public-empty__copy">Check back later for new campus notices, reminders, and updates.</p>
      </section>
    <?php else: ?>
      <?php if ($headlineAnnouncements): ?>
        <section class="public-section">
          <div class="public-section__label">Headline Announcements</div>
          <div class="announcement-stack">
            <?php foreach ($headlineAnnouncements as $row): ?>
              <?php
                $postedLabel = announcements_format_date((string)($row["date_posted"] ?? ""), true);
                $bannerUrl = announcements_banner_url((string)($row["banner_path"] ?? ""));
                $importance = announcements_importance_normalize($row["importance_level"] ?? "headline");
                $importanceLabel = announcements_importance_label($importance);
                $scheduleLabel = announcements_format_schedule($row);
              ?>
              <article class="public-card public-card--feature announcement-card announcement-card--headline">
                <?php if ($bannerUrl !== ""): ?>
                  <div class="public-card__media announcement-card__media announcement-card__media--headline">
                    <img src="<?= htmlspecialchars($bannerUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars((string)($row["title"] ?? "Announcement banner"), ENT_QUOTES, "UTF-8") ?>" loading="lazy">
                  </div>
                <?php endif; ?>
                <div class="public-card__meta">
                  <span class="public-pill public-pill--headline"><?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?></span>
                  <span class="public-pill public-pill--soft">Posted <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?></span>
                  <span class="public-pill public-pill--soft"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></span>
                </div>
                <h2 class="public-card__title public-card__title--feature"><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></h2>
                <div class="public-card__copy announcement-card__content"><?= htmlspecialchars((string)($row["content"] ?? ""), ENT_QUOTES, "UTF-8") ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($regularAnnouncements): ?>
        <section class="public-section">
          <div class="public-section__label">More Updates</div>
          <div class="public-grid public-grid--two">
            <?php foreach ($regularAnnouncements as $row): ?>
              <?php
                $postedLabel = announcements_format_date((string)($row["date_posted"] ?? ""), true);
                $bannerUrl = announcements_banner_url((string)($row["banner_path"] ?? ""));
                $importance = announcements_importance_normalize($row["importance_level"] ?? "normal");
                $importanceLabel = announcements_importance_label($importance);
                $scheduleLabel = announcements_format_schedule($row);
              ?>
              <article class="public-card announcement-card">
                <?php if ($bannerUrl !== ""): ?>
                  <div class="public-card__media announcement-card__media">
                    <img src="<?= htmlspecialchars($bannerUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars((string)($row["title"] ?? "Announcement banner"), ENT_QUOTES, "UTF-8") ?>" loading="lazy">
                  </div>
                <?php endif; ?>
                <div class="public-card__meta">
                  <span class="public-pill public-pill--<?= htmlspecialchars($importance === "important" ? "warning" : "brand", ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($importanceLabel, ENT_QUOTES, "UTF-8") ?></span>
                  <span class="public-pill public-pill--soft">Posted <?= htmlspecialchars($postedLabel, ENT_QUOTES, "UTF-8") ?></span>
                  <span class="public-pill public-pill--soft"><?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?></span>
                </div>
                <h2 class="public-card__title"><?= htmlspecialchars((string)($row["title"] ?? ""), ENT_QUOTES, "UTF-8") ?></h2>
                <div class="public-card__copy announcement-card__content"><?= htmlspecialchars((string)($row["content"] ?? ""), ENT_QUOTES, "UTF-8") ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
  .announcement-stack {
    display: grid;
    gap: 22px;
  }

  .announcement-card {
    align-content: start;
  }

  .announcement-card__media {
    min-height: 240px;
  }

  .announcement-card__media--headline {
    min-height: 320px;
  }

  .announcement-card__content {
    min-height: 0;
  }

  @media (max-width: 760px) {
    .announcement-card__media {
      min-height: 220px;
    }

    .announcement-card__media--headline {
      min-height: 260px;
    }
  }
</style>
HTML;

include __DIR__ . "/../ui/layout.php";
