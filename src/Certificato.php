<?php

namespace CertificatiINPS;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use ReflectionClass;

/**
 * Classe per cercare e gestire il certificato
 */
class Certificato {
    const URL_BASE              =   'https://serviziweb2.inps.it/AttestatiCittadinoWeb/';
    const URL_CAPTCHA           =   Certificato::URL_BASE . 'MyCaptcha.png';
    const URL_DATI_CERTIFICATO  =   Certificato::URL_BASE . 'attivaMain?cmd=immessoCertificato';
    const URL_PDF               =   Certificato::URL_BASE . 'attivaMain?cmd=stampa';

    const SESSION_COOKIE        =   'JSESSIONID_CERTIFICATI';
    const SESSION_COOKIE_DOMAIN =   'serviziweb2.inps.it';

    const ERRORE_INPS_KO        =   'Servizi INPS non disponibili';
    const ERRORE_INPUT          =   'I dati inseriti non sono validi!';
    const ERRORE_INPS_VARIATO   =   'I servizi INPS non rispondono come ci si aspetta, è possibile che siano temporaneamente offline o che abbiano cambiato la logica di autenticazione.';
    const ERRORE_RISPOSTA       =   'La risposta con i dati del certificato non è valida';

    const REGEX_CAMPI           =   "/<td[^>]*>(.*?)<\/td[^>]*>/i";

    const TIPO_VISITA_AMBULATORIALE     =   'Ambulatoriale';
    const TIPO_VISITA_DOMICILIARE       =   'Domiciliare';
    const TIPO_VISITA_PRONTO_SOCCORSO   =   'Pronto Soccorso';

    const TIPO_CERTIFICATO_INIZIO           =   'Inizio';
    const TIPO_CERTIFICATO_CONTINUAZIONE    =   'Continuazione';
    const TIPO_CERTIFICATO_RICADUTA         =   'Ricaduta';

    const AGEVOLAZIONE_TERAPIA_SALVAVITA        =   'Patologia grave che richiede terapia salvavita';
    const AGEVOLAZIONE_CAUSA_DI_SERVIZIO        =   'Malattia per la quale è stata riconosciuta la causa di servizio';
    const AGEVOLAZIONE_INVALIDITA_RICONOSCIUTA  =   'Stato patologico sotteso o connesso alla situazione di invalidità riconosciuta';

    protected ?string $codice_fiscale = null;
    protected ?string $cognome = null;
    protected ?string $nome = null;
    protected ?string $data_nascita = null;
    protected ?string $sesso = null;
    protected ?string $provincia_nascita = null;
    protected ?string $comune_nascita = null;
    protected ?string $via_residenza = null;
    protected ?string $civico_residenza = null;
    protected ?string $provincia_residenza = null;
    protected ?string $comune_residenza = null;
    protected ?string $cap_residenza = null;
    protected ?string $reperibilita_a_cura_di = null;
    protected ?string $reperibilita_indirizzo = null;
    protected ?string $reperibilita_civico = null;
    protected ?string $reperibilita_provincia = null;
    protected ?string $reperibilita_comune = null;
    protected ?string $reperibilita_cap = null;
    protected ?string $ammalato_dal = null;
    protected ?string $data_rilascio_certificato = null;
    protected ?string $ammalato_al = null;
    protected ?string $tipo_visita = null;
    protected ?string $tipo_certificato = null;
    protected bool   $inizio_giorno_successivo_visita = false;
    protected bool   $causa_evento_traumatico = false;
    protected ?string $agevolazione = null;
    protected ?string $cognome_medico = null;
    protected ?string $nome_medico = null;
    protected ?string $ruolo_medico = null;
    protected ?string $codice_regione_medico = null;
    protected ?string $codice_asl_medico = null;
    protected ?string $codice_struttura = null;

    /** @var ?string $protocollo Numero di protocollo del certificato */
    protected ?string $protocollo;
    
    /**
     * Base64 del pdf del certificato se questo è stato creato con pdf a true
     *
     * @var ?string
     */
    protected ?string $pdf;

    /**
     * Contenuto della pagina HTML del certificato (da decodificare)
     *
     * @var ?string
     */
    protected ?string $html_certificato;

