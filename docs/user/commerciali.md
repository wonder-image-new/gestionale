---
icon: user-tie
---

# Commerciali

> **Da attivare.** Funzionalità **Commerciali**, che richiede **Ordini** e
> **Vendita in ufficio**. Finché è spenta non vedi il menu *Commerciali*, il
> campo *Commerciale* nelle schede né il filtro negli elenchi.

## A cosa serve

Un **commerciale** vende per il negozio e lavora solo sui **suoi clienti**:
entra nel pannello con il suo accesso, vede le persone e le aziende assegnate
a lui, ne crea di nuove, fa preventivi e ordini da ufficio. Non vede i clienti
degli altri né i dati dell'ufficio (magazzino, impostazioni, promozioni).

L'ufficio continua a vedere tutto: assegna i clienti e filtra gli elenchi per
commerciale.

## Aggiungere un commerciale

1. In **Anagrafiche → Commerciali** premi **Nuovo commerciale** e scrivi
   **nome**, **cognome** ed **email**. Al salvataggio nasce il suo utente con
   il ruolo **Commerciale**, e gli arriva una mail per scegliere la password ed
   entrare nel pannello. Se la mail non arriva, può usare «Password
   dimenticata».
2. Puoi collegare la sua **scheda anagrafica** (un contatto).
3. **Provvigione %** e **Sconto massimo %** compaiono solo quando attivi le
   funzionalità *Provvigioni* e *Sconto massimo dei commerciali*; i riquadri
   **Rete**, **Listini**, **Provvigioni per prodotto o categoria** e
   **Obiettivi** compaiono con le loro funzionalità (vedi sotto).

