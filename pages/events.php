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
    "startTime" => trim((string)($row["start_time"] ?? "")),
    "endDate" => trim((string)($row["end_date"] ?? "")),
    "endTime" => trim((string)($row["end_time"] ?? "")),
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
  $aStamp = trim(($a["startDate"] ?: "9999-12-31") . " " . ($a["startTime"] ?: "23:59"));
  $bStamp = trim(($b["startDate"] ?: "9999-12-31") . " " . ($b["startTime"] ?: "23:59"));
  return strcmp($aStamp, $bStamp);
});

$todayCount = 0;
$ongoingCount = 0;
$upcomingCount = 0;
$mapReadyCount = 0;
$routeReadyCount = 0;

foreach ($events as $event) {
  if ($event["schedule"] === "today") $todayCount++;
  if ($event["schedule"] === "ongoing") $ongoingCount++;
  if ($event["schedule"] === "upcoming") $upcomingCount++;
  if ($event["canMap"]) $mapReadyCount++;
  if ($event["canRoute"]) $routeReadyCount++;
}

$liveCount = $todayCount + $ongoingCount;
$totalEvents = count($events);

$extraHead = <<<HTML
<style>
  .event-card {
    padding: 0;
    gap: 0;
    overflow: hidden;
  }

  .event-card__media {
    min-height: 220px;
    border: 0;
    border-radius: 0;
    background: linear-gradient(135deg, #2b2f38, #7a0000);
  }

  .event-card__media::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.35));
  }

  .event-card__badges {
    position: absolute;
    top: 14px;
    left: 14px;
    right: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    z-index: 1;
  }

  .event-pill.schedule-upcoming,
  .event-pill.schedule-today,
  .event-pill.schedule-ongoing {
    background: rgba(255, 255, 255, 0.96);
    color: #7a0000;
  }

  .event-pill.health-valid {
    background: #ecfdf3;
    color: #067647;
  }

  .event-pill.health-limited {
    background: #fffaeb;
    color: #b54708;
  }

  .event-pill.health-needs_review,
  .event-pill.health-broken {
    background: #fef3f2;
    color: #b42318;
  }

  .event-card__body {
    display: grid;
    gap: 14px;
    padding: 20px;
    min-height: 100%;
  }

  .event-card__date {
    color: #7a0000;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .event-card__location {
    color: #475467;
    font-size: 13px;
    line-height: 1.55;
    font-weight: 700;
  }

  .event-card__capability,
  .events-modal-capability {
    color: #667085;
    font-size: 12px;
    line-height: 1.6;
  }

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

  .events-modal.is-open {
    display: flex;
  }

  .events-modal-card {
    width: min(780px, 96vw);
    max-height: 88vh;
    overflow: auto;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 30px 70px rgba(15, 23, 42, 0.28);
  }

  .events-modal-media {
    min-height: 280px;
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
    gap: 16px;
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
    font-size: 28px;
    line-height: 1.08;
  }

  .events-modal-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
  }

  .events-modal-copy {
    color: #475467;
    font-size: 14px;
    line-height: 1.75;
    white-space: pre-wrap;
  }

  @media (max-width: 760px) {
    .events-modal-media {
      min-height: 220px;
    }

    .events-modal-body {
      padding: 18px;
    }
  }
</style>
HTML;

