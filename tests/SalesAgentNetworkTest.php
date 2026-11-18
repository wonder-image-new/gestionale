<?php
/** php tests/SalesAgentNetworkTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentNetwork;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentSettings;

$forza = static function (array $features): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $features);
};

// A(1) ← B(2) ← C(3); D(4) sotto A; E(5) da solo.
$rete = [1 => 0, 2 => 1, 3 => 2, 4 => 1, 5 => 0];

check('la rete sotto prende tutti i livelli', fn () => SalesAgentNetwork::descendants($rete, 1) === [2, 3, 4]
    && SalesAgentNetwork::descendants($rete, 2) === [3]
    && SalesAgentNetwork::descendants($rete, 5) === []);

check('un cerchio non manda in giro la rete', function () {
    $cerchio = [1 => 3, 2 => 1, 3 => 2];

    return SalesAgentNetwork::descendants($cerchio, 1) === [2, 3]
        && SalesAgentNetwork::ancestors($cerchio, 1) === [3, 2];
});

check('la catena sopra va dal più vicino in su', fn () => SalesAgentNetwork::ancestors($rete, 3) === [2, 1]
    && SalesAgentNetwork::ancestors($rete, 1) === []);

check('non si mette sopra se stesso né uno della rete sotto', fn () => SalesAgentNetwork::validParent($rete, 1, 3) === false
    && SalesAgentNetwork::validParent($rete, 2, 2) === false
    && SalesAgentNetwork::validParent($rete, 3, 4) === true
    && SalesAgentNetwork::validParent($rete, 3, 0) === true
    && SalesAgentNetwork::validParent($rete, 0, 1) === true);

check('i propri dati e quelli fuori rete si modificano sempre', fn () => SalesAgentNetwork::canEdit(1, 1, 'false', false, [2, 3])
    && SalesAgentNetwork::canEdit(1, 0, 'false', false, [2, 3])
    && SalesAgentNetwork::canEdit(1, 9, 'false', false, [2, 3]));

check('la rete sotto segue il commerciale o le impostazioni', fn () => SalesAgentNetwork::canEdit(1, 3, 'true', false, [2, 3])
    && !SalesAgentNetwork::canEdit(1, 3, 'false', true, [2, 3])
    && SalesAgentNetwork::canEdit(1, 3, 'default', true, [2, 3])
    && !SalesAgentNetwork::canEdit(1, 3, 'default', false, [2, 3]));

check('con la rete spenta il commerciale vede solo i suoi', function () use ($forza, $rete) {
    SalesAgentNetwork::use($rete);
    $forza(['sales_agents' => true, 'sales_agent_network' => false]);
    $spenta = SalesAgentNetwork::team(1);
    $forza(['sales_agents' => true, 'sales_agent_network' => true]);
    $accesa = SalesAgentNetwork::team(1);
    SalesAgentNetwork::forget();

    return $spenta === [1] && $accesa === [1, 2, 3, 4] && SalesAgentNetwork::team(0) === [0];
});

check('le % di rete livello per livello', fn () => SalesAgentSettings::levels('3, 1') === [1 => 3.0, 2 => 1.0]
    && SalesAgentSettings::levels('2,5; ; 1') === [1 => 2.5, 2 => null, 3 => 1.0]
    && SalesAgentSettings::levels('4;;') === [1 => 4.0]
    && SalesAgentSettings::levels('') === []);

check('le % si riscrivono con la virgola decimale', fn () => SalesAgentSettings::joinLevels([1 => 2.5, 2 => null, 3 => 1.0]) === '2,5; ; 1');

check('una % sta fra 0 e 100', fn () => SalesAgentSettings::percent('150') === 100.0
    && SalesAgentSettings::percent('-3') === 0.0
    && SalesAgentSettings::percent('12,345') === 12.35
    && SalesAgentSettings::percent('abc') === null
    && SalesAgentSettings::percent('') === null);

summary();
