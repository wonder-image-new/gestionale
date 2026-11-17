# Importazione delle anagrafiche

I tracciati della rubrica sono di **customer-account** (`contacts_people`,
`contacts_companies`, `contacts_addresses`, `contacts_relations`, in
`Wonder\Plugin\CustomerAccount\Support\Import`): riconoscimento, anteprima,
scrittura e annullo stanno lì, vedi il README del modulo («Import dei
contatti»). Il gestionale non li sostituisce: li estende con
`Support\Contacts\GestionaleContactsImport`, registrata in
`config/module.php` sotto `contacts_import.extensions`.

## Cosa aggiunge l'estensione

| Metodo | Cosa fa |
|---|---|
| `permits()` | Persone e indirizzi a chi può fare `update` su `CustomerResource`, aziende e referenti a chi può su `CompanyResource`. Senza aziende (`CustomerMode::companies()`) aziende e referenti sono negati. Per i tracciati d'altri torna `null`. |
| `model()` | Scrive persone e aziende con `Models\Contacts\Contact` e `Company` del gestionale, così valgono le loro regole e gli hook. |
| `visible()` | Con visibilità ristretta (`ContactVisibility::restricted()`) riconosce solo le schede che chi carica vede: un commerciale non aggiorna le schede degli altri. |
| `backUrl()` | La freccia accanto al titolo del caricamento porta all'elenco clienti del gestionale per persone e indirizzi, a quello aziende per aziende e referenti. |
| `columns()` / `values()` | Listino, metodo e condizione di pagamento (questa solo con più di una attiva) e commerciale, solo dove il form della Resource li mostra. Valori per nome o ID, «Nessuno» li toglie, celle vuote non scrivono, un valore sconosciuto blocca la riga. |

Il commerciale che carica non sceglie il commerciale: in creazione la scheda
prende il suo `agent_id`, in aggiornamento resta quello salvato. Le scelte
vengono da `options()` (protetto: i test lo sostituiscono con valori fissi).

## Dove si clicca

`CustomerResource::importLayoutKey()` e `CompanyResource::importLayoutKey()`
mettono il tracciato principale nel bottone «File» dell'elenco
(`importLayout()` del core, uno per elenco). Indirizzi e referenti non hanno
un elenco proprio: `importExtraLayouts()` li elenca e
`ImportLinks::buttons()` di customer-account li mostra come bottoni
«Importa indirizzi» (persone e aziende) e «Importa referenti» (aziende),
solo se `permits()` dice sì. La chiamata è una sola, in
`CustomerResource::tableLayoutSchema()`: `CompanyResource` la eredita col suo
`importExtraLayouts()`.

## Test

`tests/ContactImportTest.php`: registrazione, Model, permessi senza aziende,
colonne, valori per nome o ID, regola del commerciale e visibilità.
