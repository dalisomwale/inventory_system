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

// ── More insights ────────────────────────────────────────────

/** Item counts by stock health: in stock, low, out. */
function ins_stock_health(array $forecast): array {
    $ok = $low = $out = 0;
    foreach ($forecast as $r) {
        if ($r['current_quantity'] <= 0)                 $out++;
        elseif ($r['current_quantity'] <= $r['reorder_level']) $low++;
        else                                             $ok++;
    }
    return ['ok' => $ok, 'low' => $low, 'out' => $out];
}

/** Units issued per day for the last $days days (oldest first, zero-filled). */
function ins_daily_issued(PDO $pdo, int $days = 30): array {
    $st = $pdo->prepare("SELECT DATE(created_at) d, SUM(quantity) q FROM stock_issuing
                         WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY d");
    $st->execute([$days - 1]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['d']] = (int)$r['q'];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = new DateTime("today -$i days");
        $out[] = ['date' => $d, 'value' => $map[$d->format('Y-m-d')] ?? 0];
    }
    // 7-day rolling average
    foreach ($out as $i => &$row) {
        $win = array_slice($out, max(0, $i - 6), min(7, $i + 1));
        $row['avg'] = array_sum(array_column($win, 'value')) / count($win);
    }
    return $out;
}

/** Units and value issued per category in the last $days days. */
function ins_issued_by_category(PDO $pdo, int $days = 30): array {
    $st = $pdo->prepare("
        SELECT COALESCE(c.name, i.category, 'Other') AS label, SUM(s.quantity) AS value,
               SUM(s.quantity * i.unit_price) AS cost
        FROM stock_issuing s JOIN items i ON i.id = s.item_id
        LEFT JOIN categories c ON c.id = i.category_id
        WHERE s.created_at >= NOW() - INTERVAL ? DAY
        GROUP BY label ORDER BY value DESC");
    $st->execute([$days]);
    return $st->fetchAll();
}

/** Number of issue transactions per weekday over the last $days days (Mon..Sun). */
function ins_busiest_days(PDO $pdo, int $days = 90): array {
    $st = $pdo->prepare("SELECT WEEKDAY(created_at) wd, COUNT(*) n, SUM(quantity) q FROM stock_issuing
                         WHERE created_at >= NOW() - INTERVAL ? DAY GROUP BY wd");
    $st->execute([$days]);
    $names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $out = [];
    foreach ($names as $i => $n) $out[$i] = ['label' => $n, 'value' => 0, 'units' => 0];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['wd']]['value'] = (int)$r['n']; $out[(int)$r['wd']]['units'] = (int)$r['q']; }
    // Hide the weekend if nothing ever happens then
    if ($out[5]['value'] + $out[6]['value'] === 0) unset($out[5], $out[6]);
    return array_values($out);
}

/** Group rows into the top $n plus "Other" for a donut. */
function top_n_other(array $rows, int $n = 5): array {
    if (count($rows) <= $n + 1) return $rows;
    $top = array_slice($rows, 0, $n);
    $rest = array_sum(array_map(fn($r) => (float)$r['value'], array_slice($rows, $n)));
    $top[] = ['label' => 'Other', 'value' => $rest, 'other' => true];
    return $top;
}

// ── More charts ──────────────────────────────────────────────

