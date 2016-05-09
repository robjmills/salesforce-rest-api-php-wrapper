<?php namespace SalesforceRestAPI;

use DateTime;

/**
 * The Salesforce REST API PHP Wrapper.
 *
 * This class connects to the Salesforce REST API and performs actions on that API
 *
 * @author Anthony Humes <jah.humes@gmail.com>
 * @license GPL, or GNU General Public License, version 2
 */
class SalesforceAPI
{
    /**
     * @var mixed
     */
    public $last_response;

    /**
     * @var string
     */
    protected $client_id;

    /**
     * @var string
     */
    protected $client_secret;

    /**
     * @var string
     */
    protected $instance_url;

    /**
     * @var string
     */
    protected $base_url;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var string
     */
    protected $return_type;

    /**
     * @var int|string
     */
    protected $api_version;

    /**
     * @var string
     */
    private $access_token;

    /**
     * @var resource
     */
    private $handle;

    // Supported request methods

    const METH_DELETE = 'DELETE';
    const METH_GET = 'GET';
    const METH_POST = 'POST';
    const METH_PUT = 'PUT';
    const METH_PATCH = 'PATCH';

    // Return types
    const RETURN_OBJECT = 'object';
    const RETURN_ARRAY_A = 'array_a';

    const LOGIN_PATH = '/services/oauth2/token';
    const OBJECT_PATH = 'sobjects/';
    const GRANT_TYPE = 'password';

