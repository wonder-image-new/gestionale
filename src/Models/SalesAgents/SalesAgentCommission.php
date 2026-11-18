<?php

namespace Wonder\Plugin\Gestionale\Models\SalesAgents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Il registro delle provvigioni (#77): una riga per ogni movimento, mai
 * riscritta. `accrual` aggiunge, `reversal` toglie (l'importo è sempre
 * positivo, il segno lo dà il tipo): un reso o un annullamento dopo la
 * liquidazione scala dalla prossima.
 *
 * `level` 0 è la provvigione di chi ha venduto (`seller_id`), 1 quella di
 * chi sta subito sopra, e così via. `rates` è il dettaglio per riga
 * d'ordine (JSON), per spiegare l'importo.
 */
final class SalesAgentCommission extends Model
{
    public const TYPES = ['accrual', 'reversal'];

    public static string $table = 'gst_sales_agent_commissions';
    public static string $folder = 'gestionale/sales-agents';
    public static string $icon = 'bi bi-cash-coin';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_agent_id')->int()->null(false)->foreign(SalesAgent::$table),
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('level')->int()->default(0),
            Column::key('seller_id')->int()->default(0),
            Column::key('type')->enum(self::TYPES)->default('accrual'),
            Columns::decimal('base', '12,2'),
            Columns::decimal('percent', '5,2'),
            Columns::decimal('amount', '12,2'),
            Column::key('rates')->type('TEXT'),
            Column::key('accrued_at')->datetime(),
            Column::key('paid_at')->datetime(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_agent' => ['index' => 'sales_agent_id'],
            'ind_order' => ['index' => 'order_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('sales_agent_id')->number()->decimals(0),
            Field::key('order_id')->number()->decimals(0),
            Field::key('level')->number()->decimals(0),
            Field::key('seller_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('base')->number()->decimals(2),
            Field::key('percent')->number()->decimals(2),
            Field::key('amount')->number()->decimals(2),
            Field::key('rates')->text()->sanitize(false),
            Field::key('accrued_at')->date(),
            Field::key('paid_at')->date(),
            Field::key('note')->text(),
        ];
    }
}
