<?php

/**
 * A stand-in CRM, for trying the commission run without the real one.
 *
 * Standalone: nothing in the app loads this file, and it is never deployed.
 * Run it on its own port and point CRM_API_BASE_URL at it.
 *
 *   php -S 127.0.0.1:8011 dev/fake-crm.php
 *
 * It has to be a separate process. `php artisan serve` is single-threaded, so
 * an app that called a stub hosted inside itself would sit waiting for a reply
 * it cannot get around to sending.
 *
 * Answers the contract in docs/crm-commission-api.md, and deliberately fails
 * for one agent so the run screen's error handling can be seen working.
 */

const TOKEN = 'local-dev-crm-token';

/** Agents this stub refuses, to mimic a CRM user with no HRIS Employee ID. */
const UNKNOWN_AGENTS = ['EMP-5696'];

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$presented = null;
$headers = function_exists('getallheaders') ? getallheaders() : [];

foreach ($headers as $name => $value) {
    $name = strtolower($name);

    if ($name === 'authorization' && str_starts_with($value, 'Bearer ')) {
        $presented = substr($value, 7);
    } elseif ($name === 'x-hris-token') {
        $presented = $value;
    }
}

if (! hash_equals(TOKEN, (string) $presented)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorised', 'message' => 'Bad or missing token.']);

    return;
}

if ($path !== '/api/hris/commission-slip') {
    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => 'This stub only serves /api/hris/commission-slip.']);

    return;
}

$employeeId = $_GET['hris_employee_id'] ?? $_GET['agent'] ?? '';
$month = $_GET['month'] ?? date('Y-m');

if (in_array($employeeId, UNKNOWN_AGENTS, true)) {
    http_response_code(404);
    echo json_encode([
        'error' => 'not_found',
        'message' => "No CRM user carries the HRIS Employee ID {$employeeId}.",
    ]);

    return;
}

/*
 * Figures derived from the employee id so each agent gets their own numbers
 * and they stay the same between recomputes — a stub that returned random
 * amounts would make it impossible to tell a real change from noise.
 */
$seed = crc32($employeeId);
mt_srand($seed);

$rate = 58.4210;
$target = 20000.00;
$mtd = round(6000 + ($seed % 14000) + (mt_rand(0, 99) / 100), 2);

$brands = ['Ink House', 'Page One', 'Northfield Press', 'Blue Harbor'];
$services = ['Publishing', 'Marketing', 'Editing', 'Cover Design'];
$titles = ['Tides of Manila', 'Second Wind', 'The Quiet Ledger', 'Salt and Paper', 'Morning Shift'];
$clients = ['J. Cruz', 'R. Dela Cruz', 'M. Bautista', 'A. Reyes', 'L. Tan'];
$methods = ['Card', 'Bank Transfer', 'Card', 'Cash'];

$transactions = [];
$serviceTotal = 0.0;
$markupTotal = 0.0;
$usdTotal = 0.0;
$phpTotal = 0.0;
$holdTotal = 0.0;
$netTotal = 0.0;

$rows = 3 + ($seed % 4);

for ($i = 0; $i < $rows; $i++) {
    $sale = round(800 + mt_rand(0, 3200) + (mt_rand(0, 99) / 100), 2);
    $serviceAmount = round($sale * 0.75, 2);
    $markupAmount = round($sale - $serviceAmount, 2);

    $serviceCommission = round($serviceAmount * 0.10, 2);
    $markupCommission = round($markupAmount * 0.10, 2);

    $usd = round($serviceCommission + $markupCommission, 2);
    $php = round($usd * $rate, 2);

    $method = $methods[$i % count($methods)];
    // A hold applies only to card payments — the rule the slip explains.
    $hold = $method === 'Card' ? round($php * 0.10, 2) : 0.00;
    $net = round($php - $hold, 2);

    $transactions[] = [
        'sold_date' => $month . '-' . str_pad((string) (2 + ($i * 4)), 2, '0', STR_PAD_LEFT),
        'brand' => $brands[$i % count($brands)],
        'author' => $clients[$i % count($clients)],
        'book_title' => $titles[$i % count($titles)],
        'service' => $services[$i % count($services)],
        'payment_method' => $method,
        'sale_amount' => $sale,
        'service_amount' => $serviceAmount,
        'markup_amount' => $markupAmount,
        'service_commission' => $serviceCommission,
        'markup_commission' => $markupCommission,
        'usd_total' => $usd,
        'php_total' => $php,
        'card_hold_amount' => $hold,
        'net_commission' => $net,
    ];

    $serviceTotal += $serviceCommission;
    $markupTotal += $markupCommission;
    $usdTotal += $usd;
    $phpTotal += $php;
    $holdTotal += $hold;
    $netTotal += $net;
}

echo json_encode([
    'agent' => [
        'id' => 'CRM-' . substr((string) $seed, 0, 4),
        'name' => 'Agent ' . $employeeId,
        'team' => ['Team Alpha', 'Team Bravo', 'Team Charlie'][$seed % 3],
        'work_type' => ['Onsite', 'Remote', 'Hybrid'][$seed % 3],
    ],
    'month' => $month,
    // Echoed back so HRIS can check the reply is about who it asked for.
    'hris_employee_id' => $employeeId,
    'summary' => [
        'mtd' => $mtd,
        'target' => $target,
        'mtd_percent' => round($mtd / $target * 100, 2),
        'service_commission' => round($serviceTotal, 2),
        'markup_commission' => round($markupTotal, 2),
        'usd_total' => round($usdTotal, 2),
        'exchange_rate' => $rate,
        'php_total' => round($phpTotal, 2),
        'card_payment_hold_percent' => 10,
        'card_payment_hold_amount' => round($holdTotal, 2),
        'net_commission' => round($netTotal, 2),
    ],
    'transactions' => $transactions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
