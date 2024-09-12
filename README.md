# Certificati di malattia INPS
Questo progetto ha lo scopo di verificare la validità di un certificato di malattia inps ed ottenere tutte le info necessarie.
Per ottenere ciò viene utilizzata la pagina del sito INPS ([link](https://serviziweb2.inps.it/AttestatiCittadinoWeb/)).

Non essendoci delle API vere e proprie questo progetto usa normali chiamate HTTP al sito del link precedente e effettua un analisi del body della risposta per estrarre i dati del certificato.

## Come utilizzare il pacchetto
Questo piccolo tutorial corredato da esempi pratici serve ad aiutarvi nella realizzazione dei vostri algoritmi, nel concreto per poter recuperare i dati di uno o più certificati vi serviranno almeno 2 step:
1. Pagina di frontend che mostra il captcha e fa inserire all'utente la soluzione con eventuali codici fiscali e relativi numeri di protocollo delle malattie
1. Pagina di esito / visualizzazione dei risultati.

### STEP 1
Da un punto di vista pratico, il vostro front-end dovrà contattare una pagina di back-end che eseguirà il metodo statico getCaptcha che non farà altro che simulare una visita alla pagina del sito INPS recuperando il cookie di sessione e il base64 del png del captcha da risolvere.

```php
$pre_auth = Certificato::getCaptcha();
```

Nell'esempio precedente `$pre_auth` sarà un array associativo con 2 chiavi di tipo `?string`:
 - `sessionId`: cookie di sessione del sito INPS (servirà nel secondo step per validare il captcha)
 - `captcha`: Base 64 del png del captcha da risolvere, andrà mostrato all'utente per fargli inserire la soluzione, il modo più semplice è ad esempio attraverso un tag immagine:
    ```HTML
    <img src='data:image/png;base64, <!-- Base64 data -->' />
    ```

### STEP 2
Una volta raccolto il codice captcha e i codici fiscali con i relativi puk dei dipendenti, basterà creare un endpoint a cui inviare il tutto (incluso il sessionId ottenuto insieme al base64 del captcha). Immaginando quindi di avere un array con la struttura seguente potremmo scrivere qualcosa del tipo: 

```php
$array_esempio = [
    // array associativo dei codici dove la chiave è il codice fiscale del dipendente
    'BCDFGH94A22X123X' => [ 
        '123456789', // Ogni elemento della chiave associativa è un numero di protocollo da verificare
        '987654321',
    ], 
    //...
];

// session ID ricevuto con lo step 1
$sessionId = '0000AB9pIzL5kQL2Af5pnR1kG2n:16k7d6azz';

// codice captcha risolto dall'utente
$captcha = 'gr3df'; 

/**
 * flag per stabilire se il pdf deve essere scaricato o no, consiglio se non vi serve il pdf,
 * per velocizzare l'algoritmo e per non intasare i sistemi INPS non scaricatelo.
 */
$pdf = false;

$protocolli_validi = [];
$protocolli_non_validi = [];
$verifiche_fallite = [];

foreach ($array_esempio as $codice_fiscale => $protocolli){

    foreach( $protocolli as $protocollo){
        
        try{
            $certificato = new Certificato(
                $sessionId,
                $captcha,
                $codice_fiscale,
                $protocollo,
                $pdf
            );

            /**
             * Se arrivi qui il download del certificato è riuscito!
             * Ora puoi:
             *  - Accedere alle varie proprietà dell'oggetto,
             *  - Trasformare il certificato in un array associativo (se non ti interessa rimanere in un contesto oggetto)
             *  - Puoi usare l'oggetto come stringa (la sua rappresentazione stringa sarà il numero di protocollo)
             *  - Potrai accedere alla sua proprietà pdf (se nella costruzione dell'oggetto $pdf era true)
             */
            
            $protocolli_validi[] = $certificato;
            
        } catch ( Exception $e ){
            // Se ottenete un codice eccezione da 500 in su ci sono stati problemi sul sito INPS
            if( $e->getCode() >= 500 ){
                if(empty($verifiche_fallite[$codice_fiscale])){
                    $verifiche_fallite[$codice_fiscale] = [];
                }

                $verifiche_fallite[$codice_fiscale][] = $protocollo;
                continue;
            }

            // Se invece il codice è sotto i 500 allora uno degli input era errato (codice fiscale, protocollo, captcha o sessione)
            if(empty($protocolli_non_validi[$codice_fiscale])){
                $protocolli_non_validi[$codice_fiscale] = [];
            }

            $protocolli_non_validi[$codice_fiscale][] = $protocollo;
        } finally {
            /** 
             * NOTA BENE
             * 
             * so che può allungare di molto i tempi di risposta ma
             * fare troppe chiamate al sito inps può essere controproducente:
             *  - potrebbe bloccarvi vedendo il vostro come un tentativo di attacco
             *  - potrebbe voler adottare delle politiche di sicurezza più ferree
             *  - potrebbe aggiornare il sistema rendendo questo pacchetto completamente inutilizzabile
             * 
             * Per il bene di tutti i programmatori italiani, per i motivi sopra descritti,
             * non fare chiamate senza un delay di almeno 500 ms
             * 
             * Attenzione:
             * Ricordate che il return del try o del catch viene eseguito SEMPRE dopo il finally e
             * se ci sono dei return sia nel try (o nel catch) che nel finally, viene restituito sempre
             * e comunque quello del finally, tenetelo presente se usate il return in uno di questi tre punti.
             */
            sleep(1);
        }

    }

}

/**
 * A questo punto del codice avete i tre array popolati con i dati precedenti in base all'esito che le operazioni hanno avuto.
 * Potete quindi:
 *  - Implementare una logica di retry per gli elementi in $verifiche_fallite (sito INPS KO) ma vi consiglio di aspettare almeno 15/30 minuti
 *  - Segnalare all'utente quali numeri di protocollo non sono validi ($protocolli_non_validi), in modo che possa richiedere quelli corretti
 *  - Inserire le malattie nei vostri database sfruttando i dati scaricati del certificato ($protocolli_validi).
 */

```

> **Nota bene:** a volte il sito INPS va KO per giorni interi e non c'è soluzione a questo, in più questo approccio per importare/controllare i certificati non è ufficiale ma è l'unico modo (da me conosciuto) per effettuare un controllo in modo quasi del tutto automatizzato. In più non essendo un'API ufficiale ma una simulazione di un API costruita sulla base di una normale pagina di front-end in HTML, ad una minima modifica della struttura HTML della pagina del certificato o del sistema di autenticazione, questo sistema potrebbe funzionare in modo non corretto o non funzionare affatto. Il mio consiglio quindi è di utilizzarlo come un semplice strumento di verifica dei numeri di protocollo delle malattie e per il download e l'archiviazione dei pdf in modo automatizzato (qualora l'azienda lo preveda). Un altro modo più manuale ma sicuramente più sicuro e stabile è quello di accedere con le credenziali dell'azienda ai sistemi INPS per scaricare i file XLS / CSV dei report delle malattie dei dipendenti come strumento di importazione massiva nei vostri sistemi o di cotroverifica dei dati nel vostro gestionale.

## Container
Il progetto include i file docker per avere un ambiente "standard" per il testing e lo sviluppo.

A tal proposito potete creare e avviare il progetto con:
```bash
docker compose up --build -d
docker exec -it certificati-malattia-inps-certificati-inps-1 /bin/zsh
```