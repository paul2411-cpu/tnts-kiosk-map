<?php
// pages/events.php
$pageTitle = "Events";
$activePage = "events";

/**
 * Put your real events here.
 * IMPORTANT: Use "date" in YYYY-MM-DD format so the calendar filter works.
 */
$events = [
  ["date"=>"2026-01-09", "title"=>"ASTRO CAMP", "desc"=>"Explore astronomy and space science through hands-on missions.", "img"=>"../assets/events/astro.jpg"],
  ["date"=>"2026-01-12", "title"=>"SCIENCE FAIR", "desc"=>"Join the inter-class science quiz, at the Science Building…", "img"=>"../assets/events/science.jpg"],
  ["date"=>"2026-01-18", "title"=>"HIPHOP", "desc"=>"Students present and defend independent scientific experiments or engineering projects.", "img"=>"../assets/events/hiphop.jpg"],
  ["date"=>"2026-01-25", "title"=>"BATTLE OF THE BANDS", "desc"=>"Student music groups compete by performing songs for judges and an audience.", "img"=>"../assets/events/bands.jpg"],

  // Examples for other months
  ["date"=>"2026-02-05", "title"=>"SPORTS FEST", "desc"=>"Campus-wide sports events and intramurals.", "img"=>"../assets/events/sports.jpg"],
  ["date"=>"2026-03-14", "title"=>"JOB FAIR", "desc"=>"Meet partner companies and apply on-site.", "img"=>"../assets/events/jobfair.jpg"],
];

$extraHead = <<<HTML
<style>
  .page-pad{
    padding: 34px 46px;
    height: 100%;
    overflow: auto;
    box-sizing: border-box;
  }

  .page-title{
    font-size: 20px;
    letter-spacing: .6px;
    color: #222;
    margin: 0 0 14px;
    font-weight: 800;
  }

  /* Month + calendar trigger (Figma style) */
  .month-row{
    display:flex;
    align-items:center;
    gap: 14px;
    margin-bottom: 22px;
  }

  .month-trigger{
    display:inline-flex;
    align-items:center;
    gap: 10px;
    background:#800000;
    border-radius: 8px;
    padding: 8px 12px;
    box-shadow: 0 6px 18px rgba(0,0,0,.18);
    user-select:none;
    cursor: pointer;
    border: 0;
  }

  .month-trigger:active{ transform: translateY(1px); }

  .month-icon{
    width: 18px;
    height: 18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-weight: 900;
  }

  .month-label{
    color:#fff;
    font-weight: 900;
    letter-spacing:.4px;
    font-size: 13px;
    min-width: 38px;
    text-align: center;
  }

  .month-caret{
    color:#fff;
    font-weight: 900;
    font-size: 14px;
    margin-left: 2px;
  }

 /* Hidden but still clickable/focusable for fallback browsers */
.date-input{
  position: fixed;
  left: 0;
  top: 0;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: auto; /* IMPORTANT: must NOT be none */
  z-index: 999999;
}
  /* Grid */
  .events-grid{
    display:grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 26px;
    align-items: stretch;
    padding-bottom: 10px;
  }

  @media (max-width: 1100px){
    .events-grid{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 820px){
    .page-pad{ padding: 26px 18px; }
    .events-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 520px){
    .events-grid{ grid-template-columns: 1fr; }
  }

  /* Card */
  .event-card{
    background:#fff;
    border: 1.5px solid #d8d8d8;
    border-radius: 10px;
    overflow:hidden;
    box-shadow: 0 6px 18px rgba(0,0,0,.12);
    min-height: 320px;
    display:flex;
    flex-direction:column;
  }

  .event-img{
    height: 185px;
    background:#e9e9e9;
    position:relative;
    overflow:hidden;
  }

  .event-img img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
    filter: saturate(0.75);
  }

  /* Tint overlay like your Figma (maroon overlay) */
  .event-img::after{
    content:"";
    position:absolute; inset:0;
    background: rgba(128,0,0,.25);
    pointer-events:none;
  }

  .event-body{
    padding: 14px 14px 16px;
    display:flex;
    flex-direction:column;
    gap: 10px;
    flex:1;
    text-align:center;
  }

  .event-title{
    margin:0;
    font-weight: 900;
    font-size: 12px;
    letter-spacing: .5px;
    text-transform: uppercase;
    color:#111;
  }

  .event-desc{
    margin:0;
    font-size: 10px;
    color:#444;
    line-height: 1.35;
    min-height: 38px;
  }

  .event-btn{
    margin-top:auto;
    align-self:center;
    width: 120px;
    height: 30px;
    border:0;
    border-radius: 8px;
    background:#800000;
    color:#fff;
    font-weight: 900;
    font-size: 10px;
    cursor:pointer;
  }

  .event-card[hidden]{ display:none !important; }

  /* Optional "no results" */
  .no-events{
    display:none;
    margin-top: 8px;
    color: #666;
    font-weight: 700;
    font-size: 13px;
  }
  .no-events.show{ display:block; }
