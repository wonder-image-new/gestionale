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

        return self::runDefinitions($connection, $definitions, $uploadRoot);
    }

    private static function runDefinitions(mysqli $connection, array $definitions, ?string $uploadRoot): array
    {
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

    /**
     * Porta subito nella libreria le righe appena scritte con un file di
     * prima (import, demo, foto delle opzioni): media, misure e utilizzi.
     * Senza connessione (test) o senza libreria non fa niente: le prende
     * {@see run()} all'update successivo.
     *
     * @param class-string $model un Model di {@see CatalogMedia::MODELS}
     * @param list<int> $ids
     */
    public static function adopt(string $model, array $ids, ?mysqli $connection = null, ?string $uploadRoot = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === [] || !in_array($model, CatalogMedia::MODELS, true)) {
            return [];
        }

        try {
            $connection ??= $model::connection();
        } catch (\Throwable) {
            return [];
        }

        $definitions = array_map(
            static fn (array $definition): array => $definition + ['ids' => $ids],
            $model::legacyMediaDefinitions()
        );

        try {
            return self::runDefinitions($connection, $definitions, $uploadRoot);
        } catch (\Throwable) {
            // Il file resta com'è: lo migra l'update successivo.
            return [];
        }
    }

    /** @see LegacyMediaMigration::apply() */
    public static function apply(array $plan, callable $copy, callable $save, callable $clear, callable $after): array
    {
        return LegacyMediaMigration::apply($plan, $copy, $save, $clear, $after);
    }
}