/** Validated categorical order (brand navy, brand orange, then aqua, yellow, pink, green). */
function series_colors(): array {
    return ['#3a54b4', '#d0700c', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
}

/**
 * Donut chart with a legend that always shows label, value and percent.
 * $segments: [['label'=>..., 'value'=>..., 'color'=>optional, 'icon'=>optional]]
 */
function chart_donut(array $segments, string $centerValue, string $centerLabel, callable $fmt): string {
    $segments = array_values(array_filter($segments, fn($s) => (float)$s['value'] > 0));
    $total = array_sum(array_map(fn($s) => (float)$s['value'], $segments));
    if ($total <= 0) return '<div class="empty-state"><p>No data yet.</p></div>';
    $colors = series_colors();
    $R = 70; $r = 46; $cx = 90; $cy = 90; $gapDeg = count($segments) > 1 ? 1.6 : 0;
    $pt = fn($rad, $deg) => [round($cx + $rad * cos(deg2rad($deg - 90)), 2), round($cy + $rad * sin(deg2rad($deg - 90)), 2)];

    $svg = '<svg class="donut" viewBox="0 0 180 180" role="img" aria-label="' . htmlspecialchars($centerLabel) . '">';
    $legend = '<ul class="donut-legend">';
    $start = 0;
    foreach ($segments as $i => $s) {
        $color = $s['color'] ?? (!empty($s['other']) ? '#a3a9b8' : $colors[$i % count($colors)]);
        $pct   = (float)$s['value'] / $total * 100;
        $sweep = $pct / 100 * 360;
        $a0 = $start + $gapDeg / 2; $a1 = $start + $sweep - $gapDeg / 2;
        if ($sweep >= 359.9) {
            $path = 'M' . ($cx) . ',' . ($cy - $R) . ' A' . $R . ',' . $R . ' 0 1,1 ' . ($cx - 0.01) . ',' . ($cy - $R)
                  . ' L' . ($cx - 0.01) . ',' . ($cy - $r) . ' A' . $r . ',' . $r . ' 0 1,0 ' . $cx . ',' . ($cy - $r) . ' Z';
        } else {
            $large = ($a1 - $a0) > 180 ? 1 : 0;
            [$x0, $y0] = $pt($R, $a0); [$x1, $y1] = $pt($R, $a1);
            [$x2, $y2] = $pt($r, $a1); [$x3, $y3] = $pt($r, $a0);
            $path = "M$x0,$y0 A$R,$R 0 $large,1 $x1,$y1 L$x2,$y2 A$r,$r 0 $large,0 $x3,$y3 Z";
        }
        $label = htmlspecialchars($s['label']);
        $tip   = $label . ': ' . htmlspecialchars($fmt($s['value'])) . ' (' . round($pct) . '%)';
        $svg  .= '<path d="' . $path . '" fill="' . $color . '" class="slice" tabindex="0" data-tip="' . $tip . '"/>';
        $legend .= '<li data-tip="' . $tip . '"><i class="swatch" style="background:' . $color . '"></i>'
                 . (!empty($s['icon']) ? '<span class="lg-icon">' . $s['icon'] . '</span>' : '')
                 . '<span class="lg-label">' . $label . '</span>'
                 . '<span class="lg-value">' . htmlspecialchars($fmt($s['value'])) . '</span>'
                 . '<span class="lg-pct">' . round($pct) . '%</span></li>';
        $start += $sweep;
    }
    $svg .= '<text x="90" y="88" text-anchor="middle" class="donut-num">' . htmlspecialchars($centerValue) . '</text>';
    $svg .= '<text x="90" y="106" text-anchor="middle" class="donut-sub">' . htmlspecialchars($centerLabel) . '</text>';
    $svg .= '</svg>';
    return '<div class="donut-wrap">' . $svg . $legend . '</ul></div>';
}

/** Line graph: daily units issued plus 7-day average, one axis. */
function chart_line(array $days): string {
    $W = 640; $H = 200; $padL = 36; $padR = 10; $padT = 12; $padB = 26;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $max = max(1, max(array_column($days, 'value')));
    $step = pow(10, floor(log10($max)));
    foreach ([1, 2, 2.5, 5, 10] as $m) { if ($max <= $step * $m) { $top = $step * $m; break; } }
    $n = count($days);
    $x = fn($i) => round($padL + ($n > 1 ? $i / ($n - 1) : .5) * $plotW, 1);
    $y = fn($v) => round($padT + $plotH - $v / $top * $plotH, 1);

    $s = '<svg class="chart-svg line-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Units issued per day, last ' . $n . ' days">';
    foreach ([0, $top / 2, $top] as $t) {
        $yy = $y($t);
        $s .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid' . ($t == 0 ? ' base' : '') . '"/>';
        $s .= '<text x="' . ($padL - 6) . '" y="' . ($yy + 3.5) . '" class="axis" text-anchor="end">' . number_format($t) . '</text>';
    }
    $daily = $avg = [];
    foreach ($days as $i => $d) { $daily[] = $x($i) . ',' . $y($d['value']); $avg[] = $x($i) . ',' . $y($d['avg']); }
    $s .= '<polygon class="area" points="' . $x(0) . ',' . $y(0) . ' ' . implode(' ', $daily) . ' ' . $x($n - 1) . ',' . $y(0) . '"/>';
    $s .= '<polyline class="line daily" points="' . implode(' ', $daily) . '"/>';
    $s .= '<polyline class="line avg" points="' . implode(' ', $avg) . '"/>';
    // x labels every 7 days, hover columns for every day
    $colW = $plotW / max(1, $n - 1);
    foreach ($days as $i => $d) {
        $xx = $x($i);
        $tip = $d['date']->format('D j M') . ' · ' . number_format($d['value']) . ' issued · 7-day avg ' . number_format($d['avg'], 1);
        $s .= '<g class="pt" tabindex="0" data-tip="' . $tip . '">'
            . '<rect class="hit" x="' . round($xx - $colW / 2, 1) . '" y="' . $padT . '" width="' . round($colW, 1) . '" height="' . $plotH . '"/>'
            . '<line class="cross" x1="' . $xx . '" x2="' . $xx . '" y1="' . $padT . '" y2="' . ($padT + $plotH) . '"/>'
            . '<circle class="dot" cx="' . $xx . '" cy="' . $y($d['value']) . '" r="4"/></g>';
        if (($n - 1 - $i) % 7 === 0) {
            $s .= '<text x="' . $xx . '" y="' . ($H - 8) . '" class="axis" text-anchor="middle">' . $d['date']->format('j M') . '</text>';
        }
    }
    return $s . '</svg>';
}

/** Vertical single-series columns (e.g. weekdays). */
function chart_columns(array $rows, callable $tipFmt): string {
    $W = 320; $H = 180; $padL = 8; $padR = 8; $padT = 18; $padB = 24;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $max = max(1, max(array_column($rows, 'value')));
    $n = count($rows); $slot = $plotW / $n; $bw = min(30, $slot - 12);
    $s = '<svg class="chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img">';
    $s .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . ($padT + $plotH) . '" y2="' . ($padT + $plotH) . '" class="grid base"/>';
    foreach ($rows as $i => $r) {
        $cx = $padL + $slot * $i + $slot / 2;
        $h = $r['value'] > 0 ? max(2, $r['value'] / $max * $plotH) : 0;
        $x0 = round($cx - $bw / 2, 1); $x1 = round($cx + $bw / 2, 1); $yb = $padT + $plotH; $yt = round($yb - $h, 1); $rr = min(4, $h);
        $s .= '<g class="col-group" tabindex="0" data-tip="' . htmlspecialchars($tipFmt($r)) . '">'
            . '<rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        if ($h > 0) {
            $s .= '<path class="bar received" d="M' . $x0 . ',' . $yb . ' V' . ($yt + $rr) . ' Q' . $x0 . ',' . $yt . ' ' . ($x0 + $rr) . ',' . $yt
                . ' H' . ($x1 - $rr) . ' Q' . $x1 . ',' . $yt . ' ' . $x1 . ',' . ($yt + $rr) . ' V' . $yb . ' Z"/>';
        }
        if ($r['value'] == $max) {
            $s .= '<text x="' . round($cx, 1) . '" y="' . ($yt - 5) . '" class="axis val" text-anchor="middle">' . number_format($r['value']) . '</text>';
        }
        $s .= '<text x="' . round($cx, 1) . '" y="' . ($H - 7) . '" class="axis" text-anchor="middle">' . $r['label'] . '</text></g>';
    }
    return $s . '</svg>';
}

/** Small icon used on row action buttons. */
function row_icon(string $name): string {
    $paths = [
        'view'   => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'edit'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'key'    => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2"/><path d="M16 7l3 3"/><path d="M14 9l2 2"/>',
        'lock'   => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'unlock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/>',
        'delete' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Big dashboard action tile. */
function action_tile(string $href, string $title, string $desc, string $icon, string $badge = ''): string {
    return '<a href="' . htmlspecialchars($href) . '" class="action-tile">'
         . '<span class="at-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg></span>'
         . '<span class="at-text"><span class="at-title">' . htmlspecialchars($title)
         . ($badge !== '' ? ' <span class="at-badge">' . htmlspecialchars($badge) . '</span>' : '')
         . '</span><span class="at-desc">' . htmlspecialchars($desc) . '</span></span>'
         . '</a>';
}

function icon(string $name): string {
    $i = [
        'receive'  => '<path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/>',
        'issue'    => '<path d="M12 21V9"/><polyline points="7 14 12 9 17 14"/><path d="M5 3h14"/>',
        'request'  => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L22 7H6"/>',
        'report'   => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/><line x1="8" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="13" y2="18"/>',
        'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'list'     => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    ];
    return $i[$name] ?? '';
}
