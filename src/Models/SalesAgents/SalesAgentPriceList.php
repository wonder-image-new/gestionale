<?php

namespace Wonder\Plugin\Gestionale\Models\SalesAgents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Sql\TableSchema as Column;

/**
 * I listini che un commerciale può applicare ai suoi clienti
 * (`sales_agent_price_lists`, #77). Lo stesso listino può stare su più
 * commerciali.
 */
final class SalesAgentPriceList extends Model
{
    public static string $table = 'gst_sales_agent_price_lists';
    public static string $folder = 'gestionale/sales-agents';
    public static string $icon = 'bi bi-tags';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_agent_id')->int()->null(false)->foreign(SalesAgent::$table),
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_sales_agent' => ['index' => 'sales_agent_id'],
            'ind_price_list' => ['index' => 'price_list_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('sales_agent_id')->number()->decimals(0),
            Field::key('price_list_id')->number()->decimals(0),
        ];
    }
}
