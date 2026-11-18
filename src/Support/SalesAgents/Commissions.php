<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentCommission;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentCommissionRule;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Sql\Transaction;

/**
 * Le provvigioni dei commerciali (#77): `sales_agent_commissions` per chi
 * vende, `sales_agent_network_commissions` per chi sta sopra.
 *
 * La base è l'imponibile delle righe vendute (prodotti e righe libere, non
 * spedizione né spese), al netto degli sconti di riga e di testata e delle
 * quantità rese con un reso chiuso. La % di una riga: la regola del prodotto,
 * poi quella della categoria, poi la % del commerciale. Chi sta sopra prende
 * la % del suo livello su tutta la base.
 *
 * `sync()` porta il registro allo stato giusto scrivendo solo la differenza:
 * si può chiamare quante volte si vuole.
 */
final class Commissions
{
    /** Gli stati dell'ordine che contano. */
    public const STATUSES = ['confirmed', 'processing', 'completed'];

    /** Le righe che fanno provvigione. */
    private const LINE_TYPES = ['product', 'custom'];

    public static function enabled(): bool
    {
        return SalesAgents::enabled() && (self::direct() || self::network());
    }

    public static function direct(): bool
    {
        return SalesAgents::enabled() && Gestionale::feature('sales_agent_commissions');
    }

    public static function network(): bool
    {
        return SalesAgentNetwork::enabled() && Gestionale::feature('sales_agent_network_commissions');
    }

    /**
     * L'imponibile di una riga ancora venduta: totale riga meno la sua parte
     * di sconto di testata, senza IVA, per la quota non resa.
     *
     * @param array<string, mixed> $item
     */
    public static function lineBase(array $item, bool $pricesIncludeTax, float $returned = 0.0): float
    {
        $net = (float) ($item['line_total'] ?? 0) - (float) ($item['order_discount_amount'] ?? 0);

        if ($pricesIncludeTax) {
            $net /= 1 + max(0.0, (float) ($item['tax_rate'] ?? 0)) / 100;
        }

        $quantity = (float) ($item['quantity'] ?? 0);
        if ($quantity > 0 && $returned > 0) {
            $net *= max(0.0, $quantity - $returned) / $quantity;
        }

        return round(max(0.0, $net), 2);
    }

    /**
     * La % di un prodotto: regola del prodotto, poi della categoria (la prima
     * in elenco), poi quella del commerciale.
     *
     * @param list<array{category_id: int, product_id: int, percent: float}> $rules
     * @param list<int> $categories le categorie del prodotto, con gli antenati
     */
    public static function rate(int $productId, array $rules, float $default, array $categories): float
    {
        foreach ($rules as $rule) {
            if ($productId > 0 && (int) $rule['product_id'] === $productId) {
                return (float) $rule['percent'];
            }
        }

        foreach ($rules as $rule) {
            if ((int) $rule['product_id'] <= 0 && (int) $rule['category_id'] > 0 && in_array((int) $rule['category_id'], $categories, true)) {
                return (float) $rule['percent'];
            }
        }

        return $default;
    }

    /**
     * La provvigione di chi ha venduto, riga per riga.
     *
     * @param list<array<string, mixed>> $items le righe dell'ordine
     * @param array<int, float> $returned id riga → quantità resa
     * @param list<array{category_id: int, product_id: int, percent: float}> $rules
     * @param callable(int): list<int> $categoriesOf le categorie di un prodotto
     * @return array{base: float, amount: float, rates: list<array{item_id: int, base: float, percent: float, amount: float}>}
     */
    public static function seller(array $items, bool $pricesIncludeTax, array $returned, array $rules, float $default, callable $categoriesOf): array
    {
        $base = 0.0;
        $amount = 0.0;
        $rates = [];

        foreach ($items as $item) {
            // I componenti di una confezione hanno prezzo zero: conta la confezione.
            if (!in_array((string) ($item['type'] ?? ''), self::LINE_TYPES, true) || (int) ($item['parent_item_id'] ?? 0) > 0) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $lineBase = self::lineBase($item, $pricesIncludeTax, (float) ($returned[$id] ?? 0));
            if ($lineBase <= 0) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $percent = self::rate($productId, $rules, $default, $productId > 0 ? $categoriesOf($productId) : []);
            $lineAmount = round($lineBase * $percent / 100, 2);

            $base += $lineBase;
            $amount += $lineAmount;
            $rates[] = ['item_id' => $id, 'base' => $lineBase, 'percent' => $percent, 'amount' => $lineAmount];
        }

        return ['base' => round($base, 2), 'amount' => round($amount, 2), 'rates' => $rates];
    }

    /**
     * Le provvigioni di chi sta sopra: livello per livello sulla stessa base.
     *
     * @param list<int> $upline dal più vicino in su
     * @param array<int, float|null> $levels le % per livello; `null` o oltre = la base
     * @return list<array{agent_id: int, level: int, percent: float, amount: float}>
     */
    public static function upline(float $base, array $upline, array $levels, float $default): array
    {
        $out = [];

        foreach (array_values($upline) as $i => $agentId) {
            $level = $i + 1;
            $percent = $levels[$level] ?? $default;

            if ($percent <= 0 || $agentId <= 0) {
                continue;
            }

            $out[] = ['agent_id' => (int) $agentId, 'level' => $level, 'percent' => (float) $percent, 'amount' => round($base * $percent / 100, 2)];
        }

        return $out;
    }

