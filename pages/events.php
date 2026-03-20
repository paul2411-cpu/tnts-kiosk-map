<?php
require_once __DIR__ . "/../admin/inc/db.php";
require_once __DIR__ . "/../admin/inc/events.php";

$pageTitle = "Events";
$activePage = "events";
$ROOT = dirname(__DIR__);

events_ensure_schema($conn);
$publicModel = events_public_model_file($ROOT);

$events = [];
foreach (events_load_rows($conn, true) as $row) {
  if (!events_is_publicly_visible($row)) continue;
  $resolution = events_resolve_location($conn, $row, $publicModel, $ROOT);
  $schedule = events_classify_schedule($row);
  $eventId = (int)($row["event_id"] ?? 0);
  $events[] = [
    "id" => $eventId,
    "title" => trim((string)($row["title"] ?? "")),
    "description" => trim((string)($row["description"] ?? "")),
    "startDate" => trim((string)($row["start_date"] ?? "")),
    "endDate" => trim((string)($row["end_date"] ?? "")),
    "locationLabel" => trim((string)($resolution["displayLocation"] ?? $row["location"] ?? "")),
    "status" => trim((string)($row["status"] ?? "published")),
    "schedule" => $schedule,
    "health" => trim((string)($resolution["health"] ?? "limited")),
    "healthMessage" => trim((string)($resolution["message"] ?? "")),
    "canMap" => !empty($resolution["canMap"]),
    "canRoute" => !empty($resolution["canRoute"]),
    "mapHref" => "../pages/map.php?event=" . rawurlencode((string)$eventId),
    "routeHref" => "../pages/map.php?event=" . rawurlencode((string)$eventId) . "&autoroute=1",
    "bannerUrl" => events_banner_url((string)($row["banner_path"] ?? "")),
    "locationMode" => trim((string)($resolution["mode"] ?? "text_only")),
  ];
}

usort($events, static function (array $a, array $b): int {
  $priority = ["today" => 0, "ongoing" => 1, "upcoming" => 2, "unscheduled" => 3];
  $aRank = $priority[$a["schedule"]] ?? 9;
  $bRank = $priority[$b["schedule"]] ?? 9;
  if ($aRank !== $bRank) return $aRank <=> $bRank;
  return strcmp($a["startDate"] ?: "9999-12-31", $b["startDate"] ?: "9999-12-31");
});

