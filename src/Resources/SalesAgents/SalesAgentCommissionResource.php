<?php

namespace Wonder\Plugin\Gestionale\Resources\SalesAgents;

use DateTimeImmutable;
use Throwable;
use Wonder\App\Dataset\Column;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Backend\Table\BulkAction;
use Wonder\Elements\Components\Badge;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentCommission;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Backend\ListBulkActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\SalesAgents\Commissions;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentNetwork;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;

/**
 * «Provvigioni» (#77): le righe maturate e stornate dagli ordini, in sola
 * lettura. Le scrive `Commissions::sync`; qui l'ufficio filtra, esporta e
 * segna come liquidate. Il commerciale vede solo le sue e quelle della sua
 * rete, senza azioni.
 */
class SalesAgentCommissionResource extends GestionaleResource
{
    public static string $model = SalesAgentCommission::class;
    public static string $feature = 'sales_agent_commissions';
    public static string $orderColumn = 'accrued_at';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'anagrafiche/commerciali';

    public static function featureActive(): bool
    {
        return Commissions::enabled();
    }

    public static function path(): string
    {
        return 'app/gestionale/provvigioni';
    }

    public static function icon(): string
    {
        return 'bi-cash-coin';
    }

    public static function titleLabel(): string
    {
        return 'Provvigioni';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'provvigione',
            'plural_label' => 'provvigioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'liquidata',
            'empty' => 'da liquidare',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'sales_agent_id' => 'Commerciale',
            'order_id' => 'Ordine',
            'level' => 'Livello',
            'type' => 'Tipo',
            'base' => 'Base',
            'percent' => '%',
            'amount' => 'Importo',
            'accrued_at' => 'Maturata il',
            'paid_at' => 'Liquidata il',
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
            $schema['condition'] .= ' AND `sales_agent_id` IN ('.implode(',', $team).')';
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
            TableColumn::key('sales_agent_id')->text()
                ->formatter(static fn (array $row): string => static::escape(SalesAgents::name((int) ($row['sales_agent_id'] ?? 0)))),
            TableColumn::key('order_id')->text()->size('little')
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0))),
            TableColumn::key('level')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::levelLabel((int) ($row['level'] ?? 0)))),
            TableColumn::key('base')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::money($row['base'] ?? 0))),
            TableColumn::key('percent')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::percentLabel($row['percent'] ?? 0))),
            TableColumn::key('amount')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::money(Commissions::signed($row)))),
            TableColumn::key('accrued_at')->text()->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date((string) ($row['accrued_at'] ?? '')))),
            TableColumn::key('paid_at')->text()->size('little')
                ->formatter(static fn (array $row): string => Commissions::isPaid($row)
                    ? static::escape(OrderSheet::date((string) $row['paid_at']))
                    : Badge::make('Da liquidare')->variant('warning')->inline()->render('bootstrap')),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $layout = TableLayoutSchema::for(static::class)
            ->title()
            ->results()
            ->hideButtonAdd()
            ->filterQuery(
                'Liquidazione',
                'paid',
                ['' => 'Tutte', 'no' => 'Da liquidare', 'yes' => 'Liquidate'],
                static fn (array $values): string => match ((string) ($values[0] ?? '')) {
                    'no' => Commissions::UNPAID,
                    'yes' => 'NOT '.Commissions::UNPAID,
                    default => '1 = 1',
                }
            )
            ->filterQuery(
                'Periodo',
                'period',
                MovementPeriod::filterOptions(),
                static fn (array $values): string => MovementPeriod::sql((string) ($values[0] ?? ''), 'accrued_at', new DateTimeImmutable('now'))
            )
            ->download(['csv'])
            ->fileColumns([
                Column::make('commerciale', 'Commerciale')->value(static fn (array $row): string => SalesAgents::name((int) ($row['sales_agent_id'] ?? 0))),
                Column::make('ordine', 'Ordine')->value(static fn (array $row): string => static::orderNumber((int) ($row['order_id'] ?? 0))),
                Column::make('livello', 'Livello')->value(static fn (array $row): string => static::levelLabel((int) ($row['level'] ?? 0))),
                Column::make('tipo', 'Tipo')->value(static fn (array $row): string => (string) ($row['type'] ?? '') === 'reversal' ? 'Storno' : 'Maturata'),
                Column::make('base', 'Base')->value(static fn (array $row): string => number_format((float) ($row['base'] ?? 0), 2, ',', '')),
                Column::make('percentuale', 'Percentuale')->value(static fn (array $row): string => number_format((float) ($row['percent'] ?? 0), 2, ',', '')),
                Column::make('importo', 'Importo')->value(static fn (array $row): string => number_format(Commissions::signed($row), 2, ',', '')),
                Column::make('maturata', 'Maturata il')->value(static fn (array $row): string => OrderSheet::date((string) ($row['accrued_at'] ?? ''))),
                Column::make('liquidata', 'Liquidata il')->value(static fn (array $row): string => Commissions::isPaid($row) ? OrderSheet::date((string) $row['paid_at']) : ''),
            ])
            ->downloadFileName('provvigioni-'.date('Y-m-d'));

        // Il filtro per commerciale solo all'ufficio: il commerciale vede già la sua rete.
        if (static::team() === null) {
            $layout = $layout->filterCustom('Commerciale', 'sales_agent_id', ['' => 'Tutti'] + SalesAgents::options(false));
        }

        return ListBulkActions::apply($layout, static fn (): array => [
            BulkAction::make('mark_paid')
                ->label('Segna come liquidate')
                ->icon('bi bi-cash-stack')
                ->permission('update', static::class)
                ->transaction(false)
                ->confirm('Segnare come liquidate le provvigioni scelte?')
                ->handle(static fn (array $ids): array => Commissions::markPaid($ids)),
        ]);
    }

    public static function pageSchema(): PageSchema
    {
        $total = static::featureActive() ? static::outstandingLabel() : '';

        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Provvigioni'])
            ->subtitles($total !== '' ? ['list' => $total] : []);
    }

    public static function outstandingLabel(): string
    {
        try {
            return 'Da liquidare: '.OrderSheet::money(Commissions::outstanding(static::team()));
        } catch (Throwable $error) {
            error_log('[gestionale] totale provvigioni non letto: '.$error->getMessage());

            return '';
        }
    }

    /** Elenco per l'ufficio e la rete del commerciale; liquidare solo l'ufficio. */
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
            ->title('Provvigioni')
            ->order(41)
            ->authority(static::viewers())
            ->enabled(static::featureActive());
    }

    /** @return list<string> */
    private static function viewers(): array
    {
        return array_values(array_unique([...Access::viewers(Gestionale::area('commerciali')), SalesAgents::ROLE]));
    }

    public static function levelLabel(int $level): string
    {
        return $level <= 0 ? 'Vendita' : 'Rete, livello '.$level;
    }

    public static function percentLabel(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',').' %';
    }

    private static function orderNumber(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }

        try {
            $order = Order::findById($orderId);
        } catch (Throwable) {
            $order = null;
        }

        $number = is_array($order) ? trim((string) ($order['order_number'] ?? '')) : '';

        return $number !== '' ? $number : '#'.$orderId;
    }

    public static function listUrl(): string
    {
        $fallback = '/backend/'.static::path();

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.list');
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    private static function orderLink(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId, static::listUrl())).'">'
            .static::escape(static::orderNumber($orderId)).'</a>';
    }
}
