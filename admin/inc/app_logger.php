<?php

if (!function_exists("app_logger_root")) {
  function app_logger_root(): string {
    return dirname(__DIR__, 2);
  }

  function app_logger_log_dir(): string {
    return app_logger_root() . "/storage/logs";
  }

  function app_logger_request_id(): string {
    static $requestId = null;
    if (is_string($requestId) && $requestId !== "") {
      return $requestId;
    }

    try {
      $requestId = bin2hex(random_bytes(8));
    } catch (Throwable $_) {
      $requestId = substr(hash("sha256", uniqid((string)mt_rand(), true)), 0, 16);
    }

    return $requestId;
  }

  function app_logger_trim_text($value, int $max = 2000): string {
    $text = trim((string)$value);
    if ($text === "") return "";
    if (mb_strlen($text) > $max) {
      return mb_substr($text, 0, $max) . "…";
    }
    return $text;
  }

  function app_logger_safe_value($value, int $depth = 0) {
    if ($depth >= 4) {
      return "[max-depth]";
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
      return $value;
    }

    if (is_string($value)) {
      return app_logger_trim_text($value, 4000);
    }

    if ($value instanceof Throwable) {
      return [
        "class" => get_class($value),
        "message" => app_logger_trim_text($value->getMessage(), 4000),
        "file" => str_replace("\\", "/", $value->getFile()),
        "line" => $value->getLine(),
      ];
    }

    if (is_object($value)) {
      if (method_exists($value, "__toString")) {
        return app_logger_trim_text((string)$value, 4000);
      }
      $value = get_object_vars($value);
    }

    if (is_array($value)) {
      $out = [];
      $count = 0;
      foreach ($value as $key => $item) {
        $safeKey = is_int($key) ? $key : app_logger_trim_text((string)$key, 120);
        $out[$safeKey] = app_logger_safe_value($item, $depth + 1);
        $count++;
        if ($count >= 80) {
          $out["__truncated__"] = "Additional items omitted";
          break;
        }
      }
      return $out;
    }

    return app_logger_trim_text((string)$value, 4000);
  }

  function app_logger_detect_subsystem(?string $fallback = "app"): string {
    $script = str_replace("\\", "/", (string)($_SERVER["SCRIPT_NAME"] ?? ""));
    if ($script !== "") {
      if (strpos($script, "/api/") !== false) {
        return "api";
      }
      if (strpos($script, "/admin/") !== false) {
        return "admin";
      }
      if (strpos($script, "/pages/") !== false) {
        return "public";
      }
    }
    return $fallback ?: "app";
  }

  function app_logger_set_default_subsystem(?string $subsystem): void {
    $safe = preg_replace('/[^a-z0-9_.-]+/i', "_", trim((string)$subsystem));
    if (!is_string($safe) || $safe === "") return;
    $GLOBALS["app_logger_default_subsystem"] = strtolower($safe);
  }

  function app_logger_default_subsystem(): string {
    $current = isset($GLOBALS["app_logger_default_subsystem"])
      ? trim((string)$GLOBALS["app_logger_default_subsystem"])
      : "";
    if ($current !== "") return $current;
    return app_logger_detect_subsystem("app");
  }

  function app_logger_request_context(): array {
    static $context = null;
    if (is_array($context)) {
      return $context;
    }

    $adminId = null;
    $adminName = "";
    $adminRole = "";
    if (session_status() === PHP_SESSION_ACTIVE) {
      $adminId = isset($_SESSION["admin_id"]) ? (int)$_SESSION["admin_id"] : null;
      if (is_int($adminId) && $adminId <= 0) $adminId = null;
      $adminName = trim((string)($_SESSION["admin_full_name"] ?? $_SESSION["admin_username"] ?? ""));
      $adminRole = trim((string)($_SESSION["admin_role"] ?? ""));
    }

    $context = [
      "requestId" => app_logger_request_id(),
      "method" => trim((string)($_SERVER["REQUEST_METHOD"] ?? "")),
      "uri" => app_logger_trim_text((string)($_SERVER["REQUEST_URI"] ?? ""), 600),
      "script" => app_logger_trim_text(str_replace("\\", "/", (string)($_SERVER["SCRIPT_NAME"] ?? "")), 240),
      "host" => app_logger_trim_text((string)($_SERVER["HTTP_HOST"] ?? ""), 160),
      "ip" => app_logger_trim_text((string)($_SERVER["REMOTE_ADDR"] ?? ""), 80),
      "userAgent" => app_logger_trim_text((string)($_SERVER["HTTP_USER_AGENT"] ?? ""), 600),
      "adminId" => $adminId,
      "adminName" => app_logger_trim_text($adminName, 120),
      "adminRole" => app_logger_trim_text($adminRole, 60),
    ];

    return $context;
  }

  function app_logger_normalize_level(string $level): string {
    $normalized = strtolower(trim($level));
    if (!in_array($normalized, ["debug", "info", "warning", "error", "critical"], true)) {
      return "info";
    }
    return $normalized;
  }

  function app_logger_is_json_request(): bool {
    $accept = strtolower((string)($_SERVER["HTTP_ACCEPT"] ?? ""));
    $contentType = strtolower((string)($_SERVER["CONTENT_TYPE"] ?? ""));
    $script = str_replace("\\", "/", (string)($_SERVER["SCRIPT_NAME"] ?? ""));
    if (strpos($accept, "application/json") !== false) return true;
    if (strpos($contentType, "application/json") !== false) return true;
    if (strpos($script, "/api/") !== false) return true;
    if (isset($_GET["action"])) return true;
    return false;
  }

  function app_logger_error_level(int $severity): ?string {
    if (in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
      return "error";
    }
    if (in_array($severity, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING], true)) {
      return "warning";
    }
    if (in_array($severity, [E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED], true)) {
      return null;
    }
    return "warning";
  }

  function app_logger_ensure_log_dir(): bool {
    $dir = app_logger_log_dir();
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0775, true) || is_dir($dir);
  }

  function app_logger_record_path(?string $date = null): string {
    $stamp = preg_replace('/[^0-9-]/', "", (string)$date);
    if (!is_string($stamp) || $stamp === "") {
      $stamp = date("Y-m-d");
    }
    return app_logger_log_dir() . "/app-" . $stamp . ".log";
  }

  function app_logger_write_record(array $record): bool {
    if (!app_logger_ensure_log_dir()) {
      return false;
    }

    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === "") {
      return false;
    }

    $path = app_logger_record_path();
    return @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
  }

  function app_log(string $level, string $message, array $context = [], array $options = []): bool {
    $subsystem = trim((string)($options["subsystem"] ?? app_logger_default_subsystem()));
    if ($subsystem === "") $subsystem = app_logger_default_subsystem();

    $record = [
      "ts" => date("c"),
      "level" => app_logger_normalize_level($level),
      "subsystem" => strtolower(preg_replace('/[^a-z0-9_.-]+/i', "_", $subsystem)),
      "message" => app_logger_trim_text($message, 4000),
      "request" => app_logger_request_context(),
      "context" => app_logger_safe_value($context),
    ];

    if (isset($options["event"])) {
      $record["event"] = app_logger_trim_text((string)$options["event"], 120);
    }

    return app_logger_write_record($record);
  }

  function app_log_exception(Throwable $error, array $context = [], array $options = []): bool {
    $traceLines = preg_split('/\r\n|\r|\n/', $error->getTraceAsString()) ?: [];
    $tracePreview = array_slice($traceLines, 0, 20);
    $context["exception"] = [
      "class" => get_class($error),
      "message" => $error->getMessage(),
      "file" => str_replace("\\", "/", $error->getFile()),
      "line" => $error->getLine(),
      "trace" => $tracePreview,
    ];

    $message = $options["message"] ?? ("Unhandled exception: " . get_class($error));
    return app_log($options["level"] ?? "error", $message, $context, $options);
  }

  function app_log_http_problem(int $status, string $message, array $context = [], array $options = []): bool {
    $level = $status >= 500 ? "error" : "warning";
    $context["status"] = $status;
    return app_log($level, $message, $context, $options);
  }

  function app_logger_output_fallback_error(): void {
    if (PHP_SAPI === "cli") {
      @fwrite(STDERR, "Internal server error [" . app_logger_request_id() . "]" . PHP_EOL);
      return;
    }

    if (headers_sent()) {
      return;
    }

    http_response_code(500);
    if (app_logger_is_json_request()) {
      header("Content-Type: application/json; charset=utf-8");
      echo json_encode([
        "ok" => false,
        "error" => "Internal server error",
        "requestId" => app_logger_request_id(),
      ], JSON_PRETTY_PRINT);
      return;
    }

    header("Content-Type: text/plain; charset=utf-8");
    echo "Internal server error [" . app_logger_request_id() . "]";
  }

  function app_logger_bootstrap(array $options = []): void {
    static $bootstrapped = false;

    if (!empty($options["subsystem"])) {
      app_logger_set_default_subsystem((string)$options["subsystem"]);
    }

    if ($bootstrapped) {
      return;
    }
    $bootstrapped = true;

    set_error_handler(static function (int $severity, string $message, string $file = "", int $line = 0): bool {
      if (!(error_reporting() & $severity)) {
        return false;
      }

      $level = app_logger_error_level($severity);
      if ($level === null) {
        return false;
      }

      app_log($level, "PHP runtime warning", [
        "phpError" => [
          "severity" => $severity,
          "message" => $message,
          "file" => str_replace("\\", "/", $file),
          "line" => $line,
        ],
      ], [
        "subsystem" => app_logger_default_subsystem(),
        "event" => "php_error",
      ]);

      return false;
    });

    set_exception_handler(static function (Throwable $error): void {
      app_log_exception($error, [], [
        "subsystem" => app_logger_default_subsystem(),
        "event" => "uncaught_exception",
        "message" => "Uncaught exception",
        "level" => "critical",
      ]);
      app_logger_output_fallback_error();
      exit(1);
    });

    register_shutdown_function(static function (): void {
      $lastError = error_get_last();
      if (!is_array($lastError)) return;

      $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
      if (!in_array((int)($lastError["type"] ?? 0), $fatalTypes, true)) {
        return;
      }

      app_log("critical", "Fatal shutdown error", [
        "phpFatal" => [
          "type" => (int)($lastError["type"] ?? 0),
          "message" => (string)($lastError["message"] ?? ""),
          "file" => str_replace("\\", "/", (string)($lastError["file"] ?? "")),
          "line" => (int)($lastError["line"] ?? 0),
        ],
      ], [
        "subsystem" => app_logger_default_subsystem(),
        "event" => "fatal_shutdown",
      ]);
    });
  }

  function app_logger_client_bootstrap(array $options = []): string {
    $endpoint = isset($options["endpoint"]) && is_string($options["endpoint"]) && $options["endpoint"] !== ""
      ? $options["endpoint"]
      : "../api/client_error_log.php";
    $scriptSrc = isset($options["scriptSrc"]) && is_string($options["scriptSrc"]) && $options["scriptSrc"] !== ""
      ? $options["scriptSrc"]
      : "../js/app-error-tracker.js";
    $config = [
      "endpoint" => $endpoint,
      "subsystem" => $options["subsystem"] ?? app_logger_default_subsystem(),
      "page" => $options["page"] ?? basename((string)($_SERVER["SCRIPT_NAME"] ?? "")),
      "requestId" => app_logger_request_id(),
    ];
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return "";
    return '<script>window.TNTS_ERROR_TRACKER = Object.assign({}, window.TNTS_ERROR_TRACKER || {}, ' . $json . ');</script>' . PHP_EOL
      . '<script src="' . htmlspecialchars($scriptSrc, ENT_QUOTES, "UTF-8") . '" defer></script>';
  }

  function app_logger_is_same_origin_request(): bool {
    $origin = trim((string)($_SERVER["HTTP_ORIGIN"] ?? ""));
    $referer = trim((string)($_SERVER["HTTP_REFERER"] ?? ""));
    $host = trim((string)($_SERVER["HTTP_HOST"] ?? ""));
    if ($host === "") return false;

    $match = static function (string $url, string $host): bool {
      if ($url === "") return false;
      $parts = parse_url($url);
      if (!is_array($parts) || empty($parts["host"])) return false;
      $requestHost = strtolower((string)$parts["host"]);
      $serverHost = strtolower((string)preg_replace('/:\d+$/', "", $host));
      return $requestHost === $serverHost;
    };

    if ($origin !== "") return $match($origin, $host);
    if ($referer !== "") return $match($referer, $host);
    return true;
  }

  function app_logger_read_entries(array $filters = [], int $limit = 200): array {
    $dir = app_logger_log_dir();
    if (!is_dir($dir)) return [];

    $files = glob($dir . "/app-*.log");
    if (!is_array($files) || !count($files)) return [];
    rsort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $dateFilter = trim((string)($filters["date"] ?? ""));
    $levelFilter = strtolower(trim((string)($filters["level"] ?? "")));
    $subsystemFilter = strtolower(trim((string)($filters["subsystem"] ?? "")));
    $queryFilter = mb_strtolower(trim((string)($filters["q"] ?? "")));
    $entries = [];

    foreach ($files as $file) {
      $base = basename($file);
      if ($dateFilter !== "" && $base !== "app-" . $dateFilter . ".log") {
        continue;
      }

      $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!is_array($lines)) continue;

      for ($i = count($lines) - 1; $i >= 0; $i--) {
        $row = json_decode((string)$lines[$i], true);
        if (!is_array($row)) continue;

        $level = strtolower(trim((string)($row["level"] ?? "")));
        $subsystem = strtolower(trim((string)($row["subsystem"] ?? "")));
        $message = trim((string)($row["message"] ?? ""));
        $contextText = mb_strtolower(json_encode($row["context"] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: "");

        if ($levelFilter !== "" && $level !== $levelFilter) continue;
        if ($subsystemFilter !== "" && $subsystem !== $subsystemFilter) continue;
        if ($queryFilter !== "") {
          $haystack = mb_strtolower($message . " " . $subsystem . " " . $contextText);
          if (strpos($haystack, $queryFilter) === false) continue;
        }

        $row["file"] = $base;
        $entries[] = $row;
        if (count($entries) >= $limit) {
          return $entries;
        }
      }
    }

    return $entries;
  }

  function app_logger_list_dates(): array {
    $dir = app_logger_log_dir();
    if (!is_dir($dir)) return [];
    $files = glob($dir . "/app-*.log");
    if (!is_array($files)) return [];
    $dates = [];
    foreach ($files as $file) {
      $base = basename($file);
      if (preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/', $base, $m)) {
        $dates[] = $m[1];
      }
    }
    rsort($dates, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($dates));
  }
}
