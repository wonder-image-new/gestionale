<?php

namespace Wonder\Plugin\Gestionale\Resources\SalesAgents;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentNetwork;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;
use Wonder\Plugin\Gestionale\Support\SalesAgents\Targets;

/**
 * «Andamento commerciali» (#77, `sales_agent_targets`): per ogni commerciale
 * l'obiettivo del mese e dell'anno con il venduto, e i numeri degli ultimi
 * 12 mesi. Sola lettura. Il commerciale vede sé e la sua rete.
 */
class SalesAgentPerformanceResource extends GestionaleResource
{
    public static string $model = SalesAgent::class;
    public static string $feature = 'sales_agent_targets';
    public static string $docsPage = 'anagrafiche/commerciali';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public static function featureActive(): bool
    {
        return Targets::enabled();
    }

    public static function path(): string
    {
        return 'app/gestionale/andamento-commerciali';
    }

    public static function icon(): string
    {
        return 'bi-graph-up-arrow';
    }

    public static function titleLabel(): string
    {
        return 'Andamento commerciali';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'commerciale',
            'plural_label' => 'commerciali',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'user_id' => 'Commerciale',
            'month_target' => 'Questo mese',
            'year_target' => 'Quest\'anno',
            'revenue_12' => 'Fatturato 12 mesi',
            'orders_12' => 'Ordini 12 mesi',
            'new_customers_12' => 'Clienti nuovi 12 mesi',
        ];
    }

    public static function formSchema(): array
    {
        return [];
    }

    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "`deleted` = 'false'";

        $team = static::team();
        if ($team !== null) {
            $schema['condition'] .= ' AND `id` IN ('.implode(',', $team ?: [0]).')';
        }

        return $schema;
    }

    /** I commerciali visibili: null per l'ufficio (tutti). @return list<int>|null */
    public static function team(): ?array
    {
        if (!SalesAgents::restricted(RowVisibility::currentUser())) {
            return null;
        }

        return SalesAgentNetwork::team(SalesAgents::current());
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('user_id')->text()
                ->formatter(static fn (array $row): string => static::escape(SalesAgents::label($row))),
            TableColumn::key('month_target')->text()
                ->formatter(static fn (array $row): string => static::escape(static::cell((int) ($row['id'] ?? 0), 'month'))),
            TableColumn::key('year_target')->text()
                ->formatter(static fn (array $row): string => static::escape(static::cell((int) ($row['id'] ?? 0), 'year'))),
            TableColumn::key('revenue_12')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::money(static::last12((int) ($row['id'] ?? 0))['revenue']))),
            TableColumn::key('orders_12')->text()->size('little')
                ->formatter(static fn (array $row): string => (string) static::last12((int) ($row['id'] ?? 0))['orders']),
            TableColumn::key('new_customers_12')->text()->size('little')
                ->formatter(static fn (array $row): string => (string) static::last12((int) ($row['id'] ?? 0))['new_customers']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title()
            ->results()
            ->hideButtonAdd();
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Andamento commerciali'])
            ->subtitles(static::featureActive() ? ['list' => 'Obiettivi su: '.Targets::metricLabel()] : []);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->access(Gestionale::area('commerciali'))
            ->backend(['list'], static::viewers());
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 320)
            ->title('Andamento commerciali')
            ->order(42)
            ->authority(static::viewers())
            ->enabled(static::featureActive());
    }

    /** @return list<string> */
    private static function viewers(): array
    {
        return array_values(array_unique([...Access::viewers(Gestionale::area('commerciali')), SalesAgents::ROLE]));
    }

    /** «venduto su obiettivo (%)» del mese o dell'anno corrente. */
    public static function cell(int $agentId, string $period): string
    {
        $data = static::data();
        $metric = (string) $data['metric'];
        $month = $period === 'month' ? (int) $data['month'] : 0;
        $target = Targets::pick($data['targets'][$agentId] ?? [], (int) $data['year'], $month);
        $actual = (float) ($data[$period === 'month' ? 'month_actual' : 'year_actual'][$agentId][$metric] ?? 0);

        return Targets::summary($metric, $target, $actual);
    }

    /** @return array{revenue: float, orders: int, new_customers: int} */
    public static function last12(int $agentId): array
    {
        return static::data()['last12'][$agentId] ?? ['revenue' => 0.0, 'orders' => 0, 'new_customers' => 0];
    }

    /** I numeri di tutti i commerciali visibili, letti una volta per pagina. @return array<string, mixed> */
    private static function data(): array
    {
        if (static::$data !== null) {
            return static::$data;
        }

        $year = (int) date('Y');
        $month = (int) date('n');
        $data = ['metric' => Targets::metric(), 'year' => $year, 'month' => $month, 'targets' => [], 'month_actual' => [], 'year_actual' => [], 'last12' => []];

        try {
            $ids = static::team() ?? static::allIds();
            // Ultimi 12 mesi: dal primo del mese di 11 mesi fa a fine mese corrente.
            $start = strtotime('-11 months', mktime(0, 0, 0, $month, 1, $year));
            [$from12] = Targets::bounds((int) date('Y', $start), (int) date('n', $start));
            [, $to12] = Targets::bounds($year, $month);

            $data['targets'] = Targets::rowsFor($ids);
            $data['month_actual'] = Targets::actuals($ids, ...Targets::bounds($year, $month));
            $data['year_actual'] = Targets::actuals($ids, ...Targets::bounds($year));
            $data['last12'] = Targets::actuals($ids, $from12, $to12);
        } catch (Throwable $error) {
            error_log('[gestionale] andamento commerciali non letto: '.$error->getMessage());
        }

        return static::$data = $data;
    }

    /** @return list<int> */
    private static function allIds(): array
    {
        $found = SalesAgent::find(['deleted' => 'false']);
        if (!is_array($found) || $found === []) {
            return [];
        }

        $rows = isset($found['id']) ? [$found] : array_filter($found, 'is_array');

        return array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
    }
}
