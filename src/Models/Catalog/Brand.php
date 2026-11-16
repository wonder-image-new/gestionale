<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\HasCatalogMedia;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Marchio dei prodotti.
 *
 * Il catalogo non si sincronizza tra ambienti: è il lavoro del commerciante,
 * si scrive dove si lavora. Aliquote e impostazioni sono configurazione e
 * viaggiano con il deploy, i prodotti no.
 *
 * Il logo è un media della libreria del core in `logo_id`: un'immagine o
 * un'icona svg. `logo_svg_id` (il vettoriale separato di prima) è
 * deprecata: {@see \Wonder\Plugin\Gestionale\Migrations\BrandLogoSvgMigration}
 * la porta in `logo_id` e la svuota.
 */
final class Brand extends Model
{
    use HasCatalogMedia;

    public static string $table = 'gst_brands';
    public static string $folder = 'gestionale/brands';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('slug')->length(150)->unique(),
            // Deprecata: il file di prima della libreria Media. La migrazione
            // la svuota; si legge solo come ripiego ({@see HasCatalogMedia}).
            Column::key('logo')->json(),
            ...static::mediaTableColumns(),
            // Deprecata: il logo vettoriale separato, ora in `logo_id`.
            Column::key('logo_svg_id')->int()->null(),
            Column::key('description')->type('TEXT'),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::BRAND),
            Field::key('name')->text()->sanitize(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('logo')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp', 'svg'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/marchi/')
                ->name('{slug}'),
            ...static::mediaDataFields(),
            Field::key('description')->text(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }

    public static function mediaFields(): array
    {
        return [
            'logo' => [
                // Una colonna sola: il logo può essere raster o svg.
                'columns' => ['image' => 'logo_id', 'icon' => 'logo_id'],
                'slug' => 'logo-{slug}',
                'alt' => 'Logo {name}',
                // La cartella della pagina dei marchi più quella del campo.
                'legacy' => 'app/gestionale/marchi/catalogo/marchi',
            ],
        ];
    }

    /** L'indirizzo del logo, vuoto se manca. */
    public static function logoUrl(array $row): string
    {
        return static::mediaUrl($row, 'logo');
    }
}
