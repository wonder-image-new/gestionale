<?php

namespace Wonder\Plugin\Gestionale\Models\SalesAgents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un obiettivo di un commerciale (#77, `sales_agent_targets`): il valore da
 * raggiungere in un mese o in un anno, sulla metrica scelta nelle
 * impostazioni. `month` è 0 per l'obiettivo dell'anno.
 */
final class SalesAgentTarget extends Model
{
    public static string $table = 'gst_sales_agent_targets';
    public static string $folder = 'gestionale/sales-agents';
    public static string $icon = 'bi bi-bullseye';

    public const PERIODS = ['month' => 'Mese', 'year' => 'Anno'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('sales_agent_id')->int()->null(false)->foreign(SalesAgent::$table),
            Column::key('period')->enum(array_keys(self::PERIODS))->default('month'),
            Column::key('year')->int()->default(0),
            Column::key('month')->int()->default(0),
            Columns::decimal('amount', '12,2'),
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
            Field::key('period')->text()->sanitize(false),
            Field::key('year')->number()->decimals(0),
            Field::key('month')->number()->decimals(0),
            Field::key('amount')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
