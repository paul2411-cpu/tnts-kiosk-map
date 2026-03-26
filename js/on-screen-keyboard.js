(function () {
  if (window.TNTSOnScreenKeyboard) return;

  const SUPPORTED_INPUT_TYPES = new Set([
    "",
    "text",
    "search",
    "password",
    "email",
    "tel",
    "url",
    "number",
    "date",
    "datetime-local",
    "time",
    "month",
    "week",
  ]);
  const PROXY_INPUT_TYPES = new Set(["date", "datetime-local", "time", "month", "week"]);
  const NUMERIC_FIRST_TYPES = new Set(["number", "tel", "date", "datetime-local", "time", "month", "week"]);
  const GAP = 14;
  const MIN_WIDTH = 320;
  const MIN_HEIGHT = 220;
  const DEFAULT_WIDTH = 760;
  const DEFAULT_HEIGHT = 430;
  const MAX_WIDTH_RATIO = 0.96;
  const MAX_HEIGHT_RATIO = 0.78;

  const state = {
    root: null,
    context: null,
    display: null,
    hint: null,
    tabs: null,
    rows: null,
    resizeHandle: null,
    target: null,
    mode: "alpha",
    shift: false,
    width: DEFAULT_WIDTH,
    height: DEFAULT_HEIGHT,
    drag: null,
    resize: null,
    proxy: null,
    hasManualSize: false,
  };

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function getViewportWidth() {
    return Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0, 0);
  }

  function getViewportHeight() {
    return Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0, 0);
  }

  function getInputType(element) {
    if (!element) return "";
    if (element.tagName === "TEXTAREA") return "textarea";
    return String(element.getAttribute("type") || "text").toLowerCase();
  }

  function isEligibleTarget(element) {
    if (!(element instanceof HTMLElement)) return false;
    if (element.dataset.osk === "off") return false;
    if (element.hasAttribute("readonly") || element.hasAttribute("disabled")) return false;
    if (element.tagName === "TEXTAREA") return true;
    if (element.tagName !== "INPUT") return false;

    const type = getInputType(element);
    if (!SUPPORTED_INPUT_TYPES.has(type)) return false;
    return !["hidden", "file", "checkbox", "radio", "range", "color", "button", "submit", "reset", "image"].includes(type);
  }

  function wantsNumericMode(element) {
    if (!(element instanceof HTMLElement)) return false;
    if (element.dataset.osk === "numeric") return true;
    return NUMERIC_FIRST_TYPES.has(getInputType(element));
  }

  function shouldProxy(element) {
    return PROXY_INPUT_TYPES.has(getInputType(element));
  }

  function getMaxPanelWidth() {
    return Math.max(MIN_WIDTH, Math.floor(getViewportWidth() * MAX_WIDTH_RATIO));
  }

  function getMaxPanelHeight() {
    return Math.max(MIN_HEIGHT, Math.floor(getViewportHeight() * MAX_HEIGHT_RATIO));
  }

  function getContentMinimumHeight() {
    if (!state.root || !isOpen()) return MIN_HEIGHT;

    const rootStyles = window.getComputedStyle(state.root);
    const gap = parseFloat(rootStyles.rowGap || rootStyles.gap || "0") || 0;
    const paddingTop = parseFloat(rootStyles.paddingTop || "0") || 0;
    const paddingBottom = parseFloat(rootStyles.paddingBottom || "0") || 0;
    const sections = Array.from(state.root.children).filter((child) => child instanceof HTMLElement);
    if (!sections.length) return MIN_HEIGHT;

    const contentHeight = sections.reduce((sum, child) => sum + child.scrollHeight, 0)
      + (Math.max(0, sections.length - 1) * gap)
      + paddingTop
      + paddingBottom;

    return Math.max(MIN_HEIGHT, Math.ceil(contentHeight));
  }

  function getContentMinimumWidth() {
    if (!state.root || !isOpen()) return MIN_WIDTH;

    const rootStyles = window.getComputedStyle(state.root);
    const paddingLeft = parseFloat(rootStyles.paddingLeft || "0") || 0;
    const paddingRight = parseFloat(rootStyles.paddingRight || "0") || 0;
    let contentWidth = 0;

    if (state.rows) {
      const rowElements = Array.from(state.rows.querySelectorAll(".osk-row"));
      rowElements.forEach((row) => {
        const rowStyles = window.getComputedStyle(row);
        const gap = parseFloat(rowStyles.columnGap || rowStyles.gap || "0") || 0;
        let totalColumns = 0;
        let requiredUnitWidth = 0;

        Array.from(row.children).forEach((child) => {
          if (!(child instanceof HTMLElement)) return;

          const span = child.classList.contains("is-space")
            ? 5
            : child.classList.contains("is-wide")
              ? 2
              : 1;
          const childStyles = window.getComputedStyle(child);
          const borderWidth = (parseFloat(childStyles.borderLeftWidth || "0") || 0)
            + (parseFloat(childStyles.borderRightWidth || "0") || 0);
          const intrinsicWidth = Math.max(
            child.scrollWidth + borderWidth,
            parseFloat(childStyles.minWidth || "0") || 0
          );

          totalColumns += span;
          requiredUnitWidth = Math.max(requiredUnitWidth, (intrinsicWidth - ((span - 1) * gap)) / span);
        });

        if (totalColumns > 0) {
          const rowWidth = (totalColumns * requiredUnitWidth) + (Math.max(0, totalColumns - 1) * gap);
          contentWidth = Math.max(contentWidth, rowWidth);
        }
      });
    }

    const sections = Array.from(state.root.children).filter((child) => child instanceof HTMLElement);
    sections.forEach((child) => {
      contentWidth = Math.max(contentWidth, child.scrollWidth);
    });

    contentWidth += paddingLeft + paddingRight;

    return Math.max(MIN_WIDTH, Math.ceil(contentWidth));
  }

  function getPreferredPanelWidth() {
    return clamp(Math.max(DEFAULT_WIDTH, getContentMinimumWidth()), MIN_WIDTH, getMaxPanelWidth());
  }

  function getPreferredPanelHeight() {
    return clamp(getContentMinimumHeight(), MIN_HEIGHT, getMaxPanelHeight());
  }

  function applyPanelDimensions(forcePreferred = false) {
    if (!state.root) return;
    const minWidth = Math.min(getContentMinimumWidth(), getMaxPanelWidth());
    const minHeight = Math.min(getContentMinimumHeight(), getMaxPanelHeight());
    state.root.style.minWidth = `${minWidth}px`;
    state.root.style.minHeight = `${minHeight}px`;

    if (forcePreferred || !state.hasManualSize) {
      state.width = getPreferredPanelWidth();
      state.height = getPreferredPanelHeight();
    } else {
      normalizeDimensions();
    }

    state.root.style.width = `${state.width}px`;
    state.root.style.height = `${state.height}px`;
  }

  function normalizeDimensions() {
    const maxWidth = getMaxPanelWidth();
    const minWidth = Math.min(getContentMinimumWidth(), maxWidth);
    const maxHeight = getMaxPanelHeight();
    const minHeight = Math.min(getContentMinimumHeight(), maxHeight);
    if (state.root) {
      state.root.style.minWidth = `${minWidth}px`;
      state.root.style.minHeight = `${minHeight}px`;
    }
    state.width = clamp(state.width, minWidth, maxWidth);
    state.height = clamp(state.height, minHeight, maxHeight);
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function key(value, label, className, actionAsValue) {
    return {
      type: actionAsValue ? "action" : "key",
      value,
      label: label || value,
      className: className || "",
    };
  }

  function actionKey(action, label, className) {
    return { type: "action", value: action, label, className: className || "" };
  }

  function keyRow(values, extras) {
    const row = values.map((value) => key(value));
    return extras ? row.concat(extras) : row;
  }

  const layouts = {
    alpha: [
      keyRow("1 2 3 4 5 6 7 8 9 0".split(" ")),
      keyRow("q w e r t y u i o p".split(" ")),
      keyRow("a s d f g h j k l".split(" "), [actionKey("backspace", "Back", "wide")]),
      [actionKey("shift", "Shift", "wide"), ...keyRow("z x c v b n m".split(" ")), actionKey("enter", "Enter", "wide")],
      [key("space", "Space", "space", true), key("@"), key("."), key("-"), key("/"), actionKey("done", "Done", "wide primary")],
    ],
    symbols: [
      keyRow("1 2 3 4 5 6 7 8 9 0".split(" ")),
      [...keyRow("! @ # $ % & * ( )".split(" ")), actionKey("backspace", "Back", "wide")],
      keyRow("- _ / : ; ? + =".split(" ")),
      keyRow("< > [ ] { } \" ' , .".split(" ")),
      [key("space", "Space", "space", true), key("."), key("-"), key(":"), key("/"), actionKey("done", "Done", "wide primary")],
    ],
    numeric: [
      [key("1"), key("2"), key("3"), actionKey("backspace", "Back", "wide")],
      [key("4"), key("5"), key("6"), actionKey("clear", "Clear", "wide")],
      [key("7"), key("8"), key("9"), key("-")],
      [key("/"), key("0"), key("."), key(":")],
      [key("space", "Space", "space", true), actionKey("enter", "Enter", "wide"), actionKey("done", "Done", "wide primary")],
    ],
  };

  function ensureStyles() {
    if (document.getElementById("tnts-osk-styles")) return;

    const style = document.createElement("style");
    style.id = "tnts-osk-styles";
    style.textContent = `
      #tnts-osk {
        position: fixed;
        left: ${GAP}px;
        top: ${GAP}px;
        width: ${DEFAULT_WIDTH}px;
        height: ${DEFAULT_HEIGHT}px;
        display: none;
        flex-direction: column;
        gap: 10px;
        padding: 12px;
        box-sizing: border-box;
        border-radius: 18px;
        border: 1px solid rgba(15, 23, 42, 0.16);
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 26px 70px rgba(15, 23, 42, 0.22);
        z-index: 2147483000;
        user-select: none;
        touch-action: none;
        backdrop-filter: blur(10px);
        overflow: hidden;
      }
      #tnts-osk[data-open="true"] { display: flex; }
      #tnts-osk .osk-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: start;
        cursor: grab;
        flex: 0 0 auto;
      }
      #tnts-osk .osk-header.dragging { cursor: grabbing; }
      #tnts-osk .osk-title {
        color: #101828;
        font-size: 15px;
        font-weight: 900;
        letter-spacing: 0.02em;
      }
      #tnts-osk .osk-context {
        color: #475467;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.45;
      }
      #tnts-osk .osk-display {
        margin-top: 6px;
        min-height: 42px;
        max-height: 56px;
        padding: 10px 12px;
        box-sizing: border-box;
        border-radius: 12px;
        border: 1px solid #d0d5dd;
        background: #f8fafc;
        color: #101828;
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45;
        word-break: break-word;
        overflow: auto;
      }
      #tnts-osk .osk-display.is-error {
        border-color: #fecdca;
        background: #fef3f2;
        color: #b42318;
      }
      #tnts-osk .osk-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: flex-start;
      }
      #tnts-osk .osk-small-btn,
      #tnts-osk .osk-tab,
      #tnts-osk .osk-key {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #101828;
        border-radius: 12px;
        font-weight: 900;
        font-family: inherit;
        cursor: pointer;
        box-sizing: border-box;
        white-space: nowrap;
      }
      #tnts-osk .osk-small-btn {
        min-height: 38px;
        padding: 0 12px;
        font-size: 12px;
      }
      #tnts-osk .osk-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        flex: 0 0 auto;
      }
      #tnts-osk .osk-tab {
        min-height: 38px;
        padding: 0 12px;
        font-size: 12px;
      }
      #tnts-osk .osk-tab.is-active {
        background: #7a0000;
        border-color: #7a0000;
        color: #ffffff;
      }
      #tnts-osk .osk-rows {
        flex: 1 1 auto;
        display: grid;
        gap: 8px;
        min-height: 0;
        align-content: start;
      }
      #tnts-osk .osk-row {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: 1fr;
        gap: 8px;
      }
      #tnts-osk .osk-key {
        min-height: 44px;
        padding: 0 8px;
        font-size: 15px;
        touch-action: manipulation;
      }
      #tnts-osk .osk-key.is-wide { grid-column: span 2; }
      #tnts-osk .osk-key.is-space { grid-column: span 5; }
      #tnts-osk .osk-key.is-primary {
        background: #7a0000;
        border-color: #7a0000;
        color: #ffffff;
      }
      #tnts-osk .osk-key:active,
      #tnts-osk .osk-small-btn:active,
      #tnts-osk .osk-tab:active { transform: translateY(1px); }
      #tnts-osk .osk-footer {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        flex: 0 0 auto;
      }
      #tnts-osk .osk-hint {
        color: #667085;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.4;
        flex: 1 1 auto;
        min-width: 0;
      }
      #tnts-osk .osk-resize {
        width: 24px;
        height: 24px;
        border-radius: 8px;
        background:
          linear-gradient(135deg, transparent 48%, #98a2b3 48%, #98a2b3 56%, transparent 56%),
          linear-gradient(135deg, transparent 62%, #98a2b3 62%, #98a2b3 70%, transparent 70%),
          linear-gradient(135deg, transparent 76%, #98a2b3 76%, #98a2b3 84%, transparent 84%);
        cursor: nwse-resize;
        touch-action: none;
        flex: 0 0 auto;
      }
      @media (max-width: 760px) {
        #tnts-osk {
          padding: 10px;
          border-radius: 16px;
        }
        #tnts-osk .osk-key {
          min-height: 40px;
          font-size: 14px;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function ensureUI() {
    if (state.root) return;

    ensureStyles();

    const root = document.createElement("div");
    root.id = "tnts-osk";
    root.setAttribute("aria-hidden", "true");
    root.innerHTML = `
      <div class="osk-header" data-osk-drag>
        <div>
          <div class="osk-title">On-screen Keyboard</div>
          <div class="osk-context">Ready</div>
          <div class="osk-display"></div>
        </div>
        <div class="osk-actions">
          <button class="osk-small-btn" type="button" data-osk-action="clear">Clear</button>
          <button class="osk-small-btn" type="button" data-osk-action="close">Close</button>
        </div>
      </div>
      <div class="osk-tabs">
        <button class="osk-tab" type="button" data-osk-mode="alpha">ABC</button>
        <button class="osk-tab" type="button" data-osk-mode="numeric">123</button>
        <button class="osk-tab" type="button" data-osk-mode="symbols">#+=</button>
      </div>
      <div class="osk-rows"></div>
      <div class="osk-footer">
        <div class="osk-hint">Drag to move. Use the corner handle to resize.</div>
        <div class="osk-resize" data-osk-resize title="Resize keyboard"></div>
      </div>
    `;
    document.body.appendChild(root);

    state.root = root;
    state.context = root.querySelector(".osk-context");
    state.display = root.querySelector(".osk-display");
    state.hint = root.querySelector(".osk-hint");
    state.tabs = Array.from(root.querySelectorAll("[data-osk-mode]"));
    state.rows = root.querySelector(".osk-rows");
    state.resizeHandle = root.querySelector("[data-osk-resize]");

    root.addEventListener("pointerdown", handlePanelPointerDown, { passive: false });
    root.querySelector("[data-osk-drag]").addEventListener("pointerdown", startDrag, { passive: false });
    state.resizeHandle.addEventListener("pointerdown", startResize, { passive: false });

    document.addEventListener("pointerdown", handleDocumentPointerDown, true);
    document.addEventListener("focusin", handleDocumentFocusIn, true);
    document.addEventListener("keydown", handleDocumentKeyDown, true);
    document.addEventListener("submit", handleFormSubmit, true);
    window.addEventListener("resize", handleViewportChange);
    window.addEventListener("scroll", handleViewportChange, true);
  }

  function handleViewportChange() {
    if (!isOpen() || state.drag || state.resize) return;
    applyPanelDimensions(!state.hasManualSize);
    positionNearTarget();
  }

  function handleDocumentKeyDown(event) {
    if (!isOpen()) return;
    if (event.key === "Escape") {
      event.preventDefault();
      hideKeyboard();
    }
  }

  function handleFormSubmit(event) {
    if (!state.proxy || !state.target || state.proxy.input.form !== event.target) return;
    if (!restoreProxy(false)) {
      event.preventDefault();
      focusTarget();
    }
  }

  function handleDocumentPointerDown(event) {
    if (state.root && state.root.contains(event.target)) return;
    if (isEligibleTarget(event.target)) return;
    if (isOpen()) hideKeyboard();
  }

  function handleDocumentFocusIn(event) {
    if (state.root && state.root.contains(event.target)) return;
    if (!isEligibleTarget(event.target)) return;
    if (!showForTarget(event.target)) {
      setTimeout(() => focusTarget(), 0);
    }
  }

  function isOpen() {
    return !!(state.root && state.root.dataset.open === "true");
  }

  function inferFieldLabel(element) {
    if (!(element instanceof HTMLElement)) return "Input";

    if (element.labels && element.labels.length) {
      const labelText = String(element.labels[0].textContent || "").trim();
      if (labelText) return labelText;
    }

    const container = element.closest(".row, .events-row-stack, .announcements-row-stack, .feedback-field, .searchbox, .search-box");
    if (container) {
      const label = container.querySelector(".label");
      const labelText = label ? String(label.textContent || "").trim() : "";
      if (labelText) return labelText;
    }

    const ariaLabel = String(element.getAttribute("aria-label") || "").trim();
    if (ariaLabel) return ariaLabel;

    const placeholder = String(element.getAttribute("placeholder") || "").trim();
    if (placeholder) return placeholder;

    const name = String(element.getAttribute("name") || "").trim();
    if (name) return name;

    const id = String(element.id || "").trim();
    if (id) return id;

    return "Input";
  }

  function getProxyFormat(type) {
    switch (type) {
      case "date":
        return "YYYY-MM-DD";
      case "datetime-local":
        return "YYYY-MM-DD HH:MM";
      case "time":
        return "HH:MM";
      case "month":
        return "YYYY-MM";
      case "week":
        return "YYYY-W##";
      default:
        return "";
    }
  }

  function normalizeProxyValue(type, value) {
    let output = String(value || "").trim();
    if (output === "") return "";

    if (type === "date" || type === "datetime-local" || type === "month") {
      output = output.replace(/\//g, "-");
    }
    if (type === "datetime-local") {
      output = output.replace(/\s+/, "T");
    }
    if (type === "week") {
      output = output.toUpperCase().replace(/\s+/g, "");
      const compact = output.match(/^(\d{4})-?W?(\d{2})$/);
      if (compact) output = `${compact[1]}-W${compact[2]}`;
    }
    return output;
  }

  function isValidDateString(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const parts = value.split("-").map((part) => Number.parseInt(part, 10));
    const dt = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    return dt.getUTCFullYear() === parts[0] && dt.getUTCMonth() === parts[1] - 1 && dt.getUTCDate() === parts[2];
  }

  function isValidProxyValue(type, value) {
    if (value === "") return true;
    switch (type) {
      case "date":
        return isValidDateString(value);
      case "datetime-local": {
        const normalized = value.replace(" ", "T");
        const match = normalized.match(/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/);
        return !!(match && isValidDateString(match[1]));
      }
      case "time":
        return /^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/.test(value);
      case "month":
        return /^\d{4}-(0[1-9]|1[0-2])$/.test(value);
      case "week":
        return /^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/.test(value);
      default:
        return true;
    }
  }

  function proxyErrorMessage(type) {
    const format = getProxyFormat(type);
    return format ? `Finish a valid value in ${format} format before closing.` : "Finish a valid value before closing.";
  }

  function friendlyInputType(type) {
    switch (type) {
      case "textarea":
        return "multiline text";
      case "number":
        return "number input";
      case "password":
        return "password input";
      case "date":
        return "date input";
      case "datetime-local":
        return "date and time input";
      case "time":
        return "time input";
      default:
        return "text input";
    }
  }

  function activateProxy(element) {
    if (!shouldProxy(element)) return true;
    if (state.proxy && state.proxy.input === element) return true;
    if (state.proxy && !restoreProxy(false)) return false;

    const originalType = getInputType(element);
    state.proxy = {
      input: element,
      originalType,
      originalInputMode: String(element.getAttribute("inputmode") || ""),
      originalPlaceholder: element.getAttribute("placeholder"),
    };

    element.dataset.oskProxy = "true";
    element.type = "text";
    element.setAttribute("inputmode", "numeric");
    if (!state.proxy.originalPlaceholder) {
      element.setAttribute("placeholder", getProxyFormat(originalType));
    }
    element.value = normalizeProxyValue(originalType, element.value || "");
    return true;
  }

  function restoreProxy(force) {
    if (!state.proxy) return true;

    const input = state.proxy.input;
    const originalType = state.proxy.originalType;
    const normalizedValue = normalizeProxyValue(originalType, input.value || "");
    const valid = isValidProxyValue(originalType, normalizedValue);

    if (!valid && !force) {
      updateDisplay(proxyErrorMessage(originalType), true);
      focusTarget();
      return false;
    }

    input.type = originalType;
    if (state.proxy.originalInputMode) input.setAttribute("inputmode", state.proxy.originalInputMode);
    else input.removeAttribute("inputmode");

    if (state.proxy.originalPlaceholder === null) input.removeAttribute("placeholder");
    else input.setAttribute("placeholder", state.proxy.originalPlaceholder);

    input.value = valid ? normalizedValue : "";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    delete input.dataset.oskProxy;

    state.proxy = null;
    return true;
  }

  function getSelection(element) {
    const fallback = String(element.value || "").length;
    if (typeof element.selectionStart === "number" && typeof element.selectionEnd === "number") {
      return {
        start: clamp(element.selectionStart, 0, fallback),
        end: clamp(element.selectionEnd, 0, fallback),
      };
    }
    return { start: fallback, end: fallback };
  }

  function setSelection(element, start, end) {
    if (typeof element.setSelectionRange !== "function") return;
    try {
      element.setSelectionRange(start, end);
    } catch (_) {}
  }

  function focusTarget() {
    if (!state.target) return;
    try {
      state.target.focus({ preventScroll: true });
    } catch (_) {
      try { state.target.focus(); } catch (_) {}
    }
  }

  function mutateValue(transformer) {
    if (!state.target) return;
    focusTarget();

    const value = String(state.target.value || "");
    const selection = getSelection(state.target);
    const result = transformer(value, selection.start, selection.end);
    if (!result || typeof result.value !== "string") return;

    state.target.value = result.value;
    if (typeof result.selectionStart === "number" && typeof result.selectionEnd === "number") {
      setSelection(state.target, result.selectionStart, result.selectionEnd);
    }

    state.target.dispatchEvent(new Event("input", { bubbles: true }));
    updateDisplay();
  }

  function insertText(text) {
    mutateValue((value, start, end) => {
      const nextValue = value.slice(0, start) + text + value.slice(end);
      const caret = start + text.length;
      return { value: nextValue, selectionStart: caret, selectionEnd: caret };
    });
  }

  function deleteBackward() {
    mutateValue((value, start, end) => {
      if (start !== end) {
        return { value: value.slice(0, start) + value.slice(end), selectionStart: start, selectionEnd: start };
      }
      if (start <= 0) {
        return { value, selectionStart: start, selectionEnd: end };
      }
      const nextStart = start - 1;
      return {
        value: value.slice(0, nextStart) + value.slice(end),
        selectionStart: nextStart,
        selectionEnd: nextStart,
      };
    });
  }

  function clearValue() {
    if (!state.target) return;
    focusTarget();
    state.target.value = "";
    state.target.dispatchEvent(new Event("input", { bubbles: true }));
    updateDisplay();
  }

  function dispatchEnter() {
    if (!state.target) return;
    if (state.target.tagName === "TEXTAREA") {
      insertText("\n");
      return;
    }

    focusTarget();
    const enterEvent = new KeyboardEvent("keydown", {
      key: "Enter",
      code: "Enter",
      bubbles: true,
      cancelable: true,
    });
    const notCanceled = state.target.dispatchEvent(enterEvent);
    state.target.dispatchEvent(new KeyboardEvent("keyup", { key: "Enter", code: "Enter", bubbles: true }));

    if (notCanceled && state.target.form) {
      if (typeof state.target.form.requestSubmit === "function") state.target.form.requestSubmit();
      else state.target.form.submit();
    }
  }

  function describeValue(element) {
    if (!(element instanceof HTMLElement)) return "";

    const type = state.proxy ? state.proxy.originalType : getInputType(element);
    const rawValue = String(element.value || "");
    if (type === "password" && rawValue !== "") return "*".repeat(Math.min(rawValue.length, 24));
    if (rawValue !== "") return rawValue;

    const format = state.proxy ? getProxyFormat(type) : "";
    if (format) return format;
    return String(element.getAttribute("placeholder") || "").trim();
  }

  function updateDisplay(message, isError) {
    if (!state.display) return;

    const type = state.proxy ? state.proxy.originalType : getInputType(state.target);
    const context = state.target
      ? `${inferFieldLabel(state.target)} - ${state.proxy ? `Editing ${getProxyFormat(type)}` : friendlyInputType(type)}`
      : "Ready";
    state.context.textContent = context;
    state.display.textContent = message || (state.target ? describeValue(state.target) : "Tap an input field to start typing.");
    state.display.classList.toggle("is-error", !!isError);
    state.hint.textContent = isError
      ? message
      : "Drag to move. Use the corner handle to resize. Enter submits single-line inputs; Done closes the keyboard.";
  }

  function renderKeys() {
    if (!state.rows) return;
    const layout = layouts[state.mode] || layouts.alpha;

    state.rows.innerHTML = layout.map((row) => `
      <div class="osk-row">
        ${row.map((item) => {
          const classes = ["osk-key"];
          const extraClasses = String(item.className || "").split(" ").filter(Boolean);
          extraClasses.forEach((name) => classes.push(name === "wide" ? "is-wide" : name === "space" ? "is-space" : name === "primary" ? "is-primary" : name));

          if (item.type === "action") {
            return `<button class="${classes.join(" ")}" type="button" data-osk-action="${escapeHtml(item.value)}">${escapeHtml(item.label)}</button>`;
          }

          const outputValue = state.shift && item.value.length === 1 && /[a-z]/i.test(item.value) ? item.value.toUpperCase() : item.value;
          const label = state.shift && item.value.length === 1 && /[a-z]/i.test(item.value) ? item.label.toUpperCase() : item.label;
          return `<button class="${classes.join(" ")}" type="button" data-osk-key="${escapeHtml(outputValue)}">${escapeHtml(label)}</button>`;
        }).join("")}
      </div>
    `).join("");

    state.tabs.forEach((button) => {
      button.classList.toggle("is-active", button.getAttribute("data-osk-mode") === state.mode);
    });
  }

  function positionNearTarget() {
    if (!state.root || !state.target) return;

    normalizeDimensions();

    const rect = state.target.getBoundingClientRect();
    const panelWidth = state.width;
    const panelHeight = state.height;
    const viewportWidth = getViewportWidth();
    const viewportHeight = getViewportHeight();
    const spaceBelow = viewportHeight - rect.bottom - GAP;
    const spaceAbove = rect.top - GAP;

    let top = rect.bottom + GAP;
    if (spaceBelow < panelHeight && spaceAbove > spaceBelow) {
      top = rect.top - panelHeight - GAP;
    }
    top = clamp(top, GAP, Math.max(GAP, viewportHeight - panelHeight - GAP));

    let left = rect.left;
    if (left + panelWidth > viewportWidth - GAP) {
      left = rect.right - panelWidth;
    }
    left = clamp(left, GAP, Math.max(GAP, viewportWidth - panelWidth - GAP));

    state.root.style.width = `${panelWidth}px`;
    state.root.style.height = `${panelHeight}px`;
    state.root.style.left = `${left}px`;
    state.root.style.top = `${top}px`;
  }

  function openPanel() {
    if (!state.root) return;
    state.root.dataset.open = "true";
    state.root.setAttribute("aria-hidden", "false");
  }

  function showForTarget(element) {
    ensureUI();

    if (state.target === element) {
      state.mode = wantsNumericMode(element) ? "numeric" : state.mode;
      renderKeys();
      updateDisplay();
      openPanel();
      applyPanelDimensions(!state.hasManualSize);
      positionNearTarget();
      return true;
    }

    if (state.proxy && !restoreProxy(false)) return false;

    state.target = element;
    state.mode = wantsNumericMode(element) ? "numeric" : "alpha";
    state.shift = false;

    if (!activateProxy(element)) return false;

    renderKeys();
    updateDisplay();
    openPanel();
    applyPanelDimensions(!state.hasManualSize);
    positionNearTarget();
    focusTarget();
    return true;
  }

  function hideKeyboard(force) {
    if (!state.root || !isOpen()) return true;
    if (state.proxy && !restoreProxy(!!force)) return false;

    state.root.dataset.open = "false";
    state.root.setAttribute("aria-hidden", "true");
    state.shift = false;
    state.hasManualSize = false;
    state.target = null;
    updateDisplay();
    return true;
  }

  function handleAction(action) {
    switch (action) {
      case "close":
        hideKeyboard();
        return;
      case "clear":
        clearValue();
        return;
      case "space":
        insertText(" ");
        return;
      case "backspace":
        deleteBackward();
        return;
      case "shift":
        state.shift = !state.shift;
        renderKeys();
        return;
      case "enter":
        dispatchEnter();
        return;
      case "done":
        if (state.target && state.target.id === "search-input") {
          dispatchEnter();
        }
        hideKeyboard();
        return;
      default:
        return;
    }
  }

  function handlePanelPointerDown(event) {
    const modeButton = event.target.closest("[data-osk-mode]");
    if (modeButton) {
      event.preventDefault();
      event.stopPropagation();
      state.mode = modeButton.getAttribute("data-osk-mode") || "alpha";
      state.shift = false;
      renderKeys();
      if (isOpen()) {
        applyPanelDimensions(!state.hasManualSize);
        positionNearTarget();
      }
      return;
    }

    const actionButton = event.target.closest("[data-osk-action]");
    if (actionButton) {
      event.preventDefault();
      event.stopPropagation();
      handleAction(actionButton.getAttribute("data-osk-action"));
      return;
    }

    const keyButton = event.target.closest("[data-osk-key]");
    if (keyButton) {
      event.preventDefault();
      event.stopPropagation();
      const keyValue = keyButton.getAttribute("data-osk-key") || "";
      if (keyValue) {
        insertText(keyValue);
        if (state.shift && state.mode === "alpha") {
          state.shift = false;
          renderKeys();
        }
      }
    }
  }

  function startDrag(event) {
    if (!isOpen() || !state.root) return;
    if (event.target.closest("button")) return;

    event.preventDefault();
    event.stopPropagation();

    const rect = state.root.getBoundingClientRect();
    state.drag = { pointerId: event.pointerId, offsetX: event.clientX - rect.left, offsetY: event.clientY - rect.top };
    event.currentTarget.setPointerCapture(event.pointerId);
    event.currentTarget.classList.add("dragging");
    event.currentTarget.addEventListener("pointermove", handleDragMove, { passive: false });
    event.currentTarget.addEventListener("pointerup", stopDrag, { passive: false });
    event.currentTarget.addEventListener("pointercancel", stopDrag, { passive: false });
  }

  function handleDragMove(event) {
    if (!state.drag || !state.root) return;
    event.preventDefault();
    const left = clamp(event.clientX - state.drag.offsetX, GAP, Math.max(GAP, getViewportWidth() - state.width - GAP));
    const top = clamp(event.clientY - state.drag.offsetY, GAP, Math.max(GAP, getViewportHeight() - state.height - GAP));
    state.root.style.left = `${left}px`;
    state.root.style.top = `${top}px`;
  }

  function stopDrag(event) {
    const handle = event.currentTarget;
    if (handle.releasePointerCapture) {
      try { handle.releasePointerCapture(event.pointerId); } catch (_) {}
    }
    handle.classList.remove("dragging");
    handle.removeEventListener("pointermove", handleDragMove);
    handle.removeEventListener("pointerup", stopDrag);
    handle.removeEventListener("pointercancel", stopDrag);
    state.drag = null;
  }

  function startResize(event) {
    if (!isOpen() || !state.root) return;
    event.preventDefault();
    event.stopPropagation();

    const rect = state.root.getBoundingClientRect();
    state.resize = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startWidth: rect.width,
      startHeight: rect.height,
    };
    event.currentTarget.setPointerCapture(event.pointerId);
    event.currentTarget.addEventListener("pointermove", handleResizeMove, { passive: false });
    event.currentTarget.addEventListener("pointerup", stopResize, { passive: false });
    event.currentTarget.addEventListener("pointercancel", stopResize, { passive: false });
  }

  function handleResizeMove(event) {
    if (!state.resize || !state.root) return;
    event.preventDefault();

    const maxWidth = getMaxPanelWidth();
    const minWidth = Math.min(getContentMinimumWidth(), maxWidth);
    state.width = clamp(state.resize.startWidth + (event.clientX - state.resize.startX), minWidth, maxWidth);
    const maxHeight = getMaxPanelHeight();
    const minHeight = Math.min(getContentMinimumHeight(), maxHeight);
    state.height = clamp(state.resize.startHeight + (event.clientY - state.resize.startY), minHeight, maxHeight);
    state.hasManualSize = true;
    state.root.style.width = `${state.width}px`;
    state.root.style.height = `${state.height}px`;

    const left = clamp(parseFloat(state.root.style.left || "0"), GAP, Math.max(GAP, getViewportWidth() - state.width - GAP));
    const top = clamp(parseFloat(state.root.style.top || "0"), GAP, Math.max(GAP, getViewportHeight() - state.height - GAP));
    state.root.style.left = `${left}px`;
    state.root.style.top = `${top}px`;
  }

  function stopResize(event) {
    const handle = event.currentTarget;
    if (handle.releasePointerCapture) {
      try { handle.releasePointerCapture(event.pointerId); } catch (_) {}
    }
    handle.removeEventListener("pointermove", handleResizeMove);
    handle.removeEventListener("pointerup", stopResize);
    handle.removeEventListener("pointercancel", stopResize);
    state.resize = null;
  }

  function init() {
    ensureUI();
    updateDisplay();
  }

  window.TNTSOnScreenKeyboard = {
    version: "1.0.0",
    showFor: showForTarget,
    hide: hideKeyboard,
    isEligibleTarget,
    refreshPosition: positionNearTarget,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
