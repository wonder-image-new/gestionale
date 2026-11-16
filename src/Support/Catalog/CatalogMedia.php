<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\App\Media\MediaInputs;
use Wonder\App\ResourceSchema\Inputs\InputFileDragDrop;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;

/**
 * Le voci del catalogo che tengono le loro immagini nella libreria Media
 * del core, e il campo del form che le sceglie.
 *
 * Cosa ha ogni voce lo dice il suo Model ({@see HasCatalogMedia::mediaFields()});
 * qui c'è l'elenco dei Model, che serve alla migrazione dei file di prima
 * ({@see \Wonder\Plugin\Gestionale\Migrations\CatalogMediaMigration}).
 */
final class CatalogMedia
{
    /** @var list<class-string<\Wonder\App\Model>> i Model con {@see HasCatalogMedia} */
    public const MODELS = [Category::class, Tag::class, Brand::class, ProductSelection::class, AttributeValue::class, ProductImage::class];

    /**
     * Il campo media di un form: il drag & drop del core collegato alla
     * libreria ({@see InputFileDragDrop::mediaLibrary()}) sulla colonna con
     * l'id, con i tipi, lo slug e il testo alternativo del Model. Il file
     * caricato diventa un media e il ridimensionatore lo prepara.
     *
     * `$type` sceglie la colonna quando il campo ne ha più d'una; senza,
     * quella del primo tipo.
     *
     * @param class-string $model uno di {@see MODELS}
     */
    public static function input(string $model, string $field, ?string $type = null): InputFileDragDrop
    {
        return $model::mediaInput($field, $type);
    }

    /**
     * I nomi che un campo media manda nella richiesta: la colonna e i campi
     * di servizio del drag & drop (file, scelte dalla libreria, mappa). Servono dove la richiesta viene filtrata per nome
     * (le schede in sola lettura con qualche campo modificabile).
     *
     * @param class-string $model
     * @return list<string>
     */
    public static function requestKeys(string $model): array
    {
        $keys = [];

        foreach (self::columns($model) as $column) {
            array_push(
                $keys,
                $column,
                MediaInputs::fieldName($column, MediaInputs::FILES_SUFFIX),
                MediaInputs::fieldName($column, MediaInputs::PICKS_SUFFIX),
                MediaInputs::fieldName($column, MediaInputs::MAP_SUFFIX),
            );
        }

        return $keys;
    }

    /**
     * Le colonne con l'id dei media di un Model.
     *
     * @param class-string $model
     * @return list<string>
     */
    public static function columns(string $model): array
    {
        return $model::mediaColumns();
    }
}
