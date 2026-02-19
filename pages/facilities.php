<?php
// pages/facilities.php
$pageTitle = "Facilities";
$activePage = "facilities";

ob_start();
?>

<div class="fac-wrap">
  <div class="fac-header">
    <h1 class="fac-title">FACILITIES</h1>

    <div class="fac-filters" role="tablist" aria-label="Facilities Filters">
      <button class="fac-pill active" type="button" data-filter="all" aria-pressed="true">
        <span class="fac-icon">▦</span> ALL
      </button>
      <button class="fac-pill" type="button" data-filter="academic" aria-pressed="false">
        <span class="fac-icon">☆</span> ACADEMIC
      </button>
      <button class="fac-pill" type="button" data-filter="services" aria-pressed="false">
        <span class="fac-icon">⚙</span> SERVICES
      </button>
    </div>
  </div>

  <section class="fac-grid" id="fac-grid">
    <!-- Card 1 -->
    <article class="fac-card" data-category="academic" data-name="ANTERIO SORIANO BLDG">
      <div class="fac-img">
        <img src="../assets/facilities/anterio.jpg" alt="Anterio Soriano Bldg" onerror="this.style.display='none'; this.parentElement.classList.add('img-fallback');">
        <div class="fac-fallback-mark" aria-hidden="true">TNTS</div>
      </div>
      <div class="fac-body">
        <div class="fac-name">ANTERIO SORIANO BLDG</div>
        <div class="fac-desc">Main Administrative and Faculty Offices</div>
        <button class="fac-btn" type="button" data-open="ANTERIO_SORIANO">VIEW DETAILS</button>
      </div>
    </article>

    <!-- Card 2 -->
    <article class="fac-card" data-category="services" data-name="EPIMASCO VELASCO BLDG">
      <div class="fac-img">
        <img src="../assets/facilities/epimasco.jpg" alt="Epimasco Velasco Bldg" onerror="this.style.display='none'; this.parentElement.classList.add('img-fallback');">
        <div class="fac-fallback-mark" aria-hidden="true">TNTS</div>
      </div>
      <div class="fac-body">
        <div class="fac-name">EPIMASCO VELASCO BLDG</div>
        <div class="fac-desc">Student Support and Counseling Service</div>
        <button class="fac-btn" type="button" data-open="EPIMASCO_VELASCO">VIEW DETAILS</button>
      </div>
    </article>

    <!-- Card 3 -->
    <article class="fac-card" data-category="academic" data-name="LIBRARY">
      <div class="fac-img">
        <img src="../assets/facilities/library.jpg" alt="Library" onerror="this.style.display='none'; this.parentElement.classList.add('img-fallback');">
        <div class="fac-fallback-mark" aria-hidden="true">TNTS</div>
      </div>
      <div class="fac-body">
        <div class="fac-name">LIBRARY</div>
        <div class="fac-desc">For Books, Research and Study</div>
        <button class="fac-btn" type="button" data-open="LIBRARY">VIEW DETAILS</button>
      </div>
    </article>

    <!-- Card 4 -->
    <article class="fac-card" data-category="services" data-name="CLINIC">
      <div class="fac-img">
        <img src="../assets/facilities/clinic.jpg" alt="Clinic" onerror="this.style.display='none'; this.parentElement.classList.add('img-fallback');">
        <div class="fac-fallback-mark" aria-hidden="true">TNTS</div>
      </div>
      <div class="fac-body">
        <div class="fac-name">CLINIC</div>
        <div class="fac-desc">Medical Checkups and First Aid</div>
        <button class="fac-btn" type="button" data-open="CLINIC">VIEW DETAILS</button>
      </div>
    </article>

    <!-- Add more cards following the same pattern -->
  </section>
</div>

<?php
$content = ob_get_clean();

// Inline styles + small JS (keeps everything self-contained; no extra files needed)
$extraHead = <<<HTML
<style>
  .fac-wrap{
    width: 100%;
    height: 100%;
    padding: 26px 34px;
    box-sizing: border-box;
    overflow: auto;
  }

  .fac-header{
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 18px;
  }

  .fac-title{
    margin: 0;
    font-size: 22px;
    letter-spacing: .5px;
    font-weight: 800;
    color: #111;
  }

  .fac-filters{
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .fac-pill{
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border: 1px solid #cfcfcf;
    border-radius: 8px;
    background: #fff;
    color: #111;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    user-select: none;
  }

  .fac-pill .fac-icon{
    width: 18px;
    display: inline-flex;
    justify-content: center;
    font-weight: 900;
  }

  .fac-pill.active{
    background: #7a0000;
    border-color: #7a0000;
    color: #fff;
  }

  .fac-grid{
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 26px;
    padding-bottom: 24px;
  }

  .fac-card{
    border: 1px solid #d7d7d7;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 2px 0 rgba(0,0,0,0.03);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .fac-img{
    height: 150px;
    background: #f4f4f4;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
  }

  .fac-img img{
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .fac-img.img-fallback{
    background: #fff;
  }

  .fac-fallback-mark{
    width: 88px;
    height: 88px;
    border-radius: 999px;
    border: 10px solid #c00000;
    color: #c00000;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    letter-spacing: .5px;
  }

  .fac-body{
    padding: 12px 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    text-align: center;
  }

  .fac-name{
    font-weight: 900;
    font-size: 11px;
    letter-spacing: .4px;
    color: #111;
  }

  .fac-desc{
    font-size: 10px;
    color: #444;
    line-height: 1.25;
    min-height: 26px;
  }

  .fac-btn{
    margin-top: 4px;
    align-self: center;
    width: 120px;
    height: 28px;
    border: 0;
    border-radius: 7px;
    background: #7a0000;
    color: #fff;
    font-weight: 900;
    font-size: 10px;
    cursor: pointer;
  }

  .fac-card[hidden]{ display: none !important; }

  @media (max-width: 1100px){
    .fac-grid{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 820px){
    .fac-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
</style>
HTML;

$extraScripts = <<<HTML
<script>
  (function(){
    const pills = document.querySelectorAll(".fac-pill");
    const cards = document.querySelectorAll(".fac-card");

    function setActive(btn){
      pills.forEach(p => {
        const on = p === btn;
        p.classList.toggle("active", on);
        p.setAttribute("aria-pressed", on ? "true" : "false");
      });
    }

    function applyFilter(filter){
      cards.forEach(card => {
        const cat = card.getAttribute("data-category");
        card.hidden = !(filter === "all" || cat === filter);
      });
    }

    pills.forEach(btn => {
      btn.addEventListener("click", () => {
        const filter = btn.getAttribute("data-filter");
        setActive(btn);
        applyFilter(filter);
      });
    });

    // "VIEW DETAILS" behavior: jump to map and auto-search/highlight
    document.addEventListener("click", (e) => {
      const b = e.target.closest("[data-open]");
      if (!b) return;

      const key = b.getAttribute("data-open"); // e.g. "ALUMNI" or "ANTERIO_SORIANO"
      // Store the requested building key for map page
      sessionStorage.setItem("tnts:jumpToBuilding", key);
      window.location.href = "../pages/map.php";
    });
  })();
</script>
HTML;

include __DIR__ . "/../ui/layout.php";