$extraHead = <<<HTML
<style>
  .events-page {
    height: 100%;
    overflow: auto;
    padding: 28px 34px 100px;
    box-sizing: border-box;
    background:
      radial-gradient(circle at top left, rgba(128,0,0,0.08), transparent 34%),
      linear-gradient(180deg, #f8f4f1 0%, #f4f5f7 100%);
  }
  .events-hero {
    display: grid;
    gap: 10px;
    margin-bottom: 18px;
  }
  .events-eyebrow {
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
  .events-title {
    margin: 0;
    font-size: clamp(26px, 4vw, 40px);
    line-height: 1.05;
    letter-spacing: -0.03em;
    color: #101828;
  }
  .events-copy {
    margin: 0;
    max-width: 780px;
    color: #475467;
    font-size: 14px;
    line-height: 1.6;
  }
  .events-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 22px;
  }
  .events-filter {
    border: 1px solid #d0d5dd;
    background: rgba(255,255,255,0.86);
    color: #344054;
    padding: 10px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
  }
  .events-filter.is-active {
    background: #7a0000;
    border-color: #7a0000;
    color: #fff;
  }
  .events-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 22px;
  }
  .event-card {
    display: grid;
    grid-template-rows: 210px 1fr;
    background: rgba(255,255,255,0.94);
    border: 1px solid rgba(16,24,40,0.08);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 22px 48px rgba(15,23,42,0.08);
  }
  .event-card[hidden] { display: none !important; }
  .event-media {
    position: relative;
    background: linear-gradient(135deg, #2b2f38, #7a0000);
  }
  .event-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .event-media::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.02), rgba(0,0,0,0.35));
  }
  .event-badge-row {
    position: absolute;
    top: 14px;
    left: 14px;
    right: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    z-index: 1;
  }
  .event-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: rgba(255,255,255,0.92);
    color: #101828;
  }
  .event-pill.schedule-upcoming,
  .event-pill.schedule-today,
  .event-pill.schedule-ongoing { background: rgba(255,255,255,0.96); color: #7a0000; }
  .event-pill.health-valid { background: #ecfdf3; color: #067647; }
  .event-pill.health-limited { background: #fffaeb; color: #b54708; }
  .event-pill.health-needs_review,
  .event-pill.health-broken { background: #fef3f2; color: #b42318; }
  .event-body {
    display: grid;
    gap: 12px;
    padding: 18px;
  }
  .event-date {
    color: #7a0000;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .event-name {
    margin: 0;
    color: #101828;
    font-size: 18px;
    line-height: 1.2;
  }
  .event-location {
    color: #475467;
    font-size: 13px;
    line-height: 1.55;
    font-weight: 700;
  }
  .event-description {
    margin: 0;
    color: #475467;
    font-size: 13px;
    line-height: 1.6;
    min-height: 64px;
  }
  .event-capability {
    color: #667085;
    font-size: 12px;
    line-height: 1.55;
  }
  .event-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: auto;
  }
  .event-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 0 16px;
    border-radius: 999px;
    text-decoration: none;
    border: 1px solid #d0d5dd;
    background: #fff;
    color: #344054;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
  }
  .event-btn.primary {
    background: #7a0000;
    border-color: #7a0000;
    color: #fff;
  }
  .events-empty {
    display: none;
    padding: 18px 4px;
    color: #667085;
    font-size: 14px;
    font-weight: 800;
  }
  .events-empty.is-visible { display: block; }
  .events-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.55);
    z-index: 999;
  }
  .events-modal.is-open { display: flex; }
  .events-modal-card {
    width: min(780px, 96vw);
    max-height: 88vh;
    overflow: auto;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 30px 70px rgba(15, 23, 42, 0.28);
  }
  .events-modal-media {
    height: 280px;
    background: linear-gradient(135deg, #2b2f38, #7a0000);
  }
  .events-modal-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .events-modal-body {
    display: grid;
    gap: 14px;
    padding: 24px;
  }
  .events-modal-head {
    display: flex;
    gap: 12px;
    justify-content: space-between;
    align-items: start;
  }
  .events-modal-close {
    border: 0;
    background: #f2f4f7;
    color: #101828;
    border-radius: 999px;
    width: 42px;
    height: 42px;
    font-size: 18px;
    font-weight: 900;
    cursor: pointer;
  }
  .events-modal-title {
    margin: 0;
    color: #101828;
    font-size: 26px;
    line-height: 1.1;
  }
  .events-modal-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .events-modal-copy {
    color: #475467;
    font-size: 14px;
    line-height: 1.7;
    white-space: pre-wrap;
  }
  @media (max-width: 1100px) {
    .events-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 760px) {
    .events-page { padding: 22px 18px 100px; }
    .events-grid { grid-template-columns: 1fr; }
    .events-modal-media { height: 220px; }
    .events-modal-body { padding: 18px; }
  }
</style>
HTML;

ob_start();
?>
<div class="events-page">
  <section class="events-hero">
    <div class="events-eyebrow">Available Events</div>
    <h1 class="events-title">Plan your visit around what is happening on campus.</h1>
    <p class="events-copy">
      Browse published TNTS events that are upcoming or already in progress. Open the map when a venue is linked, and use directions only when the event resolves to a route-ready building or room.
    </p>
  </section>

  <div class="events-filter-bar" id="eventsFilters">
    <button class="events-filter is-active" type="button" data-filter="all">All Available</button>
    <button class="events-filter" type="button" data-filter="today">Today</button>
    <button class="events-filter" type="button" data-filter="ongoing">Ongoing</button>
    <button class="events-filter" type="button" data-filter="upcoming">Upcoming</button>
    <button class="events-filter" type="button" data-filter="map_ready">Map Ready</button>
  </div>

  <section class="events-grid" id="eventsGrid">
    <?php foreach ($events as $event): ?>
      <?php
        $dateLabel = events_format_date_label([
          "start_date" => $event["startDate"],
          "end_date" => $event["endDate"],
        ]);
        $descPreview = $event["description"] !== "" ? events_trimmed($event["description"], 180) : "Event details will appear here once provided.";
        $modalPayload = [
          "title" => $event["title"],
          "description" => $event["description"] !== "" ? $event["description"] : "No additional event details were provided.",
          "dateLabel" => $dateLabel,
          "location" => $event["locationLabel"] !== "" ? $event["locationLabel"] : "Location to be announced",
          "healthMessage" => $event["healthMessage"],
          "bannerUrl" => $event["bannerUrl"],
          "canMap" => $event["canMap"],
          "canRoute" => $event["canRoute"],
          "mapHref" => $event["mapHref"],
          "routeHref" => $event["routeHref"],
        ];
      ?>
      <article
        class="event-card"
        data-schedule="<?= htmlspecialchars($event["schedule"], ENT_QUOTES, "UTF-8") ?>"
        data-map-ready="<?= $event["canMap"] ? "1" : "0" ?>"
      >
        <div class="event-media">
          <?php if ($event["bannerUrl"] !== ""): ?>
            <img src="<?= htmlspecialchars($event["bannerUrl"], ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars($event["title"], ENT_QUOTES, "UTF-8") ?>" loading="lazy">
          <?php endif; ?>
          <div class="event-badge-row">
            <span class="event-pill schedule-<?= htmlspecialchars($event["schedule"], ENT_QUOTES, "UTF-8") ?>">
              <?= htmlspecialchars(ucwords(str_replace("_", " ", $event["schedule"])), ENT_QUOTES, "UTF-8") ?>
            </span>
            <span class="event-pill health-<?= htmlspecialchars($event["health"], ENT_QUOTES, "UTF-8") ?>">
              <?= htmlspecialchars(ucwords(str_replace("_", " ", $event["health"])), ENT_QUOTES, "UTF-8") ?>
            </span>
          </div>
        </div>
        <div class="event-body">
          <div class="event-date"><?= htmlspecialchars($dateLabel, ENT_QUOTES, "UTF-8") ?></div>
          <h2 class="event-name"><?= htmlspecialchars($event["title"], ENT_QUOTES, "UTF-8") ?></h2>
          <div class="event-location"><?= htmlspecialchars($event["locationLabel"] !== "" ? $event["locationLabel"] : "Location to be announced", ENT_QUOTES, "UTF-8") ?></div>
          <p class="event-description"><?= htmlspecialchars($descPreview, ENT_QUOTES, "UTF-8") ?></p>
          <div class="event-capability"><?= htmlspecialchars($event["healthMessage"] !== "" ? $event["healthMessage"] : "This event does not have a map action.", ENT_QUOTES, "UTF-8") ?></div>
          <div class="event-actions">
            <button class="event-btn" type="button" data-event='<?= htmlspecialchars(json_encode($modalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>Details</button>
            <?php if ($event["canMap"]): ?>
              <a class="event-btn" href="<?= htmlspecialchars($event["mapHref"], ENT_QUOTES, "UTF-8") ?>">Open Map</a>
            <?php endif; ?>
            <?php if ($event["canRoute"]): ?>
              <a class="event-btn primary" href="<?= htmlspecialchars($event["routeHref"], ENT_QUOTES, "UTF-8") ?>">Get Directions</a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <div class="events-empty<?= !$events ? " is-visible" : "" ?>" id="eventsEmpty">
    No published upcoming or ongoing events are available right now.
  </div>
</div>

<div class="events-modal" id="eventsModal" aria-hidden="true">
  <div class="events-modal-card" role="dialog" aria-modal="true" aria-labelledby="eventsModalTitle">
    <div class="events-modal-media" id="eventsModalMedia"></div>
    <div class="events-modal-body">
      <div class="events-modal-head">
        <div>
          <div class="events-modal-meta" id="eventsModalMeta"></div>
          <h2 class="events-modal-title" id="eventsModalTitle"></h2>
        </div>
        <button class="events-modal-close" id="eventsModalClose" type="button" aria-label="Close event details">X</button>
      </div>
      <div class="event-location" id="eventsModalLocation"></div>
      <div class="events-modal-copy" id="eventsModalCopy"></div>
      <div class="event-capability" id="eventsModalCapability"></div>
      <div class="event-actions" id="eventsModalActions"></div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

$extraScripts = <<<HTML
<script>
  (function() {
    const filterBar = document.getElementById("eventsFilters");
    const buttons = Array.from(filterBar ? filterBar.querySelectorAll("[data-filter]") : []);
    const cards = Array.from(document.querySelectorAll(".event-card"));
    const empty = document.getElementById("eventsEmpty");
    const modal = document.getElementById("eventsModal");
    const modalClose = document.getElementById("eventsModalClose");
    const modalTitle = document.getElementById("eventsModalTitle");
    const modalMeta = document.getElementById("eventsModalMeta");
    const modalLocation = document.getElementById("eventsModalLocation");
    const modalCopy = document.getElementById("eventsModalCopy");
    const modalCapability = document.getElementById("eventsModalCapability");
    const modalActions = document.getElementById("eventsModalActions");
    const modalMedia = document.getElementById("eventsModalMedia");

    function renderEmptyState() {
      const visible = cards.some((card) => !card.hidden);
      empty.classList.toggle("is-visible", !visible);
    }

    function applyFilter(filter) {
      cards.forEach((card) => {
        const schedule = card.getAttribute("data-schedule");
        const mapReady = card.getAttribute("data-map-ready") === "1";
        const show = filter === "all"
          || (filter === "map_ready" && mapReady)
          || schedule === filter;
        card.hidden = !show;
      });
      renderEmptyState();
    }

    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        buttons.forEach((btn) => btn.classList.toggle("is-active", btn === button));
        applyFilter(button.getAttribute("data-filter") || "all");
      });
    });

    function closeModal() {
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      modalMedia.innerHTML = "";
      modalActions.innerHTML = "";
    }

    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-event]");
      if (!button) return;
      let payload = null;
      try {
        payload = JSON.parse(button.getAttribute("data-event") || "{}");
      } catch (_) {}
      if (!payload) return;

      modalMeta.innerHTML = '<span class="event-pill schedule-upcoming">' + payload.dateLabel + '</span>';
      modalTitle.textContent = payload.title || "Event";
      modalLocation.textContent = payload.location || "Location to be announced";
      modalCopy.textContent = payload.description || "";
      modalCapability.textContent = payload.healthMessage || "";
      modalMedia.innerHTML = payload.bannerUrl ? '<img src="' + payload.bannerUrl + '" alt="">' : "";

      modalActions.innerHTML = "";
      if (payload.canMap && payload.mapHref) {
        const link = document.createElement("a");
        link.className = "event-btn";
        link.href = payload.mapHref;
        link.textContent = "Open Map";
        modalActions.appendChild(link);
      }
      if (payload.canRoute && payload.routeHref) {
        const link = document.createElement("a");
        link.className = "event-btn primary";
        link.href = payload.routeHref;
        link.textContent = "Get Directions";
        modalActions.appendChild(link);
      }

      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");
    });

    modalClose?.addEventListener("click", closeModal);
    modal?.addEventListener("click", (event) => {
      if (event.target === modal) closeModal();
    });
    window.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeModal();
    });

    applyFilter("all");
  })();
</script>
HTML;

include __DIR__ . "/../ui/layout.php";
