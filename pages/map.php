<?php
// pages/map.php
$pageTitle = "TNTS Map";
$activePage = "map";

ob_start();
?>
  <div id="map-stage">
    <div id="map-view-controls" aria-label="Map view controls">
      <div class="map-view-controls__header">
        <div class="map-view-controls__eyebrow">View Controls</div>
        <div class="map-view-controls__title">Explore Map</div>
      </div>

      <div class="map-view-toggle" role="group" aria-label="Map view mode">
        <button id="map-view-3d-btn" class="map-view-toggle-btn is-active" type="button" aria-pressed="true">3D</button>
        <button id="map-view-2d-btn" class="map-view-toggle-btn" type="button" aria-pressed="false">2D</button>
      </div>

      <label class="map-zoom-control" for="map-zoom-slider">
        <div class="map-zoom-control__topline">
          <span class="map-zoom-label">Zoom</span>
          <span id="map-zoom-value" class="map-zoom-value">35%</span>
        </div>
        <input id="map-zoom-slider" class="map-zoom-slider" type="range" min="0" max="100" step="1" value="35" />
      </label>
    </div>

    <div id="info-card" class="hidden">
      <div class="card-header">
        <h2 id="card-title">Building</h2>
        <button id="close-btn" title="Close">&times;</button>
      </div>

      <div class="card-body">
        <div id="card-rooms-wrap" class="card-rooms hidden">
          <div class="card-rooms-title">Rooms</div>
          <div id="card-rooms-status" class="card-rooms-status">Loading rooms...</div>
          <div id="card-rooms-list" class="card-rooms-list"></div>
        </div>
        <p id="card-info">Select "Get Directions" to show the route.</p>

        <div class="card-actions">
          <button id="directions-btn">Get Directions</button>
          <button id="clear-route-btn" disabled>Cancel Route</button>
        </div>
      </div>
    </div>

    <div id="directions-panel" class="hidden">
      <div class="directions-panel-header">
        <div>
          <div class="directions-panel-eyebrow">Step-by-step guide</div>
          <h3 id="directions-title">Directions</h3>
        </div>
        <button id="directions-close-btn" type="button" title="Close directions">&times;</button>
      </div>
      <div id="directions-summary" class="directions-panel-summary">Select a destination to view directions.</div>
      <div id="directions-status" class="directions-panel-status hidden"></div>
      <div id="directions-steps" class="directions-panel-steps"></div>
    </div>

    <div id="event-focus-card" class="hidden" aria-live="polite">
      <div class="event-focus-card__header">
        <div>
          <div class="event-focus-card__eyebrow">Event On Map</div>
          <div class="event-focus-card__meta">
            <span id="event-focus-schedule" class="event-focus-card__pill hidden"></span>
            <span id="event-focus-health" class="event-focus-card__pill hidden"></span>
          </div>
        </div>
        <button id="event-focus-close-btn" type="button" title="Close event panel">X</button>
      </div>
      <div class="event-focus-card__body">
        <h2 id="event-focus-title">Event</h2>
        <div id="event-focus-date" class="event-focus-card__date"></div>
        <div id="event-focus-location" class="event-focus-card__location"></div>
        <p id="event-focus-copy" class="event-focus-card__copy"></p>
        <div id="event-focus-message" class="event-focus-card__message"></div>
        <div class="event-focus-card__actions">
          <button id="event-focus-map-btn" type="button">Focus Event</button>
          <button id="event-focus-route-btn" type="button">Get Directions</button>
          <a id="event-focus-back-link" href="events.php">Back to Events</a>
        </div>
      </div>
    </div>
  </div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
  #event-focus-card {
    position: absolute;
    left: 20px;
    bottom: 20px;
    width: min(380px, calc(100% - 40px));
    max-height: calc(100% - 40px);
    overflow: auto;
    padding: 16px;
    border-radius: 16px;
    border: 1px solid rgba(203, 213, 225, 0.92);
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.18);
    z-index: 52;
    user-select: none;
  }
  .event-focus-card__header {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }
  .event-focus-card__eyebrow {
    color: #667085;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
  }
  .event-focus-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
  }
  .event-focus-card__pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 26px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    background: #f2f4f7;
    color: #344054;
  }
  .event-focus-card__pill.health-valid {
    background: #ecfdf3;
    color: #067647;
  }
  .event-focus-card__pill.health-limited {
    background: #fffaeb;
    color: #b54708;
  }
  .event-focus-card__pill.health-needs_review,
  .event-focus-card__pill.health-broken {
    background: #fef3f2;
    color: #b42318;
  }
  .event-focus-card__pill.schedule-today,
  .event-focus-card__pill.schedule-ongoing,
  .event-focus-card__pill.schedule-upcoming {
    background: #eff8ff;
    color: #175cd3;
  }
  .event-focus-card__body {
    display: grid;
    gap: 10px;
  }
  #event-focus-title {
    margin: 0;
    color: #101828;
    font-size: 24px;
    line-height: 1.08;
    font-weight: 800;
  }
  .event-focus-card__date {
    color: #7a0000;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .event-focus-card__location {
    color: #111827;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.45;
  }
  .event-focus-card__copy {
    margin: 0;
    color: #475467;
    font-size: 14px;
    line-height: 1.65;
    white-space: pre-wrap;
  }
  .event-focus-card__message {
    padding: 10px 12px;
    border-radius: 12px;
    background: #f8fafc;
    color: #344054;
    font-size: 13px;
    line-height: 1.55;
  }
  .event-focus-card__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }
  #event-focus-close-btn,
  #event-focus-map-btn,
  #event-focus-route-btn,
  #event-focus-back-link {
    border: 0;
    border-radius: 10px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 800;
  }
  #event-focus-close-btn {
    width: 34px;
    height: 34px;
    background: #f4f4f5;
    color: #4b5563;
  }
  #event-focus-map-btn,
  #event-focus-route-btn,
  #event-focus-back-link {
    min-height: 42px;
    padding: 0 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  #event-focus-map-btn {
    background: #f4f4f5;
    color: #111827;
  }
  #event-focus-route-btn {
    background: #1f7aff;
    color: #fff;
  }
  #event-focus-route-btn:disabled {
    background: #a8c7ff;
    cursor: not-allowed;
  }
  #event-focus-back-link {
    background: #111827;
    color: #fff;
  }
  @media (max-width: 1100px) {
    #event-focus-card {
      bottom: 20px;
      width: 340px;
    }
  }
  @media (max-width: 640px) {
    #event-focus-card {
      left: 12px;
      right: 12px;
      bottom: 12px;
      width: auto;
      max-height: 38vh;
      padding: 14px;
    }
  }
</style>
<script type="importmap">
{
  "imports": {
    "three": "../three.js-master/build/three.module.js",
    "three/addons/": "../three.js-master/examples/jsm/"
  }
}
</script>
HTML;

$extraScripts = <<<HTML
<script type="module" src="../js/map3d.js"></script>
HTML;

include __DIR__ . "/../ui/layout.php";
