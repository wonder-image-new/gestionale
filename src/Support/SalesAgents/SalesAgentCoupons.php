<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * I codici dei commerciali (#77, funzionalità `sales_agent_coupons`).
 *
 * L'ufficio dà un coupon a un commerciale (`gst_coupons.agent_id`). Quando un
 * ordine usa il codice, l'ordine va al commerciale del primo coupon applicato
 * che ne ha uno; il cliente (azienda, altrimenti persona) passa a lui solo se
 * non aveva già un commerciale. Il commerciale vede i suoi coupon in sola lettura.
 */
final class SalesAgentCoupons
{
    public static function enabled(): bool
    {
        return SalesAgents::enabled() && Gestionale::feature('sales_agent_coupons');
    }

    /**
     * Il commerciale dei coupon applicati, nell'ordine in cui sono applicati.
     *
     * @param list<int> $agents
     */
    public static function agentOf(array $agents): int
    {
        foreach ($agents as $agent) {
            if ((int) $agent > 0) {
                return (int) $agent;
            }
        }

        return 0;
    }

    /**
     * Cosa scrivere: l'ordine sempre, il cliente solo se libero.
     *
     * @param array<string, mixed> $order con `agent_id`, `company_id`, `customer_id`
     * @param int $customerAgent il commerciale di oggi del cliente dell'ordine
     * @return array{order: bool, customer: bool}
     */
    public static function plan(array $order, int $agent, int $customerAgent): array
    {
        if ($agent <= 0) {
            return ['order' => false, 'customer' => false];
        }

        $hasCustomer = (int) ($order['company_id'] ?? 0) > 0 || (int) ($order['customer_id'] ?? 0) > 0;

        return [
            'order' => (int) ($order['agent_id'] ?? 0) !== $agent,
            'customer' => $hasCustomer && $customerAgent <= 0,
        ];
    }

    /**
     * Attribuisce l'ordine (e il cliente libero) al commerciale del codice.
     * Un errore si scrive nel log: non blocca mai l'ordine.
     *
     * @param array<string, mixed> $order la testata
     */
    public static function attribute(int $orderId, array $order, int $agent): void
    {
        if (!self::enabled() || $orderId <= 0 || $agent <= 0) {
            return;
        }

        try {
            $companyId = (int) ($order['company_id'] ?? 0);
            $customerId = (int) ($order['customer_id'] ?? 0);
            $current = $companyId > 0 ? SalesAgents::forCompany($companyId) : SalesAgents::forContact($customerId);
            $plan = self::plan($order, $agent, $current);

            if ($plan['order']) {
                Order::update(['agent_id' => $agent], $orderId);
            }

            if ($plan['customer']) {
                $companyId > 0
                    ? Company::update(['agent_id' => $agent], $companyId)
                    : Contact::update(['agent_id' => $agent], $customerId);
            }
        } catch (Throwable $error) {
            error_log('[gestionale] ordine '.$orderId.' non attribuito al commerciale '.$agent.': '.$error->getMessage());
        }
    }

    /** Regola di visibilità dei coupon: il commerciale vede i suoi e quelli della sua rete. */
    public static function rule(object $user): ?string
    {
        if (!SalesAgents::restricted($user)) {
            return null;
        }

        $id = SalesAgents::idOf($user);

        return $id > 0 && self::enabled() ? '`agent_id` IN ('.SalesAgents::scope($id).')' : RowVisibility::none();
    }

    public static function register(): void
    {
        RowVisibility::rule(Coupon::$table, static fn (object $user): ?string => self::rule($user));
    }
}
