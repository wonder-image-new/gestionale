<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentTarget;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * Gli obiettivi dei commerciali (#77, `sales_agent_targets`): un valore per
 * mese o per anno, misurato sulla metrica delle impostazioni (fatturato,
 * ordini o clienti nuovi). Contano solo le vendite proprie del commerciale,
 * non quelle della sua rete. Fatturato = imponibile degli ordini validi.
 */
final class Targets
{
    public static function enabled(): bool
    {
        return SalesAgents::enabled() && Gestionale::feature('sales_agent_targets');
    }

    public static function metric(): string
    {
        try {
            $metric = (string) (Setting::current()['agent_target_metric'] ?? 'revenue');
        } catch (Throwable) {
            $metric = 'revenue';
        }

        return isset(SalesAgentSettings::METRICS[$metric]) ? $metric : 'revenue';
    }

    public static function metricLabel(?string $metric = null): string
    {
        return SalesAgentSettings::METRICS[$metric ?? self::metric()] ?? 'Fatturato';
    }

    /**
     * Obiettivo e venduto: `percent` è null senza obiettivo, `missing` quanto
     * manca (0 se raggiunto).
     *
     * @return array{target: float, actual: float, percent: float|null, missing: float}
     */
    public static function progress(float $target, float $actual): array
    {
        return [
            'target' => $target,
            'actual' => $actual,
            'percent' => $target > 0 ? round($actual / $target * 100, 1) : null,
            'missing' => round(max(0.0, $target - $actual), 2),
        ];
    }

    /**
     * L'obiettivo di un periodo fra le righe del commerciale: `month` 0 è
     * l'anno. Vale la prima riga che corrisponde; null se non c'è.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function pick(array $rows, int $year, int $month = 0): ?float
    {
        $period = $month > 0 ? 'month' : 'year';

        foreach ($rows as $row) {
            if ((string) ($row['deleted'] ?? 'false') === 'true') {
                continue;
            }

            if ((string) ($row['period'] ?? 'month') !== $period || (int) ($row['year'] ?? 0) !== $year) {
                continue;
            }

            if ($period === 'month' && (int) ($row['month'] ?? 0) !== $month) {
                continue;
            }

            return (float) ($row['amount'] ?? 0);
        }

        return null;
    }

    /**
     * Righe pulite dal form: anno valido, mese 1-12 per «Mese» e 0 per «Anno».
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        $period = (string) ($row['period'] ?? 'month') === 'year' ? 'year' : 'month';
        $row['period'] = $period;
        $row['year'] = (int) ($row['year'] ?? 0);
        $row['month'] = $period === 'year' ? 0 : (int) ($row['month'] ?? 0);

        return $row;
    }

    /** Vero se la riga è un obiettivo valido. @param array<string, mixed> $row */
    public static function valid(array $row): bool
    {
        $row = self::normalize($row);

        return $row['year'] >= 2000 && $row['year'] <= 2100
            && ($row['period'] === 'year' || ($row['month'] >= 1 && $row['month'] <= 12))
            && is_numeric(str_replace(',', '.', (string) ($row['amount'] ?? '')))
            && (float) str_replace(',', '.', (string) $row['amount']) > 0;
    }

    /** Inizio e fine (esclusa) del periodo. @return array{0: string, 1: string} */
    public static function bounds(int $year, int $month = 0): array
    {
        if ($month > 0) {
            $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $to = $month === 12 ? sprintf('%04d-01-01 00:00:00', $year + 1) : sprintf('%04d-%02d-01 00:00:00', $year, $month + 1);

            return [$from, $to];
        }

        return [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)];
    }

    /** Gli obiettivi dei commerciali, per id. @param list<int> $agentIds @return array<int, list<array<string, mixed>>> */
    public static function rowsFor(array $agentIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $agentIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $result = SalesAgentTarget::connection()->query(
            'SELECT * FROM `'.SalesAgentTarget::$table.'` WHERE `deleted` = \'false\' AND `sales_agent_id` IN ('.implode(',', $ids).') ORDER BY `position` ASC, `id` ASC'
        );

        $out = [];
        while ($result !== false && is_array($row = $result->fetch_assoc())) {
            $out[(int) $row['sales_agent_id']][] = $row;
        }

        return $out;
    }

    /**
     * Fatturato, ordini e clienti nuovi dei commerciali in [from, to). Cliente
     * nuovo: il primo ordine valido di quel cliente cade nel periodo.
     *
     * @param list<int> $agentIds
     * @return array<int, array{revenue: float, orders: int, new_customers: int}>
     */
    public static function actuals(array $agentIds, string $from, string $to): array
    {
        $ids = array_values(array_filter(array_map('intval', $agentIds), static fn (int $id): bool => $id > 0));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['revenue' => 0.0, 'orders' => 0, 'new_customers' => 0];
        }

        if ($ids === []) {
            return $out;
        }

        $connection = Order::connection();
        $table = '`'.Order::$table.'`';
        $valid = self::validSql('o');
        $statuses = self::validSql('p');

        $result = $connection->query(
            'SELECT o.`agent_id` AS `agent`, COALESCE(SUM(o.`taxable_total`), 0) AS `revenue`, COUNT(*) AS `orders`,'
            .' COUNT(DISTINCT CASE WHEN o.`customer_id` > 0 AND NOT EXISTS ('
            .'SELECT 1 FROM '.$table.' p WHERE p.`customer_id` = o.`customer_id` AND '.$statuses
            .' AND p.`ordered_at` < \''.$connection->real_escape_string($from).'\''
            .') THEN o.`customer_id` END) AS `new_customers`'
            .' FROM '.$table.' o WHERE '.$valid
            .' AND o.`agent_id` IN ('.implode(',', $ids).')'
            .' AND o.`ordered_at` >= \''.$connection->real_escape_string($from).'\''
            .' AND o.`ordered_at` < \''.$connection->real_escape_string($to).'\''
            .' GROUP BY o.`agent_id`'
        );

        while ($result !== false && is_array($row = $result->fetch_assoc())) {
            $out[(int) $row['agent']] = [
                'revenue' => round((float) $row['revenue'], 2),
                'orders' => (int) $row['orders'],
                'new_customers' => (int) $row['new_customers'],
            ];
        }

        return $out;
    }

    /** Il valore della metrica, formattato: euro per il fatturato. */
    public static function format(string $metric, float $value): string
    {
        return $metric === 'revenue' ? OrderSheet::money($value) : number_format($value, 0, ',', '.');
    }

    /** Il testo «venduto su obiettivo (%)», vuoto senza obiettivo. */
    public static function summary(string $metric, ?float $target, float $actual): string
    {
        if ($target === null || $target <= 0) {
            return self::format($metric, $actual);
        }

        $progress = self::progress($target, $actual);

        return self::format($metric, $actual).' su '.self::format($metric, $target)
            .' ('.number_format((float) $progress['percent'], 0, ',', '.').' %)';
    }

    private static function validSql(string $alias): string
    {
        return $alias.".`deleted` = 'false' AND ".$alias.".`stage` = 'order' AND ".$alias.'.`status` IN (\''
            .implode("','", Commissions::STATUSES).'\') AND '.$alias.'.`ordered_at` IS NOT NULL';
    }
}
