<?php
/** php tests/SalesAgentTargetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\SalesAgents\SalesAgentResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\SalesAgents\Targets;

check('avanzamento: percentuale e quanto manca', function () {
    $met = Targets::progress(2000.0, 1500.0);
    $over = Targets::progress(1000.0, 1200.0);
    $none = Targets::progress(0.0, 300.0);

    return $met['percent'] === 75.0 && $met['missing'] === 500.0
        && $over['percent'] === 120.0 && $over['missing'] === 0.0
        && $none['percent'] === null;
});

check('pick trova l\'obiettivo del mese e dell\'anno, la prima riga vince', function () {
    $righe = [
        ['period' => 'month', 'year' => 2026, 'month' => 11, 'amount' => '1000.00'],
        ['period' => 'month', 'year' => 2026, 'month' => 11, 'amount' => '9999.00'],
        ['period' => 'year', 'year' => 2026, 'month' => 0, 'amount' => '12000.00'],
        ['period' => 'month', 'year' => 2026, 'month' => 10, 'amount' => '500.00', 'deleted' => 'true'],
    ];

    return Targets::pick($righe, 2026, 11) === 1000.0
        && Targets::pick($righe, 2026) === 12000.0
        && Targets::pick($righe, 2026, 10) === null
        && Targets::pick($righe, 2025, 11) === null;
});

check('normalize: «Anno» porta il mese a 0', function () {
    $anno = Targets::normalize(['period' => 'year', 'year' => '2026', 'month' => '5']);
    $mese = Targets::normalize(['period' => 'strano', 'year' => '2026', 'month' => '5']);

    return $anno['month'] === 0 && $anno['year'] === 2026
        && $mese['period'] === 'month' && $mese['month'] === 5;
});

check('valid: anno, mese per «Mese» e importo maggiore di 0', function () {
    return Targets::valid(['period' => 'month', 'year' => 2026, 'month' => 3, 'amount' => '100'])
        && Targets::valid(['period' => 'year', 'year' => 2026, 'month' => '', 'amount' => '100.5'])
        && !Targets::valid(['period' => 'month', 'year' => 2026, 'month' => '', 'amount' => '100'])
        && !Targets::valid(['period' => 'month', 'year' => 2026, 'month' => 13, 'amount' => '100'])
        && !Targets::valid(['period' => 'year', 'year' => 26, 'amount' => '100'])
        && !Targets::valid(['period' => 'year', 'year' => 2026, 'amount' => '0']);
});

check('bounds: mese, dicembre e anno', function () {
    return Targets::bounds(2026, 11) === ['2026-11-01 00:00:00', '2026-12-01 00:00:00']
        && Targets::bounds(2026, 12) === ['2026-12-01 00:00:00', '2027-01-01 00:00:00']
        && Targets::bounds(2026) === ['2026-01-01 00:00:00', '2027-01-01 00:00:00'];
});

check('summary: senza obiettivo solo il venduto, numeri interi per gli ordini', function () {
    return Targets::summary('orders', null, 7.0) === '7'
        && Targets::summary('orders', 10.0, 7.0) === '7 su 10 (70 %)'
        && !str_contains(Targets::summary('revenue', 2000.0, 1500.0), '—');
});

check('la riga degli obiettivi non valida dà un errore con il numero di riga', function () {
    try {
        SalesAgentResource::checkTargets([
            ['period' => 'month', 'year' => '', 'amount' => ''],
            ['period' => 'month', 'year' => '2026', 'month' => '', 'amount' => '100'],
        ]);
    } catch (UserError $error) {
        return $error->key() === 'sales_agent.invalid_target' && str_contains($error->getMessage(), '2');
    }

    return false;
});

check('le righe salvate: vuote saltate, anno con mese 0, importo col punto', function () {
    $righe = SalesAgentResource::prepareRepeaterRows('targets', [
        ['period' => 'year', 'year' => '2026', 'month' => '4', 'amount' => '1.500,50'],
        ['period' => 'month', 'year' => '', 'month' => '', 'amount' => ''],
    ]);

    return count($righe) === 1 && $righe[0]['month'] === 0 && (float) $righe[0]['amount'] === 1500.5;
});

summary();
