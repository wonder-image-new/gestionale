<?php
/** php tests/ProductImagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MediaLibrary;
use Wonder\App\Media\MemoryMediaStore;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$immagini = [
    ['id' => 1, 'product_variant_id' => 0, 'file' => '["modello-1.jpg"]', 'position' => 2, 'status' => 'ready'],
    ['id' => 2, 'product_variant_id' => 0, 'file' => '["modello-2.jpg"]', 'position' => 1, 'status' => 'ready'],
    ['id' => 3, 'product_variant_id' => 7, 'file' => '["blu.jpg"]', 'position' => 1, 'status' => 'pending'],
];

check('la tabella delle immagini ha il prefisso del gestionale', fn () =>
    ProductImage::$table === 'gst_product_images' && ProductImage::syncSchema() === null
);

check('un\'immagine appartiene a un modello e, se serve, a una variante', function () use ($colonne) {
    $righe = $colonne(ProductImage::class);

    return $righe['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $righe['product_variant_id']->getSchema('foreign_table') === ProductVariant::$table
        // Senza variante l'immagine vale per tutte: la colonna resta vuota.
        && $righe['product_variant_id']->getSchema('null') !== false;
});

check('le colonne della vecchia coda restano nella tabella (deprecate): non si tolgono', function () use ($colonne) {
    $stato = $colonne(ProductImage::class)['status'];

    return $stato->getSchema('enum') === ['pending', 'ready', 'failed']
        && $stato->getSchema('default') === 'pending';
});

check('i tentativi, la data e l\'errore della vecchia coda restano anche loro', function () use ($colonne) {
    $c = $colonne(ProductImage::class);

    return isset($c['attempts'], $c['processed_at'], $c['error']);
});

check('una variante con le sue immagini usa quelle', function () use ($immagini) {
    $sue = ProductImages::for($immagini, 7);

    return count($sue) === 1 && (int) $sue[0]['id'] === 3;
});

check('una variante senza immagini prende quelle del modello', function () use ($immagini) {
    $sue = ProductImages::for($immagini, 99);

    return count($sue) === 2 && array_column($sue, 'id') === [2, 1];
});

check('senza variante si vedono solo quelle del modello', function () use ($immagini) {
    return array_column(ProductImages::for($immagini, null), 'id') === [2, 1];
});

check('il nome del file si legge dal JSON dell\'upload', fn () =>
    ProductImages::fileName(['file' => '["foto.jpg"]']) === 'foto.jpg'
    && ProductImages::fileName(['file' => 'foto.jpg']) === 'foto.jpg'
    && ProductImages::fileName(['file' => '[]']) === ''
    && ProductImages::fileName([]) === ''
);

check('il percorso mette insieme radice, cartelle e nome', function () {
    $percorso = ProductImages::path(['file' => '["foto.jpg"]'], '/tmp/sito');

    // Scrittura e lettura devono usare la stessa cartella, altrimenti
    // l'anteprima di una foto già caricata non si vede.
    return $percorso === '/tmp/sito/assets/upload/app/gestionale/prodotti/foto.jpg'
        && ProductImages::folder() === ProductImage::$folder;
});

check('senza file non c\'è nessun percorso', fn () =>
    ProductImages::path(['file' => '[]'], '/tmp/sito') === ''
);

check('l\'indirizzo pubblico è quello della cartella degli upload', fn () =>
    ProductImages::url(['file' => '["foto.jpg"]'])
        === '/assets/upload/app/gestionale/prodotti/foto.jpg'
);

check('il campo non rimanda più le misure a una coda del modulo', function () {
    foreach (ProductImage::dataSchema() as $field) {
        if ((string) $field->key === 'file') {
            return ($field->getSchema('resize_deferred') ?? false) !== true
                && in_array('mp4', (array) $field->getSchema('extensions'), true);
        }
    }

    return false;
});

check('la foto di un\'opzione vince su quella del suo colore', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 2, 'product_variant_id' => 7, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 42, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7, 42), 'id') === [3];
});

check('senza foto sue, l\'opzione mostra quelle del colore', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 2, 'product_variant_id' => 7, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 99, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7, 42), 'id') === [2];
});

check('la foto di un\'opzione non vale per il colore né per l\'articolo', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 42, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7), 'id') === [1]
        && array_column(ProductImages::for($images, null), 'id') === [1];
});

check('il nome del file dice di chi è la foto: modello, variante, opzione', function () {
    return ProductImages::filePrefix('maglietta-girocollo') === 'maglietta-girocollo-'
        && ProductImages::filePrefix('maglietta-girocollo', 'blu') === 'maglietta-girocollo-blu-'
        && ProductImages::filePrefix('maglietta-girocollo', 'blu', 'Blu / S / Cotone') === 'maglietta-girocollo-blu-s-cotone-'
        // Un nome scritto a mano non comincia col colore: resta intero.
        && ProductImages::filePrefix('maglietta-girocollo', 'blu', 'Maglia leggera') === 'maglietta-girocollo-blu-maglia-leggera-'
        // Lo scheletro non ha slug: la foto si chiama come quelle del modello.
        && ProductImages::filePrefix('maglietta-girocollo', '', '') === 'maglietta-girocollo-'
        && ProductImages::filePrefix('') === '';
});

check('il campo e la scheda usano il prefisso nel nome del media', function () {
    $root = dirname(__DIR__);
    $model = (string) file_get_contents($root.'/src/Models/Catalog/ProductImage.php');
    $resource = (string) file_get_contents($root.'/src/Resources/Catalog/ProductModelResource.php');

    return str_contains($model, "->name('{file_prefix}{rand}')")
        && str_contains($resource, "->mediaSlug('{file_prefix}')")
        && str_contains($resource, "'file_prefix' => rtrim(ProductImages::filePrefix(");
});

check('ogni foto ha un tipo, di serie packshot', function () use ($colonne) {
    $tipo = $colonne(ProductImage::class)['type'] ?? null;

    return $tipo !== null
        && $tipo->getSchema('enum') === ['packshot', 'lifestyle']
        && $tipo->getSchema('default') === 'packshot'
        && array_keys(ProductImages::TYPES) === ['packshot', 'lifestyle'];
});

check('una riga senza tipo, o con uno sconosciuto, è packshot', fn () =>
    ProductImages::type([]) === 'packshot'
    && ProductImages::type(['type' => 'lifestyle']) === 'lifestyle'
    && ProductImages::type(['type' => 'altro']) === 'packshot'
);

check('si tengono solo le foto del tipo chiesto, nel loro ordine', function () {
    $foto = [
        ['id' => 1, 'type' => 'lifestyle'],
        ['id' => 2],
        ['id' => 3, 'type' => 'lifestyle'],
        'non una foto',
    ];

    return array_column(ProductImages::ofType($foto, 'lifestyle'), 'id') === [1, 3]
        && array_column(ProductImages::ofType($foto, 'packshot'), 'id') === [2];
});

check('le foto nella libreria: media_id, utilizzo sull\'articolo, migrazione dalla cartella di prima', function () use ($colonne) {
    $definizione = ProductImage::legacyMediaDefinitions()[0] ?? [];

    return isset($colonne(ProductImage::class)['media_id'])
        && $definizione['field'] === 'file'
        && $definizione['columns'] === ['image' => 'media_id', 'video' => 'media_id']
        && $definizione['legacy'] === 'app/gestionale/prodotti'
        && $definizione['owner'] === ['table' => 'gst_product_models', 'key' => 'product_model_id', 'column' => 'images'];
});

MediaLibrary::useStore(new MemoryMediaStore([
    ['id' => 40, 'type' => 'image', 'slug' => 'maglietta', 'name' => 'Maglietta', 'file' => 'maglietta.jpg'],
    ['id' => 41, 'type' => 'video', 'slug' => 'giro', 'name' => 'Giro', 'file' => 'giro.mp4'],
]));

check('una foto con il media prende file e cartella dalla libreria', fn () =>
    ProductImages::url(['media_id' => 40, 'file' => '']) === '/assets/upload/images/maglietta.jpg'
    && ProductImages::path(['media_id' => 41], '/sito') === '/sito/assets/upload/videos/giro.mp4'
    && ProductImages::isShowable(['media_id' => 40])
    && !ProductImages::isShowable(['media_id' => 41])
);

check('senza media (o con un media sparito) vale il file di prima, o niente', fn () =>
    ProductImages::url(['media_id' => 0, 'file' => '["vecchia.jpg"]']) === '/assets/upload/app/gestionale/prodotti/vecchia.jpg'
    && ProductImages::url(['media_id' => 99, 'file' => '["vecchia.jpg"]']) === ''
);

check('hydrate legge i media in una volta e li scrive nelle righe', function () {
    $righe = ProductImages::hydrate([['id' => 1, 'media_id' => 40], ['id' => 2, 'file' => '["a.jpg"]']]);

    return $righe[0]['media_file'] === 'maglietta.jpg' && $righe[0]['media_folder'] === 'images'
        && !isset($righe[1]['media_file'])
        && ProductImages::url($righe[0]) === '/assets/upload/images/maglietta.jpg';
});

check('relativeName: cartella degli upload più file, per libreria e foto di prima', fn () =>
    ProductImages::relativeName(['media_id' => 40]) === 'images/maglietta.jpg'
    && ProductImages::relativeName(['media_id' => 0, 'file' => '["vecchia.jpg"]']) === 'app/gestionale/prodotti/vecchia.jpg'
    && ProductImages::relativeName(['media_id' => 99]) === ''
);

check('reconcile: tiene, riordina, crea e toglie secondo i media scelti', function () {
    $righe = [
        ['id' => 1, 'media_id' => 40, 'position' => 1],
        ['id' => 2, 'media_id' => 41, 'position' => 2],
        ['id' => 3, 'media_id' => 42, 'position' => 3],
        ['id' => 4, 'media_id' => 0, 'file' => '["vecchia.jpg"]', 'position' => 4],
        ['id' => 5, 'media_id' => 40, 'position' => 5],
    ];
    $piano = ProductImages::reconcile($righe, [41, 40, 50, 50]);

    return $piano['update'] === [2 => 1, 1 => 2]
        && $piano['create'] === [['media_id' => 50, 'position' => 3]]
        // La 3 non è più scelta, la 5 è un doppione; la foto di prima resta.
        && $piano['delete'] === [5, 3];
});

check('reconcile: rispetta il massimo e svuota un\'area', fn () =>
    ProductImages::reconcile([], [1, 2, 3], 2)['create'] === [['media_id' => 1, 'position' => 1], ['media_id' => 2, 'position' => 2]]
    && ProductImages::reconcile([['id' => 9, 'media_id' => 7, 'position' => 1]], [])['delete'] === [9]
);

MediaLibrary::useStore(null);

summary();
