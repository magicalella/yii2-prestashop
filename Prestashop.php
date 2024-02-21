<?php

namespace magicalella\prestashop;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\Exception;

/**
 * Class Prestashop
 * Prestashop component
 * @package magicalella\prestashop
 *
 * @author Raffaella Lollini
 */
class Prestashop extends Component
{

    
    
    /** @var string Shop URL */
    protected $url;
    
    /** @var string Authentication key */
    protected $key;
    
    /** @var boolean is debug activated */
    protected $debug;
    
    /** @var string PS version */
    protected $version;
    
    /** @var string Minimal version of PrestaShop to use with this library */
    const psCompatibleVersionsMin = '1.4.0.0';
    /** @var string Maximal version of PrestaShop to use with this library */
    const psCompatibleVersionsMax = '8.1.1';
    const STATUS_SUCCESS = true;
    const STATUS_ERROR = false;
    
    /**
     * Prestashop constructor. Throw an exception when CURL is not installed/activated
     
     $prestashop = Yii::$app->prestashop;
     $prestashop->$url = $this->$url;
     $prestashop->key = $this->key;
     $prestashop->debug = $this->debug;
     
     * try
     * {
     *    $xml = $prestashop->get(['resource' => 'orders','limit'=> $off_set.','.$limit,'sort'=>'id_ASC']);
     * }
     * catch (PrestashopException $ex)
     * {
     *    echo 'Error : '.$ex->getMessage();
     * }
     
     *
     * @param string $url Root URL for the shop
     * @param string $key Authentication key
     * @param mixed $debug Debug mode Activated (true) or deactivated (false)
     *
     * @throws PrestashopException if curl is not loaded
     */
    function init()
    {
        if (!extension_loaded('curl')) {
            throw new PrestashopException(
                'Please activate the PHP extension \'curl\' to allow use of PrestaShop webservice library'
            );
        }
        if (!$this->url) {
            throw new InvalidConfigException('$url not set');
        }
        if (!$this->key) {
            throw new InvalidConfigException('$key not set');
        }
        $this->debug = ($this->debug)?$this->debug:true;
        $this->version = 'unknown';
        parent::init();
    }
    
    /**
     * Take the status code and throw an exception if the server didn't return 200 or 201 code
     * <p>Unique parameter must take : <br><br>
     * 'status_code' => Status code of an HTTP return<br>
     * 'response' => CURL response
     * </p>
     *
     * @param array $request Response elements of CURL request
     *
     * @throws PrestashopException if HTTP status code is not 200 or 201
     */
    protected function checkStatusCode($request)
    {
        switch ($request['status_code']) {
            case 200:
            case 201:
                break;
            case 204:
                $error_message = 'No content';
                break;
            case 400:
                $error_message = 'Bad Request';
                break;
            case 401:
                $error_message = 'Unauthorized';
                break;
            case 404:
                $error_message = 'Not Found';
                break;
            case 405:
                $error_message = 'Method Not Allowed';
                break;
            case 500:
                $error_message = 'Internal Server Error';
                break;
            default:
                throw new PrestashopException(
                    'This call to PrestaShop Web Services returned an unexpected HTTP status of:' . $request['status_code']
                );
        }
    
        if (!empty($error_message)) {
            $response = $this->parseXML($request['response']);
            $errors = $response->children()->children();
            if ($errors && count($errors) > 0) {
                foreach ($errors as $error) {
                    $error_message.= ' - (Code ' . $error->code . '): ' . $error->message;
                }
            }
            $error_label = 'This call to PrestaShop Web Services failed and returned an HTTP status of %d. That means: %s.';
            throw new PrestashopException(sprintf($error_label, $request['status_code'], $error_message));
        }
    }
    
    /**
     * Provides default parameters for the curl connection(s)
     * @return array Default parameters for curl connection(s)
     */
    protected function getCurlDefaultParams()
    {
        $defaultParams = array(
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->key . ':',
            CURLOPT_HTTPHEADER => array('Expect:'),
            //CURLOPT_SSL_VERIFYPEER => false, // reminder, in dev environment sometimes self-signed certificates are used
            //CURLOPT_CAINFO => "PATH2CAINFO", // ssl certificate chain checking
            //CURLOPT_CAPATH => "PATH2CAPATH",
        );
        return $defaultParams;
    }
    
