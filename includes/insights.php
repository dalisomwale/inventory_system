<?php
/**
 * Dashboard insights: the numbers behind the dashboards, plus small
 * server-rendered charts (no JavaScript chart library needed, so they
 * work on the LAN without internet).
 */

/** Total value of stock on hand. */
function ins_stock_value(PDO $pdo): float {
    return (float)$pdo->query("SELECT COALESCE(SUM(current_quantity * unit_price), 0) FROM items")->fetchColumn();
}

/** Units issued in the last $days days, and in the $days before that. */
function ins_issued_compare(PDO $pdo, int $days = 30): array {
    $st = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN created_at >= NOW() - INTERVAL ? DAY THEN quantity END), 0) AS cur,
          COALESCE(SUM(CASE WHEN created_at <  NOW() - INTERVAL ? DAY
                             AND created_at >= NOW() - INTERVAL ? DAY THEN quantity END), 0) AS prev
        FROM stock_issuing WHERE created_at >= NOW() - INTERVAL ? DAY");
    $st->execute([$days, $days, $days * 2, $days * 2]);
    $r = $st->fetch();
    $cur = (int)$r['cur']; $prev = (int)$r['prev'];
    $pct = $prev > 0 ? round(($cur - $prev) / $prev * 100) : null;
    return ['current' => $cur, 'previous' => $prev, 'change_pct' => $pct];
}

/**
 * Every item with how fast it is being used and how long the stock will last.
 * days_left is null when the item hasn't been issued in the last 30 days.
 */
