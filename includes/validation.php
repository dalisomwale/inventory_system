<?php
/**
 * Input validation and form-security helpers.
 *
 * Usage in a page:
 *   $v = new Validator($_POST);
 *   $name = $v->text('item_name', 'Item name', 200);
 *   $qty  = $v->int('quantity', 'Quantity', 1, 100000);
 *   if ($v->ok()) { ... save ... } else { $error = $v->message(); }
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── CSRF ─────────────────────────────────────────────────────
// Every POST must carry the session token. script.js adds it to
// each form automatically from the <meta name="csrf-token"> tag.

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_meta(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('<div style="font-family:sans-serif;text-align:center;margin-top:80px;color:#1c2333">
              <h2 style="color:#25398d">Your session has expired</h2>
              <p>The form could not be submitted. Please go back, refresh the page and try again.</p>
              <p><a href="javascript:history.back()" style="color:#25398d">Go back</a></p></div>');
    }
}

verify_csrf();

// ── VALIDATOR ────────────────────────────────────────────────

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data) { $this->data = $data; }

    public function ok(): bool          { return empty($this->errors); }
    public function errors(): array     { return $this->errors; }
    public function fail(string $msg): void { $this->errors[] = $msg; }

    /** All errors as one HTML-safe string for an alert box. */
    public function message(): string {
        $safe = array_map('htmlspecialchars', $this->errors);
        return count($safe) === 1 ? $safe[0] : 'Please fix the following:<br>&bull; ' . implode('<br>&bull; ', $safe);
    }

    private function raw(string $key): string {
        $v = $this->data[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    /** Text field. Collapses repeated spaces, rejects control characters. */
    public function text(string $key, string $label, int $max = 255, bool $required = true, int $min = 0): string {
        $v = preg_replace('/\s+/u', ' ', $this->raw($key));
        if ($v === '') {
            if ($required) $this->errors[] = "$label is required.";
            return '';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            $this->errors[] = "$label contains invalid characters.";
        } elseif (mb_strlen($v) < $min) {
            $this->errors[] = "$label must be at least $min characters.";
        } elseif (mb_strlen($v) > $max) {
            $this->errors[] = "$label must be $max characters or fewer.";
        }
        return $v;
    }

    /** Multi-line text (keeps line breaks). */
    public function textarea(string $key, string $label, int $max = 1000, bool $required = false, int $min = 0): string {
        $v = str_replace("\r\n", "\n", $this->raw($key));
        if ($v === '') {
            if ($required) $this->errors[] = "$label is required.";
            return '';
        }
        if (mb_strlen($v) < $min)     $this->errors[] = "$label must be at least $min characters.";
        elseif (mb_strlen($v) > $max) $this->errors[] = "$label must be $max characters or fewer.";
        return $v;
    }

    /** Whole number within a range. */
    public function int(string $key, string $label, int $min = 0, int $max = 1000000, bool $required = true): ?int {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) $this->errors[] = "$label is required.";
            return null;
        }
        if (!preg_match('/^-?\d+$/', $v)) {
            $this->errors[] = "$label must be a whole number.";
            return null;
        }
        $n = (int)$v;
        if ($n < $min)     $this->errors[] = "$label must be at least " . number_format($min) . ".";
        elseif ($n > $max) $this->errors[] = "$label cannot be more than " . number_format($max) . ".";
        return $n;
    }

    /** Money amount, up to 2 decimal places. */
    public function money(string $key, string $label, float $min = 0, float $max = 100000000, bool $required = true): ?float {
        $v = str_replace(',', '', $this->raw($key));
        if ($v === '') {
            if ($required) $this->errors[] = "$label is required.";
            return null;
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
            $this->errors[] = "$label must be an amount like 150 or 150.50.";
            return null;
        }
        $n = (float)$v;
        if ($n < $min)     $this->errors[] = "$label must be at least K" . number_format($min, 2) . ".";
        elseif ($n > $max) $this->errors[] = "$label cannot be more than K" . number_format($max, 2) . ".";
        return $n;
    }

    /** Date (YYYY-MM-DD). By default it cannot be in the future or more than a year back. */
    public function date(string $key, string $label, bool $allowFuture = false, int $maxDaysBack = 366): ?string {
        $v = $this->raw($key);
        if ($v === '') { $this->errors[] = "$label is required."; return null; }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) { $this->errors[] = "$label is not a valid date."; return null; }
        $today = new DateTime('today');
        if (!$allowFuture && $d > $today) { $this->errors[] = "$label cannot be in the future."; return null; }
        if ($today->diff($d)->days > $maxDaysBack && $d < $today) {
            $this->errors[] = "$label is too far in the past."; return null;
        }
        return $v;
    }

    /** Month (YYYY-MM), not in the future. */
    public function month(string $key, string $label): ?string {
        $v = $this->raw($key);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) { $this->errors[] = "$label is not a valid month."; return null; }
        if ($v > date('Y-m'))  { $this->errors[] = "$label cannot be in the future."; return null; }
        if ($v < '2000-01')    { $this->errors[] = "$label is not a valid month."; return null; }
        return $v;
    }

    /** Value must be one of a fixed list. */
    public function choice(string $key, string $label, array $allowed): ?string {
        $v = $this->raw($key);
        if (!in_array($v, $allowed, true)) { $this->errors[] = "Please choose a valid $label."; return null; }
        return $v;
    }

    /** ID that must exist in a table. Returns the row or null. */
    public function exists(PDO $pdo, string $key, string $label, string $table, string $cols = '*'): ?array {
        $id = $this->raw($key);
        if ($id === '' || !ctype_digit($id) || (int)$id <= 0) {
            $this->errors[] = "Please select a $label.";
            return null;
        }
        $allowed = ['items', 'categories', 'procurement', 'users'];
        if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Bad table');
        $st = $pdo->prepare("SELECT $cols FROM $table WHERE id = ?");
        $st->execute([(int)$id]);
        $row = $st->fetch();
        if (!$row) { $this->errors[] = "The selected $label no longer exists. Refresh the page and try again."; return null; }
        return $row;
    }
}

/** Month string from the query string, falling back to the current month. */
function safe_month(?string $v): string {
    return (is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) ? $v : date('Y-m');
}

/** Date string from the query string, falling back to a default. */
function safe_date(?string $v, string $default): string {
    if (!is_string($v)) return $default;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}
