# Commerciali completi (#77): sconto massimo, codici, rete, provvigioni, obiettivi

Data: 2026-11-18. Riguarda le sei funzionalità di `config/features.php`
ancora con `'created' => false`. La base (#77) e i listini (#207) sono già fatti.

## Perché

Il negozio che lavora con una forza vendita deve poter limitare quello che il
commerciale concede, attribuirgli le vendite anche quando arrivano dal sito,
organizzare i commerciali in una rete, pagare le provvigioni (anche a chi sta
sopra) e misurare l'andamento rispetto agli obiettivi. Le scelte che cambiano
da negozio a negozio stanno nelle impostazioni, non nel codice.

## Decisioni prese con lo sviluppatore

| Tema | Decisione |
|---|---|
| Sconto massimo | Conta solo lo sconto **scritto a mano** (prezzo a mano, sconto di riga, sconto sulla testata), misurato sul prezzo automatico della riga (listino, campagna, prezzo scontato o base). Coupon e campagne esclusi. |
| Oltre il limite | L'ordine resta **«Da approvare»**: non si conferma e non si invia finché l'ufficio non approva. Coda dedicata. |
| Codici | Li crea **l'ufficio**: un coupon normale con il campo «Commerciale». |
| Attribuzione del codice | L'**ordine** va sempre al commerciale del codice; il **cliente** passa a lui solo se non ne aveva uno. |
| Rete | Chi sta sopra **vede** sempre la rete sotto di lui (tutti i livelli). **Modifica** solo con il permesso, dato commerciale per commerciale (sì / no / predefinito) e con il predefinito nelle impostazioni. |
| Maturazione provvigioni | Impostazione del progetto: **pagato** (predefinito) o **evaso**. Base: imponibile dei prodotti, senza IVA, spese e spedizione, al netto degli sconti. |
| Provvigioni di rete | Una **% per livello** sopra chi ha venduto, calcolata sull'**imponibile venduto** (C vende 1.000 €: C 10% = 100 €, B 1° livello 3% = 30 €, A 2° livello 1% = 10 €). Una % predefinita vale per i livelli senza un valore proprio. |
| Obiettivi | Metrica scelta nelle impostazioni: **fatturato** (predefinito), numero di ordini o clienti nuovi. Obiettivi mensili e annuali. |

## Approcci considerati

- **Stato nuovo in `Order::STATUSES`** contro **colonna di approvazione**:
  scelta la colonna `approval_status`. Uno stato nuovo tocca ogni consumatore
  degli stati (e-commerce, area cliente, MCP, code); la colonna lascia lo
  stato com'è e blocca solo conferma e invio.
- **Provvigioni ricalcolate al volo** contro **registro**: scelto il registro
  (`gst_sales_agent_commissions`) con percentuali, catena della rete e righe
  fotografate alla prima maturazione. Cambiare oggi la % di un commerciale non
  riscrive le provvigioni di ieri; un reso scrive una riga negativa.
- **Per differenza** nelle provvigioni di rete: scartato dallo sviluppatore a
  favore della % per livello.

## Modello dati

- `gst_order_items.reference_price` decimal(12,2): il prezzo automatico della
  riga senza interventi a mano. Lo scrive `Cart::recalculate`.
- `gst_orders`: `approval_status` enum(`none`,`pending`,`approved`,`rejected`)
  default `none`, `approval_discount` decimal(5,2), `approved_by` int,
  `approved_at` datetime.
- `gst_coupons.agent_id` int default 0.
- `gst_sales_agents.network_edit` enum(`default`,`true`,`false`) default `default`.
- `gst_sales_agent_commission_rules`: `sales_agent_id`, `category_id`,
  `product_id`, `percent`, `position`. Prodotto vince su categoria, categoria
  sulla % del commerciale.
- `gst_sales_agent_commissions` (registro): `sales_agent_id`, `order_id`,
  `level` (0 = vendita propria, n = livello sopra), `seller_id`, `type`
  (`accrual`/`reversal`), `base`, `percent`, `amount`, `rates` (JSON id
  riga ⇒ %), `accrued_at`, `paid_at`, `note`.
- `gst_sales_agent_network_levels` (figlia delle impostazioni): `setting_id`,
  `level`, `percent`, `position`.
- `gst_sales_agent_targets`: `sales_agent_id`, `period` (`month`/`year`),
  `year`, `month` (0 per l'anno), `amount`.
- `gst_settings`: `agent_network_edit` (true/false, default false),
  `agent_commission_trigger` (`paid`/`fulfilled`, default `paid`),
  `agent_network_commission_default` decimal(5,2) default 0,
  `agent_target_metric` (`revenue`/`orders`/`new_customers`, default `revenue`).

## Comportamento

### Sconto massimo (`sales_agent_discount_limits`)
- `DiscountLimit::percent(lines, headerManual)`: per ogni riga di prodotto con
  `reference_price` > 0, lo sconto % = 1 − (prezzo netto unitario dopo sconto
  di riga e quota dello sconto di testata a mano) / `reference_price`. Il
  risultato è il massimo fra le righe. Il sovrapprezzo di personalizzazione non conta.
- Limite = `max_discount_percent` del commerciale; **0 vuol dire nessun limite**.
- Dopo ogni ricalcolo fatto da un commerciale (`SalesAgents::current()` > 0)
  su un preventivo o ordine: oltre il limite ⇒ `pending` con
  `approval_discount` = sconto; entro il limite ⇒ torna `none` se era
  `pending`/`rejected`. Già `approved` resta tale finché lo sconto non supera
  quello approvato. L'ufficio non fa mai scattare l'approvazione.
- `Lifecycle::confirm` e l'invio del preventivo rifiutano `pending` e `rejected`.
- Azioni ufficio «Approva sconto» e «Rifiuta sconto» nella scheda; coda «Da approvare».

### Codici (`sales_agent_coupons`)
- Campo «Commerciale» nel coupon (ufficio); filtro nell'elenco.
- Il commerciale vede in sola lettura i suoi coupon (e quelli della rete).
- Al riscatto (`Coupons::redeem`) il primo coupon applicato con commerciale
  attribuisce l'ordine; il cliente (persona o azienda) passa a lui solo se senza commerciale.

### Rete (`sales_agent_network`)
- Campo «Sopra di lui» nella scheda del commerciale; rifiutati sé stesso e i
  commerciali della sua rete (niente cicli).
- `SalesAgentNetwork::descendants(parents, id)` puro con guardia sui cicli;
  ogni regola di visibilità usa `IN (sé + rete)`.
- Modifica: proprio dato sempre; dato della rete solo se `network_edit` =
  `true`, o `default` con l'impostazione accesa. Controllo nelle modifiche di
  clienti, aziende e ordini.

### Provvigioni (`sales_agent_commissions`) e di rete
- `Commissions::sync(orderId)` idempotente, chiamato da `Ledger::sync`,
  `Lifecycle::fulfill`/`cancel`/`refresh` e `Returns::complete`. Un errore si
  scrive nel log e non blocca il pagamento.
- Dovuto = 0 se l'ordine non ha raggiunto la soglia (pagato/evaso), è
  annullato o rimborsato per intero; altrimenti la somma per riga di
  (imponibile − quota resa) × % della riga. Si scrive solo la differenza con
  quanto già registrato.
- Alla prima maturazione si fotografano commerciale, % per riga e catena della rete.
- Rete: per ogni livello sopra (commerciali attivi), % del livello o la
  predefinita; 0 = niente.
- Pagina «Provvigioni»: elenco del registro con filtri commerciale, mese e
  stato, totale, export CSV, azione «Segna come liquidate». Il commerciale vede solo le sue.

### Obiettivi (`sales_agent_targets`)
- Ripetitore «Obiettivi» nella scheda del commerciale (periodo, anno, mese, valore).
- `Targets::progress(target, actual)` puro; il venduto si legge dagli ordini
  confermati, in lavorazione o completati, per `ordered_at`.
- Riquadro nella bacheca del commerciale e pagina «Andamento commerciali» per
  l'ufficio: mese e anno correnti con avanzamento, ultimi 12 mesi di fatturato,
  ordini e clienti nuovi.

## Fuori portata

Pagamento automatico delle provvigioni, fatture del commerciale (Enasarco),
provvigioni per differenza, obiettivi per categoria.
