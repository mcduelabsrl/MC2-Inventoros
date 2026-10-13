# Due magazzini per variante: attivazione in produzione

Branch: `feature/dual-warehouse`. Plugin Shopify 2.0.0.

Le scritture verso Shopify partono solo con `shopify:stock-activate`, dopo che il confronto iniziale è stato controllato e approvato. Prima di quel comando Inventoros non modifica niente su Shopify.

## Cosa cambia

- **Quantità per variante, magazzino e proprietario.**
  - Ogni variante ha quantità distinte in *MC2 Lab Srl, Via Luccoli* (principale) e in *Saxone, Via Maragliano*.
  - Le quantità sono divise per proprietario: MC2, Saxone, oppure *proprietà da attribuire*.
  - Per ogni combinazione si vedono gli stati: fisico, disponibile, impegnato, non disponibile (da controllare, danneggiato, riservato) e in arrivo.
- **`product_variants.stock` diventa un valore calcolato.** Vale fisico meno impegnato, sommato sui magazzini. Totali, report e soglie di scorta continuano a funzionare. Il prodotto padre e il vecchio saldo aggregato non vengono mai sommati.
- **Ordini.** Impegnano le paia nel magazzino indicato da Shopify per gli ordini Shopify. Per gli altri ordini l'ordine è MC2, poi Saxone.
  - Gli annullamenti liberano le paia; le spedizioni le scaricano.
  - Una spedizione parte solo quando tutte le paia sono riunite in MC2. Il consolidamento è un trasferimento collegato all'ordine e le paia restano impegnate per tutto il tempo.
- **Resi.** Entrano fisicamente in MC2 nello stato *da controllare* e mantengono il proprietario originario. Tornano in vendita solo dopo il controllo.
- **Trasferimenti in tre passaggi.** Il proprietario non cambia; il cambio di proprietà è un'operazione separata. Ogni passaggio è una conferma dell'operatore e crea il passaggio corrispondente su Shopify:
  1. *Preparato*: le paia vengono messe da parte nel magazzino di partenza e non sono più in vendita. Su Shopify nasce un trasferimento "pronto da spedire".
  2. *Spedito*, con corriere e tracking facoltativi: le paia lasciano la partenza e risultano in arrivo a destinazione. Su Shopify nasce una spedizione "in transito".
  3. *Ricevuto*: per ogni riga, paia accettate (entrano nella giacenza) e rifiutate (mancanti o inutilizzabili: non entrano). Si può ricevere in più volte. Su Shopify la spedizione viene ricevuta come ACCEPTED/REJECTED.

  Un trasferimento preparato e non ancora spedito si può annullare: le paia tornano libere, anche su Shopify. Il consolidamento di un ordine resta immediato, con le paia sempre impegnate per l'ordine.
- **Sincronizzazione Shopify bidirezionale.**
  - Operazioni tracciate con `changeFromQuantity` e chiave `@idempotent`.
  - Webhook salvati e deduplicati.
  - Riconciliazione notturna in sola lettura.
  - Conflitti segnalati all'operatore.

## Spedizioni: solo da Inventoros

- **Evasione su Shopify.** Quando una spedizione di un ordine Shopify parte da Inventoros, l'ordine viene evaso anche su Shopify, con corriere e tracking, dalla location del magazzino da cui è partita.
- **Coda e tentativi.** L'evasione passa per la stessa coda delle giacenze: viene ritentata, se ne vede lo stato, e un errore compare tra le anomalie con il pulsante "Riprova".
- **Mail al cliente.** Shopify non manda la mail al cliente; la manda Inventoros, se la spedizione lo prevede.
- **Ritorno da Shopify.** L'evasione che Shopify rimanda indietro viene riconosciuta: non crea una seconda spedizione e non scarica di nuovo.
- **Indipendente dalla modalità di giacenza.** Funziona anche prima di attivare i due magazzini, quindi già con la produzione di oggi.
- **Scope richiesto.** Serve lo scope `write_merchant_managed_fulfillment_orders`. Senza, l'evasione fallisce, viene segnalata e si può ritentare dopo aver aggiunto lo scope.
- **Disattivazione.** Si spegne con `push_fulfillments = false` sulla connessione.
- **Evasioni fatte su Shopify.** Un'evasione fatta comunque su Shopify diventa una spedizione in Inventoros, come prima.

