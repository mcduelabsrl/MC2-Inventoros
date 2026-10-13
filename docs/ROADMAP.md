# Roadmap MC2 / Saxone su Inventoros

Aggiornata al 13 ottobre 2026. Due parti: le azioni da fare in negozio, in produzione o su Shopify (A), e gli sviluppi (B). In fondo, cosa è già fatto.

## A. Azioni operative

### Subito

1. **Valuta in EUR.**
   - Oggi sono in USD: l'organizzazione, i 150 prodotti e i due magazzini.
   - Gli ordini e le spedizioni sono già in EUR.
   - La dashboard e i report mostrano quindi gli importi in euro con la sigla USD.
   - Si corregge con la procedura in fondo a questo file. Cambia solo la sigla, i prezzi restano gli stessi.
2. **Deploy della PR "ordinamento colonne"**, dopo il merge:
   ```bash
   cd /home/andrea/Saxone/MC2-Inventoros && deploy/ssh/deploy.sh --apply --assets
   ```
3. **Primo ordine nuovo su Shopify:** controlla che l'evasione risulti su **MC2 Lab Srl**, perché la priorità di instradamento è stata cambiata il 13 ottobre. Poi:
   ```
   php artisan shopify:routing-check
   ```
4. **Prima spedizione da Inventoros di un ordine Shopify.** Verifica che:
   - su Shopify l'ordine risulti evaso, con il tracking;
   - il cliente riceva **una sola** mail, quella di Shopify.

### Ordini aperti in attesa di merce

| Ordine | Scarpa | Situazione | Cosa fare quando arriva |
|---|---|---|---|
| #1324 | Woman Penny Loafer Dark Brown Suede 37 | Riserva già su MC2, disponibile −1 | Carico +1 in MC2 da *Giacenze per magazzino*, poi spedisci da Inventoros |
| #1326 | Penny Loafer Blue Leather 39.5 | Riserva già su MC2, disponibile −1 | Come sopra |
| #1328 | Arran Penny Loafer Dark Walnut 41.5 (su ordinazione) | In attesa su Shopify, riserva su Via Maragliano | Carico +1 in MC2; su Shopify togli l'attesa e *Cambia location* → MC2 Lab Srl; spedisci da Inventoros |
| #1332 | Arran Penny Loafer Black 42.5 (su ordinazione) | Come #1328 | Come #1328 |
| #1348 | Penny Loafer Calf Leather Black 46 | Aspetta il riassortimento del 5 novembre; riserva su Via Maragliano (−1), 2 paia in arrivo lì | Si decide con #T0001 (sotto) |

### Da decidere o controllare

5. **Trasferimento #T0001:** 36 paia in arrivo a Via Maragliano (modelli 1000-22, 1000-23, 1000-74, 1000-520). Il comando qui sotto mostra il piano e non cambia niente; poi decidiamo insieme se dirottarlo su MC2. Lo stesso vale per #1348.
   ```
   php artisan shopify:transfer-plan T0001
   ```
6. **`COLLW-30-35`** (Woman College Loafer Black 35, Via Maragliano): Shopify tiene 1 paio impegnato per un ordine che Inventoros non ha. Da cercare su Shopify.
7. **Ordine #1178 del 2022:** su Shopify risulta ancora aperto e non evaso. Probabilmente va archiviato.
8. **Proprietà MC2 / Saxone:** tutte le paia sono "da attribuire". Si dividono da *Giacenze per magazzino* → filtro "Proprietà da attribuire" → "Assegna proprietà". Su Shopify non cambia niente.

## B. Sviluppi

| # | Cosa | Perché | Stato |
|---|---|---|---|
| 1 | Ordinamento delle colonne in *Giacenze per magazzino* (clic sull'intestazione, crescente/decrescente; le colonne dei magazzini per paia fisiche) | Con oltre 5.000 varianti la lista partiva dalle varianti a zero | **Fatto**, da unire e pubblicare |
| 2 | Filtro "solo con giacenza", attivo di default | Nascondere le varianti a zero ovunque | Da fare |
| 3 | *Inventari fisici*: "Cosa contare" su MC2 di default quando non ci sono prodotti senza varianti | Oggi propone "Prodotti per ubicazione", che è vuoto | Da fare |
| 4 | Clienti dagli ordini Shopify: scheda cliente creata o collegata per email, anche per gli ordini già importati | La sezione *Clienti* è vuota | Da fare |
| 5 | Ordini su ordinazione (in attesa su Shopify): non riservare paia che non esistono | Oggi #1328 e #1332 compaiono come anomalia "impegnato diverso" | Da valutare |
| 6 | Comando per "Porta in MC2" su più ordini, con l'utente indicato | Il 13 ottobre è stato fatto da server con il primo amministratore (Emanuele Cosentino) | Da valutare |
| 7 | Stato della coda verso Shopify nella pagina del plugin | Oggi stati e anomalie sono solo in *Giacenze per magazzino* | Da fare |
| 8 | Traduzioni delle schermate nuove nelle altre 12 lingue | Oggi sono in italiano e inglese | Bassa priorità |
| 9 | Test `ShopifyPluginTest::test_insufficient_stock_fails_and_can_be_retried_after_restocking` su MySQL | Fallisce anche su `main`: l'attivazione del plugin crea tabelle dentro la transazione del test | Da fare (solo test) |
| 10 | Stato `safety_stock` di Shopify | Non viene importato come stato separato | Da valutare |

## Fatto (13 ottobre 2026)

- **Due magazzini per variante:** MC2 Lab Srl e Saxone, con proprietari e stati; schermata *Giacenze per magazzino*; pannello giacenza nell'ordine.
- **Sincronizzazione Shopify bidirezionale**, attivata e provata in produzione (+1/−1 con webhook riconosciuti).
- **Spedizioni da Inventoros** che evadono l'ordine su Shopify; la mail al cliente la manda Shopify.
- **Trasferimenti in tre passaggi** (preparato, spedito, ricevuto) e inventari fisici per variante e magazzino.
- **Configurazione da Shopify** con confronto approvato; 14 SKU doppi di LOAFER DARK BROWN corretti.
- **20 ordini aperti portati in MC2**, più #1324 e #1326 spostati su MC2 in attesa della merce.
- **Correzioni dal primo uso reale:**
  - attesa del limite di richieste di Shopify;
  - attivazione dell'articolo prima di spostare un'evasione;
  - nome di indice troppo lungo per MySQL.
- **Script di deploy via SSH**, con le impostazioni del server in un file locale non tracciato.

## Procedura: valuta in EUR

Sul server, nella cartella `~/www/saxone.eu/inventoros`:

1. Apri la console:
   ```
   php artisan tinker
   ```
2. Incolla queste quattro righe, una alla volta. Ognuna stampa quante righe ha aggiornato: 1, 150, 2.
   ```php
   DB::table('organizations')->where('currency', 'USD')->update(['currency' => 'EUR', 'default_currency' => 'EUR', 'updated_at' => now()]);
   DB::table('products')->where('currency', 'USD')->update(['currency' => 'EUR', 'updated_at' => now()]);
   DB::table('warehouses')->where('currency', 'USD')->update(['currency' => 'EUR', 'updated_at' => now()]);
   exit
   ```
3. Svuota la cache:
   ```
   php artisan cache:clear
   ```