ob_start();
?>
<div class="public-page public-page--events">
  <div class="public-page__shell">
    <section class="public-hero">
      <div class="public-hero__eyebrow">Available Events</div>
      <h1 class="public-hero__title">Plan your visit around what is happening on campus.</h1>
      <p class="public-hero__copy">
        Browse published TNTS events that are upcoming or already in progress. Open the map when a venue is linked, and use directions when an event resolves to a route-ready building, room, or anchored area.
      </p>

      <div class="public-stats">
        <article class="public-stat">
          <div class="public-stat__value"><?= $totalEvents ?></div>
          <div class="public-stat__label">Published Events</div>
          <div class="public-stat__hint">Only public-facing items that are available to kiosk visitors right now.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $liveCount ?></div>
          <div class="public-stat__label">Happening Now</div>
          <div class="public-stat__hint">Events scheduled for today or already in progress.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $mapReadyCount ?></div>
          <div class="public-stat__label">Map Ready</div>
          <div class="public-stat__hint">Events that can open a focused destination or highlighted area on the map.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $routeReadyCount ?></div>
          <div class="public-stat__label">Route Ready</div>
          <div class="public-stat__hint">Events with a linked destination that can generate directions immediately.</div>
        </article>
      </div>
    </section>

    <?php if (!$events): ?>
      <section class="public-empty">
        <h2 class="public-empty__title">No published events are available right now.</h2>
        <p class="public-empty__copy">Check back later for upcoming campus activities, public programs, and special events.</p>
      </section>
    <?php else: ?>
      <section class="public-toolbar">
        <div class="public-toolbar__content">
          <div class="public-toolbar__label">Refine the Feed</div>
          <div class="public-toolbar__copy">Filter by schedule or jump straight to events that already support map actions.</div>
        </div>
        <div class="public-filters" id="eventsFilters">
          <button class="public-filter is-active" type="button" data-filter="all">All Available</button>
          <button class="public-filter" type="button" data-filter="today">Today</button>
          <button class="public-filter" type="button" data-filter="ongoing">Ongoing</button>
          <button class="public-filter" type="button" data-filter="upcoming">Upcoming</button>
          <button class="public-filter" type="button" data-filter="map_ready">Map Ready</button>
        </div>
      </section>

      <section class="public-grid" id="eventsGrid">
        <?php foreach ($events as $event): ?>
          <?php
            $dateLabel = events_format_date_label([
              "start_date" => $event["startDate"],
              "start_time" => $event["startTime"],
              "end_date" => $event["endDate"],
              "end_time" => $event["endTime"],
            ]);
            $descPreview = $event["description"] !== "" ? events_trimmed($event["description"], 180) : "Event details will appear here once provided.";
            $scheduleLabel = ucwords(str_replace("_", " ", $event["schedule"]));
            $healthLabel = ucwords(str_replace("_", " ", $event["health"]));
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
              "schedule" => $event["schedule"],
              "scheduleLabel" => $scheduleLabel,
              "health" => $event["health"],
              "healthLabel" => $healthLabel,
            ];
          ?>
          <article
            class="public-card event-card"
            data-schedule="<?= htmlspecialchars($event["schedule"], ENT_QUOTES, "UTF-8") ?>"
            data-map-ready="<?= $event["canMap"] ? "1" : "0" ?>"
          >
            <div class="public-card__media event-card__media">
              <?php if ($event["bannerUrl"] !== ""): ?>
                <img src="<?= htmlspecialchars($event["bannerUrl"], ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars($event["title"], ENT_QUOTES, "UTF-8") ?>" loading="lazy">
              <?php endif; ?>
              <div class="event-card__badges">
                <span class="public-pill event-pill schedule-<?= htmlspecialchars($event["schedule"], ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars($scheduleLabel, ENT_QUOTES, "UTF-8") ?>
                </span>
                <span class="public-pill event-pill health-<?= htmlspecialchars($event["health"], ENT_QUOTES, "UTF-8") ?>">
                  <?= htmlspecialchars($healthLabel, ENT_QUOTES, "UTF-8") ?>
                </span>
              </div>
            </div>

            <div class="event-card__body">
              <div class="event-card__date"><?= htmlspecialchars($dateLabel, ENT_QUOTES, "UTF-8") ?></div>
              <h2 class="public-card__title"><?= htmlspecialchars($event["title"], ENT_QUOTES, "UTF-8") ?></h2>
              <div class="event-card__location"><?= htmlspecialchars($event["locationLabel"] !== "" ? $event["locationLabel"] : "Location to be announced", ENT_QUOTES, "UTF-8") ?></div>
              <p class="public-card__copy public-card__copy--clamp-4"><?= htmlspecialchars($descPreview, ENT_QUOTES, "UTF-8") ?></p>
              <div class="event-card__capability"><?= htmlspecialchars($event["healthMessage"] !== "" ? $event["healthMessage"] : "This event does not have a map action.", ENT_QUOTES, "UTF-8") ?></div>
              <div class="public-actions">
                <button class="public-btn public-btn--soft" type="button" data-event='<?= htmlspecialchars(json_encode($modalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>Details</button>
                <?php if ($event["canMap"]): ?>
                  <a class="public-btn" href="<?= htmlspecialchars($event["mapHref"], ENT_QUOTES, "UTF-8") ?>">Open Map</a>
                <?php endif; ?>
                <?php if ($event["canRoute"]): ?>
                  <a class="public-btn public-btn--primary" href="<?= htmlspecialchars($event["routeHref"], ENT_QUOTES, "UTF-8") ?>">Get Directions</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="public-empty public-empty--inline" id="eventsEmpty" aria-live="polite">
        <h2 class="public-empty__title">No events match this filter.</h2>
        <p class="public-empty__copy">Try another schedule view or switch back to all available events.</p>
      </section>
    <?php endif; ?>
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
      <div class="event-card__location" id="eventsModalLocation"></div>
      <div class="events-modal-copy" id="eventsModalCopy"></div>
      <div class="events-modal-capability" id="eventsModalCapability"></div>
      <div class="public-actions" id="eventsModalActions"></div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

