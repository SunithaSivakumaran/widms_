<?php

declare(strict_types=1);


/* =========================================================
   ACCESS CONTROL
   Both Admin and Subject Officer can access this page
   ========================================================= */

$allowedRoles = [
    'admin',
    'subject-officer'
];

$currentRole = (string) ($_SESSION['role'] ?? '');

if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('You do not have permission to access this page.');
}


/* =========================================================
   DATABASE
   ========================================================= */

require_once __DIR__ . '/../../config/database.php';


/* =========================================================
   PAGE SETTINGS
   ========================================================= */

$activePage = 'reports';

$type = is_string($_GET['report'] ?? null) ? $_GET['report'] : '';
$export = is_string($_GET['export'] ?? null) ? $_GET['export'] : '';


/* =========================================================
   REPORT DEFINITIONS
   ========================================================= */

$reports = [

    /* =====================================================
       INVENTORY REPORT
       ===================================================== */

    'inventory' => [

        'Inventory Report',

        'Current stock levels by item, disability type and variety',

        [
            'Item',
            'Disability Type',
            'Variety',
            'In Stock'
        ],

        "
        SELECT
            i.item_name AS Item,
            COALESCE((
                SELECT GROUP_CONCAT(DISTINCT dt.name ORDER BY dt.name SEPARATOR ', ')
                FROM disability_aid_items dai
                JOIN disability_types dt ON dt.id = dai.disability_type_id
                WHERE dai.item_id = i.id AND dai.status = 'active'
            ), 'Not configured') AS `Disability Type`,
            i.variety AS Variety,
            i.quantity AS `In Stock`

        FROM inventory_items i

        ORDER BY
            i.item_name,
            i.variety
        "
    ],


    /* =====================================================
       DISTRIBUTION REPORT
       ===================================================== */

    'distribution' => [

        'Distribution Report',

        'Recorded distributions with location and distributing officer',

        [
            'Reference',
            'Beneficiary',
            'Item',
            'Qty',
            'Type',
            'Source',
            'District',
            'DS Division',
            'Distributed By',
            'Date'
        ],

        "
        SELECT

            CONCAT(
                'DIST-',
                LPAD(d.id, 4, '0')
            ) AS Reference,

            b.full_name AS Beneficiary,

            CONCAT(
                i.item_name,
                IF(
                    i.variety = '',
                    '',
                    CONCAT(' / ', i.variety)
                )
            ) AS Item,

            d.quantity AS Qty,

            d.distribution_type AS Type,

            d.source AS Source,

            district.name AS District,

            division.name AS `DS Division`,

            u.full_name AS `Distributed By`,

            d.distributed_at AS Date

        FROM distributions d

        JOIN beneficiaries b
            ON b.id = d.beneficiary_id

        JOIN inventory_items i
            ON i.id = d.item_id

        JOIN users u
            ON u.id = d.distributed_by

        JOIN ds_divisions division
            ON division.id = COALESCE(d.ds_division_id, b.ds_division_id)

        JOIN districts district
            ON district.id = division.district_id

        ORDER BY d.id DESC
        "
    ],


    /* =====================================================
       BENEFICIARY REPORT
       ===================================================== */

    'beneficiary' => [

        'Beneficiary Report',

        'Registered beneficiaries, home division and latest distribution',

        [
            'Beneficiary',
            'NIC / Elder Card',
            'District',
            'DS Division',
            'GN Division',
            'Status',
            'Last Distribution'
        ],

        "
        SELECT

            b.full_name AS Beneficiary,

            COALESCE(NULLIF(b.nic, ''), NULLIF(b.elders_card_number, ''), '—') AS `NIC / Elder Card`,

            d.name AS District,

            ds.name AS `DS Division`,

            COALESCE(gn.name, 'Not applicable') AS `GN Division`,

            b.status AS Status,

            MAX(x.distributed_at) AS `Last Distribution`

        FROM beneficiaries b

        JOIN districts d
            ON d.id = b.district_id

        JOIN ds_divisions ds
            ON ds.id = b.ds_division_id

        LEFT JOIN gn_divisions gn
            ON gn.id = b.gn_division_id

        LEFT JOIN distributions x
            ON x.beneficiary_id = b.id

        GROUP BY
            b.id,
            b.full_name,
            b.elders_card_number,
            d.name,
            ds.name,
            gn.name,
            b.status

        ORDER BY b.full_name
        "
    ],


    /* =====================================================
       SOCIAL SERVICE OFFICER POOL REPORT
       ===================================================== */

    'pools' => [

        'DS Division Pool Report',

        'Division-owned stock, assigned SSO and remaining units',

        [
            'District',
            'DS Division',
            'Assigned SSO',
            'Item',
            'Allocated',
            'Distributed',
            'Reused',
            'Remaining'
        ],

        "
        SELECT

            district.name AS District,

            ds.name AS `DS Division`,

            COALESCE((SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ')
                      FROM users u WHERE u.ds_division_id=p.ds_division_id
                      AND u.role='social-service-officer' AND u.status='active'), 'Unassigned') AS `Assigned SSO`,

            CONCAT(
                i.item_name,
                IF(
                    i.variety = '',
                    '',
                    CONCAT(' / ', i.variety)
                )
            ) AS Item,

            p.allocated AS Allocated,

            p.distributed AS Distributed,

            p.reused AS Reused,

            (
                CAST(p.allocated AS SIGNED)
                - CAST(p.distributed AS SIGNED)
                + CAST(p.reused AS SIGNED)
            ) AS Remaining

        FROM division_pools p

        JOIN ds_divisions ds
            ON ds.id = p.ds_division_id

        JOIN districts district
            ON district.id = ds.district_id

        JOIN inventory_items i
            ON i.id = p.item_id

        ORDER BY
            ds.name,
            i.item_name
        "
    ],


    /* =====================================================
       PROCUREMENT REPORT
       ===================================================== */

    'procurement' => [

        'Procurement Cost Report',

        'Received stock batches, supplier costs and payment balances',

        [
            'Batch',
            'Supplier',
            'Bill',
            'Total',
            'Paid',
            'Balance',
            'Payment Status',
            'Received'
        ],

        "
        SELECT

            CONCAT(
                'BAT-',
                LPAD(r.id, 4, '0')
            ) AS Batch,

            s.company_name AS Supplier,

            r.bill_number AS Bill,

            r.total_cost AS Total,

            r.paid_amount AS Paid,

            r.balance_amount AS Balance,

            r.payment_status AS `Payment Status`,

            r.received_date AS Received

        FROM stock_receipts r

        JOIN suppliers s
            ON s.id = r.supplier_id

        ORDER BY r.id DESC
        "
    ],


    /* =====================================================
       REQUEST STATUS REPORT
       ===================================================== */

    'requests' => [

        'Request Status Report',

        'Aid request decisions, review time and rejection reasons',

        [
            'Request',
            'Beneficiary',
            'Item',
            'Submitted By',
            'Status',
            'Submitted',
            'Reviewed',
            'Review Hours',
            'Rejection Reason'
        ],

        "
        SELECT

            CONCAT(
                'AR-',
                LPAD(ar.id, 4, '0')
            ) AS Request,

            b.full_name AS Beneficiary,

            CONCAT(
                i.item_name,
                IF(
                    i.variety = '',
                    '',
                    CONCAT(' / ', i.variety)
                )
            ) AS Item,

            u.full_name AS `Submitted By`,

            ar.status AS Status,

            ar.created_at AS Submitted,

            ar.reviewed_at AS Reviewed,

            CASE WHEN ar.reviewed_at IS NULL THEN NULL
                 ELSE TIMESTAMPDIFF(HOUR, ar.created_at, ar.reviewed_at) END AS `Review Hours`,

            CASE WHEN ar.status='rejected' THEN ar.rejection_reason ELSE NULL END AS `Rejection Reason`

        FROM aid_requests ar

        JOIN beneficiaries b
            ON b.id = ar.beneficiary_id

        JOIN inventory_items i
            ON i.id = ar.item_id

        JOIN users u
            ON u.id = ar.submitted_by

        ORDER BY ar.id DESC
        "
    ],


    /* =====================================================
       RETURN & REUSE REPORT
       ===================================================== */

    'returns' => [

        'Return & Reuse Report',

        'Returns with condition and destination',

        [
            'Return',
            'Distribution',
            'Beneficiary',
            'Item',
            'Qty',
            'Condition',
            'Reusable',
            'Destination',
            'Stock Review',
            'Date'
        ],

        "
        SELECT

            CONCAT(
                'RET-',
                LPAD(r.id, 4, '0')
            ) AS `Return`,

            CONCAT(
                'DIST-',
                LPAD(r.distribution_id, 4, '0')
            ) AS Distribution,

            b.full_name AS Beneficiary,

            i.item_name AS Item,

            r.quantity AS Qty,

            r.item_condition AS `Condition`,

            IF(
                r.reusable,
                'Yes',
                'No'
            ) AS Reusable,

            r.restore_to AS Destination,

            r.stock_review_status AS `Stock Review`,

            r.processed_at AS Date

        FROM item_returns r

        JOIN distributions x
            ON x.id = r.distribution_id

        JOIN beneficiaries b
            ON b.id = x.beneficiary_id

        JOIN inventory_items i
            ON i.id = x.item_id

        ORDER BY r.id DESC
        "
    ],


    /* =====================================================
       AUDIT LOG REPORT
       ===================================================== */

    'audit' => [

        'Audit Log Report',

        'Full recorded system activity',

        [
            'Timestamp',
            'User',
            'Role',
            'Module',
            'Action',
            'Reference',
            'Status'
        ],

        "
        SELECT

            a.created_at AS Timestamp,

            COALESCE(
                u.full_name,
                'System'
            ) AS User,

            a.role AS Role,

            a.module AS Module,

            a.action AS Action,

            a.record_reference AS Reference,

            a.status AS Status

        FROM activity_logs a

        LEFT JOIN users u
            ON u.id = a.user_id

        ORDER BY a.id DESC
        "
    ]

];

