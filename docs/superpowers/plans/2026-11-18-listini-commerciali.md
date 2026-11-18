# Listini dei commerciali Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il commerciale applica ai suoi clienti solo i listini che l'ufficio gli ha assegnato, con un predefinito sui clienti nuovi.

**Architecture:** tabella ponte commerciale ↔ listino e colonna `default_price_list_id`; un supporto `SalesAgentPriceLists` con le regole pure (scelte ammesse, controllo, predefinito) usato dalla scheda del commerciale, dal form cliente/azienda e dall'import.

**Tech Stack:** PHP 8, Wonder app (Model, Resource, FormField), test PHP senza database.

**Spec:** `docs/superpowers/specs/2026-11-18-listini-commerciali-design.md`

## Global Constraints

- Funzionalità `sales_agent_price_lists` (richiede `sales_agents`, `customer_price_lists`).
- Ufficio e funzionalità spenta: comportamento invariato.
- Commenti brevi in italiano; nessun «—» come testo visibile; errori via `UserError::make` con chiave in `lang/it/gestionale.json`.

## Review Focus

- Cliente con un listino dell'ufficio fuori elenco: il commerciale salva la scheda senza toccarlo e non riceve errore.
- Predefinito non più nell'elenco (tolto dopo): non va applicato.
- Commerciale con elenco vuoto: solo «Nessuno (prezzi di catalogo)».
- Il commerciale manda `price_list_id` vuoto in creazione: resta catalogo, non il predefinito.
- Listino disattivato ma nell'elenco: non proposto ai clienti nuovi, mantenuto dove già c'è.

---

### Task 1: Dati e regole

**Files:**
- Create: `src/Models/SalesAgents/SalesAgentPriceList.php`, `src/Support/SalesAgents/SalesAgentPriceLists.php`
- Modify: `src/Models/SalesAgents/SalesAgent.php` (colonna `default_price_list_id`), `config/features.php`
- Test: `tests/SalesAgentPriceListsTest.php`

**Interfaces (Produces):**
- `SalesAgentPriceLists::enabled(): bool`
- `SalesAgentPriceLists::of(int $agentId): list<int>` e `defaultOf(int $agentId): int` (0 se fuori elenco)
- `SalesAgentPriceLists::assign(int $agentId, list<int> $ids, int $default): void`
- `SalesAgentPriceLists::allowed(): ?list<int>` (null = nessun limite)
- `SalesAgentPriceLists::choices(array $options, ?array $allowed, int $keep): array`
- `SalesAgentPriceLists::accepts(?array $allowed, int $requested, int $previous): bool`
- `SalesAgentPriceLists::forNew(): int`

- [ ] Test: `choices` filtra e tiene `$keep`; `accepts` ammette 0, il precedente e gli assegnati, rifiuta il resto; con `null` tutto ammesso; `defaultOf` 0 se il predefinito non è nell'elenco.
- [ ] Implementare, eseguire `php tests/SalesAgentPriceListsTest.php`, commit.

### Task 2: Scheda del commerciale

**Files:** `src/Resources/SalesAgents/SalesAgentResource.php`, `lang/it/gestionale.json`

- [ ] Card «Listini» (con la funzionalità): `price_lists` checkbox, `default_price_list_id` select; validazione: predefinito nell'elenco (`sales_agent.default_not_in_list`); scrittura in `afterStore`/`afterUpdate`; lettura in `mutateFormValues`.

### Task 3: Cliente, azienda, import

**Files:** `src/Resources/Contacts/CustomerResource.php`, `src/Support/Contacts/GestionaleContactsImport.php`, `lang/it/gestionale.json`

- [ ] Scelte filtrate con `choices`, predefinito nel form nuovo; in `mutateRequestValues` rifiuto con `sales_agent.price_list_not_allowed`, predefinito se la chiave manca in creazione; import con scelte filtrate e predefinito sulle schede nuove.

### Task 4: Guide e chiusura

- [ ] `docs/user/commerciali.md`, `docs/user/promozioni-listini-cliente.md`, `docs/user/funzionalita.md`, doc sviluppatore, `CHANGELOG.md`, `TODO.md`; tutti i test, `php -l`; PR; checklist di #77.
