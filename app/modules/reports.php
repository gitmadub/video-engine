<?php

declare(strict_types=1);

function ve_dashboard_reports_rows_html(array $rows): string
{
    $htmlRows = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $htmlRows[] = sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            ve_h((string) ($row['date'] ?? '')),
            number_format((int) ($row['views'] ?? 0)),
            ve_h((string) ($row['profit'] ?? '$0.00000')),
            ve_h((string) ($row['referral_share'] ?? '$0.00000')),
            ve_h((string) ($row['traffic'] ?? '0 B'))
        );
    }

    if ($htmlRows === []) {
        return '<tr><td colspan="5" class="text-center text-muted">No report data is available for this range.</td></tr>';
    }

    return implode("\n", $htmlRows);
}

function ve_dashboard_reports_footer_html(array $totals): string
{
    return sprintf(
        '<tr><td>Total</td><td data-reports-footer-views>%s</td><td data-reports-footer-profit>%s</td><td data-reports-footer-referral>%s</td><td data-reports-footer-traffic>%s</td></tr>',
        number_format((int) ($totals['views'] ?? 0)),
        ve_h((string) ($totals['profit'] ?? '$0.00000')),
        ve_h((string) ($totals['referral_share'] ?? '$0.00000')),
        ve_h((string) ($totals['traffic'] ?? '0 B'))
    );
}

function ve_dashboard_reports_replace_data_content(string $html, string $attribute, string $content): string
{
    $quotedAttribute = preg_quote($attribute, '/');
    $pattern = '/(<(?P<tag>[A-Za-z0-9]+)\b(?=[^>]*\b' . $quotedAttribute . '\b)[^>]*>)[\s\S]*?(<\/(?P=tag)>)/i';

    return (string) (preg_replace_callback(
        $pattern,
        static fn (array $matches): string => $matches[1] . $content . $matches[3],
        $html,
        1
    ) ?? $html);
}

function ve_dashboard_reports_apply_snapshot(string $html, array $snapshot): string
{
    $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-total-views', ve_h((string) ($totals['views'] ?? 0)));
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-total-profit', ve_h((string) ($totals['profit'] ?? '$0.00000')));
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-total-referral', ve_h((string) ($totals['referral_share'] ?? '$0.00000')));
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-total-revenue', ve_h((string) ($totals['total'] ?? '$0.00000')));
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-total-traffic', ve_h((string) ($totals['traffic'] ?? '0 B')));
    $html = ve_dashboard_reports_replace_data_content($html, 'data-reports-updated', ve_h('Updated ' . gmdate('Y-m-d H:i:s') . ' UTC'));

    $rowsHtml = ve_dashboard_reports_rows_html((array) ($snapshot['rows'] ?? []));
    $footerHtml = ve_dashboard_reports_footer_html($totals);

    $html = preg_replace_callback(
        '/(<table id="datatable"[^>]*>[\s\S]*?<tbody\b[^>]*>)[\s\S]*?(<\/tbody>)/i',
        static fn (array $matches): string => $matches[1] . "\n" . $rowsHtml . "\n" . $matches[2],
        $html,
        1
    ) ?? $html;

    return preg_replace_callback(
        '/(<table id="datatable"[^>]*>[\s\S]*?<tfoot\b[^>]*>)[\s\S]*?(<\/tfoot>)/i',
        static fn (array $matches): string => $matches[1] . "\n" . $footerHtml . "\n" . $matches[2],
        $html,
        1
    ) ?? $html;
}

function ve_render_reports_page(): void
{
    $user = ve_require_auth();
    $from = isset($_GET['from']) ? (string) $_GET['from'] : (isset($_GET['date1']) ? (string) $_GET['date1'] : null);
    $to = isset($_GET['to']) ? (string) $_GET['to'] : (isset($_GET['date2']) ? (string) $_GET['date2'] : null);
    $snapshot = ve_dashboard_reports_snapshot((int) $user['id'], $from, $to);
    $range = is_array($snapshot['range'] ?? null) ? $snapshot['range'] : ['from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d')];
    $html = (string) file_get_contents(ve_root_path('dashboard', 'reports.html'));
    $html = ve_runtime_html_transform($html, 'dashboard/reports.html');
    $html = ve_html_set_input_value($html, 'from', (string) ($range['from'] ?? ''));
    $html = ve_html_set_input_value($html, 'to', (string) ($range['to'] ?? ''));
    $html = ve_dashboard_reports_apply_snapshot($html, $snapshot);

    ve_html(ve_rewrite_html_paths($html));
}
