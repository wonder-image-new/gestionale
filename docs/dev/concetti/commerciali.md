# Commerciali: visibilità per riga

Funzionalità `sales_agents` (#77, area Vendite, richiede `orders` e
`office_sales`). Un commerciale è un utente del backend col ruolo
`sales_agent` che vede e gestisce solo i propri clienti. Il codice sta in
`Support\SalesAgents\SalesAgents`; la scheda in `Models\SalesAgents\SalesAgent`
(`gst_sales_agents`) e `Resources\SalesAgents\SalesAgentResource`.

## Chi è commerciale

`SalesAgents::restricted($user)` è vero se la funzionalità è accesa, l'utente
ha `sales_agent` e **nessun** ruolo d'ufficio (`SalesAgents::OFFICE`: `admin`,
`administrator`, `sales`). Chi ha anche `sales` lavora per tutti.

Il perimetro è la riga di `gst_sales_agents` con il suo `user_id`, attiva e non
eliminata (`idOf()`, in cache per richiesta; `forget()` la svuota dopo un
salvataggio e nei test). Un utente per scheda: lo controlla
`SalesAgentResource::mutateRequestValues`.

L'utente nasce dalla pagina «Nuovo commerciale»: il form in creazione ha
`user_name`, `user_surname`, `user_email` (non sono colonne, si tolgono prima
del salvataggio) e `SalesAgentUsers::create()` chiama `user()` di app col ruolo
`sales_agent` nell'area `backend`, poi `Wonder\Backend\Support\WelcomeMail::send()`
(link `password_set`, la mail non blocca). Un'email già di un commerciale senza
scheda si riusa; con scheda dà `sales_agent.user_taken`, con un altro ruolo
`sales_agent.user_other_role`. In modifica `user_id` è disabilitato e la
Resource lo scarta: l'utente non si cambia. Senza scheda attiva il commerciale
non vede nulla (`RowVisibility::none()`).

## Le regole

`config/boot.php` (in `module.json` → `boot.files`) chiama
`SalesAgents::register()`, che registra le regole su `Wonder\App\Visibility\RowVisibility`
del core (vedi *Visibilità per riga* nella documentazione di app). Le regole
leggono lo stato della funzionalità quando vengono chiamate e restituiscono
`null` per chi non è commerciale.

| Tabella | Visibile se |
|---|---|
| contatti | `agent_id` è suo **oppure** la persona è legata (`ContactRelation`) a un'azienda sua |
| aziende | `agent_id` è suo |
| brand delle aziende (`company_brands`) | è legato con un legame aperto (`ended_at` nullo) a un'azienda sua |
| ordini/preventivi, DDT, resi, abbonamenti | `agent_id` è suo **oppure** `customer_id` è un contatto suo **oppure** `company_id` è un'azienda sua |
| pagamenti (Scadenzario) | l'ordine o l'abbonamento è visibile **oppure** `customer_id` è un contatto suo **oppure** `company_id` è un'azienda sua |

Le condizioni sono in OR: il documento è di chi l'ha fatto (`agent_id`) e
segue anche il cliente di oggi, così il nuovo commerciale eredita lo storico.
`RowVisibility` vieta di nominare `deleted` in una regola (toglierebbe il soft
delete alla query principale), quindi nemmeno nelle sotto-query: un cliente
eliminato ma ancora assegnato apre comunque i documenti che il commerciale
vedeva già, e un legame di referente cancellato (`contact_relations.deleted`)
apre ancora la persona. Per chiuderlo serve un aiuto del core (vedi *Limiti*
sotto).

### Legami, scelte e accessi

`config/boot.php` chiama anche `ContactVisibility::register()`, che dà a
customer-account i due ganci:

- `ContactRelations::useCounterpartFilter()`: nei repeater «Referenti» e
  «Aziende» le scelte sono le schede visibili (più quelle già legate) e in
  scrittura un id non visibile si scarta (`ContactVisibility::visibleIds()`:
  nessuna query per chi vede tutto, una per chiamata per chi ha la regola);
- `ContactRelations::useAccessGuard()`: alza l'accesso all'area clienti
  (`buyer`, `admin`) solo chi vede tutte le aziende e può modificarle
  (`ContactVisibility::canGrantAccess()`); per il commerciale l'accesso resta
  quello salvato.

