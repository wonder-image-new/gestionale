<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentPriceList;

/**
 * I listini dei commerciali (#77, funzionalità `sales_agent_price_lists`).
 *
 * L'ufficio dà a ogni commerciale un elenco di listini (anche vuoto: solo
 * prezzi di catalogo) e un predefinito per i clienti nuovi. Il listino resta
 * sul cliente: qui si decide solo quali può scegliere il commerciale.
 */
final class SalesAgentPriceLists
{
    /** @var array<int, array{lists: list<int>, default: int}> id del commerciale → elenco e predefinito */
    private static array $cache = [];

    public static function enabled(): bool
    {
        return SalesAgents::enabled()
            && Gestionale::feature('sales_agent_price_lists')
            && Gestionale::feature('customer_price_lists');
    }

    /** @return list<int> */
    public static function of(int $agentId): array
    {
        return self::read($agentId)['lists'];
    }

    /** Il predefinito, solo se è ancora nell'elenco; altrimenti 0. */
    public static function defaultOf(int $agentId): int
    {
        $row = self::read($agentId);

        return in_array($row['default'], $row['lists'], true) ? $row['default'] : 0;
    }

    /**
     * Scrive esattamente questo elenco e il predefinito.
     *
     * @param list<int> $ids
     */
    public static function assign(int $agentId, array $ids, int $default): void
    {
        if ($agentId <= 0) {
            return;
        }

        $ids = self::ids($ids);
        SalesAgentPriceList::query()->Delete(SalesAgentPriceList::$table, 'WHERE `sales_agent_id` = '.$agentId);

        foreach ($ids as $id) {
            SalesAgentPriceList::create(['sales_agent_id' => $agentId, 'price_list_id' => $id]);
        }

        SalesAgent::update(['default_price_list_id' => in_array($default, $ids, true) ? $default : 0], ['id' => $agentId]);
        unset(self::$cache[$agentId]);
    }

    /**
     * I listini ammessi per chi sta lavorando: null se non c'è limite
     * (ufficio o funzionalità spenta), altrimenti l'elenco del commerciale.
     *
     * @return list<int>|null
     */
    public static function allowed(): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        $agent = SalesAgents::current();

        return $agent > 0 ? self::of($agent) : null;
    }

    /**
     * Le scelte del campo «Listino»: senza limite tutte, altrimenti solo
     * quelle ammesse più il valore già salvato (che si vede ma non si rimette).
     *
     * @param array<int, string> $options id ⇒ nome
     * @param list<int>|null $allowed
     * @return array<int, string>
     */
    public static function choices(array $options, ?array $allowed, int $keep = 0): array
    {
        if ($allowed === null) {
            return $options;
        }

        return array_filter(
            $options,
            static fn (int $id): bool => in_array($id, $allowed, true) || ($keep > 0 && $id === $keep),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Vero se il listino si può salvare: catalogo, il listino che c'era già
     * o uno dell'elenco.
     *
     * @param list<int>|null $allowed
     */
    public static function accepts(?array $allowed, int $requested, int $previous = 0): bool
    {
        return $allowed === null
            || $requested <= 0
            || $requested === $previous
            || in_array($requested, $allowed, true);
    }

    /** Il listino per un cliente nuovo creato dal commerciale; 0 se nessuno. */
    public static function forNew(): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $agent = SalesAgents::current();

        return $agent > 0 ? self::defaultOf($agent) : 0;
    }

    /** Dimentica gli elenchi letti: dopo una modifica e nei test. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    public static function ids(array $values): array
    {
        $ids = array_map('intval', array_values($values));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** @return array{lists: list<int>, default: int} */
    private static function read(int $agentId): array
    {
        if ($agentId <= 0) {
            return ['lists' => [], 'default' => 0];
        }

        if (!isset(self::$cache[$agentId])) {
            try {
                $found = SalesAgentPriceList::find(['sales_agent_id' => $agentId]);
                $rows = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
                $agent = SalesAgent::find(['id' => $agentId]);

                self::$cache[$agentId] = [
                    'lists' => self::ids(array_map(static fn (array $row): int => (int) ($row['price_list_id'] ?? 0), $rows)),
                    'default' => (int) (is_array($agent) ? ($agent['default_price_list_id'] ?? 0) : 0),
                ];
            } catch (Throwable $error) {
                error_log('[gestionale] listini del commerciale '.$agentId.' non letti: '.$error->getMessage());
                self::$cache[$agentId] = ['lists' => [], 'default' => 0];
            }
        }

        return self::$cache[$agentId];
    }
}
