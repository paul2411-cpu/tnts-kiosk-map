<?php
// pages/map.php
$pageTitle = "TNTS Map";
$activePage = "map";

ob_start();
?>
  <div id="map-stage">
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
