<?php
$pageTitle = "Facilities";
$activePage = "facilities";

$ROOT = dirname(__DIR__);
require_once $ROOT . "/admin/inc/db.php";
require_once $ROOT . "/admin/inc/map_sync.php";
require_once $ROOT . "/admin/inc/events.php";

function facilities_page_has_column(mysqli $conn, string $table, string $column): bool {
  $safeTable = str_replace("`", "``", $table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function facilities_page_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function facilities_page_infer_category(string $name, string $description = ""): string {
  $haystack = mb_strtolower(trim($name . " " . $description), "UTF-8");
  foreach (["library", "reading", "science", "ict", "print", "assessment"] as $keyword) {
    if (strpos($haystack, $keyword) !== false) return "academic";
  }
  return "services";
}

function facilities_page_initials(string $value): string {
  $clean = trim(preg_replace('/\s+/u', ' ', $value));
  if ($clean === "") return "MAP";

  $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $clean);
  if ($compact !== null && $compact !== "") {
    return strtoupper((string)mb_substr($compact, 0, 3, "UTF-8"));
  }

  return strtoupper((string)mb_substr($clean, 0, 3, "UTF-8"));
}

function facilities_page_category_label(string $category): string {
  return $category === "academic" ? "Academic Support" : "Campus Service";
}

$state = map_sync_resolve_public_model($ROOT);
$currentModel = trim((string)($state["modelFile"] ?? ""));
$facilities = [];
$publicRouteKeys = [];

try {
  $publicRouteKeys = events_public_route_keys($ROOT);
} catch (Throwable $_) {
  $publicRouteKeys = [];
}

try {
  $hasSource = facilities_page_has_column($conn, "facilities", "source_model_file");
  $hasPresent = facilities_page_has_column($conn, "facilities", "is_present_in_latest");
  $hasObjectName = facilities_page_has_column($conn, "facilities", "model_object_name");

  $sql = "SELECT facility_id, facility_name, "
    . ($hasObjectName ? "model_object_name" : "NULL AS model_object_name")
    . ", description, logo_path, location, contact_info"
    . ($hasSource ? ", source_model_file" : ", NULL AS source_model_file")
    . " FROM facilities WHERE facility_name IS NOT NULL AND facility_name <> ''";
  if ($hasPresent) {
    $sql .= " AND (is_present_in_latest = 1 OR is_present_in_latest IS NULL)";
  }

  if ($currentModel !== "" && $hasSource) {
    $stmt = $conn->prepare($sql . " AND source_model_file = ? ORDER BY facility_name ASC");
    if ($stmt) {
      $stmt->bind_param("s", $currentModel);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
          while ($row = $res->fetch_assoc()) $facilities[] = $row;
        }
      }
      $stmt->close();
    }
  } else {
    $res = $conn->query($sql . " ORDER BY facility_name ASC");
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) $facilities[] = $row;
    }
  }
} catch (Throwable $_) {
  $facilities = [];
}

$preparedFacilities = [];
$academicCount = 0;
$servicesCount = 0;
$contactCount = 0;

foreach ($facilities as $facility) {
  $facilityName = trim((string)($facility["facility_name"] ?? ""));
  if ($facilityName === "") continue;

  $description = trim((string)($facility["description"] ?? ""));
  $location = trim((string)($facility["location"] ?? ""));
  $contactInfo = trim((string)($facility["contact_info"] ?? ""));
  $imagePath = trim((string)($facility["logo_path"] ?? ""));
  $objectName = trim((string)($facility["model_object_name"] ?? ""));
  $facilityId = (int)($facility["facility_id"] ?? 0);
  $category = facilities_page_infer_category($facilityName, $description);

  $routeTarget = null;
  if ($facilityId > 0) {
    $facilityResolution = events_resolve_facility_target($conn, $facilityId, $currentModel);
    if (is_array($facilityResolution["resolvedTarget"] ?? null)) {
      $routeTarget = $facilityResolution["resolvedTarget"];
    }
  }
  if (!$routeTarget && $facilityName !== "") {
    $routeTarget = [
      "type" => "facility",
      "buildingUid" => "",
      "buildingName" => $facilityName,
      "objectName" => $objectName,
    ];
  }
  $canRoute = is_array($routeTarget) && events_has_route_for_target($publicRouteKeys, [
    "buildingUid" => trim((string)($routeTarget["buildingUid"] ?? "")),
    "buildingName" => trim((string)($routeTarget["buildingName"] ?? $facilityName)),
    "objectName" => trim((string)($routeTarget["objectName"] ?? $objectName)),
  ]);
  $routeMessage = $canRoute
    ? "Directions are available for this facility on the current public map."
    : "Open the map to inspect the destination. Directions are not published for this facility yet.";

  if ($category === "academic") $academicCount++;
  else $servicesCount++;
  if ($contactInfo !== "") $contactCount++;

  $preparedFacilities[] = [
    "name" => $facilityName,
    "description" => $description !== "" ? $description : "Mapped facility destination.",
    "location" => $location,
    "contactInfo" => $contactInfo,
    "imagePath" => $imagePath,
    "objectName" => $objectName,
    "category" => $category,
    "categoryLabel" => facilities_page_category_label($category),
    "initials" => facilities_page_initials($facilityName),
    "destination" => $facilityName,
    "canRoute" => $canRoute,
    "routeMessage" => $routeMessage,
  ];
}