function ins_item_forecast(PDO $pdo, int $window = 30): array {
    $st = $pdo->prepare("
        SELECT i.id, i.item_name, i.category, i.category_id, i.current_quantity, i.reorder_level, i.unit_price,
               COALESCE(u.used, 0) AS used
        FROM items i
        LEFT JOIN (
            SELECT item_id, SUM(quantity) AS used
            FROM stock_issuing
            WHERE created_at >= NOW() - INTERVAL ? DAY
            GROUP BY item_id
        ) u ON u.item_id = i.id");
    $st->execute([$window]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $per_day   = $r['used'] / $window;
        $days_left = $per_day > 0 ? (int)floor($r['current_quantity'] / $per_day) : null;
        // Aim to hold a month of usage on top of the reorder level
        $target    = max((int)$r['reorder_level'] * 2, (int)ceil($per_day * 30) + (int)$r['reorder_level']);
        $r['per_day']   = $per_day;
        $r['days_left'] = $days_left;
        $r['suggest']   = max(0, $target - (int)$r['current_quantity']);
        $r['below']     = $r['current_quantity'] <= $r['reorder_level'];
        $out[] = $r;
    }
    return $out;
}

/** Items that are below reorder level or will run out within $days. Most urgent first. */
function ins_running_low(array $forecast, int $days = 14): array {
    $list = array_filter($forecast, fn($r) => $r['below'] || ($r['days_left'] !== null && $r['days_left'] <= $days));
    usort($list, function ($a, $b) {
        $ka = $a['current_quantity'] == 0 ? -1 : ($a['days_left'] ?? 9999);
        $kb = $b['current_quantity'] == 0 ? -1 : ($b['days_left'] ?? 9999);
        return $ka <=> $kb ?: $a['current_quantity'] <=> $b['current_quantity'];
    });
    return array_values($list);
}

/** Units received and issued per week for the last $weeks weeks (Monday-start). */
function ins_weekly_movement(PDO $pdo, int $weeks = 8): array {
    $start = new DateTime('monday this week');
    $start->modify('-' . ($weeks - 1) . ' weeks');
    $rows = [];
    for ($i = 0; $i < $weeks; $i++) {
        $d = (clone $start)->modify("+$i weeks");
        $rows[$d->format('Y-m-d')] = ['week' => $d, 'received' => 0, 'issued' => 0];
    }
    $from = $start->format('Y-m-d');
    $sql = "SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS wk, SUM(quantity) AS q
            FROM %s WHERE created_at >= ? GROUP BY wk";
    foreach (['received' => 'stock_receiving', 'issued' => 'stock_issuing'] as $key => $table) {
        $st = $pdo->prepare(sprintf($sql, $table));
        $st->execute([$from]);
        foreach ($st->fetchAll() as $r) {
            if (isset($rows[$r['wk']])) $rows[$r['wk']][$key] = (int)$r['q'];
        }
    }
    return array_values($rows);
}

/** Stock value per category, largest first. */
function ins_value_by_category(PDO $pdo): array {
    return $pdo->query("
        SELECT COALESCE(c.name, i.category, 'Other') AS label,
               SUM(i.current_quantity * i.unit_price) AS value,
               COUNT(*) AS items
        FROM items i LEFT JOIN categories c ON c.id = i.category_id
        GROUP BY label HAVING value > 0
        ORDER BY value DESC")->fetchAll();
}

/** Most-issued items in the last $days days. */
function ins_top_issued(PDO $pdo, int $days = 30, int $limit = 6): array {
    $st = $pdo->prepare("
        SELECT i.item_name AS label, SUM(s.quantity) AS value, COUNT(*) AS times
        FROM stock_issuing s JOIN items i ON i.id = s.item_id
        WHERE s.created_at >= NOW() - INTERVAL ? DAY
        GROUP BY i.id, i.item_name ORDER BY value DESC LIMIT " . (int)$limit);
    $st->execute([$days]);
    return $st->fetchAll();
}

/** Who stock is issued to (departments/people) in the last $days days. */
function ins_top_recipients(PDO $pdo, int $days = 30, int $limit = 6): array {
    $st = $pdo->prepare("
        SELECT TRIM(issued_to) AS label, SUM(quantity) AS value, COUNT(*) AS times,
               SUM(s.quantity * i.unit_price) AS cost
        FROM stock_issuing s JOIN items i ON i.id = s.item_id
        WHERE s.created_at >= NOW() - INTERVAL ? DAY AND TRIM(COALESCE(issued_to,'')) <> ''
        GROUP BY TRIM(issued_to) ORDER BY cost DESC, value DESC LIMIT " . (int)$limit);
    $st->execute([$days]);
    return $st->fetchAll();
}

/** Items holding stock that nobody has issued in $days days. */
function ins_idle_items(PDO $pdo, int $days = 60, int $limit = 6): array {
    $st = $pdo->prepare("
        SELECT i.id, i.item_name, i.category, i.current_quantity,
               i.current_quantity * i.unit_price AS value,
               MAX(s.created_at) AS last_issued
        FROM items i
        LEFT JOIN stock_issuing s ON s.item_id = i.id
        WHERE i.current_quantity > 0 AND i.created_at < NOW() - INTERVAL ? DAY
        GROUP BY i.id
        HAVING last_issued IS NULL OR last_issued < NOW() - INTERVAL ? DAY
        ORDER BY value DESC LIMIT " . (int)$limit);
    $st->execute([$days, $days]);
    return $st->fetchAll();
}

/** Latest manual stock adjustments, with who made them. */
function ins_recent_adjustments(PDO $pdo, int $limit = 5): array {
    return $pdo->query("
        SELECT a.created_at, a.quantity, a.details, u.full_name, i.item_name
        FROM audit_trail a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN items i ON i.id = a.item_id
        WHERE a.action = 'ADJUST_STOCK'
        ORDER BY a.created_at DESC LIMIT " . (int)$limit)->fetchAll();
}

/** Days since the oldest pending procurement request was made. */
function ins_oldest_pending_days(PDO $pdo): ?int {
    $d = $pdo->query("SELECT DATEDIFF(NOW(), MIN(created_at)) FROM procurement WHERE status='pending'")->fetchColumn();
    return $d === null || $d === false ? null : (int)$d;
}

// ── Formatting ───────────────────────────────────────────────

function kwacha(float $n, bool $short = false): string {
    if ($short && $n >= 1000000) return 'K' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($short && $n >= 10000)   return 'K' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
    return 'K' . number_format($n, 2);
}

function kwacha_whole(float $n): string {
    return 'K' . number_format($n, 0);
}

function days_left_label(?int $d, int $qty): string {
    if ($qty <= 0)   return 'Out of stock';
    if ($d === null) return 'Not used lately';
    if ($d === 0)    return 'Less than a day';
    if ($d === 1)    return '1 day';
    if ($d < 14)     return $d . ' days';
    if ($d < 60)     return round($d / 7) . ' weeks';
    return 'Over 2 months';
}

// ── Charts ───────────────────────────────────────────────────

/**
 * Horizontal ranked bars, one series. Each row: label, bar, value.
 * $fmt formats the value shown at the end of the bar.
 */
function chart_hbars(array $rows, callable $fmt, string $tipUnit = ''): string {
    if (!$rows) return '';
    $max = max(array_map(fn($r) => (float)$r['value'], $rows)) ?: 1;
    $h = '<div class="hbars" role="list">';
    foreach ($rows as $r) {
        $pct  = max(1.5, round((float)$r['value'] / $max * 100, 1));
        $tip  = htmlspecialchars($r['label']) . ': ' . htmlspecialchars($fmt($r['value']))
              . (isset($r['times']) ? ' &middot; ' . (int)$r['times'] . ' ' . ($r['times'] == 1 ? 'time' : 'times') : '')
              . (isset($r['items']) ? ' &middot; ' . (int)$r['items'] . ' items' : '');
        $h .= '<div class="hbar-row" role="listitem" tabindex="0" data-tip="' . $tip . '">'
            . '<div class="hbar-label">' . htmlspecialchars($r['label']) . '</div>'
            . '<div class="hbar-track"><div class="hbar-fill" style="width:' . $pct . '%"></div></div>'
            . '<div class="hbar-value">' . htmlspecialchars($fmt($r['value'])) . '</div>'
            . '</div>';
    }
    return $h . '</div>';
}

/** Weekly received vs issued: grouped columns on one axis (both are units). */
function chart_weekly(array $weeks): string {
    $W = 640; $H = 220; $padL = 36; $padR = 8; $padT = 10; $padB = 26;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $max = 0;
    foreach ($weeks as $w) $max = max($max, $w['received'], $w['issued']);
    // round the axis up to a tidy number
    $step = $max <= 0 ? 1 : pow(10, floor(log10($max)));
    foreach ([1, 2, 2.5, 5, 10] as $m) { if ($max <= $step * $m) { $top = $step * $m; break; } }
    $top = max($top ?? 1, 1);

    $n = count($weeks); $slot = $plotW / $n; $bw = min(16, ($slot - 14) / 2); $gap = 2;
    $y = fn($v) => $padT + $plotH - ($v / $top * $plotH);

    $s = '<svg class="chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Units received and issued per week, last ' . $n . ' weeks">';
    // gridlines + y labels (0, half, top)
    foreach ([0, $top / 2, $top] as $t) {
        $yy = round($y($t), 1);
        $s .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid' . ($t == 0 ? ' base' : '') . '"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . ($yy + 3.5) . '" class="axis" text-anchor="end">' . number_format($t) . '</text>';
    }
    foreach ($weeks as $i => $w) {
        $cx = $padL + $slot * $i + $slot / 2;
        $label = $w['week']->format('j M');
        $tip = 'Week of ' . $label . ' &middot; Received ' . number_format($w['received']) . ' &middot; Issued ' . number_format($w['issued']);
        $s .= '<g class="col-group" tabindex="0" data-tip="' . $tip . '">';
        $s .= '<rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        foreach (['received' => -1, 'issued' => 1] as $k => $side) {
            $v = $w[$k];
            $x = $side < 0 ? $cx - $gap / 2 - $bw : $cx + $gap / 2;
            $hgt = $v > 0 ? max(2, $plotH - ($y($v) - $padT)) : 0;
            if ($hgt > 0) {
                // rounded top, square bottom on the baseline
                $x0 = round($x, 1); $x1 = round($x + $bw, 1); $yb = $padT + $plotH; $yt = round($yb - $hgt, 1); $r = min(4, $bw / 2, $hgt);
                $s .= '<path class="bar ' . $k . '" d="M' . $x0 . ',' . $yb . ' V' . ($yt + $r) . ' Q' . $x0 . ',' . $yt . ' ' . ($x0 + $r) . ',' . $yt
                    . ' H' . ($x1 - $r) . ' Q' . $x1 . ',' . $yt . ' ' . $x1 . ',' . ($yt + $r) . ' V' . $yb . ' Z"/>';
            }
        }
        $s .= '<text x="' . round($cx, 1) . '" y="' . ($H - 8) . '" class="axis" text-anchor="middle">' . $label . '</text>';
        $s .= '</g>';
    }
    return $s . '</svg>';
}
