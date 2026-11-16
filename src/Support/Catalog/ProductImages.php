<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\App\Media\MediaFile;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Media\MediaUsages;
use Wonder\App\Media\ResizeQueue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

/**
 * Quali immagini valgono per una variante, e dove sta il file.
 *
 * La regola dell'ereditarietà è una sola riga di buon senso — *se la variante
 * ha le sue foto si usano quelle, altrimenti quelle del modello* — ma la devono
 * applicare uguale il pannello, la vetrina e domani il feed di Google. Per
 * questo sta qui, pura, e si prova senza database.
 */
final class ProductImages
{
    /**
     * La cartella delle foto, sotto `assets/upload`.
     *
     * È il percorso della pagina dei prodotti, e non è un vezzo: il repeater
     * **scrive** i file nella cartella del Model e li **rilegge** in quella
     * della Resource che ospita il form. Finché le due non coincidono
     * l'anteprima di una foto già caricata non si vede. Per questo il Model
     * dichiara questa stessa cartella e il campo non ne aggiunge un'altra —
     * e per questo rinominare la pagina vuol dire rinominare anche questa.
     */
    public const DIR = '/app/gestionale/prodotti/';

    /** Il prodotto da solo, su fondo neutro: la foto della card e della scheda. */
    public const PACKSHOT = 'packshot';

    /** Il prodotto in uso, con le persone o l'ambiente: la foto di contesto. */
    public const LIFESTYLE = 'lifestyle';

    public const TYPES = [self::PACKSHOT => 'Prodotto', self::LIFESTYLE => 'In uso'];

    /** Il tipo di una foto; una riga senza tipo (o con uno sconosciuto) è packshot. */
    public static function type(array $image): string
    {
        $type = (string) ($image['type'] ?? '');

        return isset(self::TYPES[$type]) ? $type : self::PACKSHOT;
    }

    /**
     * Solo le foto di un tipo, nel loro ordine. Si usa dopo {@see for()}:
     * prima si sceglie di chi sono le foto, poi quali.
     *
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    public static function ofType(array $images, string $type): array
    {
        return array_values(array_filter($images, static fn ($image): bool => is_array($image) && self::type($image) === $type));
    }

    /** La cartella vera sotto `assets/upload`. */
    public static function folder(): string
    {
        return self::DIR;
    }

    /**
     * Le immagini da mostrare, in ordine di posizione.
     *
     * Tre livelli, dal più preciso al più generale: le foto di quella singola
     * opzione in vendita, se ne ha; altrimenti quelle del suo colore;
     * altrimenti quelle dell'articolo. Il primo livello che ha qualcosa vince
     * **intero**: non si mescolano, o una maglietta blu mostrerebbe in mezzo
     * la foto di quella rossa.
     *
     * Chi passa due soli argomenti continua a vedere quello di prima — le
     * foto del colore — e **non** vede quelle delle singole opzioni: è la
     * risposta giusta per chi sta guardando un colore, non una taglia.
     *
     * @param list<array<string, mixed>> $images tutte le immagini del modello
     * @return list<array<string, mixed>>
     */
    public static function for(array $images, ?int $variantId, ?int $productId = null): array
    {
        $ofModel = [];
        $ofVariant = [];
        $ofProduct = [];

        foreach ($images as $image) {
            $product = (int) ($image['product_id'] ?? 0);
            $variant = (int) ($image['product_variant_id'] ?? 0);

            if ($product > 0) {
                if ($productId !== null && $product === $productId) {
                    $ofProduct[] = $image;
                }

                // La foto di un'altra opzione non vale né per il colore né per
                // l'articolo: è di quella riga e basta.
                continue;
            }

            if ($variant === 0) {
                $ofModel[] = $image;
                continue;
            }

            if ($variantId !== null && $variant === $variantId) {
                $ofVariant[] = $image;
            }
        }

        if ($ofProduct !== []) {
            return self::sorted($ofProduct);
        }

        return self::sorted($ofVariant !== [] ? $ofVariant : $ofModel);
    }

