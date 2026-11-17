<?php
/** php tests/ContactImportTest.php — l'estensione del gestionale ai tracciati della rubrica. */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Import\Context;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Support\Import\{AddressLayout, CompanyLayout, ImportExtensions, PeopleLayout, RelationLayout};
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\{Company, Contact};
use Wonder\Plugin\Gestionale\Support\Contacts\{ContactVisibility, GestionaleContactsImport};
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

// `__t()` esiste solo a sito avviato: una finta che legge i file di lingua.
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        static $file = [];
        [$nome, $resto] = explode('.', $key, 2) + [1 => ''];
        $percorso = $nome === 'contacts'
            ? __DIR__.'/../vendor/wonder-image/customer-account/lang/it/contacts.json'
            : __DIR__.'/../lang/it/'.$nome.'.json';
        $file[$nome] ??= (array) json_decode((string) @file_get_contents($percorso), true);
        $nodo = $file[$nome];

        foreach (explode('.', $resto) as $passo) {
            $nodo = is_array($nodo) ? ($nodo[$passo] ?? null) : null;
        }

        if (!is_string($nodo)) {
            return $key;
        }

        foreach ($replacements as $chiave => $valore) {
            $nodo = str_replace('{{'.$chiave.'}}', (string) $valore, $nodo);
        }

        return $nodo;
    }
}

/** Le scelte fisse al posto di quelle lette dal database. */
final class ImportConScelte extends GestionaleContactsImport
{
    protected function options(string $layout): array
    {
        return $layout === PeopleLayout::KEY || $layout === CompanyLayout::KEY ? [
            'price_list' => [3 => 'Rivenditori', 4 => 'Ingrosso (non attivo)'],
            'payment_method' => [1 => 'Bonifico'],
            'agent' => [5 => 'Mario Bianchi'],
        ] : [];
    }
}

$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato + ['sales_agents' => true, 'private_customers' => true, 'companies' => true]);
};
$forza([]);
(new ReflectionProperty(SalesAgents::class, 'ids'))->setValue(null, [10 => 5]);

RowVisibility::reset();
RowVisibility::useResources([]);
SalesAgents::register();

$commerciale = (object) ['id' => 10, 'authority' => json_encode(['sales_agent'])];
$ufficio = (object) ['id' => 11, 'authority' => json_encode(['sales'])];
$estensione = new ImportConScelte();
$contesto = new Context();

echo "GestionaleContactsImport\n";

check('si registra nella config del modulo e customer-account la trova', function () {
    $config = require __DIR__.'/../config/module.php';
    $trovate = ImportExtensions::all(['gestionale' => $config]);

    return count($trovate) === 1 && $trovate[0] instanceof GestionaleContactsImport;
});

check('scrive coi Model del gestionale persone e aziende, gli altri restano', function () use ($estensione) {
    return $estensione->model(PeopleLayout::KEY) === Contact::class
        && $estensione->model(CompanyLayout::KEY) === Company::class
        && $estensione->model(AddressLayout::KEY) === null
        && $estensione->model(RelationLayout::KEY) === null;
});

check('senza aziende niente aziende e niente referenti; i tracciati d\'altri non li tocca', function () use ($estensione, $forza, $ufficio) {
    $forza(['companies' => false]);
    $esito = $estensione->permits(CompanyLayout::KEY, $ufficio) === false
        && $estensione->permits(RelationLayout::KEY, $ufficio) === false
        && $estensione->permits('catalog', $ufficio) === null;
    $forza([]);

    return $esito;
});

check('le colonne in più solo nei tracciati di persone e aziende', function () use ($estensione) {
    $chiavi = array_map(static fn ($colonna) => $colonna->key, $estensione->columns(PeopleLayout::KEY));

    return $chiavi === ['price_list', 'payment_method', 'agent'] && $estensione->columns(AddressLayout::KEY) === [];
});

check('listino, metodo e commerciale per nome o per id; «Nessuno» li toglie; le celle vuote non scrivono', function () use ($estensione, $contesto) {
    $per = $estensione->values(PeopleLayout::KEY, ['price_list' => 'rivenditori', 'payment_method' => '1', 'agent' => 'Mario  Bianchi'], ['id' => 2], $contesto);
    $via = $estensione->values(PeopleLayout::KEY, ['price_list' => 'Nessuno', 'payment_method' => 'nessuno', 'agent' => ''], ['id' => 2], $contesto);
    $spento = $estensione->values(CompanyLayout::KEY, ['price_list' => 'Ingrosso'], ['id' => 5], $contesto);

    return $per['values'] === ['price_list_id' => '3', 'payment_method_id' => '1', 'agent_id' => '5'] && $per['errors'] === []
        && $via['values'] === ['price_list_id' => '0', 'payment_method_id' => ''] && $via['errors'] === []
        && $spento['values'] === ['price_list_id' => '4'];
});

check('un nome o un id che non c\'è blocca la riga', function () use ($estensione, $contesto) {
    $esito = $estensione->values(PeopleLayout::KEY, ['price_list' => 'Dettaglio', 'agent' => '99'], ['id' => 2], $contesto);

    return $esito['values'] === [] && count($esito['errors']) === 2
        && str_contains($esito['errors'][0], 'Listino') && str_contains($esito['errors'][0], 'Dettaglio');
});

check('il commerciale che carica: la scheda nuova nasce sua, quella che c\'è non cambia commerciale', function () use ($estensione, $contesto, $commerciale, $ufficio) {
    $nuova = RowVisibility::as($commerciale, static fn (): array => $estensione->values(PeopleLayout::KEY, [], null, $contesto));
    $esistente = RowVisibility::as($commerciale, static fn (): array => $estensione->values(PeopleLayout::KEY, [], ['id' => 3], $contesto));
    $dallUfficio = RowVisibility::as($ufficio, static fn (): array => $estensione->values(PeopleLayout::KEY, [], null, $contesto));

    return $nuova['values'] === ['agent_id' => '5'] && $esistente['values'] === [] && $dallUfficio['values'] === [];
});

check('le schede che il commerciale non vede non si riconoscono; l\'ufficio le vede tutte senza query', function () use ($estensione, $commerciale, $ufficio) {
    $letture = 0;
    ContactVisibility::useReader(static function (string $table) use (&$letture): array {
        $letture++;

        return $table === Company::$table ? [7] : [];
    });

    $sua = RowVisibility::as($commerciale, static fn (): bool => $estensione->visible(CompanyLayout::KEY, ['id' => 7]));
    $altrui = RowVisibility::as($commerciale, static fn (): bool => $estensione->visible(CompanyLayout::KEY, ['id' => 8]));
    $prima = $letture;
    $ufficioVede = RowVisibility::as($ufficio, static fn (): bool => $estensione->visible(CompanyLayout::KEY, ['id' => 8]));
    ContactVisibility::useReader(null);

    return $sua && !$altrui && $ufficioVede && $letture === $prima;
});

check('bottoni in più negli elenchi: indirizzi sulle persone, indirizzi e referenti sulle aziende', function () {
    return \Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource::importExtraLayouts() === [\Wonder\Plugin\CustomerAccount\Support\Import\AddressLayout::KEY]
        && \Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource::importExtraLayouts() === [\Wonder\Plugin\CustomerAccount\Support\Import\AddressLayout::KEY, \Wonder\Plugin\CustomerAccount\Support\Import\RelationLayout::KEY];
});

summary();
