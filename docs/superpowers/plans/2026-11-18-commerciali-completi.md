# Commerciali completi: piano

**Spec:** `docs/superpowers/specs/2026-11-18-commerciali-completi-design.md`

Un commit per funzionalità; dopo ognuno `php -l`, tutti i test e
`'created' => false` tolto dalla voce in `config/features.php`.

## Vincoli
- Calcoli puri in classi senza DB (`DiscountLimit`, `SalesAgentNetwork`,
  `CommissionMath`, `Targets`), provati in `tests/`.
- Ogni aggancio esterno (pagamenti, evasione, resi) in try/catch con `error_log`:
  un errore delle provvigioni non blocca mai un ordine.
- Testi dell'interfaccia in italiano, mai «—» come testo visibile.
- Dopo il merge lo sviluppatore lancia `php forge update --local`.

## Task
1. **Sconto massimo**: `OrderItem.reference_price`; colonne di approvazione su
   `Order`; `Support/SalesAgents/DiscountLimit` (puro + `apply(orderId)`);
   chiamata in fondo a `Cart::recalculate`; blocco in `Lifecycle::confirm` e
   nell'invio del preventivo; azioni «Approva sconto» / «Rifiuta sconto»;
   coda «Da approvare». Test `SalesAgentDiscountLimitTest`.
2. **Codici**: `Coupon.agent_id`, campo e filtro; attribuzione in
   `Coupons::redeem`; regola di visibilità sui coupon. Test `SalesAgentCouponsTest`.
3. **Rete**: `SalesAgentNetwork` (discendenti con guardia, catena sopra,
   controllo cicli, permesso di modifica); regole con `IN (...)`; campo «Sopra
   di lui» e «Può modificare la sua rete»; impostazione del predefinito; blocco
   delle modifiche. Test `SalesAgentNetworkTest`.
4. **Provvigioni e provvigioni di rete**: regole per categoria/prodotto,
   registro, `Commissions::sync`, agganci, impostazioni (soglia, % di rete
   predefinita e per livello), pagina «Provvigioni» con export e liquidazione.
   Test `SalesAgentCommissionsTest`.
5. **Obiettivi**: tabella e ripetitore, impostazione della metrica,
   `Targets` puro, riquadro in bacheca e pagina «Andamento commerciali».
   Test `SalesAgentTargetsTest`.
6. Guide (`docs/user/commerciali.md`, `docs/user/funzionalita.md`,
   `docs/dev/concetti/commerciali.md`), CHANGELOG, PR, checklist di #77.