    /**
     * L'inizio del nome di un file caricato: dice di chi è la foto.
     *
     * `maglietta-girocollo-`, `maglietta-girocollo-blu-`,
     * `maglietta-girocollo-blu-s-cotone-`: il core ci attacca le sue tre
     * lettere a caso. L'opzione si chiama «Blu / S / Cotone», ma il colore
     * c'è già: se il suo slug comincia con quello della variante, lo si toglie.
     */
    public static function filePrefix(string $modelSlug, string $variantSlug = '', string $optionName = ''): string
    {
        $variantSlug = trim($variantSlug);
        $option = trim($optionName) !== '' ? Slug::base($optionName) : '';

        if ($variantSlug !== '' && str_starts_with($option, $variantSlug.'-')) {
            $option = substr($option, strlen($variantSlug) + 1);
        }

        $parts = array_filter([trim($modelSlug), $variantSlug, $option], static fn (string $part): bool => $part !== '');

        return $parts === [] ? '' : implode('-', $parts).'-';
    }

    /**
     * Il nome del file.
     *
     * L'upload del core scrive un JSON con l'elenco dei nomi, anche quando il
     * file è uno solo: qui si accetta sia quello sia un nome scritto a mano.
     */
    public static function fileName(array $image): string
    {
        $media = self::mediaFile($image);

        if ($media !== null) {
            return $media['file'];
        }

        $raw = $image['file'] ?? '';

        if (is_array($raw)) {
            return (string) (reset($raw) ?: '');
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && $decoded !== [] ? (string) reset($decoded) : '';
        }

        return $raw;
    }

    /** Il percorso sul disco, vuoto quando il file non c'è. */
    public static function path(array $image, ?string $root = null): string
    {
        $name = self::fileName($image);

        if ($name === '') {
            return '';
        }

        $root = rtrim($root ?? (string) ($GLOBALS['ROOT'] ?? ''), '/');

        return $root.'/assets/upload'.self::directory($image).$name;
    }

    /** L'indirizzo pubblico, vuoto quando il file non c'è. */
    public static function url(array $image): string
    {
        $name = self::fileName($image);

        return $name === '' ? '' : '/assets/upload'.self::directory($image).$name;
    }

    /**
     * Il nome relativo alla cartella degli upload (`immagini/foto.jpg`): i
     * campi che mostrano foto di cartelle diverse, libreria e di prima,
     * leggono da lì.
     */
    public static function relativeName(array $image): string
    {
        $name = self::fileName($image);

        return $name === '' ? '' : trim(self::directory($image), '/').'/'.$name;
    }

    /**
     * Le righe di un'area allineate ai media scelti, nell'ordine dato.
     *
     * Funzione pura: dice cosa cambiare, non scrive. Le foto di prima (senza
     * media) non si toccano: il campo non le mostra, quindi non sono state
     * tolte da nessuno.
     *
     * @param list<array<string, mixed>> $rows le righe dell'area
     * @param list<int> $ids i media nell'ordine voluto
     * @return array{update: array<int, int>, create: list<array{media_id: int, position: int}>, delete: list<int>}
     */
    public static function reconcile(array $rows, array $ids, int $max = 0): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($max > 0) {
            $ids = array_slice($ids, 0, $max);
        }

        $byMedia = [];
        $delete = [];

        foreach ($rows as $row) {
            $mediaId = self::mediaId($row);

            if ($mediaId === 0) {
                continue;
            }

            // Lo stesso media due volte nell'area: la seconda riga è di troppo.
            if (isset($byMedia[$mediaId])) {
                $delete[] = (int) $row['id'];
                continue;
            }

            $byMedia[$mediaId] = $row;
        }

        $update = [];
        $create = [];

        foreach ($ids as $index => $mediaId) {
            $position = $index + 1;
            $row = $byMedia[$mediaId] ?? null;

            if ($row === null) {
                $create[] = ['media_id' => $mediaId, 'position' => $position];
                continue;
            }

            unset($byMedia[$mediaId]);

            if ((int) ($row['position'] ?? 0) !== $position) {
                $update[(int) $row['id']] = $position;
            }
        }