$reportMeta = [
    'inventory' => ['icon' => '📦', 'source' => 'inventory_items', 'unit' => 'stock items', 'tone' => 'blue'],
    'distribution' => ['icon' => '🤝', 'source' => 'distributions', 'unit' => 'distributions', 'tone' => 'green'],
    'beneficiary' => ['icon' => '👥', 'source' => 'beneficiaries', 'unit' => 'beneficiaries', 'tone' => 'violet'],
    'pools' => ['icon' => '🧩', 'source' => 'division_pools', 'unit' => 'pool lines', 'tone' => 'teal'],
    'procurement' => ['icon' => '🧾', 'source' => 'stock_receipts', 'unit' => 'stock batches', 'tone' => 'amber'],
    'requests' => ['icon' => '📋', 'source' => 'aid_requests', 'unit' => 'aid requests', 'tone' => 'blue'],
    'returns' => ['icon' => '♻️', 'source' => 'item_returns', 'unit' => 'returns', 'tone' => 'green'],
    'audit' => ['icon' => '🕒', 'source' => 'activity_logs', 'unit' => 'activity entries', 'tone' => 'slate'],
];

$reportCounts = [];
foreach ($reportMeta as $key => $meta) {
    try {
        $reportCounts[$key] = (int) database()->query('SELECT COUNT(*) FROM `' . $meta['source'] . '`')->fetchColumn();
    } catch (PDOException $exception) {
        error_log('Report count unavailable for ' . $key . ': ' . $exception->getMessage());
        $reportCounts[$key] = null;
    }
}


