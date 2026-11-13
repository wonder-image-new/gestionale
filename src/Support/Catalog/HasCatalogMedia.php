<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\App\Media\HasMedia;

/**
 * Le immagini di una voce del catalogo (categoria, tag, marchio, selezione)
 * stanno nella libreria Media del core: tutto il comportamento è quello di
 * {@see HasMedia}. Resta come nome del catalogo, per i Model del modulo.
 */
trait HasCatalogMedia
{
    use HasMedia;
}
