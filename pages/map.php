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
        <button id="close-btn" title="Close">×</button>
      </div>

      <div class="card-body">
        <div id="card-rooms-wrap" class="card-rooms hidden">
          <div class="card-rooms-title">Rooms</div>
          <div id="card-rooms-status" class="card-rooms-status">Loading rooms...</div>
          <div id="card-rooms-list" class="card-rooms-list"></div>
        </div>
        <p id="card-info">Select “Get Directions” to show the route.</p>

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
        <button id="directions-close-btn" type="button" title="Close directions">×</button>
      </div>
      <div id="directions-summary" class="directions-panel-summary">Select a destination to view directions.</div>
      <div id="directions-status" class="directions-panel-status hidden"></div>
      <div id="directions-steps" class="directions-panel-steps"></div>
    </div>
  </div>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
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