I select costruiti dal gestionale con le scelte di tutti (brand della scheda
azienda, brand seguiti dei referenti, brand degli indirizzi, capogruppo,
brand principale) passano da `ContactVisibility::options()`; il salvataggio
dei brand dell'azienda tiene solo quelli che il commerciale vede o ha appena
creato con la «+» (`linkableBrands()`, i nuovi in sessione); capogruppo e
brand principale non visibili si rifiutano. La «+» del brand, per lui, non
aggancia un brand con lo stesso nome che non vede (rifiuta con un messaggio
generico) e non mostra i nomi simili di altri. La pagina del brand (voce
*Anagrafiche → Brand*, con la funzionalità «Brand») gli elenca solo i suoi,
con la stessa regola, e non ha «Aggiungi» per lui: il brand nasce dalla «+»,
legato alla sua azienda.
Un doppione (email, codice fiscale, partita IVA) di una scheda che non vede si
rifiuta senza nominarla (`contact.duplicate_hidden`, e nella creazione rapida).

### Limiti

- Legame cancellato in `contactsRule()`: vedi sopra. Il gancio minimo nel core
  sarebbe permettere `deleted` dentro una sotto-query esplicita (per esempio
  una regola che dichiara le sue sotto-query), lasciando il divieto sulla
  tabella principale.
- L'esistenza di un doppione resta deducibile (la creazione si rifiuta): non
  si può evitare senza creare doppioni.

Le regole valgono ovunque si legga attraverso `RowVisibility`: elenchi e schede
delle Resource (dal core), ricerche dell'ordine da ufficio (`OfficeSearch`,
`OfficeCustomerPanel`), salvataggio dell'ordine da ufficio (`OfficeOrders`),
abbonamenti d'ufficio. Codice nuovo che legge clienti o documenti per un
utente del backend deve passare da `RowVisibility::current()` (`find`,
`findById`, `count`, `visible`) o `RowVisibility::applySql()`: così fanno
`OrderPaymentResource` e `OrderActionResource`, che altrimenti aprirebbero un
ordine qualsiasi dall'id nell'indirizzo.

I **Fornitori** sono acquisti: `SupplierResource` toglie `sales_agent` da menu
e permessi con `SalesAgents::withoutAgents()`. Lo stesso fanno i riquadri della
home con dati di tutti (`AttentionWidget`, `DuePaymentsWidget`).

## Il timbro sui documenti

`SalesAgents::forDocument($document, $creator)` dà il commerciale di un
documento nuovo: quello dell'azienda se c'è `company_id`, altrimenti quello del
contatto; se il cliente non ne ha e il creatore è un commerciale, il suo. Lo
chiamano `Checkout` (ordini online), `OfficeOrders` (preventivi e ordini da
ufficio) e `OfficeSubscriptions`; gli abbonamenti online prendono quello della
scheda del cliente (`SalesAgents::forContact`). DDT e resi copiano `agent_id`
dall'ordine. Il valore resta sul
documento: spostare il cliente non lo cambia. Con la funzionalità spenta è 0.

## Clienti e aziende

Nella scheda (`CustomerResource`, persone e aziende) il campo **Commerciale**
c'è solo per l'ufficio (`agentShown()`). Quando salva un commerciale,
`agentValue()` toglie `agent_id` dalla richiesta e, in creazione, mette il suo:
il commerciale non assegna né sposta clienti.

## Listini dei commerciali

Funzionalità `sales_agent_price_lists` (richiede `sales_agents` e
`customer_price_lists`). L'elenco sta in `gst_sales_agent_price_lists`
(`Models\SalesAgents\SalesAgentPriceList`, `sales_agent_id` e
`price_list_id`), il predefinito in `gst_sales_agents.default_price_list_id`.
Tutto passa da `Support\SalesAgents\SalesAgentPriceLists`:

- `allowed()`: `null` per l'ufficio o a funzionalità spenta, altrimenti
  l'elenco del commerciale corrente (anche vuoto: solo catalogo).
- `choices($options, $allowed, $keep)` e `accepts($allowed, $id, $previous)`:
  il listino già sul cliente resta fra le scelte e si accetta, gli altri fuori
  elenco no (`sales_agent.price_list_not_allowed`). Il catalogo (0) si accetta
  sempre.
- `forNew()`: il predefinito, solo se è ancora nell'elenco.
- `assign($agentId, $ids, $default)`: riscrive elenco e predefinito.

`SalesAgentResource` mostra il riquadro «Listini» solo con la funzionalità
accesa e scrive l'elenco in `afterStore`/`afterUpdate` solo se il riquadro è
arrivato nel POST; un predefinito fuori elenco dà
`sales_agent.default_not_in_list`. `CustomerResource::priceListChoices()`
filtra le scelte (solo listini attivi per il commerciale) e
`newPriceList()` mette il predefinito in creazione: nel form, nelle chiamate
API senza `price_list_id` e nell'importazione
(`GestionaleContactsImport::priceListChoices()`/`newPriceList()`). Il prezzo
non cambia: vale sempre il listino del cliente.