</style>
HTML;

ob_start();
?>

<div class="page-pad">
  <h1 class="page-title">EVENTS</h1>

  <div class="month-row">
    <!-- Trigger button -->
    <button class="month-trigger" id="monthTrigger" type="button" aria-label="Select date">
      <span class="month-icon">📅</span>
      <span class="month-label" id="monthLabel">JAN</span>
      <span class="month-caret">▾</span>
    </button>

    <!-- Real date input (opens native calendar UI) -->
    <input class="date-input" id="datePicker" type="date" />
  </div>

  <section class="events-grid" id="eventsGrid">
    <?php foreach ($events as $i => $ev): ?>
      <article class="event-card"
               data-date="<?= htmlspecialchars($ev["date"]) ?>"
               data-month="<?= htmlspecialchars(substr($ev["date"], 5, 2)) ?>">
        <div class="event-img">
          <img src="<?= htmlspecialchars($ev["img"]) ?>"
               alt="<?= htmlspecialchars($ev["title"]) ?>"
               onerror="this.style.display='none'; this.parentElement.style.background='#f3f3f3';">
        </div>

        <div class="event-body">
          <h3 class="event-title"><?= htmlspecialchars($ev["title"]) ?></h3>
          <p class="event-desc"><?= htmlspecialchars($ev["desc"]) ?></p>
          <button class="event-btn" type="button" data-details="<?= $i ?>">VIEW DETAILS</button>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <div class="no-events" id="noEvents">No events for this month.</div>
</div>

<script>
(function(){
  const monthTrigger = document.getElementById("monthTrigger");
  const monthLabel   = document.getElementById("monthLabel");
  const datePicker   = document.getElementById("datePicker");
  const cards        = Array.from(document.querySelectorAll(".event-card"));
  const noEvents     = document.getElementById("noEvents");

  const MONTHS = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];

  function pad2(n){ return String(n).padStart(2, "0"); }

  function setMonthUIFromDateStr(dateStr){
    // dateStr format: YYYY-MM-DD
    if (!dateStr || dateStr.length < 7) return;
    const m = parseInt(dateStr.slice(5,7), 10); // 01..12
    monthLabel.textContent = MONTHS[m - 1] || "JAN";
  }

  function filterByMonthFromDateStr(dateStr){
    if (!dateStr || dateStr.length < 7) return;

    const month = dateStr.slice(5,7); // "01".."12"
    let visibleCount = 0;

    cards.forEach(card => {
      const cardMonth = card.getAttribute("data-month"); // "01".."12"
      const show = (cardMonth === month);
      card.hidden = !show;
      if (show) visibleCount++;
    });

    noEvents.classList.toggle("show", visibleCount === 0);
  }

  // Default date: Jan 9 of current year (close to your Figma screenshot)
  const now = new Date();
  const defaultDate = `${now.getFullYear()}-01-09`;
  datePicker.value = defaultDate;
  setMonthUIFromDateStr(datePicker.value);
  filterByMonthFromDateStr(datePicker.value);

monthTrigger.addEventListener("click", () => {
  // Ensure it is focusable
  datePicker.blur();
  datePicker.focus({ preventScroll: true });

  // Best case (Chrome/Edge): opens the calendar UI
  if (typeof datePicker.showPicker === "function") {
    datePicker.showPicker();
    return;
  }

  // Fallback: some browsers only open after a "real" click
  // We trigger a click while the element is still focusable and not pointer-events:none
  datePicker.click();
});


  // When date changes, update label + filter
  datePicker.addEventListener("change", () => {
    setMonthUIFromDateStr(datePicker.value);
    filterByMonthFromDateStr(datePicker.value);
  });

  // Optional: VIEW DETAILS placeholder (replace with modal/page later)
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-details]");
    if (!btn) return;
    alert("Event details not implemented yet.");
  });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . "/../ui/layout.php";