Ogni utente ha una sola scheda da commerciale: in modifica l'utente si vede ma
non si cambia. Se l'email è già di un commerciale con la sua scheda, il
salvataggio si ferma e te lo dice; se è di un utente con un altro ruolo (per
esempio un operatore d'ufficio), si ferma anche lì: cambia il ruolo da
**Utenti** o usa un'altra email. Un utente con il ruolo Commerciale ma senza
scheda attiva entra nel pannello e non vede nessun cliente.

> Se all'utente dai anche il ruolo **Vendite**, torna un operatore d'ufficio e
> vede tutto.

## Assegnare i clienti

Nella scheda della persona o dell'azienda, campo **Commerciale**, scegli a chi
va. Un commerciale vede:

* le **persone** e le **aziende** assegnate a lui;
* le persone **collegate** a un'azienda sua (i referenti);
* i **documenti** (preventivi, ordini, DDT, resi, abbonamenti) dei suoi clienti,
  anche quelli fatti prima dell'assegnazione;
* i documenti **che ha fatto lui**, anche se poi il cliente passa a un altro.

Quando un commerciale crea un cliente, il cliente è suo: non può assegnarlo a
qualcun altro. Solo l'ufficio sposta i clienti.

Con la funzionalità *Brand*, il commerciale vede e modifica i **brand** legati
alle sue aziende (anche in **Anagrafiche → Brand**, dove trova solo quelli), e
nelle tendine (referenti, aziende, brand, capogruppo) trova solo le schede che
vede. Un brand nuovo lo crea con il **+** della scheda o dell'ordine, non con
*Aggiungi* della pagina dei brand: nasce legato alla sua azienda. Non dà a un referente l'accesso all'area clienti
(*Ordina*, *Gestisce l'azienda*): lo fa l'ufficio. Se prova a creare una scheda
o un brand che esiste già fra quelli di altri, il gestionale lo ferma senza
dire di chi è: chiede all'ufficio.

## Listini

> **Da attivare.** Funzionalità **Listini dei commerciali**, che richiede
> **Commerciali** e **Listini cliente**. Spenta, il commerciale sceglie fra
> tutti i listini come l'ufficio.

Nella scheda del commerciale, riquadro **Listini**:

* spunta i **listini** che può dare ai suoi clienti. Lo stesso listino può
  stare su più commerciali. Senza spunte il commerciale non dà listini: i suoi
  clienti pagano i prezzi di catalogo;
* scegli il **listino predefinito** fra quelli spuntati: va da solo sui
  clienti nuovi che crea il commerciale, dalla scheda o da un'importazione. *Nessuno* li lascia a prezzi di catalogo.

Il listino resta sul cliente: i prezzi si calcolano come sempre (vedi
[Listini cliente](promozioni-listini-cliente.md)). Nella scheda del cliente il
commerciale trova solo i suoi listini attivi. Se il cliente ha già un listino
messo dall'ufficio, lo vede e può toglierlo, ma poi non può rimetterlo: per
quello chiede all'ufficio. L'ufficio continua a scegliere fra tutti i
listini.

Togliere un listino dall'elenco non cambia i clienti che ce l'hanno già.

## Il commerciale sui documenti

Un preventivo o un ordine nuovo prende il commerciale del cliente: quello
dell'**azienda** se il documento è intestato a un'azienda, altrimenti quello
della **persona**. Se il cliente non ne ha e il documento lo fa un commerciale,
va a lui. Il commerciale resta sul documento anche se in seguito il cliente
cambia commerciale: la vendita è di chi l'ha fatta.

## Negli elenchi

Clienti, aziende, preventivi, ordini e bozze hanno il filtro **Commerciale**:
*Tutti*, *Nessuno* (senza commerciale) o un nome. Il filtro lo vede solo
l'ufficio; il commerciale vede già solo i suoi.

## Rete di commerciali

> **Da attivare.** Funzionalità **Rete di commerciali**, che richiede
> **Commerciali**.

Nella scheda del commerciale, riquadro **Rete**, il campo **Sopra di lui**
dice a chi risponde. I livelli sono quanti servono: A sopra B, B sopra C, e
così via. Un commerciale non può stare sotto se stesso né sotto uno della sua
rete: il salvataggio si ferma.

Chi sta sopra vede i clienti, le aziende e i documenti di tutta la sua rete
sotto, oltre ai suoi. Se può anche **modificarli** lo decidi commerciale per
commerciale nel campo **Può modificare la sua rete**:

* **Sì** o **No** valgono solo per lui;
* **Come da impostazioni** segue la scelta di **Impostazioni → Commerciali →
  Chi sta sopra modifica la sua rete**, che vale per tutti quelli lasciati
  così.

Senza permesso li vede in sola lettura. I propri dati li modifica sempre.

## Sconto massimo

> **Da attivare.** Funzionalità **Sconto massimo dei commerciali**, che
> richiede **Commerciali**.

Nella scheda del commerciale scrivi lo **Sconto massimo %**. Conta solo lo
sconto fatto a mano (prezzo cambiato, sconto di riga, sconto sul totale),
misurato sul prezzo che il gestionale avrebbe messo da solo: i prezzi di
listino e le promozioni automatiche non contano.

Se un commerciale supera il suo massimo, il preventivo o l'ordine resta **da
approvare**: il preventivo non si invia al cliente e la bozza non si conferma.
L'ufficio apre il documento e preme **Approva sconto** oppure **Rifiuta
sconto**. Se il commerciale poi riporta lo sconto entro il massimo, il
documento si sblocca da solo; se dopo l'approvazione lo alza ancora, torna da
approvare. Un pagamento che arriva si registra comunque.

## Codici dei commerciali

> **Da attivare.** Funzionalità **Codici dei commerciali**, che richiede
> **Commerciali** e **Coupon**.

I codici li crea l'ufficio: in **Promozioni → Coupon**, nella scheda del
codice, scegli il **Commerciale**. Quando un cliente usa quel codice in un
ordine sul sito:

* l'**ordine** va al commerciale del codice;
* il **cliente** (l'azienda, altrimenti la persona) passa a lui solo se non
  aveva già un commerciale.

Se l'ordine usa più codici, vale il primo che ha un commerciale. Il
commerciale vede i suoi codici in sola lettura: per crearli o cambiarli
chiede all'ufficio.

## Provvigioni

> **Da attivare.** Funzionalità **Provvigioni**, che richiede
> **Commerciali**.

**Quanto.** Nella scheda del commerciale scrivi la **Provvigione %**. Nel
riquadro delle provvigioni puoi aggiungere eccezioni: una % diversa per un
**prodotto** o per una **categoria**. Per ogni riga dell'ordine vale prima la
regola del prodotto, poi quella della categoria, poi la % del commerciale.

**Su cosa.** La base è l'**imponibile** (senza IVA) dei prodotti e delle
righe libere, al netto degli sconti. Spedizione e spese non contano.

**Quando.** In **Impostazioni → Commerciali → La provvigione matura** scegli
**Al pagamento** (ordine pagato) o **All'evasione** (ordine evaso). Va al
commerciale scritto sull'ordine.

**Resi e modifiche.** Un reso chiuso toglie la sua parte: la provvigione
viene **stornata** con una riga in negativo. Lo stesso succede se l'ordine
viene annullato. Se cambi la % o le eccezioni, valgono per gli ordini che si
aggiornano da quel momento: le provvigioni già maturate non si ricalcolano da
sole.

**La pagina Provvigioni.** In **Anagrafiche → Provvigioni** trovi tutte le
righe maturate e stornate, con il totale **da liquidare** in alto. Puoi
filtrare per commerciale, periodo e liquidazione, scaricare il **CSV** e,
scelte le righe, premere **Segna come liquidate**. Uno storno non ancora
liquidato si scala dal pagamento successivo: segnalo insieme alle altre.

Il commerciale vede le sue provvigioni (e quelle della sua rete), senza
azioni.

## Provvigioni di rete

> **Da attivare.** Funzionalità **Provvigioni di rete**, che richiede **Rete
> di commerciali** e **Provvigioni**.

Chi sta sopra prende una % su quello che vende la sua rete, calcolata sulla
stessa base della provvigione di chi vende. In **Impostazioni → Commerciali**:

* **Provvigione di rete di base (%)** vale per tutti i livelli;
* **Provvigione di rete per livello (%)** la cambia livello per livello, in
  ordine dal primo sopra: per esempio «3, 1». Un livello lasciato vuoto prende
  la base; 0 non dà niente.

Per esempio, con A sopra B e B sopra C, C che vende 1.000 € con il 10 % e
livelli «3, 1»: C prende 100 €, B 30 € e A 10 €. Nella pagina Provvigioni
queste righe hanno il livello «Rete, livello 1», «Rete, livello 2» e così via.

## Obiettivi

> **Da attivare.** Funzionalità **Obiettivi dei commerciali**, che richiede
> **Commerciali**.

Nella scheda del commerciale, riquadro **Obiettivi**, aggiungi una riga per
ogni traguardo: **periodo** (mese o anno), **anno**, **mese** e il valore da
raggiungere. Per lo stesso periodo vale la prima riga in elenco.

Su cosa si misura lo decidi in **Impostazioni → Commerciali → Obiettivi su**:
**Fatturato** (imponibile degli ordini, senza IVA), **Numero di ordini** o
**Clienti nuovi** (clienti al loro primo ordine). Contano solo gli ordini
confermati e non annullati, e solo le vendite del commerciale stesso, non
quelle della sua rete.

Il commerciale vede l'avanzamento nella home (**I miei obiettivi**, mese e
anno in corso). La pagina **Anagrafiche → Andamento commerciali** mostra per
ognuno l'obiettivo del mese e dell'anno con la percentuale raggiunta, e
fatturato, ordini e clienti nuovi degli ultimi 12 mesi. L'ufficio vede tutti;
il commerciale vede sé e la sua rete.

## Nella home

Il commerciale trova il riquadro **I miei clienti**, con quante persone,
aziende, preventivi e ordini ha e i collegamenti agli elenchi. Con gli
obiettivi accesi trova anche **I miei obiettivi**. I riquadri con i dati di
tutto il negozio (scorte, pagamenti, valore del magazzino) restano
dell'ufficio.

## Disattivare un commerciale

Nella lista dei commerciali premi **Disattiva**. Da quel momento non vede più
nessun cliente; i clienti restano assegnati a lui finché non li sposti, così
puoi riassegnarli con calma filtrando gli elenchi per il suo nome.