$extraScripts = <<<HTML
<script>
  (function () {
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

    function safeText(value) {
      return String(value || "").trim();
    }

    function safeToken(value, fallback) {
      const token = String(value || "").toLowerCase().replace(/[^a-z_]/g, "");
      return token || fallback;
    }

    function appendPill(text, className) {
      if (!modalMeta || !text) return;
      const pill = document.createElement("span");
      pill.className = className;
      pill.textContent = text;
      modalMeta.appendChild(pill);
    }

    function renderEmptyState() {
      if (!empty) return;
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

    function closeModal() {
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      modalMedia.replaceChildren();
      modalMeta.replaceChildren();
      modalActions.replaceChildren();
    }

    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        buttons.forEach((btn) => btn.classList.toggle("is-active", btn === button));
        applyFilter(button.getAttribute("data-filter") || "all");
      });
    });

    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-event]");
      if (!button) return;

      let payload = null;
      try {
        payload = JSON.parse(button.getAttribute("data-event") || "{}");
      } catch (_) {}
      if (!payload || !modal) return;

      modalMeta.replaceChildren();
      appendPill(safeText(payload.scheduleLabel), "public-pill event-pill schedule-" + safeToken(payload.schedule, "upcoming"));
      appendPill(safeText(payload.dateLabel), "public-pill public-pill--soft");
      appendPill(safeText(payload.healthLabel), "public-pill event-pill health-" + safeToken(payload.health, "limited"));

      modalTitle.textContent = safeText(payload.title) || "Event";
      modalLocation.textContent = safeText(payload.location) || "Location to be announced";
      modalCopy.textContent = safeText(payload.description);
      modalCapability.textContent = safeText(payload.healthMessage) || "This event does not have a map action.";

      modalMedia.replaceChildren();
      if (safeText(payload.bannerUrl)) {
        const img = document.createElement("img");
        img.src = safeText(payload.bannerUrl);
        img.alt = safeText(payload.title) || "Event";
        modalMedia.appendChild(img);
      }

      modalActions.replaceChildren();
      if (payload.canMap && safeText(payload.mapHref)) {
        const mapLink = document.createElement("a");
        mapLink.className = "public-btn";
        mapLink.href = safeText(payload.mapHref);
        mapLink.textContent = "Open Map";
        modalActions.appendChild(mapLink);
      }
      if (payload.canRoute && safeText(payload.routeHref)) {
        const routeLink = document.createElement("a");
        routeLink.className = "public-btn public-btn--primary";
        routeLink.href = safeText(payload.routeHref);
        routeLink.textContent = "Get Directions";
        modalActions.appendChild(routeLink);
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

    if (cards.length > 0) {
      applyFilter("all");
    }
  })();
</script>
HTML;

include __DIR__ . "/../ui/layout.php";
