<?php
/** php tests/SalesAgentCouponsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentCoupons;

$forza = static function (array $features): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $features);
};

check('il coupon ha la colonna del commerciale', function () {
    $colonne = array_map(static fn ($c) => (string) $c->name, Coupon::tableSchema());

    return in_array('agent_id', $colonne, true);
});

check('la funzionalità vuole anche i commerciali accesi', function () use ($forza) {
    $forza(['sales_agents' => false, 'sales_agent_coupons' => true]);
    $senza = SalesAgentCoupons::enabled();
    $forza(['sales_agents' => true, 'sales_agent_coupons' => true]);

    return $senza === false && SalesAgentCoupons::enabled() === true;
});

check('vince il primo coupon applicato che ha un commerciale', fn () => SalesAgentCoupons::agentOf([0, 4, 9]) === 4
    && SalesAgentCoupons::agentOf([]) === 0
    && SalesAgentCoupons::agentOf([0, 0]) === 0);

check('l\'ordine va sempre al commerciale del codice', fn () => SalesAgentCoupons::plan(['agent_id' => 2, 'customer_id' => 5], 7, 2)
    === ['order' => true, 'customer' => false]);

check('il cliente passa al commerciale solo se libero', fn () => SalesAgentCoupons::plan(['agent_id' => 0, 'company_id' => 3], 7, 0)
    === ['order' => true, 'customer' => true]);

check('ordine già suo e senza cliente: niente da scrivere', fn () => SalesAgentCoupons::plan(['agent_id' => 7], 7, 0)
    === ['order' => false, 'customer' => false]);

check('senza commerciale non si scrive nulla', fn () => SalesAgentCoupons::plan(['customer_id' => 5], 0, 0)
    === ['order' => false, 'customer' => false]);

summary();
