<?php namespace SalesforceRestAPI;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

/**
 * The Salesforce REST API PHP Wrapper.
 *
 * This class connects to the Salesforce REST API and performs actions on that API
 *
 * @author Mike Corrigan <mike@corrlabs.com>
 * @author Anthony Humes <jah.humes@gmail.com> (original author)
 * @license GPL-2.0
 */
class SalesforceAPI
{
    // Supported HTTP request methods
    private static $HTTP_METHOD_DELETE    = 'DELETE';
    private static $HTTP_METHOD_GET       = 'GET';
    private static $HTTP_METHOD_POST      = 'POST';
    private static $HTTP_METHOD_PATCH     = 'PATCH';

    /**
     * Object URI
     */
    private static $OBJECT_URI = 'sobjects/';

    /**
     * Grant-type
     */
    private static $GRANT_TYPE = 'password';

    /**
     * @var string
     */
    protected $client_id;

    /**
     * @var string
     */
    protected $client_secret;

    /**
     * @var int|string
     */
    protected $api_version;

    /**
     * @var Client
     */
    protected $guzzle;

    /**
     * @var ResponseInterface
     */
    protected $last_response;

    /**
     * @var bool
     */
    protected $debug;

    /**
     * Constructs the SalesforceAPI
     *
     * This sets up the connection to salesforce and instantiates all default variables
     *
     * @param string     $instanceUrl  The url to connect to
     * @param string|int $version      The version of the API to connect to
     * @param string     $clientId     The Consumer Key from Salesforce
     * @param string     $clientSecret The Consumer Secret from Salesforce
     * @param bool       $debug        If the requests should output debug information (default: false)
     */
    public function __construct($instanceUrl, $version, $clientId, $clientSecret, $debug = false)
    {
        // Instantiate base variables
        $this->api_version   = $version;
        $this->client_id     = $clientId;
        $this->client_secret = $clientSecret;
        $this->debug         = $debug;

        $this->guzzle = new Client(['base_uri' => $instanceUrl]);
    }

    /**
     * Logs in the user to Salesforce with a username, password, and security token.
     *
     * @param string $username
     * @param string $password
     * @param string $securityToken
     *
     * @return ResponseInterface
     *
     * @throws SalesforceAPIException
     */
    public function login($username, $password, $securityToken)
    {
        $login_data = [
            'grant_type'    => self::$GRANT_TYPE,
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'username'      => $username,
            'password'      => $password.$securityToken,
        ];

        try {
            $response = $this->guzzle->post('/services/oauth2/token', [
                'form_params' => $login_data,
                'debug'       => $this->debug
            ]);
        }
        catch (ClientException $e)
        {
            throw new SalesforceAPIException($e->getMessage());
        }

        $this->afterLoginSetup($response);
        return $response;
    }

    /**
     * Get a list of all the API Versions for the instance.
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function getAPIVersions()
    {
        try {
            $response = $this->guzzle->get('/services/data', ['debug' => $this->debug]);
            return json_decode($response->getBody()->getContents(), true);
        }catch(ClientException $e)
        {
            throw new SalesforceAPIException($e->getMessage());
        }
    }

    /**
     * Lists the limits for the organization.
     * @note: This functionality may not work for some accounts.
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function getOrgLimits()
    {
        return $this->request('limits/', self::$HTTP_METHOD_GET);
    }

    /**
     * Gets a list of all the available REST resources.
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function getAvailableResources()
    {
        return $this->request('', self::$HTTP_METHOD_GET);
    }

    /**
     * Get a list of all available objects for the organization.
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function getAllObjects()
    {
        return $this->request(self::$OBJECT_URI, self::$HTTP_METHOD_GET);
    }

    /**
     * Getter for last response
     *
     * @return ResponseInterface
     */
    public function getLastResponse()
    {
        return $this->last_response;
    }

    /**
     * Sets debug to true
     */
    public function enableDebug(){
        $this->debug = true;
    }

    /**
     * Sets debug to false
     */
    public function disableDebug()
    {
        $this->debug = false;
    }