/* =========================================================
   LOAD REPORT DATA
   ========================================================= */

$rows = [];

$error = '';

if (isset($reports[$type])) {

    try {

        $rows = database()
            ->query($reports[$type][3])
            ->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log($e->getMessage());

        $error = 'Unable to generate this report.';
    }
}

// Preview, print and downloads all use this same filtered dataset.
$reportFilterFields = [
    'inventory' => ['Disability Type', 'Item'],
    'distribution' => ['District', 'Type', 'Source'],
    'beneficiary' => ['District', 'Status'],
    'pools' => ['District', 'Item'],
    'procurement' => ['Supplier', 'Payment Status'],
    'requests' => ['Status', 'Item'],
    'returns' => ['Condition', 'Destination', 'Stock Review'],
    'audit' => ['Role', 'Module', 'Status'],
];
$reportDateFields = [
    'distribution' => 'Date',
    'beneficiary' => 'Last Distribution',
    'procurement' => 'Received',
    'requests' => 'Submitted',
    'returns' => 'Date',
    'audit' => 'Timestamp',
];
$reportSearch = trim(substr(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 0, 120));
$reportFrom = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
$reportTo = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportFrom)) $reportFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportTo)) $reportTo = '';
$requestedFilters = is_array($_GET['filters'] ?? null) ? $_GET['filters'] : [];
$selectedFilters = [];
$filterOptions = [];
if (isset($reports[$type])) {
    foreach ($reportFilterFields[$type] as $field) {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') $values[$value] = true;
        }
        $filterOptions[$field] = array_keys($values);
        natcasesort($filterOptions[$field]);
        $filterOptions[$field] = array_values($filterOptions[$field]);
        $requested = $requestedFilters[$field] ?? '';
        $selectedFilters[$field] = is_string($requested) && in_array($requested, $filterOptions[$field], true)
            ? $requested : '';
    }
    $dateField = $reportDateFields[$type] ?? null;
    $rows = array_values(array_filter($rows, static function (array $row) use ($reportSearch, $selectedFilters, $dateField, $reportFrom, $reportTo): bool {
        if ($reportSearch !== '') {
            $haystack = implode(' ', array_map(static fn ($value) => (string) ($value ?? ''), $row));
            $matches = function_exists('mb_stripos')
                ? mb_stripos($haystack, $reportSearch, 0, 'UTF-8') !== false
                : stripos($haystack, $reportSearch) !== false;
            if (!$matches) return false;
        }
        foreach ($selectedFilters as $field => $value) {
            if ($value !== '' && (string) ($row[$field] ?? '') !== $value) return false;
        }
        if ($dateField !== null && ($reportFrom !== '' || $reportTo !== '')) {
            $date = substr((string) ($row[$dateField] ?? ''), 0, 10);
            if ($date === '' || ($reportFrom !== '' && $date < $reportFrom) || ($reportTo !== '' && $date > $reportTo)) return false;
        }
        return true;
    }));
}
$reportQuery = ['page' => 'reports', 'report' => $type];
if ($reportSearch !== '') $reportQuery['q'] = $reportSearch;
foreach ($selectedFilters as $field => $value) {
    if ($value !== '') $reportQuery['filters'][$field] = $value;
}
if ($reportFrom !== '') $reportQuery['from'] = $reportFrom;
if ($reportTo !== '') $reportQuery['to'] = $reportTo;
$reportUrl = 'dashboard.php?' . http_build_query($reportQuery, '', '&', PHP_QUERY_RFC3986);