        foreach ($byMedia as $row) {
            $delete[] = (int) $row['id'];
        }

        return ['update' => $update, 'create' => $create, 'delete' => $delete];
    }

    /**
     * Carica in una lettura sola i media delle foto che stanno nella libreria
     * e li scrive nelle righe (`media_file`, `media_folder`): chi mostra un
     * elenco la chiama prima, così {@see url()} non legge una foto alla volta.
     *
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    public static function hydrate(array $images): array
    {
        $ids = [];

        foreach ($images as $image) {
            if (is_array($image) && self::mediaId($image) > 0 && !isset($image['media_file'])) {
                $ids[] = self::mediaId($image);
            }
        }

        $media = $ids === [] ? [] : MediaFile::many($ids);

        foreach ($images as $key => $image) {
            $id = is_array($image) ? self::mediaId($image) : 0;

            if ($id > 0 && !isset($image['media_file'])) {
                $images[$key] = self::withMedia($image, $media[$id] ?? null);
            }
        }

        return $images;
    }

    /** L'id del media della libreria, 0 per le foto di prima. */
    public static function mediaId(array $image): int
    {
        return max(0, (int) ($image['media_id'] ?? 0));
    }

    /**
     * L'utilizzo «Foto» di un modello nel registro dei media: i media di
     * tutte le sue foto ancora in vita. Si chiama dopo ogni scrittura.
     */
    public static function syncUsages(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }

        try {
            $rows = ProductImage::find(['product_model_id' => $modelId, 'deleted' => 'false']);
            $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array'));
            $ids = array_values(array_unique(array_filter(array_map([self::class, 'mediaId'], $rows))));

            MediaUsages::sync(ProductModel::$table, $modelId, 'images', $ids);
        } catch (Throwable) {
            // Fuori dal sito (test) o senza registro: lo ricalcola il salvataggio dopo.
        }
    }

    /** La cartella del file: quella del tipo del media, o quella di prima. */
    private static function directory(array $image): string
    {
        $media = self::mediaFile($image);

        return $media !== null ? '/'.$media['folder'].'/' : self::folder();
    }

    /**
     * Il file e la cartella del media di una foto, o null per le foto di
     * prima. Senza {@see hydrate()} legge il media qui, una volta per id.
     *
     * @return array{file: string, folder: string}|null
     */
    private static function mediaFile(array $image): ?array
    {
        $id = self::mediaId($image);

        if ($id === 0) {
            return null;
        }

        if (!isset($image['media_file'])) {
            $image = self::withMedia($image, self::$media[$id] ??= MediaFile::find($id) ?? false);
        }

        return ['file' => (string) $image['media_file'], 'folder' => (string) ($image['media_folder'] ?? '')];
    }

    /** @param MediaFile|false|null $media */
    private static function withMedia(array $image, mixed $media): array
    {
        // Un media sparito dalla libreria lascia la foto senza file: non si mostra.
        $image['media_file'] = $media instanceof MediaFile ? $media->file : '';
        $image['media_folder'] = $media instanceof MediaFile ? MediaTypes::folder($media->type) : '';

        return $image;
    }

    /** @var array<int, MediaFile|false> i media già letti da {@see mediaFile()} */
    private static array $media = [];

    /** Le misure ci sono, o non servono (un video, un file ridimensionato al salvataggio). */
    public const READY = 'ready';

    /** Le misure sono nella coda del core: intanto il sito mostra l'originale. */
    public const PENDING = 'pending';

    /** Le misure non si sono generate: l'errore lo dà {@see error()}. */
    public const FAILED = 'failed';

    /**
     * Lo stato delle misure di una foto, letto dalla coda del core
     * (`ResizeQueue::status()` del percorso del file).
     *
     * La colonna `status` della riga è della vecchia coda del modulo e non
     * si scrive più: vale solo dove la coda del core non sa niente del file,
     * cioè per le righe `pending` non ancora consegnate dalla migrazione e
     * per quelle rimaste `failed`.
     *
     * Legge la coda: una query per foto. Va bene nella scheda di un
     * articolo, non in un elenco del sito.
     *
     * @return self::READY|self::PENDING|self::FAILED
     */
    public static function state(array $image, ?string $root = null): string
    {
        $path = self::path($image, $root);

        if ($path === '' || !ImageSizes::resizable($path)) {
            return self::READY;
        }

        $queued = ResizeQueue::status($path);

        if ($queued !== null) {
            return match ($queued) {
                ResizeQueue::PENDING => self::PENDING,
                ResizeQueue::FAILED => self::FAILED,
                default => self::READY,
            };
        }

        return match ((string) ($image['status'] ?? '')) {
            self::PENDING => self::PENDING,
            self::FAILED => self::FAILED,
            default => self::READY,
        };
    }

    /** Perché le misure non si sono generate; vuoto se non c'è un errore. */
    public static function error(array $image, ?string $root = null): string
    {
        $path = self::path($image, $root);
        $queued = $path !== '' ? ResizeQueue::error($path) : null;

        return $queued ?? trim((string) ($image['error'] ?? ''));
    }

    /**
     * Gli avvisi sulle misure delle foto di un articolo, per la sua scheda:
     * quante sono ancora in preparazione e quali non si sono generate.
     *
     * @param list<array<string, mixed>> $images
     * @return list<array{level: string, message: string}>
     */
    public static function notices(array $images, ?string $root = null): array
    {
        $pending = 0;
        $failed = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $state = self::state($image, $root);

            if ($state === self::PENDING) {
                $pending++;
            } elseif ($state === self::FAILED) {
                $error = self::error($image, $root);
                $failed[] = self::fileName($image).($error !== '' ? ' ('.$error.')' : '');
            }
        }

        $notices = [];

        if ($pending > 0) {
            $notices[] = [
                'level' => 'info',
                'message' => $pending === 1
                    ? 'Una foto è in elaborazione: le sue misure per il sito si stanno preparando. Intanto il sito mostra l\'originale.'
                    : $pending.' foto sono in elaborazione: le loro misure per il sito si stanno preparando. Intanto il sito mostra gli originali.',
            ];
        }

        if ($failed !== []) {
            $notices[] = [
                'level' => 'warning',
                'message' => 'Le misure di '.(count($failed) === 1 ? 'questa foto' : 'queste foto')
                    .' non si sono generate: '.implode(', ', $failed)
                    .'. Il sito mostra l\'originale. Togli la foto e ricaricala, magari riesportata in JPG.',
            ];
        }

        return $notices;
    }

    /**
     * Vero quando le misure sono pronte (o non servono).
     *
     * @deprecated Legge la coda del core con {@see state()}: una query per
     *             foto. Chi mostra una foto non ne ha bisogno, perché il
     *             motore delle immagini ripiega sull'originale.
     */
    public static function isReady(array $image): bool
    {
        return self::state($image) === self::READY;
    }

    /**
     * Vero quando la foto si può mostrare in vetrina.
     *
     * Non serve che le misure siano pronte: finché la coda non le genera il
     * motore delle immagini ripiega sul file originale. Restano fuori i file
     * che non sono immagini e le foto che la vecchia coda del modulo aveva
     * segnato `failed` (file rotti o spariti): la colonna non si scrive più,
     * ma quelle righe restano nascoste come prima. Non legge la coda del
     * core: è pura, e si chiama foto per foto negli elenchi.
     */
    public static function isShowable(array $image): bool
    {
        if ((string) ($image['status'] ?? '') === self::FAILED) {
            return false;
        }

        $extension = strtolower(pathinfo(self::fileName($image), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'], true);
    }

    /**
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $images): array
    {
        usort($images, static fn (array $a, array $b): int =>
            [(int) ($a['position'] ?? 0), (int) ($a['id'] ?? 0)]
            <=> [(int) ($b['position'] ?? 0), (int) ($b['id'] ?? 0)]);

        return array_values($images);
    }
}
