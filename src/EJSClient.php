<?php

namespace Ekojs\Library;

/**
 * Api Client Library
 * 
 * @category Library
 * @version 1.0.0
 * @author Eko Junaidi Salam <eko.junaidi.salam@gmail.com>
 * @license AGPL v3
 */

use GuzzleHttp\Client;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise;
use \ReCaptcha\ReCaptcha;

class EJSClient {
	protected $numRetries = 3;
	protected $timeout = 30;
	protected $customKeyHeader = 'x-api-key';
	protected $token;

    public $client;
    public static $instance;


    /**
     * Assign the CodeIgniter super-object and 
     */
    public function __construct() {
        $handlerStack = HandlerStack::create(new CurlHandler());
        $handlerStack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $this->client = new Client([
            'handler' => $handlerStack,
            'base_uri' => API_HOST,
            'http_errors' => false,
            'timeout'  => $this->timeout,
	    'verify' => true,
            'debug' => false
        ]);
        self::$instance = $this;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Based on issue https://github.com/guzzle/guzzle/issues/1806
    public function retryDecider() {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ) {
            // Limit the number of retries to 3
            if ($retries >= $this->numRetries) {
                return false;
            }

            // Retry connection exceptions
            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response) {
                // Retry on server errors
                if ($response->getStatusCode() >= 500 ) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * delay 1s 2s 3s 4s 5s
     *
     * @return Closure
     */
    public function retryDelay() {
        return function ($numberOfRetries) {
            return 1000 * $numberOfRetries;
        };
    }

    /**
     * Async Request Function
     * 
     * Untuk melakukan pengiriman data secara concurrent menggunakan promises dan asynchronous requests terbatas.
     * 
     * * array                                            array   Root data harus berupa array
     *      array                                       array   Child data harus berupa array bisa single, bisa multiple array
     *          ['method']                              string  Berupa method HEAD, GET, PUT, POST, PATCH, DELETE, OPTIONS
     *          ['url']                                 string  Berupa Full url endpoint atau part dari endpoint ex: '/oauth/token'
     *          ['headers']                             array   Headers harus bertipe data array
     *              array
     *                  ['Content-Type']                string  Menambahkan header Content-Type disetiap request yg dibuat
     *                  ['Content-Encoding]             string  Menambahkan header Content-Encoding disetiap request yg dibuat
     *                  [$this->customKeyHeader]                   string  Menambahkan header customKeyHeader disetiap request yg dibuat
     *                  ...
     *          ['body']                                string|null|resource|StreamInterface    Request body bila data array maka gunakan http_build_query($data), resource atau implement StreamInterface
     *          ['form_params']                         array   Untuk melakukan pengiriman data pada form fields
     *          ['multipart']                           array   Untuk melakukan pengiriman data berupa file dengan header multipart/form-data
     * 
     * @param array     $params (lihat diatas)          Struktur array untuk mengirimkan request
     * 
     * @return array    $results                        Berisi array hasil seluruh request
     * 
     * array['key']             array                               Berisi seluruh response yang diterima sesuai key dari promises
     *      array
     *          ['state']       string                              Berisi state 'fulfilled' atau 'rejected'
     *          ['value']       GuzzleHttp\Psr7\Response Object     Berisi object response dari hasil request
     */
    public function asyncRequest($params){
        if(empty($params)) die('Parameter harus berupa array.');

        if(empty($params[0]['method']) || empty($params[0]['url'])) die('Parameter minimal berisi 1 data dengan parameter method dan url.');

        $promises = function($params){
            foreach($params as $v){
                yield $this->client->requestAsync($v['method'],$v['url'],array(
                    'headers' => (!empty($v['headers'])?$v['headers']:null),
                    'body' => (!empty($v['body'])?$v['body']:null),
                    'json' => (!empty($v['json'])?$v['json']:null),
                    'multipart' => (!empty($v['multipart'])?$v['multipart']:null),
                    'form_params' => (!empty($v['form_params'])?$v['form_params']:null),
                    'sink' => (!empty($v['sink'])?$v['sink']:null)
                ));
            }
        };
        $results = \GuzzleHttp\Promise\unwrap($promises($params));
        $results = \GuzzleHttp\Promise\settle($promises($params))->wait();
        
        return $results;
    }

    /**
     * Bulk Request Function
     * 
     * Untuk melakukan pengiriman data banyak yang tidak terhitung.
     * 
     * array                                            array   Root data harus berupa array
     *      array                                       array   Child data harus berupa array bisa single, bisa multiple array
     *          ['method']                              string  Berupa method HEAD, GET, PUT, POST, PATCH, DELETE, OPTIONS
     *          ['url']                                 string  Berupa Full url endpoint atau part dari endpoint ex: '/oauth/token'
     *          ['headers']                             array   Headers harus bertipe data array
     *              array
     *                  ['Content-Type']                string  Menambahkan header Content-Type disetiap request yg dibuat
     *                  ['Content-Encoding]             string  Menambahkan header Content-Encoding disetiap request yg dibuat
     *                  [$this->customKeyHeader]                   string  Menambahkan header customKeyHeader disetiap request yg dibuat
     *                  ...
     *          ['body']                                string|null|resource|StreamInterface    Request body bila data array maka gunakan http_build_query($data), resource atau implement StreamInterface
     * 
     * @param array     $params (lihat diatas)          Struktur array untuk mengirimkan request
     * @param array     &$res (variable by reference)   Hasil seluruh response dari request yang telah diberikan
     * @param integer   $concurrency                    Mengatur batas maksimal concurency tiap pengiriman request
     * @param function  $fulfillCallback                (callable) Function untuk melakukan invoke ketika request selesai
     * @param function  $rejectCallback                 (callable) Function untuk melakukan invoke ketika request ditolak
     * 
     */
    public function bulkRequest($params,&$res,$concurrency=10,$fulfillCallback=null,$rejectCallback=null){
        if(empty($params)) die('Parameter harus berupa array.');
        if(empty($fulfillCallback)){
            $fulfillCallback = function(ResponseInterface $response, $index) use (&$res) {
                array_push($res,$response);
            };
        }

        if(empty($rejectCallback)){
            $rejectCallback = function($reason, $index) use (&$res) {
                array_push($res,$reason);
            };
        }
        
        $requests = function ($params) {
            for ($i = 0; $i < count($params); $i++) {
                yield new Request($params[$i]['method'],$params[$i]['url'],$params[$i]['headers'],$params[$i]['body']);
            }
        };

        (new Pool(
            $this->client,
            $requests($params),[
                'concurrency' => $concurrency,
                'fulfilled' => $fulfillCallback,
                'rejected' => $rejectCallback
            ]
        ))->promise()->wait();
    }

    /**
     * Verify Request Function
     * 
     * Digunakan untuk melakukan verifikasi token ke webservices
     * 
     * @param   string  $url        Alamat url endpoint webservice
     * @param   string  $token      Access token oauth untuk otentikasi
     * @return  boolean $status     Berisi value FALSE atau TRUE
     */
    public function verifyRequest($url){
        $params = array(
            array(
                'method' => 'GET',
                'url' => $url,
                'headers' => array($this->customKeyHeader => $this->token)
            )
        );
        $res = $this->asyncRequest($params)[0];
        $status = false;
        if(200 == $res['value']->getStatusCode()){
            $status = json_decode($res['value']->getBody(),true)['status'];
        }
        return $status;
    }

    /**
     * Get Data Function
     * 
     * Fungsi yang digunakan untuk mengambil data secara async sekali.
     * 
     * @param   string      $url        Alamat url endpoint webservice
     * @param   string      $token      Access token oauth untuk otentikasi
     * @param   array       $fields     Berisi array data yang akan dikirimkan sesuaikan parameter OPTIONS pada webservices
     * 
     * @return  array       $results    Berisi response array terkait request
     */
    public function getData($url,$fields=null){
        $params = array(
			array(
				'method' => 'GET',
				'url' => $url,
				'headers' => array($this->customKeyHeader  => $this->token)
				// 'form_params' => $fields
			),
		);
		$results = $this->asyncRequest($params);
		return (200 === $results[0]['value']->getStatusCode()?json_decode($results[0]['value']->getBody(),true):false);
    }
    
    public function saveData($url,$data){
        $params = array(
		'method' => 'POST',
            'url' => $url,
            'headers' => array($this->customKeyHeader  => $this->token),
            'json' => $data
        );

		$res = $this->client->post($params['url'],[
            'headers' => $params['headers'],
            'json' => $params['json']
        ]);

		if(200 === $res->getStatusCode()){
            return json_decode((string) $res->getBody(),true);
        }else{
            return false;
        }
    }

    public function checkGRecaptcha($domain,$gRecaptchaResponse, $remoteIp) {
        $recaptcha = new ReCaptcha(GSECRET_KEY);
        switch (ENVIRONMENT){
            case 'development':
                $resp = $recaptcha->verify($gRecaptchaResponse, $remoteIp);
                break;
                
                case 'testing':
                case 'production':
                $resp = $recaptcha->setExpectedHostname($domain)->verify($gRecaptchaResponse, $remoteIp);
                break;
                
                default:
                $resp = $recaptcha->verify($gRecaptchaResponse, $remoteIp);
            break;
        }

        return ($resp->isSuccess()?"Success":$resp->getErrorCodes());
    }
}