## Schermate

- **Magazzino → Giacenze per magazzino.** La griglia per variante con:
  - filtri per magazzino, proprietario, anomalie e "da controllare";
  - rettifica (movimento o conteggio), trasferimento (prepara, spedisci, ricevi, annulla), controllo resi, cambio stato, assegnazione proprietà (anche su più varianti);
  - storico, impegni aperti, anomalie di sincronizzazione con le relative decisioni;
  - trasferimenti recenti con lo stato su Shopify.
- **Ordine → Giacenza per questo ordine.** Dove sono impegnate le paia, cosa portare in MC2 e il pulsante "Porta in MC2".

## Prerequisiti

1. **Scope dell'app Shopify.** Nel Dev Dashboard aggiungi alla versione dell'app i seguenti scope (vedi `plugins/shopify/README.md`), rilascia la versione e approvala nel negozio:
   - `write_merchant_managed_fulfillment_orders`;
   - `read_inventory_transfers` e `write_inventory_transfers`;
   - `read_inventory_shipments`, `write_inventory_shipments` e `write_inventory_shipments_received_items`.
2. **Impostazioni Shopify da verificare a mano.** Non sono leggibili via API.
   - *Impostazioni → Spedizione e consegna → Instradamento degli ordini*: priorità delle location con MC2 Lab Srl prima, e la regola che riduce le spedizioni separate.
   - Entrambe le location devono evadere gli ordini online, così il cliente può comprare tutte le paia disponibili.
   - Oggi la location MC2 Lab Srl risulta con `shipsInventory = false`. Verifica che possa ricevere e spedire i trasferimenti che servono.
3. **Cron ogni minuto.** È già impostato su SiteGround con `/usr/local/bin/php`. Lo scheduler lavora la coda (`queue:work --stop-when-empty --max-time=50`) e invia l'outbox.

## Procedura

Tutti i comandi vanno lanciati sul server, in `~/www/saxone.eu/inventoros`, con `php` (il binario `/usr/local/bin/php`).

1. **Backup.**
   - Database:
     ```
     mysqldump --single-transaction --no-tablespaces <db> | gzip > ~/backups/inventoros/pre-doppio-magazzino.sql.gz
     ```
     In alternativa usa lo script di deploy del branch `deploy/ssh-script`, che fa il backup da solo.
   - Copia anche `storage/` e `.env`.
2. **Deploy del codice.** Merge su `main` dopo la revisione. Poi:
   ```
   php artisan migrate --force
   php artisan optimize
   php artisan queue:restart
   ```
   Le nuove tabelle del plugin vengono create alla prima richiesta, quando cambia la versione del plugin.
3. **Job precedenti.**
   ```
   php artisan shopify:purge-legacy-stock-jobs
   ```
   Rimuove dalla coda le vecchie spinte del totale, che potrebbero riversare le quantità aggregate sulla prima location. Dal momento della configurazione, il codice 2.0 rifiuta comunque quelle spinte.
4. **Webhook.** Dalla pagina Shopify premi "Registra i webhook". Si aggiunge `inventory_levels/update`.
5. **Prova a secco (sola lettura).**
   ```
   php artisan shopify:stock-setup
   ```
   Il percorso del CSV di confronto viene stampato a video (in `storage/app/shopify/`).
6. **Configurazione.**
   ```
   php artisan shopify:stock-setup --apply
   ```
   Il comando:
   - crea o riusa i due magazzini e i proprietari MC2/Saxone;
   - mappa le location Shopify sui magazzini;
   - prende i saldi per variante e location da Shopify;
   - impegna gli ordini aperti dove Shopify li ha assegnati, senza un secondo scarico;
   - imposta la proprietà a "da attribuire".

   La sincronizzazione resta **in pausa**. Gli eventi Shopify arrivati nel frattempo vengono registrati e lavorati subito dopo.

   Si può rilanciare: riallinea a Shopify senza duplicare.
7. **Controllo del confronto.** Apri il CSV e verifica, per variante e location, Shopify contro Inventoros:
   - `match`: tutto allineato.
   - `committed_differs`: Shopify tiene impegnate paia per ordini che Inventoros non ha, ad esempio righe senza SKU.
   - `not_in_inventoros`: articoli Shopify senza variante in Inventoros.
   - Disponibile negativo: più impegnato che fisico.
