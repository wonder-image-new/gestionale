<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\HasCatalogMedia;
use Wonder\Sql\TableSchema as Column;

/**
 * Valore di un attributo a elenco: "Blu", "M", "Cotone".
 *
 * Esiste solo per i tipi `select`, `color`, `pattern` e `icon`; `text` e
 * `number` scrivono il valore sul collegamento del prodotto. `color` tiene il
 * codice esadecimale per il pallino in vetrina (tipo Colore), `image`
 * l'immagine della fantasia (tipo Fantasia) o dell'icona (tipo Icona:
 * un'icona è un'immagine sua). Cambiando tipo all'attributo le colonne
 * restano come sono: la scheda smette solo di chiederle.
 *
 * L'immagine è un media della libreria del core in `image_id`, scelto dal
 * repeater della scheda attributo; l'utilizzo sta sull'attributo
 * (`values.image_id`). `image` è la colonna file di prima, deprecata: la
 * migrazione la svuota e si legge solo come ripiego.
 */
final class AttributeValue extends Model
{
    use HasCatalogMedia;

    public static string $table = 'gst_attribute_values';
    public static string $folder = 'gestionale/attributes';
    public static string $icon = 'bi bi-palette';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('attribute_id')->int()->null(false)->foreign(Attribute::$table),
            Column::key('label'),
            Column::key('description')->type('TEXT'),
            Column::key('color')->length(20),
            // Deprecata: il file di prima della libreria Media.
            Column::key('image')->json(),
            ...static::mediaTableColumns(),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_attribute' => ['index' => 'attribute_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('attribute_id')->number()->decimals(0),
            // Niente `sanitizeFirst()`: un'etichetta è spesso una sigla, e
            // "XL" non deve diventare "Xl".
            Field::key('label')->text(),
            Field::key('description')->text(),
            Field::key('color')->text(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/attributi/')
                ->name('{label}'),
            ...static::mediaDataFields(),
            Field::key('position')->number()->decimals(0),
        ];
    }

    public static function mediaFields(): array
    {
        return [
            'image' => [
                'columns' => ['image' => 'image_id'],
                'slug' => 'attributo-{label}',
                'alt' => '{label}',
                'name' => '{label}',
                // La cartella del Model più quella del campo di prima.
                'legacy' => 'gestionale/attributes/catalogo/attributi',
                'owner' => ['table' => Attribute::$table, 'key' => 'attribute_id', 'column' => 'values.image_id'],
            ],
        ];
    }

    /** L'indirizzo dell'immagine di un valore, vuoto se non ne ha. */
    public static function imageUrl(array $value): string
    {
        return static::mediaUrl($value, 'image');
    }
}