    /**
     * Vero se l'ordine fa provvigione adesso.
     *
     * @param array<string, mixed> $order
     */
    public static function eligible(array $order, string $trigger): bool
    {
        if ((string) ($order['stage'] ?? 'order') !== 'order'
            || (string) ($order['deleted'] ?? 'false') === 'true'
            || (int) ($order['agent_id'] ?? 0) <= 0
            || !in_array((string) ($order['status'] ?? ''), self::STATUSES, true)) {
            return false;
        }

        return $trigger === 'fulfilled'
            ? (string) ($order['fulfillment_status'] ?? '') === 'fulfilled'
            : in_array((string) ($order['payment_status'] ?? ''), ['paid', 'partially_refunded'], true);
    }

    /**
     * Le righe da scrivere per arrivare a `$target` partendo da `$current`.
     * Chiave `agente:livello`; ogni riga è la differenza, col suo tipo.
     *
     * @param array<string, array{agent_id: int, level: int, base: float, percent: float, amount: float, rates?: list<array<string, mixed>>}> $target
     * @param array<string, array{base: float, amount: float}> $current
     * @return list<array{agent_id: int, level: int, type: string, base: float, percent: float, amount: float, rates: list<array<string, mixed>>}>
     */
    public static function diff(array $target, array $current): array
    {
        $out = [];

        foreach (array_unique([...array_keys($target), ...array_keys($current)]) as $key) {
            $want = $target[$key] ?? null;
            $have = $current[$key] ?? ['base' => 0.0, 'amount' => 0.0];
            $delta = round((float) ($want['amount'] ?? 0) - (float) $have['amount'], 2);

            if (abs($delta) < 0.01) {
                continue;
            }

            [$agentId, $level] = array_map('intval', explode(':', (string) $key));
            $out[] = [
                'agent_id' => $agentId,
                'level' => $level,
                'type' => $delta > 0 ? 'accrual' : 'reversal',
                'base' => round(abs((float) ($want['base'] ?? 0) - (float) $have['base']), 2),
                'percent' => (float) ($want['percent'] ?? 0),
                'amount' => abs($delta),
                'rates' => $want['rates'] ?? [],
            ];
        }

        return $out;
    }

    /** L'importo con il segno. @param array<string, mixed> $row */
    public static function signed(array $row): float
    {
        $amount = (float) ($row['amount'] ?? 0);

        return (string) ($row['type'] ?? 'accrual') === 'reversal' ? -$amount : $amount;
    }

    /** Porta il registro dell'ordine allo stato giusto. Un errore non ferma l'ordine. */
    public static function refresh(int $orderId): void
    {
        if ($orderId <= 0 || !self::enabled()) {
            return;
        }

        try {
            self::sync($orderId);
        } catch (Throwable $error) {
            error_log('[gestionale] provvigioni dell\'ordine '.$orderId.' non aggiornate: '.$error->getMessage());
        }
    }

    /**
     * Ricalcola le provvigioni dell'ordine e scrive solo la differenza.
     *
     * @return int le righe scritte
     */
    public static function sync(int $orderId): int
    {
        return Transaction::run(static function () use ($orderId): int {
            $order = Order::findForUpdate(['id' => $orderId], 1);
            if (!is_array($order) || $order === []) {
                return 0;
            }

            $current = [];
            foreach (self::rows(SalesAgentCommission::findForUpdate(['order_id' => $orderId, 'deleted' => 'false'])) as $row) {
                $key = (int) $row['sales_agent_id'].':'.(int) $row['level'];
                $sign = (string) $row['type'] === 'reversal' ? -1 : 1;
                $current[$key]['base'] = round(($current[$key]['base'] ?? 0) + $sign * (float) $row['base'], 2);
                $current[$key]['amount'] = round(($current[$key]['amount'] ?? 0) + $sign * (float) $row['amount'], 2);
            }

            $setting = Setting::current();
            $target = self::eligible($order, (string) ($setting['agent_commission_trigger'] ?? 'paid'))
                ? self::target($order, $setting)
                : [];

            $now = date('Y-m-d H:i:s');
            $seller = (int) ($order['agent_id'] ?? 0);
            $written = 0;

            foreach (self::diff($target, $current) as $row) {
                SalesAgentCommission::create([
                    'sales_agent_id' => $row['agent_id'],
                    'order_id' => $orderId,
                    'level' => $row['level'],
                    'seller_id' => $seller,
                    'type' => $row['type'],
                    'base' => number_format($row['base'], 2, '.', ''),
                    'percent' => number_format($row['percent'], 2, '.', ''),
                    'amount' => number_format($row['amount'], 2, '.', ''),
                    'rates' => json_encode($row['rates']),
                    'accrued_at' => $now,
                ]);
                $written++;
            }

            return $written;
        });
    }