8. **Instradamento degli ordini aperti.**
   ```
   php artisan shopify:routing-check
   ```
   Per ogni ordine aperto dice se Shopify lo ha assegnato a Via Maragliano pur avendo le paia in MC2, o se è diviso fra le due sedi. **Non sposta niente**: le paia da consolidare si gestiscono dalla pagina dell'ordine, quando sono fisicamente arrivate.
9. **Attivazione.** Solo dopo l'approvazione del confronto.
   ```
   php artisan shopify:stock-activate
   ```
   Invia a Shopify le operazioni fatte in Inventoros dopo la configurazione e avvia un controllo di tutte le location.
10. **Prova end-to-end.** Su una variante di prova:
    - rettifica +1 in MC2: su Shopify deve comparire una volta sola e lo stato diventa "Sincronizzato";
    - modifica la stessa variante su Shopify: Inventoros si aggiorna e non rimanda niente;
    - trasferimento di 1 paio da Via Maragliano a MC2: dopo ogni passaggio (prepara, spedisci, ricevi) controlla su Shopify il trasferimento e le quantità impegnate, in arrivo e disponibili.
11. **#T0001.**
    ```
    php artisan shopify:transfer-plan T0001
    ```
    Mostra lo stato reale del trasferimento e il piano:
    - **bozza**: si cambia la destinazione;
    - **pronto da spedire**: annulla, duplica, destinazione MC2;
    - **in corso**: ricezione come "rifiutato" a Via Maragliano e carico in MC2 con riferimento al trasferimento;
    - **ricevuto**: trasferimento in tre passaggi verso MC2.

    Nessuna modifica senza `php artisan shopify:transfer-approve <id>`.
12. **Proprietà.** Da *Giacenze per magazzino*: filtro "Proprietà da attribuire", selezione delle varianti, "Assegna proprietà". Le quantità sono proposte e modificabili riga per riga.

## Rollback

- **Prima di `stock-activate`.** Shopify non è stato toccato. Puoi:
  - ripristinare il backup del database e la release precedente;
  - in alternativa, spegnere solo la modalità per magazzino (chiave `variant_warehouse_stock.enabled` in `organizations.settings`) e rimettere `stock_sync_mode = legacy`.
  - **Attenzione:** in modalità legacy il plugin torna a spingere i totali. Fallo solo con la mappa a una location.
- **Dopo l'attivazione.**
  ```
  php artisan shopify:stock-pause
  ```
  Ferma subito gli invii; i webhook restano registrati. Ogni modifica fatta su Shopify è in `shopify_stock_operations`, con l'id del gruppo di rettifiche, e si può ripercorrere. `shopify:stock-activate` riprende e invia quanto accumulato.
- **Storico.** Nessuno storico viene cancellato. Le righe `stock_adjustments` restano, e le nuove operazioni sono in `variant_stock_operations` e `variant_stock_movements`.

## Limiti noti

- **Prodotti semplici e conteggi.** I prodotti senza varianti restano sul sistema precedente (ubicazioni). I conteggi inventariali (`stock_audits`) non gestiscono ancora le varianti per magazzino: per le scarpe usa il conteggio dalla nuova schermata.
- **Stato "safety_stock" di Shopify.** Non viene importato come stato separato.
- **Interfaccia.** La pagina impostazioni del plugin non mostra ancora lo stato dell'outbox. Stati e anomalie sono nella schermata *Giacenze per magazzino*.
- **Mutazioni non provate sul negozio reale.** `inventoryTransferCreateAsReadyToShip`, `inventoryShipmentCreateInTransit`, `inventoryTransferCancel`, `inventoryTransferEdit`, `inventoryShipmentReceive` e `fulfillmentOrderMove` sono validate sullo schema e provate con un fake di Shopify, non sul negozio reale. Per questo #T0001 richiede l'approvazione esplicita, e il primo trasferimento va fatto su una variante di prova (passaggio 10).
- **Annullamento senza chiave.** `inventoryTransferCancel` non accetta una chiave di idempotenza. Se dopo un errore di rete il trasferimento risulta già annullato su Shopify, il nuovo tentativo lo considera riuscito.
