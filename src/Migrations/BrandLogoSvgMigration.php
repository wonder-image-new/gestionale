<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use RuntimeException;
use Throwable;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;

/**
 * Il logo di un marchio sta in una colonna sola, `logo_id`, che accetta sia
 * un'immagine sia un'icona svg. Il logo vettoriale di prima (`logo_svg_id`)
 * passa in `logo_id`: il sito lo mostrava già al posto del raster, quindi
 * il raster di quella riga non è più usato dal marchio (resta in libreria).
 *
 * Sposta anche gli utilizzi nel registro. Idempotente: lavora solo le righe
 * con `logo_svg_id` ancora pieno e lo svuota. La colonna resta, deprecata.
 */
final class BrandLogoSvgMigration implements ModuleMigration
{
    public const COLUMN = 'logo_svg_id';
    public const TARGET = 'logo_id';
    public const USAGES_TABLE = CatalogMediaMigration::USAGES_TABLE;

    public static function run(mysqli $connection): array
    {
        $table = Brand::$table;

        if (!self::tableExists($connection, $table) || !in_array(self::COLUMN, self::columns($connection, $table), true)) {
            return [];
        }

        $plan = self::plan($table, self::tableExists($connection, self::USAGES_TABLE));
        $done = [];

        $connection->begin_transaction();

        try {
            foreach ($plan as $step => $sql) {
                if ($connection->query($sql) === false) {
                    throw new RuntimeException('Logo svg dei marchi non migrato ('.$step.'): '.$connection->error);
                }

                $done[$step] = max(0, $connection->affected_rows);
            }

            $connection->commit();
        } catch (Throwable $error) {
            $connection->rollback();

            throw $error;
        }

        return array_filter($done, static fn ($value) => $value !== 0);
    }

    /**
     * Le istruzioni in ordine: utilizzi prima (leggono le righe ancora da
     * spostare), poi l'id, poi la colonna svuotata.
     *
     * @return array<string, string> passo => SQL
     */
    public static function plan(string $table, bool $usages): array
    {
        $pending = 'SELECT `id` FROM `'.$table.'` WHERE `'.self::COLUMN.'` > 0';
        $plan = [];

        if ($usages) {
            // Il raster che la riga lascia non è più un suo utilizzo.
            $plan['usages.drop'] = 'DELETE FROM `'.self::USAGES_TABLE."` WHERE `owner_table` = '".$table."' AND `owner_column` = '".self::TARGET."' AND `owner_id` IN (".$pending.')';
            $plan['usages.move'] = 'UPDATE `'.self::USAGES_TABLE."` SET `owner_column` = '".self::TARGET."' WHERE `owner_table` = '".$table."' AND `owner_column` = '".self::COLUMN."'";
        }

        $plan['logo'] = 'UPDATE `'.$table.'` SET `'.self::TARGET.'` = `'.self::COLUMN.'` WHERE `'.self::COLUMN.'` > 0';
        $plan['clear'] = 'UPDATE `'.$table.'` SET `'.self::COLUMN.'` = NULL WHERE `'.self::COLUMN.'` IS NOT NULL';

        return $plan;
    }

    /** @return list<string> */
    private static function columns(mysqli $connection, string $table): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `'.$table.'`');

        return $result === false ? [] : array_column($result->fetch_all(MYSQLI_ASSOC), 'Field');
    }

    private static function tableExists(mysqli $connection, string $table): bool
    {
        $result = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        return $result !== false && $result->num_rows > 0;
    }
}