    /**
     * Lo stato giusto del registro per un ordine che fa provvigione.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $setting
     * @return array<string, array{agent_id: int, level: int, base: float, percent: float, amount: float, rates?: list<array<string, mixed>>}>
     */
    private static function target(array $order, array $setting): array
    {
        $orderId = (int) $order['id'];
        $agentId = (int) $order['agent_id'];
        $agent = SalesAgent::findById($agentId);
        $items = self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']));

        $seller = self::seller(
            $items,
            (string) ($order['prices_include_tax'] ?? 'false') === 'true',
            self::returned($orderId),
            self::rules($agentId),
            is_array($agent) ? (float) ($agent['commission_percent'] ?? 0) : 0.0,
            static fn (int $productId): array => ProductScope::facts($productId)['categories']
        );

        $out = [];

        if (self::direct() && $seller['amount'] > 0) {
            $out[$agentId.':0'] = [
                'agent_id' => $agentId,
                'level' => 0,
                'base' => $seller['base'],
                'percent' => $seller['base'] > 0 ? round($seller['amount'] / $seller['base'] * 100, 2) : 0.0,
                'amount' => $seller['amount'],
                'rates' => $seller['rates'],
            ];
        }

        if (self::network() && $seller['base'] > 0) {
            $levels = SalesAgentSettings::levels((string) ($setting['agent_network_levels'] ?? ''));
            $default = (float) ($setting['agent_network_commission_default'] ?? 0);

            foreach (self::upline($seller['base'], SalesAgentNetwork::upline($agentId), $levels, $default) as $row) {
                if ($row['amount'] <= 0) {
                    continue;
                }
                $out[$row['agent_id'].':'.$row['level']] = [
                    'agent_id' => $row['agent_id'],
                    'level' => $row['level'],
                    'base' => $seller['base'],
                    'percent' => $row['percent'],
                    'amount' => $row['amount'],
                ];
            }
        }

        return $out;
    }

    /**
     * Le regole di un commerciale, in ordine.
     *
     * @return list<array{category_id: int, product_id: int, percent: float}>
     */
    public static function rules(int $agentId): array
    {
        $out = [];

        foreach (self::rows(SalesAgentCommissionRule::find(['sales_agent_id' => $agentId, 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
            $out[] = [
                'category_id' => (int) ($row['category_id'] ?? 0),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'percent' => (float) ($row['percent'] ?? 0),
            ];
        }

        return $out;
    }

    /** Le righe non ancora liquidate, per SQL. */
    public const UNPAID = "(`paid_at` IS NULL OR `paid_at` < '1000-01-02')";

    /**
     * Segna come liquidate le righe scelte (l'ufficio, dall'elenco). Uno
     * storno non liquidato va con le altre: si scala dal pagamento.
     *
     * @param list<int|string> $ids
     * @return array{done: int, failed: int}
     */
    public static function markPaid(array $ids, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $done = 0;
        $failed = 0;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }

            try {
                $row = SalesAgentCommission::findById($id);
                if (!is_array($row) || (string) ($row['deleted'] ?? 'false') === 'true') {
                    $failed++;
                    continue;
                }

                if (self::isPaid($row)) {
                    continue;
                }

                SalesAgentCommission::update(['paid_at' => $now], $id);
                $done++;
            } catch (Throwable $error) {
                error_log('[gestionale] provvigione '.$id.' non liquidata: '.$error->getMessage());
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    public static function isPaid(array $row): bool
    {
        $paid = trim((string) ($row['paid_at'] ?? ''));

        return $paid !== '' && $paid >= '1000-01-02';
    }

    /**
     * Il totale ancora da liquidare (maturate meno storni), dei commerciali
     * dati o di tutti.
     *
     * @param list<int>|null $agents
     */
    public static function outstanding(?array $agents = null): float
    {
        $where = "`deleted` = 'false' AND ".self::UNPAID;
        if ($agents !== null) {
            $where .= ' AND `sales_agent_id` IN ('.implode(',', array_map('intval', $agents ?: [0])).')';
        }

        $connection = SalesAgentCommission::connection();
        $result = $connection->query(
            "SELECT COALESCE(SUM(CASE WHEN `type` = 'reversal' THEN -`amount` ELSE `amount` END), 0) AS `total`"
            .' FROM `'.SalesAgentCommission::$table.'` WHERE '.$where
        );

        $row = $result === false ? null : $result->fetch_assoc();

        return round((float) (is_array($row) ? ($row['total'] ?? 0) : 0), 2);
    }

    /** @return array<int, float> riga d'ordine → quantità resa con un reso chiuso */
    private static function returned(int $orderId): array
    {
        $out = [];

        foreach (self::rows(SalesReturn::find(['order_id' => $orderId, 'status' => 'completed', 'deleted' => 'false'])) as $return) {
            foreach (self::rows(SalesReturnItem::find(['sales_return_id' => (int) $return['id'], 'deleted' => 'false'])) as $item) {
                $id = (int) $item['order_item_id'];
                $out[$id] = ($out[$id] ?? 0.0) + (float) $item['quantity'];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