    /**
     * Handles a CURL request to PrestaShop Webservice. Can throw exception.
     *
     * @param string $url Resource name
     * @param mixed $curl_params CURL parameters (sent to curl_set_opt)
     *
     * @return array status_code, response, header
     *
     * @throws PrestashopException
     */
    protected function executeRequest($url, $curl_params = array())
    {
        $defaultParams = $this->getCurlDefaultParams();
    
        $session = curl_init($url);
    
        $curl_options = array();
        foreach ($defaultParams as $defkey => $defval) {
            if (isset($curl_params[$defkey])) {
                $curl_options[$defkey] = $curl_params[$defkey];
            } else {
                $curl_options[$defkey] = $defaultParams[$defkey];
            }
        }
        foreach ($curl_params as $defkey => $defval) {
            if (!isset($curl_options[$defkey])) {
                $curl_options[$defkey] = $curl_params[$defkey];
            }
        }
    
        curl_setopt_array($session, $curl_options);
        $response = curl_exec($session);
    
        $index = strpos($response, "\r\n\r\n");
        if ($index === false && $curl_params[CURLOPT_CUSTOMREQUEST] != 'HEAD') {
            throw new PrestashopException('Bad HTTP response ' . $response . curl_error($session));
        }
    
        $header = substr($response, 0, $index);
        $body = substr($response, $index + 4);
    
        $headerArrayTmp = explode("\n", $header);
    
        $headerArray = array();
        foreach ($headerArrayTmp as &$headerItem) {
            $tmp = explode(':', $headerItem);
            $tmp = array_map('trim', $tmp);
            if (count($tmp) == 2) {
                $headerArray[$tmp[0]] = $tmp[1];
            }
        }
    
        if (array_key_exists('PSWS-Version', $headerArray)) {
            $this->version = $headerArray['PSWS-Version'];
            if (
                version_compare(Prestashop::psCompatibleVersionsMin, $headerArray['PSWS-Version']) == 1 ||
                version_compare(Prestashop::psCompatibleVersionsMax, $headerArray['PSWS-Version']) == -1
            ) {
                throw new PrestashopException(
                    'This library is not compatible with this version of PrestaShop. Please upgrade/downgrade this library'
                );
            }
        }
    
        if ($this->debug) {
            $this->printDebug('HTTP REQUEST HEADER', curl_getinfo($session, CURLINFO_HEADER_OUT));
            $this->printDebug('HTTP RESPONSE HEADER', $header);
        }
        $status_code = curl_getinfo($session, CURLINFO_HTTP_CODE);
        if ($status_code === 0) {
            throw new PrestashopException('CURL Error: ' . curl_error($session));
        }
        curl_close($session);
        if ($this->debug) {
            if ($curl_params[CURLOPT_CUSTOMREQUEST] == 'PUT' || $curl_params[CURLOPT_CUSTOMREQUEST] == 'POST') {
                $this->printDebug('XML SENT', urldecode($curl_params[CURLOPT_POSTFIELDS]));
            }
            if ($curl_params[CURLOPT_CUSTOMREQUEST] != 'DELETE' && $curl_params[CURLOPT_CUSTOMREQUEST] != 'HEAD') {
                $this->printDebug('RETURN HTTP BODY', $body);
            }
        }
        return array('status_code' => $status_code, 'response' => $body, 'header' => $header);
    }
    
    public function printDebug($title, $content)
    {
        if (php_sapi_name() == 'cli') {
            echo $title . PHP_EOL . $content;
        } else {
            echo '<div style="display:table;background:#CCC;font-size:8pt;padding:7px"><h6 style="font-size:9pt;margin:0">'
                . $title
                . '</h6><pre>'
                . htmlentities($content)
                . '</pre></div>';
        }
    }
    
    public function getVersion()
    {
        return $this->version;
    }
    
