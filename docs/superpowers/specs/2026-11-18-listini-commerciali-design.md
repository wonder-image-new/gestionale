# Listini dei commerciali (#77, `sales_agent_price_lists`)

## Perché

L'ufficio crea i listini; il commerciale li applica ai suoi clienti ma non
deve poter dare un listino qualsiasi. Oggi nel campo «Listino» della scheda
cliente e azienda, e nell'import, il commerciale vede e assegna tutti i
listini.

## Cosa

- Ogni commerciale ha un elenco di listini che può applicare (zero, uno o
  più). Lo stesso listino può stare nell'elenco di più commerciali. Elenco
  vuoto: il commerciale dà solo i prezzi di catalogo.
- Un **listino predefinito**, scelto fra quelli dell'elenco: si mette da solo
  sui clienti nuovi che crea il commerciale (form, API, import).
- Il listino resta sul cliente o sull'azienda: i prezzi si calcolano come
  oggi (`PriceLists::candidates`). Spostare un cliente a un altro commerciale
  non cambia i suoi prezzi.
- Lato commerciale (`SalesAgents::current() > 0`, funzionalità accesa): le
  scelte del campo «Listino» e della colonna dell'import sono solo i suoi
  listini. Il listino già sul cliente resta visibile anche se non è suo: lo
  può togliere, non rimettere. Il server rifiuta un id fuori elenco.
- Ufficio e funzionalità spenta: nulla cambia.

## Non obiettivi

- Richiesta all'ufficio per un listino fuori elenco (verrà con «Sconto
  massimo dei commerciali», che ha già lo stato *da approvare*).
- Un listino legato al commerciale che valga da sé sui prezzi.

## Approcci

1. **Listino sul commerciale** che vale sui prezzi dei suoi clienti: scartato,
   lo spostamento di un cliente cambierebbe i prezzi senza una decisione.
2. **Richiesta all'ufficio** per ogni assegnazione: scartato come base, troppo
   attrito sui casi ovvi; resta come estensione futura.
3. **Elenco di listini applicabili** (scelto): copre anche «un listino solo» e
   «solo catalogo», e la condivisione fra commerciali.

Per i dati: tabella ponte `gst_sales_agent_price_lists` (come i ponti del
filtro catalogo dei listini) e colonna `default_price_list_id` su
`gst_sales_agents`. Una colonna JSON non si filtra né si indicizza.

## Parti

- `Models\SalesAgents\SalesAgentPriceList` e la colonna sul commerciale.
- `Support\SalesAgents\SalesAgentPriceLists`: lettura, scrittura, scelte
  ammesse e controllo (le regole pure sono testate senza database).
- `SalesAgentResource`: card «Listini» con la funzionalità accesa.
- `CustomerResource` (e quindi `CompanyResource`): scelte filtrate, predefinito
  sui nuovi, rifiuto lato server.
- `GestionaleContactsImport`: colonna «Listino» filtrata, predefinito sulle
  schede nuove.
- `config/features.php`: `created` tolto (funzionalità costruita).
