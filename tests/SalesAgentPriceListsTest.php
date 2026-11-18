<?php
/** php tests/SalesAgentPriceListsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentPriceList;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentPriceLists as Lists;

// Elenchi dei commerciali forzati senza database.
$cache = static function (array $mappa): void {
    (new ReflectionProperty(Lists::class, 'cache'))->setValue(null, $mappa);
};
$opzioni = [3 => 'Rivenditori', 5 => 'Grossisti', 8 => 'Promo'];

check('la tabella ponte e la colonna del predefinito esistono', function () {
    $ponte = array_map(static fn ($c) => (string) $c->name, SalesAgentPriceList::tableSchema());
    $agente = array_map(static fn ($c) => (string) $c->name, SalesAgent::tableSchema());

    return SalesAgentPriceList::$table === 'gst_sales_agent_price_lists'
        && $ponte === ['sales_agent_id', 'price_list_id']
        && in_array('default_price_list_id', $agente, true);
});

check('la funzionalità è costruita e dipende da commerciali e listini cliente', function () {
    $catalogo = require __DIR__.'/../config/features.php';
    $trovata = $catalogo['sales_agent_price_lists'] ?? null;

    return is_array($trovata)
        && !array_key_exists('created', $trovata)
        && array_diff(['sales_agents', 'customer_price_lists'], (array) ($trovata['requires'] ?? [])) === [];
});

check('senza limite le scelte restano tutte', fn () => Lists::choices($opzioni, null, 0) === $opzioni);

check('con un elenco restano solo i suoi listini', fn () => Lists::choices($opzioni, [5], 0) === [5 => 'Grossisti']);

check('elenco vuoto: nessun listino, solo catalogo', fn () => Lists::choices($opzioni, [], 0) === []);

check('il listino già sul cliente resta fra le scelte', fn () => Lists::choices($opzioni, [5], 8) === [5 => 'Grossisti', 8 => 'Promo']);

check('senza limite si accetta tutto', fn () => Lists::accepts(null, 8, 0));

check('il catalogo si accetta sempre', fn () => Lists::accepts([], 0, 8));

check('il listino che c\'era già si accetta anche fuori elenco', fn () => Lists::accepts([5], 8, 8));

check('un listino dell\'elenco si accetta', fn () => Lists::accepts([3, 5], 5, 0));

check('un listino fuori elenco si rifiuta', fn () => !Lists::accepts([5], 8, 0) && !Lists::accepts([5], 8, 3));

check('il predefinito vale solo se è nell\'elenco', function () use ($cache) {
    $cache([
        7 => ['lists' => [3, 5], 'default' => 5],
        9 => ['lists' => [3], 'default' => 5],
    ]);
    $risultato = Lists::defaultOf(7) === 5 && Lists::defaultOf(9) === 0 && Lists::of(9) === [3];
    Lists::forget();

    return $risultato;
});

check('con la funzionalità spenta non c\'è limite né predefinito', function () {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['sales_agents' => true, 'customer_price_lists' => true, 'sales_agent_price_lists' => false]);

    return Lists::allowed() === null && Lists::forNew() === 0;
});

check('gli id si puliscono: interi positivi senza doppioni', fn () => Lists::ids(['3', 5, '5', 0, '', 'x', -2]) === [3, 5]);
