<?php
/** php tests/SalesAgentDiscountLimitTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\SalesAgents\DiscountLimit as Limit;

$riga = static fn (float $ref, float $prezzo, float $qta = 1, float $sovrapprezzo = 0, float $testata = 0): array => [
    'reference_price' => $ref, 'unit_price' => $prezzo, 'quantity' => $qta,
    'customization_surcharge' => $sovrapprezzo, 'order_discount_amount' => $testata,
];

check('le colonne esistono', function () {
    $ordine = array_map(static fn ($c) => (string) $c->name, Order::tableSchema());
    $riga = array_map(static fn ($c) => (string) $c->name, OrderItem::tableSchema());

    return array_diff(['approval_status', 'approval_discount', 'approved_by', 'approved_at'], $ordine) === []
        && in_array('reference_price', $riga, true);
});

check('la funzionalità è costruita', function () {
    $catalogo = require __DIR__.'/../config/features.php';

    return is_array($catalogo['sales_agent_discount_limits'] ?? null) && !array_key_exists('created', $catalogo['sales_agent_discount_limits']);
});

check('sconto di riga: 100 → 85 è il 15%', fn () => Limit::percent([$riga(100, 85)], false) === 15.0);

check('vale la riga più scontata', fn () => Limit::percent([$riga(100, 95), $riga(50, 40)], false) === 20.0);

check('il sovrapprezzo di personalizzazione non conta', fn () => Limit::percent([$riga(100, 95, 1, 5)], false) === 10.0);

check('lo sconto in testata conta solo se a mano', function () use ($riga) {
    $righe = [$riga(100, 100, 2, 0, 20)];

    return Limit::percent($righe, true) === 10.0 && Limit::percent($righe, false) === 0.0;
});

check('righe senza prezzo di riferimento o quantità si saltano', fn () => Limit::percent([$riga(0, 10), $riga(100, 50, 0)], false) === 0.0);

check('un prezzo più alto non è uno sconto', fn () => Limit::percent([$riga(100, 120)], false) === 0.0);

check('limite 0: nessun limite', fn () => Limit::status('none', 90, 0, 0) === 'none');

check('oltre il limite: da approvare', fn () => Limit::status('none', 25, 20, 0) === 'pending');

check('entro il limite: torna libero', fn () => Limit::status('pending', 10, 20, 0) === 'none' && Limit::status('rejected', 20, 20, 0) === 'none');

check('approvato resta finché non si supera l\'approvato', fn () => Limit::status('approved', 25, 20, 25) === 'approved' && Limit::status('approved', 26, 20, 25) === 'pending');

check('approvato entro il limite resta approvato', fn () => Limit::status('approved', 5, 20, 25) === 'approved');

check('blocca da approvare e rifiutato', fn () => Limit::blocks(['approval_status' => 'pending']) && Limit::blocks(['approval_status' => 'rejected'])
    && !Limit::blocks(['approval_status' => 'approved']) && !Limit::blocks([]));

check('le decisioni sono solo dell\'ufficio', fn () => Limit::decisions(['approval_status' => 'pending'], true) === ['approve', 'reject']
    && Limit::decisions(['approval_status' => 'rejected'], true) === ['approve']
    && Limit::decisions(['approval_status' => 'pending'], false) === []
    && Limit::decisions(['approval_status' => 'approved'], true) === []);

check('un ordine bloccato non offre «Conferma»', fn () => !in_array(OrderActions::CONFIRM, OrderActions::available(['status' => 'pending', 'approval_status' => 'pending']), true)
    && in_array(OrderActions::CONFIRM, OrderActions::available(['status' => 'pending', 'approval_status' => 'approved']), true));

check('con la funzionalità spenta non ci sono decisioni', function () {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['sales_agents' => true, 'sales_agent_discount_limits' => false]);

    return !Limit::enabled() && Limit::decisionsNow(['approval_status' => 'pending']) === [];
});
summary();