    /**
     * Constructs the SalesforceConnection.
     *
     * This sets up the connection to salesforce and instantiates all default variables
     *
     * @param string     $instanceUrl  The url to connect to
     * @param string|int $version       The version of the API to connect to
     * @param string     $clientId     The Consumer Key from Salesforce
     * @param string     $clientSecret The Consumer Secret from Salesforce
     */
    public function __construct($instanceUrl, $version, $clientId, $clientSecret, $returnType = self::RETURN_ARRAY_A)
    {
        // Instantiate base variables
        $this->instance_url  = $instanceUrl;
        $this->api_version   = $version;
        $this->client_id     = $clientId;
        $this->client_secret = $clientSecret;
        $this->return_type   = $returnType;

        $this->base_url      = $instanceUrl;
        $this->instance_url  = $instanceUrl.'/services/data/v'.$version.'/';

        $this->headers = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Logs in the user to Salesforce with a username, password, and security token.
     *
     * @param string $username
     * @param string $password
     * @param string $securityToken
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function login($username, $password, $securityToken)
    {
        // Set the login data
        $login_data = [
            'grant_type' => self::GRANT_TYPE,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'username' => $username,
            'password' => $password.$securityToken,
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->base_url.'/services/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $login_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSLVERSION, 6);

        $ret = curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->checkForRequestErrors($ret, $ch);
        curl_close($ch);

        $ret = json_decode($ret);
        $this->afterLoginSetup($ret);

        return $ret;
    }

    /**
     * afterLoginSetup
     *
     * @param object $loginResponse json_decoded /services/oauth2/token response
     * @return bool
     */
    public function afterLoginSetup($loginResponse)
    {
        $this->access_token = $loginResponse->access_token;
        $this->base_url     = $loginResponse->instance_url;
        $this->instance_url = $loginResponse->instance_url.'/services/data/v'.$this->api_version.'/';

        return true;
    }

    /**
     * Get a list of all the API Versions for the instance.
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function getAPIVersions()
    {
        return $this->httpRequest($this->base_url.'/services/data');
    }

    /**
     * Lists the limits for the organization. This is in beta and won't return for most people.
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function getOrgLimits()
    {
        return $this->request('limits/');
    }

    /**
     * Gets a list of all the available REST resources.
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function getAvailableResources()
    {
        return $this->request('');
    }

    /**
     * Get a list of all available objects for the organization.
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function getAllObjects()
    {
        return $this->request(self::OBJECT_PATH);
    }

    /**
     * Get metadata about an Object.
     *
     * @param string   $objectName
     * @param bool     $all         Should this return all meta data including information about each field, URLs, and child relationships
     * @param DateTime $since       Only return metadata if it has been modified since the date provided
     *
     * @return mixed
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
            return $this->request(self::OBJECT_PATH.$objectName.'/describe/', [], self::METH_GET, $headers);
        } else {
            return $this->request(self::OBJECT_PATH.$objectName, [], self::METH_GET, $headers);
        }
    }

    /**
     * Create a new record.
     *
     * @param string $objectName
     * @param array  $data
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function create($objectName, $data)
    {
        return $this->request(self::OBJECT_PATH.(string) $objectName, $data, self::METH_POST);
    }

    /**
     * Update or Insert a record based on an external field and value.
     *
     *
     * @param string $objectName object_name/field_name/field_value to identify the record
     * @param array  $data
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function upsert($objectName, $data)
    {
        return $this->request(self::OBJECT_PATH.(string) $objectName, $data, self::METH_PATCH);
    }

    /**
     * Update an existing object.
     *
     * @param string $objectName
     * @param string $objectId
     * @param array  $data
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function update($objectName, $objectId, $data)
    {
        return $this->request(self::OBJECT_PATH.(string) $objectName.'/'.$objectId, $data, self::METH_PATCH);
    }

    /**
     * Delete a record.
     *
     * @param string $objectName
     * @param string $objectId
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function delete($objectName, $objectId)
    {
        return $this->request(self::OBJECT_PATH.(string) $objectName.'/'.$objectId, null, self::METH_DELETE);
    }

    /**
     * Get a record.
     *
     * @param string     $objectName
     * @param string     $objectId
     * @param array|null $fields
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function get($objectName, $objectId, $fields = null)
    {
        $params = [];
        // If fields are included, append them to the parameters
        if ($fields !== null && is_array($fields)) {
            $params['fields'] = implode(',', $fields);
        }

        return $this->request(self::OBJECT_PATH.(string) $objectName.'/'.$objectId, $params);
    }

    /**
     * Searches using a SOQL Query.
     *
     * @param string $query   The query to perform
     * @param bool   $all     Search through deleted and merged data as well
     * @param bool   $explain If the explain flag is set, it will return feedback on the query performance
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function searchSOQL($query, $all = false, $explain = false)
    {
        $search_data = [
            'q' => $query,
        ];

        // If the explain flag is set, it will return feedback on the query performance
        if ($explain) {
            $search_data['explain'] = $search_data['q'];
            unset($search_data['q']);
        }

        // If is set, search through deleted and merged data as well
        if ($all) {
            $path = 'queryAll/';
        } else {
            $path = 'query/';
        }

        return $this->request($path, $search_data, self::METH_GET);
    }

    /**
     * @param string $query
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getQueryFromUrl($query)
    {
        // Throw an error if no access token
        if (!isset($this->access_token))
        {
            throw new SalesforceAPIException('You have not logged in yet.');
        }

        // Set the Authorization header
        $request_headers = [
            'Authorization' => 'Bearer '.$this->access_token,
        ];

        // Merge all the headers
        $request_headers = array_merge($request_headers, []);

        return $this->httpRequest($this->base_url.$query, [], $request_headers);
    }

    /**
     * Makes a request to the API using the access key.
     *
     * @param string $path    The path to use for the API request
     * @param array  $params
     * @param string $method
     * @param array  $headers
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    protected function request($path, $params = [], $method = self::METH_GET, $headers = [])
    {
        // Throw an error if no access token
        if (!isset($this->access_token)) {
            throw new SalesforceAPIException('You have not logged in yet.');
        }

        // Set the Authorization header
        $request_headers = [
            'Authorization' => 'Bearer '.$this->access_token,
        ];

        // Merge all the headers
        $request_headers = array_merge($request_headers, $headers);

        return $this->httpRequest($this->instance_url.$path, $params, $request_headers, $method);
    }

    /**
     * Performs the actual HTTP request to the Salesforce API.
     *
     * @param string     $url
     * @param array|null $params
     * @param array|null $headers
     * @param string     $method
     *
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    protected function httpRequest($url, $params = null, $headers = null, $method = self::METH_GET)
    {
        $this->handle = curl_init();
        $options = [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BUFFERSIZE     => 128000,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_SSLVERSION     => 6
        ];
        curl_setopt_array($this->handle, $options);

        // Set the headers
        if (isset($headers) && $headers !== null && !empty($headers)) {
            $request_headers = array_merge($this->headers, $headers);
        } else {
            $request_headers = $this->headers;
        }

        // Add any custom fields to the request
        if (isset($params) && $params !== null && !empty($params)) {
            if ($request_headers['Content-Type'] == 'application/json') {
                $json_params = json_encode($params);
                curl_setopt($this->handle, CURLOPT_POSTFIELDS, $json_params);
            } else {
                $http_params = http_build_query($params);
                curl_setopt($this->handle, CURLOPT_POSTFIELDS, $http_params);
            }
        }

        // Modify the request depending on the type of request
        switch ($method) {
            case 'POST':
                curl_setopt($this->handle, CURLOPT_POST, true);
                break;
            case 'GET':
                curl_setopt($this->handle, CURLOPT_HTTPGET, true);
                if (isset($params) && $params !== null && !empty($params)) {
                    $url .= '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                }
                break;
            default:
                curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $method);
                break;
        }

        curl_setopt($this->handle, CURLOPT_URL, $url);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, $this->createCurlHeaderArray($request_headers));

        $response = curl_exec($this->handle);

        $response = $this->checkForRequestErrors($response, $this->handle);

        if ($this->return_type === self::RETURN_OBJECT) {
            $result = json_decode($response);
        } elseif ($this->return_type === self::RETURN_ARRAY_A) {
            $result = json_decode($response, true);
        }

        curl_close($this->handle);

        return $result;
    }

    /**
     * Makes the header array have the right format for the Salesforce API.
     *
     * @param $headers
     *
     * @return array
     */
    private function createCurlHeaderArray($headers)
    {
        $curl_headers = [];
        // Create the header array for the request
        foreach ($headers as $key => $header) {
            $curl_headers[] = $key.': '.$header;
        }

        return $curl_headers;
    }

    /**
     * Checks for errors in a request.
     *
     * @param string   $response The response from the server
     * @param Resource $handle   The CURL handle
     *
     * @return string The response from the API
     *
     * @throws SalesforceAPIException
     *
     * @see http://www.salesforce.com/us/developer/docs/api_rest/index_Left.htm#CSHID=errorcodes.htm|StartTopic=Content%2Ferrorcodes.htm|SkinName=webhelp
     */
    private function checkForRequestErrors($response, $handle)
    {
        $curl_error = curl_error($handle);
        if ($curl_error !== '') {
            throw new SalesforceAPIException($curl_error);
        }
        $request_info = curl_getinfo($handle);

        switch ($request_info['http_code']) {
            case 304:
                if ($response === '') {
                    return json_encode(['message' => 'The requested object has not changed since the specified time']);
                }
                break;
            case 300:
            case 200:
            case 201:
            case 204:
                if ($response === '') {
                    return json_encode(['success' => true]);
                }
                break;
            default:
                if (empty($response) || $response !== '') {
                    $err = new SalesforceAPIException($response, $request_info);
                    throw $err;
                } else {
                    $result = json_decode($response);
                    if (isset($result->error)) {
                        $err = new SalesforceAPIException($result->error_description, $request_info);
                        throw $err;
                    }
                }
                break;
        }

        $this->last_response = $response;

        return $response;
    }
}