    /**
     * Load XML from string. Can throw exception
     *
     * @param string $response String from a CURL response
     *
     * @return SimpleXMLElement status_code, response
     * @throws PrestashopException
     */
    protected function parseXML($response)
    {
        if ($response != '') {
            libxml_clear_errors();
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string(trim($response), 'SimpleXMLElement', LIBXML_NOCDATA);
            if (libxml_get_errors()) {
                $msg = var_export(libxml_get_errors(), true);
                libxml_clear_errors();
                throw new PrestashopException('HTTP XML response is not parsable: ' . $msg);
            }
            return $xml;
        } else {
            throw new PrestashopException('HTTP response is empty');
        }
    }
    
    /**
     * Add (POST) a resource
     * <p>Unique parameter must take : <br><br>
     * 'resource' => Resource name<br>
     * 'postXml' => Full XML string to add resource<br><br>
     * Examples are given in the tutorial</p>
     *
     * @param array $options
     *
     * @return SimpleXMLElement status_code, response
     * @throws PrestashopException
     */
    public function add($options)
    {
        $xml = '';
    
        if (isset($options['resource'], $options['postXml']) || isset($options['url'], $options['postXml'])) {
            $url = (isset($options['resource']) ? $this->url . '/api/' . $options['resource'] : $options['url']);
            $xml = $options['postXml'];
            if (isset($options['id_shop'])) {
                $url .= '&id_shop=' . $options['id_shop'];
            }
            if (isset($options['id_group_shop'])) {
                $url .= '&id_group_shop=' . $options['id_group_shop'];
            }
        } else {
            throw new PrestashopException('Bad parameters given');
        }
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $xml));
    
        $this->checkStatusCode($request);
        return $this->parseXML($request['response']);
    }
    
    /**
     * Retrieve (GET) a resource
     * <p>Unique parameter must take : <br><br>
     * 'url' => Full URL for a GET request of Webservice (ex: https://mystore.com/api/customers/1/)<br>
     * OR<br>
     * 'resource' => Resource name,<br>
     * 'id' => ID of a resource you want to get<br><br>
     * </p>
     * <code>
     * <?php
     * require_once('./Prestashop.php');
     * try
     * {
     * $ws = new Prestashop('https://mystore.com/', 'ZQ88PRJX5VWQHCWE4EE7SQ7HPNX00RAJ', false);
     * $xml = $ws->get(array('resource' => 'orders', 'id' => 1));
     *    // Here in $xml, a SimpleXMLElement object you can parse
     * foreach ($xml->children()->children() as $attName => $attValue)
     *    echo $attName.' = '.$attValue.'<br />';
     * }
     * catch (PrestashopException $ex)
     * {
     *    echo 'Error : '.$ex->getMessage();
     * }
     * ?>
     * </code>
     *
     * @param array $options Array representing resource to get.
     *
     * @return SimpleXMLElement status_code, response
     * @throws PrestashopException
     */
    public function get($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource'])) {
            $url = $this->url . '/api/' . $options['resource'];
            $url_params = array();
            if (isset($options['id'])) {
                $url .= '/' . $options['id'];
            }
    
            $params = array('filter', 'display', 'sort', 'limit', 'id_shop', 'id_group_shop', 'schema', 'language', 'date', 'price');
            foreach ($params as $p) {
                foreach ($options as $k => $o) {
                    if (strpos($k, $p) !== false) {
                        $url_params[$k] = $options[$k];
                    }
                }
            }
            if (count($url_params) > 0) {
                $url .= '?' . http_build_query($url_params);
            }
        } else {
            throw new PrestashopException('Bad parameters given');
        }
    
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'GET'));
    
        $this->checkStatusCode($request);// check the response validity
    
        return $this->parseXML($request['response']);
    }
    
    /**
     * Head method (HEAD) a resource
     *
     * @param array $options Array representing resource for head request.
     *
     * @return SimpleXMLElement status_code, response
     * @throws PrestashopException
     */
    public function head($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource'])) {
            $url = $this->url . '/api/' . $options['resource'];
            $url_params = array();
            if (isset($options['id'])) {
                $url .= '/' . $options['id'];
            }
    
            $params = array('filter', 'display', 'sort', 'limit');
            foreach ($params as $p) {
                foreach ($options as $k => $o) {
                    if (strpos($k, $p) !== false) {
                        $url_params[$k] = $options[$k];
                    }
                }
            }
            if (count($url_params) > 0) {
                $url .= '?' . http_build_query($url_params);
            }
        } else {
            throw new PrestashopException('Bad parameters given');
        }
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'HEAD', CURLOPT_NOBODY => true));
        $this->checkStatusCode($request);// check the response validity
        return $request['header'];
    }
    
    /**
     * Edit (PUT) a resource
     * <p>Unique parameter must take : <br><br>
     * 'resource' => Resource name ,<br>
     * 'id' => ID of a resource you want to edit,<br>
     * 'putXml' => Modified XML string of a resource<br><br>
     * Examples are given in the tutorial</p>
     *
     * @param array $options Array representing resource to edit.
     *
     * @return SimpleXMLElement
     * @throws PrestashopException
     */
    public function edit($options)
    {
        $xml = '';
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif ((isset($options['resource'], $options['id']) || isset($options['url'])) && $options['putXml']) {
            $url = (isset($options['url']) ? $options['url'] :
                $this->url . '/api/' . $options['resource'] . '/' . $options['id']);
            $xml = $options['putXml'];
            if (isset($options['id_shop'])) {
                $url .= '&id_shop=' . $options['id_shop'];
            }
            if (isset($options['id_group_shop'])) {
                $url .= '&id_group_shop=' . $options['id_group_shop'];
            }
        } else {
            throw new PrestashopException('Bad parameters given');
        }
    
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $xml));
        $this->checkStatusCode($request);// check the response validity
        return $this->parseXML($request['response']);
    }
    
    /**
     * Delete (DELETE) a resource.
     * Unique parameter must take : <br><br>
     * 'resource' => Resource name<br>
     * 'id' => ID or array which contains IDs of a resource(s) you want to delete<br><br>
     * <code>
     * <?php
     * require_once('./Prestashop.php');
     * try
     * {
     * $ws = new Prestashop('https://mystore.com/', 'ZQ88PRJX5VWQHCWE4EE7SQ7HPNX00RAJ', false);
     * $xml = $ws->delete(array('resource' => 'orders', 'id' => 1));
     *    // Following code will not be executed if an exception is thrown.
     *    echo 'Successfully deleted.';
     * }
     * catch (PrestashopException $ex)
     * {
     *    echo 'Error : '.$ex->getMessage();
     * }
     * ?>
     * </code>
     *
     * @param array $options Array representing resource to delete.
     *
     * @return bool
     * @throws PrestashopException
     */
    public function delete($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource']) && isset($options['id'])) {
            $url = (is_array($options['id']))
                ? $this->url . '/api/' . $options['resource'] . '/?id=[' . implode(',', $options['id']) . ']'
                : $this->url . '/api/' . $options['resource'] . '/' . $options['id'];
        } else {
            throw new PrestashopException('Bad parameters given');
        }
    
        if (isset($options['id_shop'])) {
            $url .= '&id_shop=' . $options['id_shop'];
        }
        if (isset($options['id_group_shop'])) {
            $url .= '&id_group_shop=' . $options['id_group_shop'];
        }
    
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'DELETE'));
        $this->checkStatusCode($request);// check the response validity
        return true;
    }
    
    
    
    function normalizeSimpleXML($obj, &$result) {
        $data = $obj;
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $res = null;
                normalizeSimpleXML($value, $res);
                if (($key == '@attributes') && ($key)) {
                    $result = $res;
                } else {
                    $result[$key] = $res;
                }
            }
        } else {
            $result = $data;
        }
        return $result;
    }
    
    public function XML2JSON($xml) {
    
        function normalizeSimpleXML($obj, &$result) {
            $data = $obj;
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $res = null;
                    normalizeSimpleXML($value, $res);
                    if (($key == '@attributes') && ($key)) {
                        $result = $res;
                    } else {
                        $result[$key] = $res;
                    }
                }
            } else {
                $result = $data;
            }
        }
        normalizeSimpleXML(simplexml_load_string($xml), $result);
        return json_encode($result);
    }
}

/**
 * @package Prestashop
 */
class PrestashopException extends Exception
{
}