$totalFacilities = count($preparedFacilities);

ob_start();
?>
<div class="public-page public-page--facilities">
  <div class="public-page__shell">
    <section class="public-hero">
      <div class="public-hero__eyebrow">Campus Facilities</div>
      <h1 class="public-hero__title">Find the service spaces, study hubs, and support offices available across TNTS.</h1>
      <p class="public-hero__copy">
        Facilities now follow the same polished card system used by announcements and events, so visitors can scan information faster and jump straight into the public map when they are ready.
      </p>

      <div class="public-stats">
        <article class="public-stat">
          <div class="public-stat__value"><?= $totalFacilities ?></div>
          <div class="public-stat__label">Visible Facilities</div>
          <div class="public-stat__hint">Currently available in the active public campus model.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $academicCount ?></div>
          <div class="public-stat__label">Academic Support</div>
          <div class="public-stat__hint">Libraries, reading spaces, ICT areas, and learning-focused destinations.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $servicesCount ?></div>
          <div class="public-stat__label">Campus Services</div>
          <div class="public-stat__hint">Operational offices and visitor-facing support points around the campus.</div>
        </article>
        <article class="public-stat">
          <div class="public-stat__value"><?= $contactCount ?></div>
          <div class="public-stat__label">With Contact Info</div>
          <div class="public-stat__hint">Cards that already include a direct phone number, email, or office contact note.</div>
        </article>
      </div>
    </section>

    <?php if (!$preparedFacilities): ?>
      <section class="public-empty">
        <h2 class="public-empty__title">No facilities are published yet.</h2>
        <p class="public-empty__copy">
          Facilities will appear here after the current public map model is imported and the facility metadata is saved from the admin side.
        </p>
      </section>
    <?php else: ?>
      <section class="public-toolbar">
        <div class="public-toolbar__content">
          <div class="public-toolbar__label">Browse by Type</div>
          <div class="public-toolbar__copy">Switch between learning spaces and support services without leaving the page.</div>
        </div>
        <div class="public-filters" role="tablist" aria-label="Facilities Filters">
          <button class="public-filter is-active" type="button" data-filter="all" aria-pressed="true">All Facilities</button>
          <button class="public-filter" type="button" data-filter="academic" aria-pressed="false">Academic Support</button>
          <button class="public-filter" type="button" data-filter="services" aria-pressed="false">Campus Services</button>
        </div>
      </section>

      <section class="public-grid" id="facGrid">
        <?php foreach ($preparedFacilities as $facility): ?>
          <article
            class="public-card facility-card"
            data-category="<?= facilities_page_escape($facility["category"]) ?>"
            data-name="<?= facilities_page_escape($facility["name"]) ?>"
          >
            <div class="public-card__media facility-card__media<?= $facility["imagePath"] === "" ? " is-fallback" : "" ?>">
              <div class="facility-card__overlay">
                <span class="public-pill public-pill--soft"><?= $facility["canRoute"] ? "Directions Ready" : "Map Ready" ?></span>
              </div>
              <?php if ($facility["imagePath"] !== ""): ?>
                <img
                  src="../<?= facilities_page_escape(ltrim($facility["imagePath"], "/")) ?>"
                  alt="<?= facilities_page_escape($facility["name"]) ?>"
                  loading="lazy"
                  onerror="this.remove(); this.parentElement.classList.add('is-fallback');"
                >
              <?php endif; ?>
              <div class="facility-card__fallback" aria-hidden="true"><?= facilities_page_escape($facility["initials"]) ?></div>
            </div>

            <div class="public-card__meta">
              <span class="public-pill public-pill--brand"><?= facilities_page_escape($facility["categoryLabel"]) ?></span>
              <?php if ($facility["contactInfo"] !== ""): ?>
                <span class="public-pill public-pill--soft">Has Contact</span>
              <?php endif; ?>
            </div>

            <h2 class="public-card__title"><?= facilities_page_escape($facility["name"]) ?></h2>
            <p class="public-card__copy public-card__copy--clamp-4"><?= facilities_page_escape($facility["description"]) ?></p>

            <div class="public-details">
              <?php if ($facility["location"] !== ""): ?>
                <div class="public-detail">
                  <div class="public-detail__label">Location</div>
                  <div class="public-detail__value"><?= facilities_page_escape($facility["location"]) ?></div>
                </div>
              <?php endif; ?>
              <?php if ($facility["contactInfo"] !== ""): ?>
                <div class="public-detail">
                  <div class="public-detail__label">Contact</div>
                  <div class="public-detail__value"><?= facilities_page_escape($facility["contactInfo"]) ?></div>
                </div>
              <?php endif; ?>
              <?php if ($facility["location"] === "" && $facility["contactInfo"] === ""): ?>
                <div class="public-note">Open the map to inspect the linked destination and its surrounding campus context.</div>
              <?php endif; ?>
              <div class="public-note"><?= facilities_page_escape($facility["routeMessage"]) ?></div>
            </div>

            <div class="public-actions">
              <?php if ($facility["canRoute"]): ?>
                <button
                  class="public-btn public-btn--primary"
                  type="button"
                  data-route="<?= facilities_page_escape($facility["destination"]) ?>"
                  data-object="<?= facilities_page_escape($facility["objectName"]) ?>"
                >
                  Get Directions
                </button>
                <button
                  class="public-btn"
                  type="button"
                  data-open="<?= facilities_page_escape($facility["destination"]) ?>"
                  data-object="<?= facilities_page_escape($facility["objectName"]) ?>"
                >
                  Open on Map
                </button>
              <?php else: ?>
                <button
                  class="public-btn public-btn--primary"
                  type="button"
                  data-open="<?= facilities_page_escape($facility["destination"]) ?>"
                  data-object="<?= facilities_page_escape($facility["objectName"]) ?>"
                >
                  Open on Map
                </button>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="public-empty public-empty--inline" id="facFilteredEmpty" aria-live="polite">
        <h2 class="public-empty__title">No facilities match that filter.</h2>
        <p class="public-empty__copy">Try switching back to all facilities or choose the other category to continue browsing.</p>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
  .facility-card {
    align-content: start;
  }

  .facility-card__media {
    min-height: 220px;
    background:
      radial-gradient(circle at top left, rgba(122, 0, 0, 0.18), transparent 44%),
      linear-gradient(135deg, #f6ebe6 0%, #f8fbff 100%);
  }

  .facility-card__overlay {
    position: absolute;
    top: 14px;
    right: 14px;
    z-index: 2;
  }

  .facility-card__fallback {
    display: none;
    align-items: center;
    justify-content: center;
    width: 96px;
    height: 96px;
    border-radius: 999px;
    border: 10px solid rgba(122, 0, 0, 0.88);
    background: rgba(255, 255, 255, 0.86);
    box-shadow: 0 20px 44px rgba(122, 0, 0, 0.16);
    color: #7a0000;
    font-size: 24px;
    font-weight: 900;
    letter-spacing: 0.08em;
  }

  .facility-card__media.is-fallback {
    display: grid;
    place-items: center;
  }

  .facility-card__media.is-fallback::after {
    content: "";
    position: absolute;
    inset: 20px;
    border-radius: 22px;
    border: 1px dashed rgba(122, 0, 0, 0.2);
  }

  .facility-card__media.is-fallback .facility-card__fallback {
    display: inline-flex;
    z-index: 1;
  }
</style>
HTML;

$extraScripts = <<<HTML
<script>
  (function () {
    const filters = Array.from(document.querySelectorAll(".public-filter[data-filter]"));
    const cards = Array.from(document.querySelectorAll(".facility-card"));
    const filteredEmpty = document.getElementById("facFilteredEmpty");

    function renderFilteredEmpty() {
      if (!filteredEmpty) return;
      const hasVisibleCards = cards.some((card) => !card.hidden);
      filteredEmpty.classList.toggle("is-visible", !hasVisibleCards);
    }

    function applyFilter(filter) {
      cards.forEach((card) => {
        const category = card.getAttribute("data-category");
        card.hidden = !(filter === "all" || category === filter);
      });
      renderFilteredEmpty();
    }

    filters.forEach((button) => {
      button.addEventListener("click", () => {
        const filter = button.getAttribute("data-filter") || "all";
        filters.forEach((item) => {
          const active = item === button;
          item.classList.toggle("is-active", active);
          item.setAttribute("aria-pressed", active ? "true" : "false");
        });
        applyFilter(filter);
      });
    });

    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-open], [data-route]");
      if (!button) return;
      const destination = String(button.getAttribute("data-route") || button.getAttribute("data-open") || "").trim();
      if (!destination) return;
      const autoRoute = button.hasAttribute("data-route");
      sessionStorage.setItem("tnts:jumpToDestination", destination);
      const suffix = autoRoute ? "&autoroute=1" : "";
      window.location.href = "../pages/map.php?destination=" + encodeURIComponent(destination) + suffix;
    });

    if (cards.length > 0) {
      applyFilter("all");
    }
  })();
</script>
HTML;

include __DIR__ . "/../ui/layout.php";
