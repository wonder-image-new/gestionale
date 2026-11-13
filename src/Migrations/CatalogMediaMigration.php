<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use Wonder\App\Media\LegacyMediaMigration;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogMedia;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;

/**
 * Le immagini di categorie, tag e selezioni e il logo dei marchi passano
 * alla libreria Media del core. Il lavoro lo fa {@see LegacyMediaMigration}
 * del core, con i campi dei Model del catalogo e lo slug del catalogo.
 *
 * Gira prima dell'allineamento delle tabelle: le colonne con l'id le
 * aggiunge la migrazione del core. Senza libreria o registro non fa niente
 * e ci riprova all'update successivo.
 */
final class CatalogMediaMigration implements ModuleMigration
{
    public const MEDIA_TABLE = LegacyMediaMigration::MEDIA_TABLE;
    public const USAGES_TABLE = LegacyMediaMigration::USAGES_TABLE;
    public const EMPTY = LegacyMediaMigration::EMPTY;

    public static function run(mysqli $connection, ?string $uploadRoot = null): array
    {
        $definitions = [];

        foreach (CatalogMedia::MODELS as $model) {
            array_push($definitions, ...$model::legacyMediaDefinitions());
        }

        return LegacyMediaMigration::run(
            $connection,
            $definitions,
            $uploadRoot,
            static fn (string $text): string => trim(Slug::base($text), '-'),
            static function (string $path): void {
                ImageSizes::prepare($path);
            }
        );
    }

    /** @see LegacyMediaMigration::apply() */
    public static function apply(array $plan, callable $copy, callable $save, callable $clear, callable $after): array
    {
        return LegacyMediaMigration::apply($plan, $copy, $save, $clear, $after);
    }
}