## Elenchi, home e MCP

- `SalesAgents::filter($layout)` aggiunge il filtro «Commerciale» (`agent_id`,
  con *Nessuno* = 0) agli elenchi; non lo mostra al commerciale. È su clienti,
  aziende, preventivi, ordini e bozze.
- I riquadri della home con dati di tutto il negozio tolgono `sales_agent` con
  `SalesAgents::withoutAgents()`. `Backend\Widgets\SalesAgentWidget` («I miei
  clienti») c'è solo per il commerciale e conta con `RowVisibility::current()`.
- Il server MCP esegue ogni strumento dentro `RowVisibility::as($utente, ...)`:
  un token di un commerciale legge e scrive solo i suoi clienti
  (`ContactTools`, `QuoteTools`, ricerche).

## Permessi

Ruolo `sales_agent` in `config/permissions.php` (non un'estensione di `sales`:
la visibilità per riga nasconderebbe i dati anche all'ufficio). Aree in
`config/module.php`: `contatti` e `vendite` in `view`/`create`/`edit`,
`catalogo` in lettura; nessuna `delete`. L'area `commerciali` (gestione delle
schede) è dell'`administrator`.

## Impostazioni

`Support\SalesAgents\SalesAgentSettings` aggiunge il riquadro «Commerciali»
alle Impostazioni (colonne su `gst_settings`); ogni campo c'è solo con la sua
funzionalità accesa e il salvataggio scarta quelli spenti:

| Colonna | Funzionalità | Valori |
|---|---|---|
| `agent_network_edit` | `sales_agent_network` | `true`/`false`: il default di chi sta sopra |
| `agent_commission_trigger` | `sales_agent_commissions` | `paid` o `fulfilled` |
| `agent_network_commission_default` | `sales_agent_network_commissions` | % di base per ogni livello |
| `agent_network_levels` | `sales_agent_network_commissions` | «3, 1»: % dal livello 1 in su, vuoto = la base |
| `agent_target_metric` | `sales_agent_targets` | `revenue`, `orders`, `new_customers` |

## Rete di commerciali

Funzionalità `sales_agent_network`. `gst_sales_agents.parent_id` dice chi sta
sopra; `network_edit` (`default`/`true`/`false`) se può modificare la rete
sotto. Tutto in `Support\SalesAgents\SalesAgentNetwork`:

- `parents()` legge la mappa una volta per richiesta (`forget()` la svuota,
  `use()` la imposta nei test); `descendants()`, `ancestors()` hanno la
  guardia contro i cicli.
- `team($id)`: lui e tutta la rete sotto. Con la rete accesa le regole di
  `SalesAgents` usano `agent_id IN (team)` al posto di `agent_id = N`.
- `validParent()`: un commerciale non va sotto se stesso né sotto la sua rete
  (`sales_agent.invalid_parent`).
- `canEdit()`/`canEditNow()` e `guard($oldValues)`: le schede di clienti,
  aziende e ordini da ufficio chiamano `guard()` prima di salvare una riga di un altro della
  rete; senza permesso `sales_agent.network_read_only`.

## Sconto massimo

Funzionalità `sales_agent_discount_limits`, colonna
`gst_sales_agents.max_discount_percent`. Su `gst_orders`: `approval_status`
(`none`, `pending`, `approved`, `rejected`), `approval_discount`,
`approved_by`, `approved_at`. In `Support\SalesAgents\DiscountLimit`:

- `percent($lines, $headerManual)`: solo lo sconto a mano, misurato su
  `reference_price` della riga (prezzo automatico), più lo sconto di testata
  se scritto a mano.
- `status()` e `apply()`: dopo ogni ricalcolo del carrello (`Cart`) di un
  documento con commerciale; entro il massimo torna `none`, sopra va
  `pending`, un `approved` resta tale finché lo sconto non supera quello
  approvato.
- `blocks()`: `pending` e `rejected` fermano la conferma (`Lifecycle`,
  `order.discount_needs_approval`, tranne quando arriva un pagamento) e l'invio
  del preventivo (`Quotes`). `decide()` è l'azione «Approva/Rifiuta sconto»
  di `OfficeOrderPage`, solo per l'ufficio.

## Codici dei commerciali

