<?php

namespace Wonder\Plugin\Gestionale\Models\SalesAgents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una regola di provvigione di un commerciale (#77, `sales_agent_commissions`):
 * la % per un prodotto o per una categoria (con le sue sotto-categorie).
 * Il prodotto vince sulla categoria, la categoria sulla % del commerciale;
 * fra due categorie vale la prima in elenco.
 */
final class SalesAgentCommissionRule extends Model
{
    public static string $table = 'gst_sales_agent_commission_rules';
    public static string $folder = 'gestionale/sales-agents';
    public static string $icon = 'bi bi-percent';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_agent_id')->int()->null(false)->foreign(SalesAgent::$table),
            Column::key('category_id')->int()->default(0),
            Column::key('product_id')->int()->default(0),
            Columns::decimal('percent', '5,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_agent' => ['index' => 'sales_agent_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('sales_agent_id')->number()->decimals(0),
            Field::key('category_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('percent')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