    /**
     * Get metadata about an Object.
     *
     * @param string   $objectName
     * @param bool     $all         Should this return all meta data including information about each field, URLs, and child relationships
     * @param DateTime $since       Only return metadata if it has been modified since the date provided
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function getObjectMetadata($objectName, $all = false, DateTime $since = null)
    {
        $headers = [];
        // Check if the If-Modified-Since header should be set
        if ($since !== null && $since instanceof DateTime) {
            $headers['IF-Modified-Since'] = $since->format('D, j M Y H:i:s e');
        } elseif ($since !== null && !$since instanceof DateTime) {
            // If the $since flag has been set and is not a DateTime instance, throw an error
            throw new SalesforceAPIException('To get object metadata for an object, you must provide a DateTime object');
        }

        // Should this return all meta data including information about each field, URLs, and child relationships
        if ($all === true) {
            return $this->request(self::$OBJECT_URI.$objectName.'/describe/', self::$HTTP_METHOD_GET, [], $headers);
        } else {
            return $this->request(self::$OBJECT_URI.$objectName, self::$HTTP_METHOD_GET, [], $headers);
        }
    }

    /**
     * Create a new record.
     *
     * @param string $objectName
     * @param array  $data
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function create($objectName, $data)
    {
        return $this->request(self::$OBJECT_URI.(string) $objectName, self::$HTTP_METHOD_POST, $data);
    }

    /**
     * Update or Insert a record based on an external field and value.
     *
     *
     * @param string $objectName object_name/field_name/field_value to identify the record
     * @param array  $data
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function upsert($objectName, $data)
    {
        return $this->request(self::$OBJECT_URI.(string) $objectName, self::$HTTP_METHOD_PATCH, $data);
    }

    /**
     * Update an existing object.
     *
     * @param string $objectName
     * @param string $objectId
     * @param array  $data
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function update($objectName, $objectId, $data)
    {
        return $this->request(self::$OBJECT_URI.(string) $objectName.'/'.$objectId, self::$HTTP_METHOD_PATCH, $data);
    }

    /**
     * Delete a record.
     *
     * @param string $objectName
     * @param string $objectId
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function delete($objectName, $objectId)
    {
        return $this->request(self::$OBJECT_URI.(string) $objectName.'/'.$objectId, self::$HTTP_METHOD_DELETE, []);
    }

    /**
     * Get a record.
     *
     * @param string $objectName
     * @param string $objectId
     * @param array  $fields
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function get($objectName, $objectId, array $fields = [])
    {
        $params = [];

        if (!empty($fields))
        {
            $params['fields'] = implode(',', $fields);
        }

        return $this->request(self::$OBJECT_URI.(string) $objectName.'/'.$objectId, self::$HTTP_METHOD_GET, $params);
    }

    /**
     * Searches using a SOQL Query.
     *
     * @param string $query   The query to perform
     * @param bool   $all     Search through deleted and merged data as well
     * @param bool   $explain If the explain flag is set, it will return feedback on the query performance
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    public function searchSOQL($query, $all = false, $explain = false)
    {
        $search_data = [
            'q' => $query,
        ];

        // If the explain flag is set, it will return feedback on the query performance
        if ($explain)
        {
            $search_data['explain'] = $search_data['q'];
            unset($search_data['q']);
        }

        // If is set, search through deleted and merged data as well
        if ($all) {
            $path = 'queryAll/';
        } else {
            $path = 'query/';
        }

        return $this->request($path, self::$HTTP_METHOD_GET, $search_data);
    }

    /**
     * Makes a request to the API using the access key.
     *
     * @param string $uri     The path to use for the API request
     * @param string $method  The HTTP method to use
     * @param array  $params  Parameters to include (default: [])
     * @param array  $headers Headers to include (default: [])
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    protected function request($uri, $method, $params = [], $headers = [])
    {
        return $this->httpRequest('/services/data/v'.$this->api_version.'/'.$uri, $method, $params, $headers);
    }

    /**
     * Performs the actual HTTP request to the Salesforce API.
     *
     * @param string $uri     The URI path used for the API request
     * @param string $method  The HTTP method to use
     * @param array  $params  Parameters to include (default: [])
     * @param array  $headers Headers to include (default: [])
     *
     * @return array
     *
     * @throws SalesforceAPIException
     */
    protected function httpRequest($uri, $method, array $params = [], array $headers = [])
    {
        try {

            $options = ['debug' => $this->debug];

            if (!empty($headers))
            {
                $options['headers'] = $headers;
            }

            if (!empty($params))
            {
                if ($method == self::$HTTP_METHOD_GET)
                {
                    $uri .= '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                }else
                {
                    $options['body'] = json_encode($params);
                }
            }

            $response = $this->guzzle->request($method, $uri, $options);
        }
        catch (ClientException $e)
        {
            throw new SalesforceAPIException($e->getMessage());
        }

        return $this->handleSalesForceResponse($response);
    }

    /**
     * Check the payload to see if there was an issue working with the API
     *
     * @param ResponseInterface $response
     *
     * @throws SalesforceAPIException
     */
    protected function handleSalesForceResponse($response)
    {
        $responseBody = $response->getBody()->getContents();
        $response->getBody()->rewind(); // rewind the stream
        switch ($response->getStatusCode()) {
            case 304:
                if ($responseBody === '') {
                    return ['message' => 'The requested object has not changed since the specified time'];
                }
                break;
            case 300:
            case 200:
            case 201:
            case 204:
                if ($responseBody === '') {
                    return ['success' => true];
                }
                break;
            default:
                if (empty($responseBody) || $responseBody !== '') {
                    throw new SalesforceAPIException($response);
                } else {
                    $result = json_decode($responseBody);
                    if (isset($result->error)) {
                        throw new SalesforceAPIException($result->error_description);
                    }
                }
                break;
        }

        if ($response->getStatusCode() > 400)
        {
            throw new SalesforceAPIException($response->getReasonPhrase());
        }

        $this->last_response = $response;

        return json_decode($responseBody, true);
    }

    /**
     * Following login, restablish guzzle using bearer token and new instance_url.
     *
     * @param ResponseInterface $response
     * @return bool
     */
    protected function afterLoginSetup($response)
    {
        $responseData = json_decode($response->getBody()->getContents(), true);

        // rebuild guzzle client with new instance URL (it can change) and bearer token
        $this->guzzle = new Client([
            'base_uri'  => $responseData['instance_url'],
            'headers'   => [
                'Authorization' => 'Bearer '.$responseData['access_token'],
                'Content-Type'  => 'application/json'
            ]
        ]);

        return true;
    }
}