Funzionalità `sales_agent_coupons`, colonna `gst_coupons.agent_id`. In
`CouponResource` il campo c'è solo per l'ufficio; il commerciale vede i suoi
coupon in sola lettura (regola `SalesAgentCoupons::rule()` su `RowVisibility`,
`sales_agent.coupon_read_only` in scrittura). `Coupons` chiama
`SalesAgentCoupons::attribute()` quando un ordine registra i coupon: l'ordine
prende l'`agent_id` del primo coupon che ne ha uno (`agentOf()`), il cliente
(azienda, altrimenti persona) solo se non ne aveva (`plan()`).

## Provvigioni

Funzionalità `sales_agent_commissions` e `sales_agent_network_commissions`.

- `gst_sales_agent_commissions` (`Models\SalesAgents\SalesAgentCommission`):
  un registro a sola aggiunta, una riga per `order_id`, `sales_agent_id`,
  `level` (0 = chi vende, 1.. = rete), `type` (`accrual`/`reversal`), `base`,
  `percent`, `amount` (sempre positivo, il segno lo dà `type`: `signed()`),
  `accrued_at`, `paid_at`.
- `gst_sales_agent_commission_rules`
  (`SalesAgentCommissionRule`): le eccezioni per `product_id` o
  `category_id`, repeater della scheda del commerciale.

`Support\SalesAgents\Commissions::sync($orderId)` ricalcola in transazione
cosa spetta (`eligible()` col trigger delle impostazioni, `seller()` con
`lineBase()` e `rate()`, `upline()` per la rete) e scrive solo la differenza
con il registro (`diff()`): maturate in più, storni in meno. Si può chiamare
quante volte si vuole. `refresh()` (che non lancia) la chiamano `Lifecycle`
(cambio di stato), `Ledger` (pagamenti) e `Returns` (reso chiuso). Le regole
cambiate valgono dal prossimo `sync` dell'ordine: non c'è un ricalcolo di
massa.

`Resources\SalesAgents\SalesAgentCommissionResource` («Provvigioni», solo
elenco): filtri per commerciale (solo ufficio), periodo e liquidazione, CSV,
azione di gruppo «Segna come liquidate» (`markPaid()`, che scrive `paid_at`
anche sugli storni) e il totale `outstanding()` nel sottotitolo. Il
commerciale vede `SalesAgentNetwork::team()`.

## Obiettivi

Funzionalità `sales_agent_targets`. `gst_sales_agent_targets`
(`Models\SalesAgents\SalesAgentTarget`): `sales_agent_id`, `period`
(`month`/`year`), `year`, `month` (0 per l'anno), `amount`, `position`;
repeater «Obiettivi» della scheda (`sales_agent.invalid_target`).

`Support\SalesAgents\Targets`:

- `pick($rows, $year, $month)`: la prima riga del periodo vince;
- `bounds()` e `actuals($ids, $from, $to)`: una query per fatturato
  (`taxable_total`), ordini e clienti nuovi (nessun ordine valido prima di
  `$from`). Ordini validi: `stage = 'order'`, stato in
  `Commissions::STATUSES`, `ordered_at` valorizzato. Solo `agent_id` del
  commerciale, non la rete;
- `progress()`, `summary()`, `format()` per il testo.

`Backend\Widgets\SalesAgentWidget` aggiunge «I miei obiettivi» (mese e anno).
`Resources\SalesAgents\SalesAgentPerformanceResource` («Andamento
commerciali», solo elenco) mostra per commerciale obiettivo del mese e
dell'anno e i totali degli ultimi 12 mesi, letti una volta per pagina.

## Test

- `tests/QuickContactsTest.php` — controlli di `SalesAgentUsers::create()`
  prima del database (la creazione rapida di referenti e aziende è in
  `tests/ContactResourcesTest.php`).
- `tests/SalesAgentsTest.php` — ruolo, `restricted`, SQL delle regole,
  `none()` senza scheda, `withoutAgents`, `forDocument`.
- `tests/integrazione/SalesAgentsTest.php` — visibilità reale di contatti,
  aziende e documenti, ufficio, commerciale disattivato, timbro, scheda
  cliente e filtro.
- `tests/SalesAgentPriceListsTest.php` — listini consentiti e predefinito.
- `tests/SalesAgentNetworkTest.php` — rete, cicli, `validParent`, `canEdit`.
- `tests/SalesAgentDiscountLimitTest.php` — calcolo dello sconto e stati.
- `tests/SalesAgentCouponsTest.php` — commerciale del coupon e del cliente.
- `tests/SalesAgentCommissionsTest.php` — base, %, rete, trigger, differenza.
- `tests/SalesAgentTargetsTest.php` — scelta del periodo, validazione, testo.
