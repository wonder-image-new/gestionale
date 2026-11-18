<?php
/** php tests/SalesAgentCommissionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\SalesAgents\SalesAgentCommissionResource;
use Wonder\Plugin\Gestionale\Support\SalesAgents\Commissions;

$uguale = static fn (float $a, float $b): bool => abs($a - $b) < 0.005;

check('la base toglie IVA, sconto di testata e quantità rese', function () use ($uguale) {
    $riga = ['line_total' => 122.0, 'order_discount_amount' => 0.0, 'tax_rate' => 22, 'quantity' => 2];

    return $uguale(Commissions::lineBase($riga, true), 100.0)
        && $uguale(Commissions::lineBase($riga, false), 122.0)
        && $uguale(Commissions::lineBase($riga, true, 1), 50.0)
        && $uguale(Commissions::lineBase(['line_total' => 100, 'order_discount_amount' => 10, 'quantity' => 1], false), 90.0)
        && $uguale(Commissions::lineBase($riga, true, 5), 0.0);
});

check('la % va prodotto, poi categoria, poi quella del commerciale', function () {
    $regole = [
        ['category_id' => 7, 'product_id' => 0, 'percent' => 5.0],
        ['category_id' => 0, 'product_id' => 42, 'percent' => 12.0],
    ];

    return Commissions::rate(42, $regole, 10.0, [7]) === 12.0
        && Commissions::rate(43, $regole, 10.0, [3, 7]) === 5.0
        && Commissions::rate(44, $regole, 10.0, [3]) === 10.0
        && Commissions::rate(0, $regole, 10.0, []) === 10.0;
});

check('chi vende: salta componenti di confezione, spedizioni e spese', function () use ($uguale) {
    $righe = [
        ['id' => 1, 'type' => 'product', 'product_id' => 42, 'line_total' => 500, 'quantity' => 1],
        ['id' => 2, 'type' => 'product', 'product_id' => 43, 'line_total' => 300, 'quantity' => 1, 'parent_item_id' => 1],
        ['id' => 3, 'type' => 'custom', 'product_id' => 0, 'line_total' => 500, 'quantity' => 1],
        ['id' => 4, 'type' => 'shipping', 'line_total' => 10, 'quantity' => 1],
        ['id' => 5, 'type' => 'fee', 'line_total' => 5, 'quantity' => 1],
    ];
    $regole = [['category_id' => 0, 'product_id' => 42, 'percent' => 20.0]];
    $out = Commissions::seller($righe, false, [], $regole, 10.0, static fn (int $id): array => []);

    return $uguale($out['base'], 1000.0)
        && $uguale($out['amount'], 150.0)
        && count($out['rates']) === 2
        && $out['rates'][0]['percent'] === 20.0;
});

check('la rete: C vende 1000, B 3 % prende 30, A 1 % prende 10', function () {
    $out = Commissions::upline(1000.0, [2, 1], [1 => 3.0, 2 => 1.0], 0.0);

    return $out === [
        ['agent_id' => 2, 'level' => 1, 'percent' => 3.0, 'amount' => 30.0],
        ['agent_id' => 1, 'level' => 2, 'percent' => 1.0, 'amount' => 10.0],
    ];
});

check('un livello vuoto prende la base, la % zero non scrive niente', function () {
    $out = Commissions::upline(1000.0, [2, 1, 9], [1 => null, 2 => 0.0], 2.0);

    return count($out) === 2
        && $out[0] === ['agent_id' => 2, 'level' => 1, 'percent' => 2.0, 'amount' => 20.0]
        && $out[1]['agent_id'] === 9 && $out[1]['level'] === 3;
});

check('matura al pagamento o all\'evasione, solo con commerciale e stato valido', function () {
    $ordine = ['stage' => 'order', 'deleted' => 'false', 'agent_id' => 3, 'status' => 'confirmed', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled'];

    return Commissions::eligible($ordine, 'paid')
        && !Commissions::eligible($ordine, 'fulfilled')
        && Commissions::eligible(['fulfillment_status' => 'fulfilled'] + $ordine, 'fulfilled')
        && !Commissions::eligible(['agent_id' => 0] + $ordine, 'paid')
        && !Commissions::eligible(['status' => 'cancelled'] + $ordine, 'paid')
        && !Commissions::eligible(['stage' => 'quote'] + $ordine, 'paid')
        && !Commissions::eligible(['payment_status' => 'pending'] + $ordine, 'paid');
});

check('la differenza scrive maturate e storni, niente se non cambia', function () {
    $target = [
        '3:0' => ['agent_id' => 3, 'level' => 0, 'base' => 1000.0, 'percent' => 10.0, 'amount' => 100.0],
        '2:1' => ['agent_id' => 2, 'level' => 1, 'base' => 1000.0, 'percent' => 3.0, 'amount' => 30.0],
    ];
    $current = [
        '3:0' => ['base' => 1000.0, 'amount' => 100.0],
        '2:1' => ['base' => 2000.0, 'amount' => 60.0],
        '1:2' => ['base' => 1000.0, 'amount' => 10.0],
    ];
    $out = Commissions::diff($target, $current);
    $per = [];
    foreach ($out as $row) {
        $per[$row['agent_id'].':'.$row['level']] = $row;
    }

    return count($out) === 2
        && $per['2:1']['type'] === 'reversal' && $per['2:1']['amount'] === 30.0 && $per['2:1']['base'] === 1000.0
        && $per['1:2']['type'] === 'reversal' && $per['1:2']['amount'] === 10.0
        && Commissions::diff($target, ['3:0' => ['base' => 1000.0, 'amount' => 100.0], '2:1' => ['base' => 1000.0, 'amount' => 30.0]]) === []
        && Commissions::diff($target, [])[0]['type'] === 'accrual';
});

check('storno col segno meno, liquidata solo con una data vera', fn () => Commissions::signed(['type' => 'reversal', 'amount' => 5]) === -5.0
    && Commissions::signed(['type' => 'accrual', 'amount' => 5]) === 5.0
    && Commissions::isPaid(['paid_at' => '2026-11-18 10:00:00'])
    && !Commissions::isPaid(['paid_at' => '0000-00-00 00:00:00'])
    && !Commissions::isPaid(['paid_at' => null]));

check('etichette di livello e percentuale', fn () => SalesAgentCommissionResource::levelLabel(0) === 'Vendita'
    && SalesAgentCommissionResource::levelLabel(2) === 'Rete, livello 2'
    && SalesAgentCommissionResource::percentLabel(10) === '10 %'
    && SalesAgentCommissionResource::percentLabel('2.50') === '2,5 %');

summary();
