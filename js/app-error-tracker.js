(function () {
  "use strict";

  const config = Object.assign({
    endpoint: "../api/client_error_log.php",
    subsystem: "client",
    page: "",
    requestId: "",
  }, window.TNTS_ERROR_TRACKER || {});

  const seen = new Set();

  function trimText(value, max) {
    const text = String(value == null ? "" : value).trim();
    if (!text) return "";
    return text.length > max ? text.slice(0, max) + "..." : text;
  }

  function safeError(error) {
    if (!error) return {};
    return {
      name: trimText(error.name || "", 120),
      message: trimText(error.message || "", 2000),
      stack: trimText(error.stack || "", 6000),
    };
  }

  function post(payload) {
    if (!config.endpoint || !payload) return;

    const body = JSON.stringify({
      subsystem: config.subsystem,
      page: config.page,
      requestId: config.requestId,
      href: trimText(window.location.href || "", 1000),
      payload,
    });

    if (navigator.sendBeacon) {
      try {
        const blob = new Blob([body], { type: "application/json" });
        navigator.sendBeacon(config.endpoint, blob);
        return;
      } catch (_) {
      }
    }

    if (window.fetch) {
      fetch(config.endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
        credentials: "same-origin",
        keepalive: true,
      }).catch(function () {
      });
    }
  }

  function dedupeKey(kind, message, stack) {
    return [kind, trimText(message, 400), trimText(stack, 800)].join("|");
  }

  function send(kind, message, extra) {
    const stack = extra && extra.error ? extra.error.stack || "" : (extra && extra.stack ? extra.stack : "");
    const key = dedupeKey(kind, message, stack);
    if (seen.has(key)) return;
    seen.add(key);
    if (seen.size > 200) {
      seen.clear();
      seen.add(key);
    }

    post({
      kind,
      message: trimText(message, 2000),
      extra: extra || {},
    });
  }

  window.tntsReportClientError = function tntsReportClientError(kind, message, extra) {
    send(kind || "manual", message || "Client error", extra || {});
  };

  window.addEventListener("error", function (event) {
    const error = safeError(event.error);
    send("window_error", event.message || error.message || "Window error", {
      source: trimText(event.filename || "", 600),
      line: Number(event.lineno || 0),
      column: Number(event.colno || 0),
      error,
    });
  });

  window.addEventListener("unhandledrejection", function (event) {
    const reason = event.reason;
    const error = safeError(reason instanceof Error ? reason : null);
    const message = reason instanceof Error
      ? (reason.message || "Unhandled promise rejection")
      : (typeof reason === "string" ? reason : "Unhandled promise rejection");

    send("unhandled_rejection", message, {
      reason: trimText(typeof reason === "string" ? reason : JSON.stringify(reason || {}), 3000),
      error,
    });
  });
})();
