<?php
/** php tests/BrandLogoSvgTest.php: C-UPL-6, il logo dei marchi (immagine o svg) */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MediaInputs;
use Wonder\App\Media\MediaLibrary;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Media\MediaUsages;
use Wonder\App\Media\MemoryMediaStore;
use Wonder\App\Media\MemoryMediaUsageStore;
use Wonder\App\ResourceSchema\Inputs\InputFileDragDrop;
use Wonder\App\Security\SvgSanitizer;
use Wonder\Plugin\Gestionale\Migrations\BrandLogoSvgMigration;
use Wonder\Plugin\Gestionale\Migrations\CatalogMediaMigration;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;

$campi = [];

foreach (BrandResource::formSchema() as $field) {
    $campi[(string) $field->name] = $field;
}

$upload = static fn (string $name, string $mime): array => [
    'name' => $name, 'type' => $mime, 'tmp_name' => '/tmp/php-wi-media', 'error' => UPLOAD_ERR_OK, 'size' => 41,
];

check('il marchio ha un campo logo solo, drag & drop con immagini e svg', fn () =>
    ($campi['logo_id'] ?? null) instanceof InputFileDragDrop
    && $campi['logo_id']->mediaConfig()['types'] === [MediaTypes::IMAGE, MediaTypes::ICON]
    && $campi['logo_id']->mediaConfig()['library'] === true
    && !isset($campi['logo_svg_id'], $campi['logo'])
    && !array_key_exists('logo_svg_id', BrandResource::labelSchema())
);

check('l\'svg è un\'icona, il png un\'immagine', fn () =>
    MediaTypes::extensions(MediaTypes::ICON) === ['svg']
    && !MediaTypes::accepts(MediaTypes::IMAGE, 'logo.svg')
);

foreach ([['acme.svg', 'image/svg+xml', MediaTypes::ICON], ['acme.png', 'image/png', MediaTypes::IMAGE]] as [$nome, $mime, $tipo]) {
    check("{$nome} caricato nel logo diventa un media di tipo {$tipo} in logo_id", function () use ($upload, $nome, $mime, $tipo) {
        MediaLibrary::useStore($libreria = new MemoryMediaStore());
        MediaUsages::useStore(new MemoryMediaUsageStore());

        try {
            $inputs = MediaInputs::for(BrandResource::class);
            $valori = $inputs->resolve([
                'name' => 'Acme',
                'slug' => 'acme',
                'logo_id' => $upload($nome, $mime),
                'logo_id__wi_files' => '[0]',
                'logo_id__wi_picks' => '[null]',
            ], ['id' => 3, 'name' => 'Acme', 'slug' => 'acme', 'logo_id' => '']);

            $media = $libreria->rows[(int) ($valori['logo_id'] ?? 0)] ?? null;

            return $inputs->errors() === []
                && $media !== null
                && $media['type'] === $tipo
                && $media['slug'] === 'logo-acme'
                && $media['alt'] === 'Logo Acme';
        } finally {
            MediaLibrary::useStore(null);
            MediaUsages::useStore(null);
        }
    });
}

check('un pdf nel logo si rifiuta, con il motivo', function () use ($upload) {
    MediaLibrary::useStore(new MemoryMediaStore());
    MediaUsages::useStore(new MemoryMediaUsageStore());

    try {
        $inputs = MediaInputs::for(BrandResource::class);
        $inputs->resolve([
            'logo_id' => $upload('acme.pdf', 'application/pdf'),
            'logo_id__wi_files' => '[0]',
            'logo_id__wi_picks' => '[null]',
        ], ['id' => 3, 'name' => 'Acme', 'slug' => 'acme', 'logo_id' => '']);

        return ($inputs->errors()['logo_id'] ?? '') !== '';
    } finally {
        MediaLibrary::useStore(null);
        MediaUsages::useStore(null);
    }
});

// --- La migrazione del vettoriale di prima ---------------------------------

$piano = BrandLogoSvgMigration::plan(Brand::$table, true);

check('la migrazione porta il vettoriale in logo_id e svuota la colonna vecchia', fn () =>
    array_keys($piano) === ['usages.drop', 'usages.move', 'logo', 'clear']
    && str_contains($piano['logo'], 'SET `logo_id` = `logo_svg_id` WHERE `logo_svg_id` > 0')
    && str_contains($piano['clear'], 'SET `logo_svg_id` = NULL')
);

check('gli utilizzi: via il raster lasciato, il vettoriale passa a logo_id', fn () =>
    str_starts_with($piano['usages.drop'], 'DELETE FROM `media_usages`')
    && str_contains($piano['usages.drop'], "`owner_column` = 'logo_id'")
    && str_contains($piano['usages.drop'], 'WHERE `logo_svg_id` > 0')
    && str_contains($piano['usages.move'], "SET `owner_column` = 'logo_id'")
    && str_contains($piano['usages.move'], "`owner_column` = 'logo_svg_id'")
);

check('senza registro degli utilizzi si sposta solo il logo', fn () =>
    array_keys(BrandLogoSvgMigration::plan(Brand::$table, false)) === ['logo', 'clear']
);

check('la migrazione è registrata dopo quella dei file del catalogo', function () {
    $dopo = json_decode((string) file_get_contents(__DIR__.'/../module.json'), true)['database']['migrations_after_tables'] ?? [];
    $catalogo = array_search(CatalogMediaMigration::class, $dopo, true);
    $svg = array_search(BrandLogoSvgMigration::class, $dopo, true);

    return $catalogo !== false && $svg !== false && $svg > $catalogo;
});

// Il modulo non sanifica da sé: lo fa il core quando salva il media.
check('il core toglie script e handler da un svg caricato', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script>'
        .'<rect width="10" height="10" onclick="alert(3)"/></svg>';
    $pulito = SvgSanitizer::sanitize($svg);

    return is_string($pulito)
        && stripos($pulito, '<script') === false
        && stripos($pulito, 'onload') === false
        && stripos($pulito, 'onclick') === false
        && stripos($pulito, '<rect') !== false;
});

check('il core rifiuta un svg con entità', fn () =>
    SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>') === null
);

summary();