if ($error !== '' && in_array($export, ['csv', 'pdf'], true)) {
    http_response_code(503);
    exit($error);
}


/* =========================================================
   CSV EXPORT
   ========================================================= */

if (
    $export === 'csv'
    && isset($reports[$type])
) {

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="SWPCS-'
        . ucfirst($type)
        . '-Report-'
        . date('Y-m-d')
        . '.csv"'
    );


    $output = fopen(
        'php://output',
        'wb'
    );


    /*
     * UTF-8 BOM for Excel
     */
    fwrite(
        $output,
        "\xEF\xBB\xBF"
    );


    /*
     * Column headings
     */
    fputcsv(
        $output,
        $reports[$type][2]
    );


    /*
     * Report rows
     */
    foreach ($rows as $row) {

        fputcsv(
            $output,
            array_values($row)
        );
    }


    fclose($output);

    exit;
}


/* =========================================================
   PDF EXPORT
   ========================================================= */

if (
    $export === 'pdf'
    && isset($reports[$type])
) {

    /*
     * Composer autoloader
     */
    $autoload =
        __DIR__
        . '/../../vendor/autoload.php';


    if (!is_file($autoload)) {

        exit(
            'Dompdf is not installed. '
            . 'Run: composer require dompdf/dompdf'
        );
    }


    require_once $autoload;


    /* =====================================================
       REPORT INFORMATION
       ===================================================== */

    $reportTitle =
        $reports[$type][0];

    $reportDescription =
        $reports[$type][1];

    $columns =
        $reports[$type][2];


    /* =====================================================
       CREATE PDF HTML
       ===================================================== */

    $html = '

    <!doctype html>

    <html>

    <head>

        <meta charset="UTF-8">

        <style>

            @page {
                margin: 25px 25px 35px 25px;
            }

            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 9px;
                color: #1f2937;
            }

            .report-header {
                border-bottom: 2px solid #5e9bd3;
                padding-bottom: 12px;
                margin-bottom: 14px;
            }

            .system-name {
                font-size: 11px;
                color: #64748b;
                margin-bottom: 5px;
            }

            .report-title {
                margin: 0;
                font-size: 21px;
                color: #1e4f7a;
            }

            .report-description {
                margin-top: 5px;
                color: #64748b;
                font-size: 10px;
            }

            .report-details {
                width: 100%;
                margin-bottom: 15px;
                font-size: 9px;
                color: #475569;
            }

            .report-details td {
                border: none;
                padding: 2px;
            }

            .text-right {
                text-align: right;
            }

            .report-table {
                width: 100%;
                border-collapse: collapse;
                table-layout: auto;
            }

            .report-table thead {
                display: table-header-group;
            }

            .report-table th {
                background-color: #e7f1fb;
                color: #15324b;
                border: 1px solid #b8c9d9;
                padding: 7px 5px;
                text-align: left;
                font-weight: bold;
            }

            .report-table td {
                border: 1px solid #d6e0e8;
                padding: 6px 5px;
                vertical-align: top;
                word-wrap: break-word;
            }

            .report-table tr:nth-child(even) {
                background-color: #f8fafc;
            }

            .empty-row {
                text-align: center;
                padding: 25px;
                color: #64748b;
            }

            .footer {
                position: fixed;
                bottom: -22px;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 8px;
                color: #94a3b8;
                border-top: 1px solid #e2e8f0;
                padding-top: 5px;
            }

        </style>

    </head>

    <body>


        <div class="report-header">

            <div class="system-name">
                Welfare Inventory and Distribution Management System
            </div>

            <h1 class="report-title">'
                . htmlspecialchars(
                    $reportTitle,
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '
            </h1>

            <div class="report-description">'
                . htmlspecialchars(
                    $reportDescription,
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '
            </div>

        </div>


        <table class="report-details">

            <tr>

                <td>

                    <strong>
                        Generated:
                    </strong>

                    '
                    . date('d M Y h:i A')
                    . '

                </td>

                <td class="text-right">

                    <strong>
                        Generated By:
                    </strong>

                    '
                    . htmlspecialchars(
                        ucfirst(
                            str_replace(
                                '-',
                                ' ',
                                $currentRole
                            )
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '

                    &nbsp;&nbsp;

                    <strong>
                        Total Records:
                    </strong>

                    '
                    . count($rows)
                    . '

                </td>

            </tr>

        </table>


        <table class="report-table">

            <thead>

                <tr>';


    /* =====================================================
       PDF COLUMN HEADINGS
       ===================================================== */

    foreach ($columns as $column) {

        $html .=

            '<th>'
            . htmlspecialchars(
                $column,
                ENT_QUOTES,
                'UTF-8'
            )
            . '</th>';
    }


    $html .= '

                </tr>

            </thead>

            <tbody>';


    /* =====================================================
       PDF DATA
       ===================================================== */

    if (empty($rows)) {

        $html .= '

            <tr>

                <td
                    colspan="'
                    . count($columns)
                    . '"
                    class="empty-row"
                >

                    No records available for this report.

                </td>

            </tr>';

    } else {

        foreach ($rows as $row) {

            $html .= '<tr>';


            foreach ($row as $value) {

                $displayValue =
                    $value ?? '—';


                $html .=

                    '<td>'
                    . htmlspecialchars(
                        (string) $displayValue,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '</td>';
            }


            $html .= '</tr>';
        }
    }


    $html .= '

            </tbody>

        </table>


        <div class="footer">

            Generated by SWPCS

        </div>


    </body>

    </html>';


    /* =====================================================
       DOMPDF OPTIONS
       ===================================================== */

    $options =
        new \Dompdf\Options();


    $options->set(
        'isRemoteEnabled',
        true
    );


    /* =====================================================
       CREATE DOMPDF
       ===================================================== */

    $dompdf =
        new \Dompdf\Dompdf(
            $options
        );


    $dompdf->loadHtml(
        $html,
        'UTF-8'
    );


    /*
     * Landscape because some reports contain
     * many columns.
     */
    $dompdf->setPaper(
        'A4',
        'landscape'
    );


    $dompdf->render();


    /* =====================================================
       PDF FILE NAME
       ===================================================== */

    $filename =

        'SWPCS-'
        . ucfirst($type)
        . '-Report-'
        . date('Y-m-d')
        . '.pdf';


    /* =====================================================
       DOWNLOAD PDF
       ===================================================== */

    $dompdf->stream(
        $filename,
        [
            'Attachment' => true
        ]
    );


    exit;
}

?>


<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >


    <title>
        Reports | SWPCS
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        href="assets/css/admin-dashboard.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/admin-dashboard.css') ?>"
        rel="stylesheet"
    >


    <style>

        body.report-modal-open { overflow: hidden; }
        .report-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1500;
            display: grid;
            place-items: center;
            padding: 18px;
            background: rgba(7, 25, 39, .68);
        }
        .reports-page .report-modal-backdrop .report-preview {
            display: flex;
            width: min(1440px, 100%);
            height: min(860px, calc(100dvh - 36px));
            min-height: 0;
            margin: 0;
            padding: 0;
            flex-direction: column;
            overflow: hidden;
            border-radius: 12px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, .28);
        }
        .reports-page .report-preview .admin-data-header {
            position: relative;
            display: flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 14px;
            padding: 18px 72px 18px 22px;
            background: #fff;
        }
        .report-preview .admin-data-header > div:first-child { min-width: 0; margin-right: auto; }
        .report-preview .admin-data-header h2 { margin: 0; }
        .report-preview .report-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .report-preview .report-actions .outline-action,
        .report-preview .report-filter-buttons .outline-action,
        .report-preview .report-filter-buttons button { min-height: 39px; padding: 9px 14px; white-space: nowrap; }
        .report-modal-close {
            position: absolute;
            top: 16px;
            right: 18px;
            display: grid;
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            place-items: center;
            color: #4c6476;
            background: #f0f4f7;
            border: 1px solid #d2dfe7;
            border-radius: 8px;
            font-size: 27px;
            line-height: 1;
            text-decoration: none;
        }
        .report-modal-close:hover { color: #9c2930; background: #fff0f0; }
        .report-filter-form {
            display: flex;
            min-height: 0;
            flex: 0 0 auto;
            flex-direction: column;
            background: #f3f7fa;
            border-top: 1px solid #dce6ed;
            border-bottom: 1px solid #dce6ed;
        }
        .report-filter-fields {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px 12px;
            max-height: min(38vh, 320px);
            padding: 16px 22px;
            min-height: 0;
            overflow-y: auto;
        }
        .report-filter-form label { display: flex; min-width: 0; flex-direction: column; gap: 5px; color: #244259; font-weight: 650; }
        .report-filter-form input,
        .report-filter-form select { width: 100%; min-width: 0; height: 40px; padding: 7px 10px; background: #fff; border: 1px solid #bdd0de; border-radius: 7px; }
        .report-filter-search { grid-column: span 2; }
        .report-filter-buttons { display: flex; flex: 0 0 auto; align-items: center; gap: 8px; padding: 0 22px 14px; }
        .reports-page .report-modal-backdrop .admin-data-table-wrap { min-height: 0; margin: 0 22px 20px; flex: 1 1 auto; overflow: auto; }
        .report-preview .admin-data-table thead th { position: sticky; top: 0; z-index: 1; }
        @media (max-width: 767.98px) {
            .report-modal-backdrop { padding: 8px; }
            .reports-page .report-modal-backdrop .report-preview { height: calc(100dvh - 16px); }
            .reports-page .report-preview .admin-data-header { align-items: flex-start; padding: 14px 62px 14px 14px; }
            .report-modal-close { top: 12px; right: 12px; }
            .report-preview .report-actions { width: 100%; }
            .report-filter-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 12px 14px; }
            .report-filter-buttons { padding: 0 14px 12px; }
            .report-filter-search { grid-column: 1 / -1; }
            .reports-page .report-modal-backdrop .admin-data-table-wrap { margin: 0 14px 14px; }
        }
        @media (max-width: 479.98px) {
            .report-filter-fields { grid-template-columns: 1fr; }
            .report-filter-search { grid-column: auto; }
            .report-filter-buttons { flex-wrap: wrap; }
        }

        @media print {

            .sidebar,
            .topbar,
            .reports-grid,
            .report-actions {

                display: none !important;
            }


            .admin-shell {

                margin: 0;
            }


            .dashboard-content {

                padding: 0;
                overflow: visible;
            }


            .report-preview {

                box-shadow: none;
            }
            body.report-modal-open { overflow: visible; }
            .report-modal-backdrop { position: static; display: block; padding: 0; background: #fff; }
            .reports-page .report-modal-backdrop .report-preview { display: block; width: 100%; height: auto; max-height: none; overflow: visible; }
            .report-filter-form,
            .report-modal-close { display: none !important; }
            .reports-page .report-modal-backdrop .admin-data-table-wrap { margin: 0; overflow: visible; }
            .report-preview .admin-data-table { min-width: 0 !important; width: 100%; font-size: 8px; }
            .report-preview .admin-data-table thead th { position: static; }
        }

    </style>

</head>


<body<?= isset($reports[$type]) ? ' class="report-modal-open"' : '' ?>>


<?php

/* =========================================================
   LOAD CORRECT SIDEBAR BASED ON ROLE
   ========================================================= */

if ($currentRole === 'admin') {

    require __DIR__
        . '/../../includes/admin-sidebar.php';

} elseif ($currentRole === 'subject-officer') {

    require __DIR__
        . '/../../includes/subject-officer-sidebar.php';
}

?>


<div class="admin-shell">


    <!-- =====================================================
         TOP BAR
         ===================================================== -->

    <header class="topbar">


        <div class="d-flex align-items-center gap-3">


            <button
                type="button"
                class="menu-button"
                id="menu-button"
                aria-label="Open navigation"
            >

                &#9776;

            </button>


            <h1>
                Reports
            </h1>


        </div>


        <div class="topbar-actions">


            <label class="search-box">


                <span aria-hidden="true">
                    &#128269;
                </span>


                <input
                    type="search"
                    placeholder="Search anything..."
                    aria-label="Search"
                >


            </label>


            <button
                class="notification-button"
                type="button"
                aria-label="Notifications"
            >

                &#128276;

            </button>


        </div>


    </header>


    <!-- =====================================================
         MAIN CONTENT
         ===================================================== -->

    <main class="dashboard-content reports-page">


        <!-- =================================================
             REPORT CARDS
             ================================================= -->

        <div class="reports-intro">
            <div><span class="reports-eyebrow">LIVE REPORTING</span><h2>Operational reports</h2><p>Select a report to inspect its current records, or download it as a PDF or CSV.</p></div>
            <span class="reports-total"><?= htmlspecialchars(sprintf(t('%s report types'), (string) count($reports)), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <section class="reports-grid">


            <?php foreach ($reports as $key => $report): ?>


                <article class="report-card report-tone-<?= htmlspecialchars($reportMeta[$key]['tone'], ENT_QUOTES, 'UTF-8') ?>">


                    <div class="report-card-top"><span class="report-icon" aria-hidden="true"><?= htmlspecialchars($reportMeta[$key]['icon'], ENT_QUOTES, 'UTF-8') ?></span><span class="report-record-count"><?= htmlspecialchars($reportCounts[$key] === null ? t('Unavailable') : number_format($reportCounts[$key]) . ' ' . t($reportMeta[$key]['unit']), ENT_QUOTES, 'UTF-8') ?></span></div>


                    <h2>

                        <?= htmlspecialchars(
                            $report[0],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </h2>


                    <p>

                        <?= htmlspecialchars(
                            $report[1],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </p>


                    <div class="report-card-actions">


                        <!-- ===============================
                             GENERATE REPORT
                             =============================== -->

                        <a
                            class="admin-primary-action"
                            href="dashboard.php?page=reports&amp;report=<?= urlencode($key) ?>"
                        >

                            Generate

                        </a>


                        <!-- ===============================
                             PDF DOWNLOAD
                             =============================== -->

                        <a
                            class="outline-action"
                            href="dashboard.php?page=reports&amp;report=<?= urlencode($key) ?>&amp;export=pdf"
                        >

                            PDF

                        </a>


                        <!-- ===============================
                             CSV DOWNLOAD
                             =============================== -->

                        <a
                            class="outline-action"
                            href="dashboard.php?page=reports&amp;report=<?= urlencode($key) ?>&amp;export=csv"
                        >

                            CSV

                        </a>


                    </div>


                </article>


            <?php endforeach; ?>


        </section>


        <!-- =================================================
             GENERATED REPORT PREVIEW
             ================================================= -->

        <?php if (isset($reports[$type])): ?>


            <div class="report-modal-backdrop" id="report-modal-backdrop">
            <section class="admin-data-card report-preview" id="report-preview" role="dialog" aria-modal="true" aria-labelledby="report-modal-title" tabindex="-1">


                <div class="admin-data-header">


                    <div>


                        <h2 id="report-modal-title">

                            <?= htmlspecialchars(
                                $reports[$type][0],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </h2>


                        <small>

                            <?= htmlspecialchars(
                                $reports[$type][1],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </small>
                        <p class="report-preview-summary"><?= htmlspecialchars(sprintf(t('%s matching records · %s columns · %s view'), number_format(count($rows)), (string) count($reports[$type][2]), t($currentRole === 'admin' ? 'Administrator' : 'Subject Officer')), ENT_QUOTES, 'UTF-8') ?></p>


                    </div>


                    <div class="report-actions">


                        <!-- PDF -->

                        <a
                            class="outline-action"
                            href="<?= htmlspecialchars($reportUrl . '&export=pdf', ENT_QUOTES, 'UTF-8') ?>"
                        >

                            Download PDF

                        </a>


                        <!-- CSV -->

                        <a
                            class="outline-action"
                            href="<?= htmlspecialchars($reportUrl . '&export=csv', ENT_QUOTES, 'UTF-8') ?>"
                        >

                            Download CSV

                        </a>


                        <!-- PRINT -->

                        <button
                            type="button"
                            class="outline-action"
                            onclick="window.print()"
                        >

                            Print

                        </button>


                    </div>

                    <a class="report-modal-close" href="dashboard.php?page=reports" aria-label="Close report">&times;</a>


                </div>

                <form class="report-filter-form" method="get" action="dashboard.php">
                    <input type="hidden" name="page" value="reports">
                    <input type="hidden" name="report" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="report-filter-fields">
                    <label class="report-filter-search">Search all columns
                        <input type="search" name="q" value="<?= htmlspecialchars($reportSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name, item, reference or keyword">
                    </label>
                    <?php foreach ($reportFilterFields[$type] as $field): ?>
                        <label><?= htmlspecialchars(t($field), ENT_QUOTES, 'UTF-8') ?>
                            <select name="filters[<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>]">
                                <option value=""><?= htmlspecialchars(t('All') . ' ' . t($field), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($filterOptions[$field] as $option): ?>
                                    <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedFilters[$field] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                    <?php if (isset($reportDateFields[$type])): ?>
                        <label>From date<input type="date" name="from" value="<?= htmlspecialchars($reportFrom, ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label>To date<input type="date" name="to" value="<?= htmlspecialchars($reportTo, ENT_QUOTES, 'UTF-8') ?>"></label>
                    <?php endif; ?>
                    </div>
                    <div class="report-filter-buttons">
                        <button type="submit" class="admin-primary-action">Apply filters</button>
                        <a class="outline-action" href="dashboard.php?page=reports&amp;report=<?= urlencode($type) ?>">Clear</a>
                    </div>
                </form>


                <!-- =========================================
                     ERROR MESSAGE
                     ========================================= -->

                <?php if ($error !== ''): ?>


                    <div class="alert alert-danger">

                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>


                <?php endif; ?>


                <!-- =========================================
                     REPORT TABLE
                     ========================================= -->

                <div class="admin-data-table-wrap">


                    <table class="admin-data-table">


                        <thead>


                            <tr>


                                <?php
                                foreach (
                                    $reports[$type][2]
                                    as $column
                                ):
                                ?>


                                    <th>

                                        <?= htmlspecialchars(
                                            $column,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </th>


                                <?php endforeach; ?>


                            </tr>


                        </thead>


                        <tbody>


                            <?php if (empty($rows)): ?>


                                <tr>


                                    <td
                                        colspan="<?= count($reports[$type][2]) ?>"
                                        class="admin-empty-row"
                                    >

                                        <?= $reportSearch !== '' || array_filter($selectedFilters) || $reportFrom !== '' || $reportTo !== ''
                                            ? 'No records match the current search or filters.'
                                            : 'No records available for this report.' ?>

                                    </td>


                                </tr>


                            <?php else: ?>


                                <?php foreach ($rows as $row): ?>


                                    <tr>


                                        <?php foreach ($row as $value): ?>


                                            <td>

                                                <?= htmlspecialchars(
                                                    (string) ($value ?? '—'),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                        <?php endforeach; ?>


                                    </tr>


                                <?php endforeach; ?>


                            <?php endif; ?>


                        </tbody>


                    </table>


                </div>


            </section>
            </div>


        <?php endif; ?>


    </main>


</div>


<script src="assets/js/admin-dashboard.js"></script>
<script>
(() => {
    const backdrop = document.getElementById('report-modal-backdrop');
    if (!backdrop) return;
    document.body.classList.add('report-modal-open');
    const closeUrl = 'dashboard.php?page=reports';
    backdrop.addEventListener('click', event => {
        if (event.target === backdrop) window.location.assign(closeUrl);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') window.location.assign(closeUrl);
    });
    backdrop.querySelector('.report-filter-search input')?.focus();
})();
</script>


</body>

</html>
