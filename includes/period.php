<?php
/**
 * Time-period filter shared by the tables that can be exported to Excel:
 * Today, 7 days, 30 days, 3 months, 6 months, 1 year (each ending today), chosen with ?period=.
 *
 *   $pr = period_from_request('month');          // ['key' => 'month', 'from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => 'Last 30 days', 'range' => '…']
 *   echo period_tabs($pr['key'], '#recent');      // filter icon with a menu of periods, keeping the page's other filters
 */

const PERIODS = [
    'day'   => ['Today',    'Today'],
    'week'  => ['7 days',   'Last 7 days'],
    'month' => ['30 days',  'Last 30 days'],
    '3m'    => ['3 months', 'Last 3 months'],
    '6m'    => ['6 months', 'Last 6 months'],
    'year'  => ['1 year',   'Last 12 months'],
];

/** First and last day of a period that ends today. */
function period_range(string $key): array {
    $to = date('Y-m-d');
    $from = match ($key) {
        'day'   => $to,
        'week'  => date('Y-m-d', strtotime('-6 days')),
        'month' => date('Y-m-d', strtotime('-29 days')),
        '3m'    => date('Y-m-d', strtotime('-3 months +1 day')),
        '6m'    => date('Y-m-d', strtotime('-6 months +1 day')),
        'year'  => date('Y-m-d', strtotime('-1 year +1 day')),
    };
    return [$from, $to];
}

/**
 * The period asked for in the address (?period=), or $default when none is given.
 * Returns null when there is no period and no default (the page then uses its own dates).
 */
function period_from_request(?string $default = 'month'): ?array {
    $key = $_GET['period'] ?? $default;
    if (!is_string($key) || !isset(PERIODS[$key])) $key = $default;
    if ($key === null) return null;
    [$from, $to] = period_range($key);
    return ['key' => $key, 'from' => $from, 'to' => $to, 'label' => PERIODS[$key][1],
            'range' => $from === $to ? date('d M Y', strtotime($to)) : date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))];
}

/**
 * Filter-icon button showing the current period; clicking it opens a menu of the periods.
 * Keeps the page's other filters; $drop lists address parts to clear when a period is picked.
 */
function period_tabs(?string $current, string $anchor = '', array $drop = []): string {
    $keep = array_diff_key($_GET, array_flip(array_merge(['period', 'export'], $drop)));
    $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $now  = $current !== null ? PERIODS[$current][0] : 'Period';
    $h  = '<details class="period-filter"><summary class="period-btn" title="Filter by time period" aria-label="Filter by time period: ' . $now . '">'
        . '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>'
        . '<span>' . $now . '</span></summary><div class="period-menu" role="menu">';
    foreach (PERIODS as $key => [$short, $long]) {
        $href = $base . '?' . http_build_query($keep + ['period' => $key]) . $anchor;
        $h .= '<a role="menuitem" href="' . htmlspecialchars($href) . '"' . ($current === $key ? ' class="active" aria-current="true"' : '') . '>' . $long . '</a>';
    }
    return $h . '</div></details>';
}