    /**
     * Regole di post-processing degli input per settare le proprietà
     *
     * @return array[
     *  {
     *      property:string,
     *      post_processing:function,
     *      only_truthy:bool
     *  }
     * ] array con la mappatura della proprietà corrispondente all'indice del match
     * e la funzione da eseguire, se only_truthy è true sostituisce il valore nella
     * proprietà solo se il valore dopo il post_processing ha un valore truthy
     */
    protected static function postProcessingRules():array{
        $clean = fn($in)=>trim($in);
        $input = function($in){
            $matches = [];

            if(!preg_match('/value="([^"]+)"/i', $in, $matches)){
                return "";
            }

            return trim($matches[1]);
        };

        $bool = fn($in)=>(bool) preg_match('/checked/i',$in);

        return [
            5   =>  [
                'property'          =>  'codice_fiscale',
                'post_processing'   =>  $clean,
            ],
            7   =>  [
                'property'          =>  'cognome',
                'post_processing'   =>  $clean,
            ],
            9   =>  [
                'property'          =>  'nome',
                'post_processing'   =>  $clean,
            ],
            11  =>  [
                'property'          =>  'data_nascita',
                'post_processing'   =>  $clean,
            ],
            13  =>  [
                'property'          =>  'sesso',
                'post_processing'   =>  $clean,
            ],
            15  =>  [
                'property'          =>  'provincia_nascita',
                'post_processing'   =>  $clean,
            ],
            17  =>  [
                'property'          =>  'comune_nascita',
                'post_processing'   =>  $clean,
            ],
            19  =>  [
                'property'          =>  'via_residenza',
                'post_processing'   =>  $clean,
            ],
            21  =>  [
                'property'          =>  'civico_residenza',
                'post_processing'   =>  $clean,
            ],
            23  =>  [
                'property'          =>  'provincia_residenza',
                'post_processing'   =>  $clean,
            ],
            25  =>  [
                'property'          =>  'comune_residenza',
                'post_processing'   =>  $clean,
            ],
            27  =>  [
                'property'          =>  'cap_residenza',
                'post_processing'   =>  $clean,
            ],
            29  =>  [
                'property'          =>  'reperibilita_a_cura_di',
                'post_processing'   =>  $clean,
            ],
            31  =>  [
                'property'          =>  'reperibilita_indirizzo',
                'post_processing'   =>  $clean,
            ],
            33  =>  [
                'property'          =>  'reperibilita_civico',
                'post_processing'   =>  $clean,
            ],
            35  =>  [
                'property'          =>  'reperibilita_provincia',
                'post_processing'   =>  $clean,
            ],
            37  =>  [
                'property'          =>  'reperibilita_comune',
                'post_processing'   =>  $clean,
            ],
            39  =>  [
                'property'          =>  'reperibilita_cap',
                'post_processing'   =>  $clean,
            ],
            41  =>  [
                'property'          =>  'ammalato_dal',
                'post_processing'   =>  $input,
            ],
            43  =>  [
                'property'          =>  'data_rilascio_certificato',
                'post_processing'   =>  $input,
            ],
            45  =>  [
                'property'          =>  'ammalato_al',
                'post_processing'   =>  $input,
            ],
            47  =>  [
                'property'          =>  'tipo_visita',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_VISITA_AMBULATORIALE;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            49  =>  [
                'property'          =>  'tipo_visita',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_VISITA_DOMICILIARE;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            51  =>  [
                'property'          =>  'tipo_visita',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_VISITA_PRONTO_SOCCORSO;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            54  =>  [
                'property'          =>  'tipo_certificato',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_CERTIFICATO_INIZIO;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            56  =>  [
                'property'          =>  'tipo_certificato',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_CERTIFICATO_INIZIO;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            58  =>  [
                'property'          =>  'tipo_certificato',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::TIPO_CERTIFICATO_INIZIO;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            61  =>  [
                'property'          =>  'inizio_giorno_successivo_visita',
                'post_processing'   =>  $bool,
            ],
            63  =>  [
                'property'          =>  'causa_evento_traumatico',
                'post_processing'   =>  $bool,
            ],
            66  =>  [
                'property'          =>  'agevolazione',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::AGEVOLAZIONE_TERAPIA_SALVAVITA;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            68  =>  [
                'property'          =>  'agevolazione',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::AGEVOLAZIONE_CAUSA_DI_SERVIZIO;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            68  =>  [
                'property'          =>  'agevolazione',
                'post_processing'   =>  function(string $in){
                    if(preg_match('/checked/i', $in)){
                        return Certificato::AGEVOLAZIONE_INVALIDITA_RICONOSCIUTA;
                    }

                    return false;
                },
                'only_truthy'        =>  true
            ],
            72  =>  [
                'property'          =>  'cognome_medico',
                'post_processing'   =>  $input,
            ],
            74  =>  [
                'property'          =>  'nome_medico',
                'post_processing'   =>  $input,
            ],
            76  =>  [
                'property'          =>  'ruolo_medico',
                'post_processing'   =>  $input,
            ],
            78  =>  [
                'property'          =>  'codice_regione_medico',
                'post_processing'   =>  $input,
            ],
            80  =>  [
                'property'          =>  'codice_asl_medico',
                'post_processing'   =>  $input,
            ],
            82  =>  [
                'property'          =>  'odice_struttura',
                'post_processing'   =>  $input,
            ],
        ];
    }

    /**
     * Recupera sessionId e base64 del captcha del sito INPS
     *
     * @return array{sessionId:string,captcha:string} Dati per richiedere il certificato
     * @throws Exception Problemi con il sito INPS
     */
    public static function getCaptcha():array{
        $response = [
            'sessionId' =>  Certificato::getJSessionId(),
            'captcha'   =>  null,
        ];

        $response['captcha'] = Certificato::getCaptchaReal($response['sessionId']);

        return $response;
    }

    /**
     * Recupera il cookie di sessione
     *
     * @return string|null cookie di sessione o null se non trovato
     * @throws Exception Servizi INPS non disponibili o risposta inattesa
     */
    protected static function getJSessionId():string{
        $client = new Client();

        $cookieJar = new CookieJar();
        
        $response = $client->request('GET', Certificato::URL_BASE,[
            'cookies' => $cookieJar,
        ]);

        if($response->getStatusCode() >= 400){
            throw new Exception(Certificato::ERRORE_INPS_KO, 500);
        }

        $cookie = $cookieJar->getCookieByName(Certificato::SESSION_COOKIE);

        if($cookie instanceof SetCookie){
            return $cookie->getValue();    
        }
        
        throw new Exception(Certificato::ERRORE_INPS_VARIATO, 500);
    }

    /**
     * Restituisce il base64 del codice captcha da utilizzare per avere le informazioni del certificato
     *
     * @param string $sessionId cookie di sessione del sito INPS
     * @return string base64 del codice captcha
     * @throws Exception Il sito INPS non risponde correttamente
     */
    protected static function getCaptchaReal(string $sessionId):string{
        $client = new Client();
        
        $response = $client->request('GET', Certificato::URL_CAPTCHA,[
            'cookies' => CookieJar::fromArray(
                [
                    Certificato::SESSION_COOKIE =>  $sessionId,
                ],
                Certificato::SESSION_COOKIE_DOMAIN
            ),
        ]);

        if($response->getStatusCode() >= 400){
            throw new Exception(Certificato::ERRORE_INPS_KO, 500);
        }

        $body = $response->getBody()->getContents();

        return base64_encode($body);
    }

    /**
     * Inizializza l'oggetto del certificato
     *
     * @param string $sessionId Id sessione INPS
     * @param string $captcha Soluzione del captcha INPS
     * @param string $codice_fiscale Codice fiscale della persona
     * @param string $n_protocollo Numero di protocollo dipendente
     * @param boolean $pdf Se true scarica il pdf e lo rende disponibile in base64
     * @throws Exception Problemi con il sito INPS
     */
    function __construct(string $sessionId, string $captcha, string $codice_fiscale, string $n_protocollo, bool $pdf = false)
    {
        $this->downloadData($sessionId, $captcha, $codice_fiscale, $n_protocollo, $pdf);
        $this->estraiDatiCertificato();
        $this->protocollo = $n_protocollo;
    }

    /**
     * Recupera le informazioni del certificato e il pdf se richiesto
     *
     * @param string $sessionId Id sessione INPS
     * @param string $captcha Soluzione del captcha INPS
     * @param string $codice_fiscale Codice fiscale della persona
     * @param string $n_protocollo Numero di protocollo dipendente
     * @param boolean $pdf Se true scarica il pdf e lo rende disponibile in base64
     * @return void
     * @throws Exception Problemi con il sito INPS
     */
    protected function downloadData(string $sessionId, string $captcha, string $codice_fiscale, string $n_protocollo, bool $pdf = false){

        $client = new Client();
        
        $response = $client->request('POST', Certificato::URL_DATI_CERTIFICATO,[
            'cookies' => CookieJar::fromArray(
                [
                    Certificato::SESSION_COOKIE =>  $sessionId,
                ],
                Certificato::SESSION_COOKIE_DOMAIN
            ),

            'form_params' => [
                'codicefisc'    =>  $codice_fiscale,
                'numerocert'    =>  $n_protocollo,
                'controllo'     =>  $captcha,
            ],

        ]);

        if($response->getStatusCode() >= 400){
            throw new Exception(Certificato::ERRORE_INPS_KO, 500);
        }

        $this->html_certificato = str_replace("\n", '', $response->getBody()->getContents());

        if(stripos($this->html_certificato,'Codice di controllo')){
            throw new Exception(Certificato::ERRORE_INPUT,400);
        }

        if( $pdf ){
            $this->downloadPdf($sessionId);
        }
    }

    /**
     * Scarica il pdf del certificato caricato in sessione
     *
     * @param string $sessionId sessione INPS
     * @return void
     * @throws Exception Problemi con il sito INPS
     */
    protected function downloadPdf(string $sessionId){
        $client = new Client();
        
        $response = $client->request('GET', Certificato::URL_PDF,[
            'cookies' => CookieJar::fromArray(
                [
                    Certificato::SESSION_COOKIE =>  $sessionId,
                ],
                Certificato::SESSION_COOKIE_DOMAIN
            ),
        ]);

        if($response->getStatusCode() >= 400){
            throw new Exception(Certificato::ERRORE_INPS_KO, 500);
        }

        $this->pdf = base64_encode($response->getBody()->getContents());
    }

    /**
     * Questo metodo effettua il parsing della stringa HTML del certificato scaricato per ottenere le proprietà dell'oggetto
     *
     * @return void
     * @throws Exception Nessun dato da elaborare
     */
    protected function estraiDatiCertificato(){
        $matches = [];
        
        $chk = preg_match_all(Certificato::REGEX_CAMPI, $this->html_certificato, $matches);

        if(!$chk){
            throw new Exception(Certificato::ERRORE_RISPOSTA, 500);
        }

        $matches = $matches[1];

        /** @var int $i Idice del match */
        /** @var array{
         *  property:string,            // Nome proprietà
         *  post_processing:function,   // Funzione per formattare il campo
         *  only_truthy:bool,           // Indica se il campo va sovrascritto solo se il suo valore è truthy (default false)
         * } $regole Regole per la formattazione e il popolamento della proprietà */
        foreach(Certificato::postProcessingRules() as $i => $regole){
            $valore = $matches[$i];

            if(is_callable($regole['post_processing'])){
                $valore = $regole['post_processing']($valore);
            }

            if($regole['only_truthy'] ?? false and !$valore){
                continue;
            }
            
            $this->{$regole['property']} = $valore;
        }
    }

    /**
     * Magic method per il recupero di una proprietà
     *
     * @param string $property
     * @return mixed
     */
    public function __get(string $property) {

        if (property_exists($this, $property)) {
            return $this->{$property};
        }

        throw new Exception('Proprietà inesistente.');
    }

    /**
     * Converte l'oggetto in array
     *
     * @return array
     */
    public function toArray(): array {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();

        $array = [];
        foreach ($properties as $property) {
            $array[$property->getName()] = $this->{$property->getName()};
        }

        unset($array['html_certificato']);

        return $array;
    }

    /**
     * Numero di protocollo del certificato
     *
     * @return string
     */
    public function __toString()
    {
        return $this->protocollo;
    }
}

/* Recupero JSESSIONID_CERTIFICATI
    URL: https://serviziweb2.inps.it/AttestatiCittadinoWeb/
    METHOD: GET
*/

/* Recupero Immagine captcha
    URL: https://serviziweb2.inps.it/AttestatiCittadinoWeb/MyCaptcha.png
    METHOD: GET
*/

/* Recupero dati certificato:
    URL: https://serviziweb2.inps.it/AttestatiCittadinoWeb/attivaMain?cmd=immessoCertificato
    BODY:
        codicefisc=RSLCSM94A12L049V
        numerocert=389564456
        controllo=farc4
    METHOD: POST,
    COOKIES:
        JSESSIONID_CERTIFICATI=0000r7IPypVzqgr95i1dM66C3Yd:16j983198
*/