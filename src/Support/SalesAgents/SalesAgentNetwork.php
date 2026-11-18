<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * La rete dei commerciali (#77, funzionalità `sales_agent_network`): chi sta
 * sopra a chi, letto da `parent_id`.
 *
 * Il commerciale vede i dati suoi e quelli di tutta la sua rete sotto. Li
 * modifica se il suo `network_edit` lo permette (`true`), o se è `default` e
 * l'impostazione `agent_network_edit` è accesa. I propri dati li modifica
 * sempre. Ogni giro sulla catena ha la guardia contro i cicli.
 */
final class SalesAgentNetwork
{
    /** @var array<int, int>|null id → parent_id */
    private static ?array $parents = null;

    public static function enabled(): bool
    {
        return SalesAgents::enabled() && Gestionale::feature('sales_agent_network');
    }

    /**
     * Tutti i commerciali sotto `$id`, a qualsiasi livello.
     *
     * @param array<int, int> $parents id → parent_id
     * @return list<int>
     */
    public static function descendants(array $parents, int $id): array
    {
        $children = [];
        foreach ($parents as $child => $parent) {
            $children[(int) $parent][] = (int) $child;
        }

        $out = [];
        $seen = [$id => true];
        $queue = $children[$id] ?? [];

        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            $out[] = $next;
            array_push($queue, ...($children[$next] ?? []));
        }

        sort($out);

        return $out;
    }

    /**
     * La catena sopra `$id`, dal più vicino in su.
     *
     * @param array<int, int> $parents
     * @return list<int>
     */
    public static function ancestors(array $parents, int $id): array
    {
        $out = [];
        $seen = [$id => true];
        $current = (int) ($parents[$id] ?? 0);

        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $out[] = $current;
            $current = (int) ($parents[$current] ?? 0);
        }

        return $out;
    }

    /**
     * Vero se `$parent` può stare sopra `$id`: niente se stesso e niente
     * commerciali della sua rete sotto (farebbe un cerchio).
     *
     * @param array<int, int> $parents
     */
    public static function validParent(array $parents, int $id, int $parent): bool
    {
        if ($parent <= 0) {
            return true;
        }

        if ($id <= 0) {
            return true;
        }

        return $parent !== $id && !in_array($parent, self::descendants($parents, $id), true);
    }

    /**
     * Vero se `$viewer` può modificare un dato di `$owner`.
     *
     * @param string $networkEdit `default`, `true` o `false` del commerciale che guarda
     * @param bool $default l'impostazione `agent_network_edit`
     * @param list<int> $downline la rete sotto di `$viewer`
     */
    public static function canEdit(int $viewer, int $owner, string $networkEdit, bool $default, array $downline): bool
    {
        if ($owner <= 0 || $owner === $viewer || !in_array($owner, $downline, true)) {
            return true;
        }

        return match ($networkEdit) {
            'true' => true,
            'false' => false,
            default => $default,
        };
    }

    /**
     * Il commerciale e la sua rete sotto, per `IN (...)`. Con la rete spenta
     * solo lui.
     *
     * @return list<int>
     */
    public static function team(int $id): array
    {
        if ($id <= 0) {
            return [0];
        }

        if (!self::enabled()) {
            return [$id];
        }

        return array_merge([$id], self::descendants(self::parents(), $id));
    }

    /** La rete sotto di `$id`, senza di lui. @return list<int> */
    public static function downline(int $id): array
    {
        return $id > 0 && self::enabled() ? self::descendants(self::parents(), $id) : [];
    }

    /** La catena sopra di `$id`. @return list<int> */
    public static function upline(int $id): array
    {
        return $id > 0 ? self::ancestors(self::parents(), $id) : [];
    }

    /**
     * Vero se chi sta lavorando può modificare un dato di `$owner`. L'ufficio
     * sempre.
     */
    public static function canEditNow(int $owner): bool
    {
        $viewer = SalesAgents::current();
        if ($viewer <= 0 || !self::enabled()) {
            return true;
        }

        try {
            $agent = SalesAgent::findById($viewer);
        } catch (Throwable) {
            $agent = null;
        }

        $mode = is_array($agent) ? (string) ($agent['network_edit'] ?? 'default') : 'default';
        $default = (string) (Setting::current()['agent_network_edit'] ?? 'false') === 'true';

        return self::canEdit($viewer, $owner, $mode, $default, self::downline($viewer));
    }

    /**
     * Blocca la modifica di un dato della rete sotto, quando non è permessa.
     *
     * @param array<string, mixed>|null $oldValues la riga prima della modifica
     */
    public static function guard(?array $oldValues): void
    {
        if ($oldValues !== null && !self::canEditNow((int) ($oldValues['agent_id'] ?? 0))) {
            throw UserError::make('sales_agent.network_read_only');
        }
    }

    /** @return array<int, int> id → parent_id dei commerciali non eliminati */
    public static function parents(): array
    {
        if (self::$parents !== null) {
            return self::$parents;
        }

        try {
            $rows = SalesAgent::find(['deleted' => 'false']) ?: [];
        } catch (Throwable $error) {
            error_log('[gestionale] rete dei commerciali non letta: '.$error->getMessage());

            return self::$parents = [];
        }

        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : array_filter(is_array($rows) ? $rows : [], 'is_array');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row['id'] ?? 0)] = (int) ($row['parent_id'] ?? 0);
        }
        unset($out[0]);

        return self::$parents = $out;
    }

    /** Dimentica la rete letta: dopo una modifica ai commerciali e nei test. */
    public static function forget(): void
    {
        self::$parents = null;
    }

    /** Forza la rete (test). @param array<int, int> $parents */
    public static function use(array $parents): void
    {
        self::$parents = $parents;
    }
}
