<?php

namespace Phuze\PhpCosmos;

use \GuzzleHttp\Client;
use \GuzzleHttp\Exception\GuzzleException;
use \GuzzleHttp\Exception\ClientException;
use \GuzzleHttp\Exception\RequestException;
use \GuzzleHttp\Exception\TransferException;
use \Psr\Http\Message\ResponseInterface;
use \Psr\Log\LoggerInterface;

class CosmosDb
{
    private $host;
    private $private_key;
    private $httpClient = null;
    private $logger = null;
    private $pkRanges = [];
    private $maxThrottleRetries = 0;
    private $maxThrottleWaitMs = 0;
    public $httpClientOptions = [];
    public $debug = false;

    /**
     * __construct
     *
     * @access public
     * @param string $host URI of hostname
     * @param string $private_key Primary (or Secondary key) private key
     */
    public function __construct(string $host, string $private_key)
    {
        $this->host = $host;
        $this->private_key = $private_key;
    }

    /**
     * set guzzle http client options using an associative array.
     * these are merged over the defaults (60s timeout, 5s connect timeout).
     *
     * @param array $options
     */
    public function setHttpClientOptions(array $options = [])
    {
        $this->httpClientOptions = $options;

        # rebuild the client on the next request so the new options apply
        $this->httpClient = null;
    }

    /**
     * retry requests rejected with 429 (rate limited). off by default, so a 429
     * is thrown to the caller. each retry waits for the time cosmos asks for in
     * the x-ms-retry-after-ms header. the defaults match microsoft's own SDKs.
     *
     * @param int $maxRetries maximum retries per request. 0 disables retrying.
     * @param int $maxWaitMs maximum total time to wait across retries of one request, in milliseconds.
     */
    public function setRetryOptions(int $maxRetries = 9, int $maxWaitMs = 30000)
    {
        $this->maxThrottleRetries = $maxRetries;
        $this->maxThrottleWaitMs = $maxWaitMs;
    }

    /**
     * set a PSR-3 logger. every request is logged at debug level with its
     * status, duration, request charge and activity id. retries are logged
     * at warning level.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * the guzzle client is created once and reused, so requests share
     * pooled connections instead of opening a new one each time.
     *
     * @return Client
     */
    private function getHttpClient()
    {
        if ($this->httpClient === null) {
            $defaults = [
                'base_uri'        => rtrim($this->host, '/'),
                'http_errors'     => true,
                'timeout'         => 60.0,
                'connect_timeout' => 5.0,
                'curl'            => $this->getKeepAliveCurlOptions(),
            ];

            $options = (array)$this->httpClientOptions;

            # merge curl options individually, so setting one doesn't drop the keep-alive defaults
            if (isset($options['curl']) && is_array($options['curl'])) {
                $options['curl'] = $options['curl'] + $defaults['curl'];
            }

            $this->httpClient = new Client(array_merge($defaults, $options));
        }

        return $this->httpClient;
    }

    /**
     * azure drops connections that sit idle for about 4 minutes. tcp keep-alive
     * probes stop a pooled connection from going stale between requests.
     *
     * @return array
     */
    private function getKeepAliveCurlOptions()
    {
        $options = [];

        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $options[CURLOPT_TCP_KEEPALIVE] = 1;
            if (defined('CURLOPT_TCP_KEEPIDLE')) {
                $options[CURLOPT_TCP_KEEPIDLE] = 60;
            }
            if (defined('CURLOPT_TCP_KEEPINTVL')) {
                $options[CURLOPT_TCP_KEEPINTVL] = 30;
            }
        }

        return $options;
    }

    /**
     * getAuthHeaders
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn783368.aspx
     * @access private
     * @param string $verb Request Method (GET, POST, PUT, DELETE)
     * @param string $resource_type Resource Type
     * @param string $resource_id Resource ID
     * @return array of Request Headers
     */
    private function getAuthHeaders(string $verb, string $resource_type, string $resource_id)
    {
        $x_ms_date = gmdate('D, d M Y H:i:s T', strtotime('+2 minutes'));
        $master = 'master';
        $token = '1.0';
        $x_ms_version = '2018-12-31';

        $key = base64_decode($this->private_key);
        $string_to_sign = $verb . "\n" .
            $resource_type . "\n" .
            $resource_id . "\n" .
            $x_ms_date . "\n" .
            "\n";

        $sig = base64_encode(hash_hmac('sha256', strtolower($string_to_sign), $key, true));

        return [
            'Accept' => 'application/json',
            'User-Agent' => 'cosmos.php.sdk/3.0.2',
            'Cache-Control' => 'no-cache',
            'x-ms-date' => $x_ms_date,
            'x-ms-version' => $x_ms_version,
            'authorization' => urlencode("type={$master}&ver={$token}&sig={$sig}")
        ];
    }

    /**
     * request
     *
     * a network error with no response, such as a pooled connection that went
     * stale, is retried once. a 429 (rate limited) response is retried only if
     * enabled with setRetryOptions().
     *
     * @access private
     * @param string $path request path
     * @param string $method request method
     * @param array $headers request headers
     * @param string|null $body request body (JSON or QUERY)
     * @param bool $retryNetworkErrors false for requests that aren't safe to send twice
     * @return ResponseInterface JSON response
     * @throws GuzzleException
     */
    private function request(string $path, string $method, array $headers, $body = null, bool $retryNetworkErrors = true)
    {
        # guzzle 7.11+ deprecates numeric header values (ie: Content-Length from strlen()),
        # and guzzle 8 rejects them
        foreach ($headers as $name => $value) {
            if (is_int($value) || is_float($value)) {
                $headers[$name] = (string)$value;
            }
        }

        $options = [
            'headers' => $headers,
            'body' => $body,
        ];

        $path = '/' . ltrim($path, '/');
        $networkRetried = !$retryNetworkErrors;
        $throttleRetries = 0;
        $throttleWaitMs = 0;

        while (true) {
            $start = microtime(true);
            $error = null;

            try {
                $response = $this->getHttpClient()->request($method, $path, $options);
            }
            catch (TransferException $e) {
                # guzzle 6 and 7 both throw subclasses of TransferException, but
                # only a RequestException can carry a response
                $error = $e;
                $response = $e instanceof RequestException ? $e->getResponse() : null;
            }

            $this->logRequest($method, $path, $start, $response, $error);

            if ($response === null) {
                if ($networkRetried) {
                    throw $error;
                }
                $networkRetried = true;
                $this->log('warning', 'Cosmos DB connection error, retrying', [
                    'method' => $method,
                    'path'   => $path,
                    'error'  => $error->getMessage(),
                ]);
                continue;
            }

            # checked on the response rather than the exception, because
            # with http_errors disabled a 429 is returned instead of thrown
            $delayMs = $this->getThrottleDelay($response, $throttleRetries, $throttleWaitMs);
            if ($delayMs === null) {
                if ($error !== null) {
                    throw $error;
                }
                break;
            }

            $throttleRetries++;
            $throttleWaitMs += $delayMs;
            $this->log('warning', 'Cosmos DB request rate limited (429), retrying', [
                'method'         => $method,
                'path'           => $path,
                'retry_after_ms' => $delayMs,
                'attempt'        => $throttleRetries,
            ]);
            usleep($delayMs * 1000);
        }

        # debug
        if($this->debug) {
            echo "=============== DEBUG (CosmosDb::request) ===============".PHP_EOL;
            echo json_encode([
                'method'        => $method,
                'config'        => array_merge($options, (array)$this->httpClientOptions),
                'requestUrl'    => rtrim($this->host, '/') . $path,
                'response'      => $this->readBody($response),
            ], JSON_PRETTY_PRINT).PHP_EOL;
        }

        return $response;
    }

    /**
     * how long to wait before retrying a 429 (rate limited) response, using the
     * x-ms-retry-after-ms header. null if it isn't a 429 or the retry limits are reached.
     *
     * @param ResponseInterface $response
     * @param int $retries retries made so far
     * @param int $waitedMs time waited so far, in milliseconds
     * @return int|null milliseconds to wait
     */
    private function getThrottleDelay(ResponseInterface $response, int $retries, int $waitedMs)
    {
        if ($response->getStatusCode() !== 429 || $retries >= $this->maxThrottleRetries) {
            return null;
        }

        $delayMs = (int)ceil((float)$response->getHeaderLine('x-ms-retry-after-ms'));
        if ($delayMs <= 0) {
            $delayMs = 1000;
        }

        return $waitedMs + $delayMs <= $this->maxThrottleWaitMs ? $delayMs : null;
    }

    /**
     * read a response body without consuming it, so it can still be read afterwards
     *
     * @param ResponseInterface $response
     * @return string
     */
    private function readBody(ResponseInterface $response)
    {
        $body = $response->getBody();
        $contents = (string)$body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return $contents;
    }

    /**
     * decode the error body of a 4xx response
     *
     * @param ClientException $e
     * @return object|null
     */
    private function decodeError(ClientException $e)
    {
        return json_decode($this->readBody($e->getResponse()));
    }

    /**
     * log one request attempt at debug level
     *
     * @param string $method
     * @param string $path
     * @param float $start microtime the attempt started
     * @param ResponseInterface|null $response
     * @param \Exception|null $error
     */
    private function logRequest(string $method, string $path, float $start, $response, $error)
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'method'      => $method,
            'path'        => $path,
            'status'      => $response ? $response->getStatusCode() : null,
            'duration_ms' => (int)round((microtime(true) - $start) * 1000),
        ];
        if ($response) {
            $context['request_charge'] = (float)$response->getHeaderLine('x-ms-request-charge');
            $context['activity_id'] = $response->getHeaderLine('x-ms-activity-id');
        }
        if ($error) {
            $context['error'] = $error->getMessage();
        }

        $this->log('debug', "Cosmos DB {$method} {$path}", $context);
    }

    /**
     * @param string $level PSR-3 log level
     * @param string $message
     * @param array $context
     */
    private function log(string $level, string $message, array $context = [])
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * format a partition key value for the x-ms-documentdb-partitionkey header.
     * json encoding keeps numbers and booleans typed, and escapes quotes in strings.
     *
     * @param mixed $value
     * @return string
     */
    private function getPartitionKeyHeader($value)
    {
        return json_encode([$value]);
    }

    /**
     * selectDB
     *
     * @access public
     * @param string $db_name Database name
     * @return ?CosmosDbDatabase class
     * @throws GuzzleException
     */
    public function selectDB(string $db_name)
    {
        $rid_db = false;
        $object = json_decode($this->listDatabases());

        $db_list = $object->Databases;
        for ($i = 0; $i < count($db_list); $i++) {
            if ($db_list[$i]->id === $db_name) {
                $rid_db = $db_list[$i]->_rid;
            }
        }
        if (!$rid_db) {
            $object = json_decode($this->createDatabase(json_encode(['id' => $db_name])));
            $rid_db = $object->_rid;
        }

        return $rid_db ? new CosmosDbDatabase($this, $rid_db) : null;
    }

    /**
     * getInfo
     *
     * @access public
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getInfo()
    {
        $headers = $this->getAuthHeaders('GET', '', '');
        $headers['Content-Length'] = '0';
        return $this->request("", "GET", $headers)->getBody()->getContents();
    }

    /**
     * query
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn783363.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $query Query
     * @param boolean $isCrossPartition used for cross partition query
     * @param mixed $partitionValue partition key value, to query a single partition
     * @return array JSON response
     * @throws GuzzleException
     */
    public function query(string $rid_id, string $rid_col, string $query, bool $isCrossPartition = false, $partitionValue = null)
    {
        $headers = $this->getAuthHeaders('POST', 'docs', $rid_col);
        $headers['Content-Length'] = strlen($query);
        $headers['Content-Type'] = 'application/query+json';
        $headers['x-ms-max-item-count'] = -1;
        $headers['x-ms-documentdb-isquery'] = 'True';

        if ($isCrossPartition) {
            $headers['x-ms-documentdb-query-enablecrosspartition'] = 'True';
        }

        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        $this->log('debug', 'Cosmos DB query', [
            'query'           => $query,
            'cross_partition' => $isCrossPartition,
            'partition_value' => $partitionValue,
        ]);

        try {
            return $this->getQueryResults($rid_id, $rid_col, $query, $headers);
        }
        catch (ClientException $e) {
            $responseError = $this->decodeError($e);

            # debug
            if($this->debug) {
                echo "=============== DEBUG (CosmosDb::query) ===============".PHP_EOL;
                echo json_encode([
                    'responseError' => $responseError,
                ], JSON_PRETTY_PRINT).PHP_EOL;
            }

            // -- Retry the request with PK Ranges --
            // The provided cross partition query can not be directly served by the gateway.
            // This is a first chance (internal) exception that all newer clients will know how to
            // handle gracefully. This exception is traced, but unless you see it bubble up as an
            // exception (which only happens on older SDK clients), then you can safely ignore this message.
            $notServedByGateway = $isCrossPartition
                && isset($responseError->code, $responseError->message)
                && $responseError->code === "BadRequest"
                && strpos($responseError->message, "cross partition query can not be directly served by the gateway") !== false;

            if (!$notServedByGateway) {
                throw $e;
            }
        }

        $this->log('info', 'Cosmos DB cross partition query not served by the gateway, querying each partition key range');

        return $this->queryEachPkRange($rid_id, $rid_col, $query, $headers);
    }

    /**
     * run a query against each partition key range in turn. the results of each
     * range are separate, so an ORDER BY or TOP applies within each range only.
     *
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $query Query
     * @param array $headers request headers
     * @return array JSON response
     * @throws GuzzleException
     */
    private function queryEachPkRange(string $rid_id, string $rid_col, string $query, array $headers)
    {
        $refreshed = false;

        while (true) {
            try {
                $results = [];
                foreach ($this->getPkRanges($rid_id, $rid_col)->PartitionKeyRanges as $range) {
                    $headers['x-ms-documentdb-partitionkeyrangeid'] = $range->id;
                    $results = array_merge($results, $this->getQueryResults($rid_id, $rid_col, $query, $headers));
                }
                return $results;
            }
            catch (ClientException $e) {
                // the partition key ranges are cached, and go stale when cosmos splits a partition.
                // querying a range that no longer exists returns 410 Gone, so refresh them and start over once.
                if ($refreshed || $e->getResponse()->getStatusCode() !== 410) {
                    throw $e;
                }
                $refreshed = true;
                unset($this->pkRanges[$rid_id][$rid_col]);
                $this->log('info', 'Cosmos DB partition key ranges changed, refreshing');
            }
        }
    }

    /**
     * getQueryResults
     *
     * run a query and return every page of results
     *
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $query Query
     * @param array $headers request headers
     * @return array JSON response for each page
     * @throws GuzzleException
     */
    private function getQueryResults(string $rid_id, string $rid_col, string $query, array $headers)
    {
        /*
         * Fix for https://github.com/jupitern/cosmosdb/issues/21 (credits to https://github.com/ElvenSpellmaker).
         *
         * CosmosDB has a max packet size of 4MB and will automatically paginate after that, regardless of x-ms-max-items.
         * If this is the case, a 'x-ms-continuation'-header will be present in the response headers. The value of this
         * header will be a continuation token. If this header is detected, we can rerun our query with an additional
         * 'x-ms-continuation' request header, with the continuation token we received earlier as its value.
         *
         * This fix checks if this header is present on the response headers and handles the additional requests, untill
         * all results are loaded.
         */
        $results = [];
        do {
            $result = $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs", "POST", $headers, $query);
            $results[] = $result->getBody()->getContents();
            $continuation = $result->getHeaderLine('x-ms-continuation');
            $headers['x-ms-continuation'] = $continuation;
        } while ($continuation !== '');

        return $results;
    }

    /**
     * getPkRanges
     *
     * the ranges are cached for the life of this object
     *
     * @param string $rid_id
     * @param string $rid_col
     * @return mixed
     * @throws GuzzleException
     */
    public function getPkRanges(string $rid_id, string $rid_col)
    {
        if (!isset($this->pkRanges[$rid_id][$rid_col])) {
            $headers = $this->getAuthHeaders('GET', 'pkranges', $rid_col);
            $headers['Accept'] = 'application/json';
            $headers['x-ms-max-item-count'] = -1;
            $result = $this->request("/dbs/{$rid_id}/colls/{$rid_col}/pkranges", "GET", $headers);
            $this->pkRanges[$rid_id][$rid_col] = json_decode($result->getBody()->getContents());
        }

        return $this->pkRanges[$rid_id][$rid_col];
    }

    /**
     * getPkFullRange
     *
     * @param $rid_id
     * @param $rid_col
     *
     * @return string
     * @throws GuzzleException
     */
	public function getPkFullRange($rid_id, $rid_col)
    {
		$result = $this->getPkRanges($rid_id, $rid_col);
		$ids = array_column($result->PartitionKeyRanges, "id");
		return $result->_rid . "," . implode(",", $ids);
	}

    /**
     * listDatabases
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803945.aspx
     * @access public
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listDatabases()
    {
        $headers = $this->getAuthHeaders('GET', 'dbs', '');
        $headers['Content-Length'] = '0';
        return $this->request("/dbs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getDatabase
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803937.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getDatabase(string $rid_id)
    {
        $headers = $this->getAuthHeaders('GET', 'dbs', $rid_id);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createDatabase
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803954.aspx
     * @access public
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createDatabase(string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'dbs', '');
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceDatabase
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803943.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceDatabase(string $rid_id, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'dbs', $rid_id);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteDatabase
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803942.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @return string JSON response
     */
    public function deleteDatabase(string $rid_id)
    {
        $headers = $this->getAuthHeaders('DELETE', 'dbs', $rid_id);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listUsers
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803958.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listUsers(string $rid_id)
    {
        $headers = $this->getAuthHeaders('GET', 'users', $rid_id);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getUser
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getUser(string $rid_id, string $rid_user)
    {
        $headers = $this->getAuthHeaders('GET', 'users', $rid_user);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createUser
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803946.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createUser(string $rid_id, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'users', $rid_id);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/users", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceUser
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803941.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceUser(string $rid_id, string $rid_user, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'users', $rid_user);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteUser
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803953.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteUser(string $rid_id, string $rid_user)
    {
        $headers = $this->getAuthHeaders('DELETE', 'users', $rid_user);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listCollections
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803935.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listCollections(string $rid_id)
    {
        $headers = $this->getAuthHeaders('GET', 'colls', $rid_id);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getCollection
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803951.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getCollection(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('GET', 'colls', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createCollection
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803934.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createCollection(string $rid_id, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'colls', $rid_id);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteCollection
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803953.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteCollection(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('DELETE', 'colls', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listDocuments
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803955.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listDocuments(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('GET', 'docs', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getDocument
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803957.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getDocument(string $rid_id, string $rid_col, string $rid_doc)
    {
        $headers = $this->getAuthHeaders('GET', 'docs', $rid_doc);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createDocument
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803948.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $json JSON request
     * @param mixed $partitionKey partition key value
     * @param array $headers Optional headers to send along with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createDocument(string $rid_id, string $rid_col, string $json, $partitionKey = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('POST', 'docs', $rid_col);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        if ($partitionKey !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionKey);
        }

        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceDocument
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803947.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $json JSON request
     * @param mixed $partitionKey partition key value
     * @param array $headers Optional headers to send along with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceDocument(string $rid_id, string $rid_col, string $rid_doc, string $json, $partitionKey = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('PUT', 'docs', $rid_doc);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        if ($partitionKey !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionKey);
        }

        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * patchDocument
     *
     * not retried on a network error, because operations such as incr
     * aren't safe to apply twice
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/patch-a-document
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $json JSON request; ie: {"operations": [...]}
     * @param mixed $partitionKey partition key value
     * @param array $headers Optional headers to send along with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function patchDocument(string $rid_id, string $rid_col, string $rid_doc, string $json, $partitionKey = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('PATCH', 'docs', $rid_doc);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        $headers['Content-Type'] = 'application/json_patch+json';
        if ($partitionKey !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionKey);
        }

        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}", "PATCH", $headers, $json, false)->getBody()->getContents();
    }

    /**
     * deleteDocument
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803952.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param mixed $partitionKey partition key value
     * @param array $headers Optional headers to send along with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteDocument(string $rid_id, string $rid_col, string $rid_doc, $partitionKey = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('DELETE', 'docs', $rid_doc);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = '0';
        if ($partitionKey !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionKey);
        }

        /*
        # debug
        echo "=============== DEBUG (CosmosDb::deleteDocument) ===============".PHP_EOL;
        echo json_encode([
            'method'        => "DELETE",
            'path'          => "/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}",
            '$authHeaders'  => $authHeaders,
            '$headers'      => $headers,
        ], JSON_PRETTY_PRINT).PHP_EOL;
        */

        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listAttachments
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col
     * @param string $rid_doc Resource Doc ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listAttachments(string $rid_id, string $rid_col, string $rid_doc)
    {
        $headers = $this->getAuthHeaders('GET', 'attachments', $rid_doc);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}/attachments", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getAttachment
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $rid_at Resource Attachment ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getAttachment(string $rid_id, string $rid_col, string $rid_doc, string $rid_at)
    {
        $headers = $this->getAuthHeaders('GET', 'attachments', $rid_at);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}/attachments/{$rid_at}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createAttachment
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $content_type Content-Type of Media
     * @param string $filename Attachement file name
     * @param string $file URL encoded Attachement file (Raw Media)
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createAttachment(string $rid_id, string $rid_col, string $rid_doc, string $content_type, string $filename, string $file)
    {
        $headers = $this->getAuthHeaders('POST', 'attachments', $rid_doc);
        $headers['Content-Length'] = strlen($file);
        $headers['Content-Type'] = $content_type;
        $headers['Slug'] = urlencode($filename);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}/attachments", "POST", $headers, $file)->getBody()->getContents();
    }

    /**
     * replaceAttachment
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $rid_at Resource Attachment ID
     * @param string $content_type Content-Type of Media
     * @param string $filename Attachement file name
     * @param string $file URL encoded Attachement file (Raw Media)
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceAttachment(string $rid_id, string $rid_col, string $rid_doc, string $rid_at, string $content_type, string $filename, string $file)
    {
        $headers = $this->getAuthHeaders('PUT', 'attachments', $rid_at);
        $headers['Content-Length'] = strlen($file);
        $headers['Content-Type'] = $content_type;
        $headers['Slug'] = urlencode($filename);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}/attachments/{$rid_at}", "PUT", $headers, $file)->getBody()->getContents();
    }

    /**
     * deleteAttachment
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_doc Resource Doc ID
     * @param string $rid_at Resource Attachment ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteAttachment(string $rid_id, string $rid_col, string $rid_doc, string $rid_at)
    {
        $headers = $this->getAuthHeaders('DELETE', 'attachments', $rid_at);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/docs/{$rid_doc}/attachments/{$rid_at}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listOffers
     *
     * @link http://
     * @access public
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listOffers()
    {
        $headers = $this->getAuthHeaders('GET', 'offers', '');
        $headers['Content-Length'] = '0';
        return $this->request("/offers", "GET", $headers)->getBody()->getContents();
    }

    /**
     * getOffer
     *
     * @link http://
     * @access public
     * @param string $rid Resource ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getOffer(string $rid)
    {
        $headers = $this->getAuthHeaders('GET', 'offers', $rid);
        $headers['Content-Length'] = '0';
        return $this->request("/offers/{$rid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * replaceOffer
     *
     * @link http://
     * @access public
     * @param string $rid Resource ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceOffer(string $rid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'offers', $rid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/offers/{$rid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * queryingOffers
     *
     * @link http://
     * @access public
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function queryingOffers(string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'offers', '');
        $headers['Content-Length'] = strlen($json);
        $headers['Content-Type'] = 'application/query+json';
        $headers['x-ms-documentdb-isquery'] = 'True';
        return $this->request("/offers", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * listPermissions
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listPermissions(string $rid_id, string $rid_user)
    {
        $headers = $this->getAuthHeaders('GET', 'permissions', $rid_user);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}/permissions", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createPermission
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803946.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createPermission(string $rid_id, string $rid_user, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'permissions', $rid_user);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}/permissions", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * getPermission
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @param string $rid_permission Resource Permission ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getPermission(string $rid_id, string $rid_user, string $rid_permission)
    {
        $headers = $this->getAuthHeaders('GET', 'permissions', $rid_permission);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}/permissions/{$rid_permission}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * replacePermission
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @param string $rid_permission Resource Permission ID
     * @param string $json JSON request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replacePermission(string $rid_id, string $rid_user, string $rid_permission, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'permissions', $rid_permission);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}/permissions/{$rid_permission}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deletePermission
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803949.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_user Resource User ID
     * @param string $rid_permission Resource Permission ID
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deletePermission(string $rid_id, string $rid_user, string $rid_permission)
    {
        $headers = $this->getAuthHeaders('DELETE', 'permissions', $rid_permission);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/users/{$rid_user}/permissions/{$rid_permission}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listStoredProcedures
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listStoredProcedures(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('GET', 'sprocs', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/sprocs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * executeStoredProcedure
     *
     * not retried on a network error, because a stored procedure
     * isn't necessarily safe to run twice
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_sproc Resource ID of Stored Procedurea
     * @param string $json Parameters
     * @return string JSON response
     * @throws GuzzleException
     */
    public function executeStoredProcedure(string $rid_id, string $rid_col, string $rid_sproc, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'sprocs', $rid_sproc);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/sprocs/{$rid_sproc}", "POST", $headers, $json, false)->getBody()->getContents();
    }

    /**
     * createStoredProcedure
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $json JSON of function
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createStoredProcedure(string $rid_id, string $rid_col, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'sprocs', $rid_col);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/sprocs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceStoredProcedure
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_sproc Resource ID of Stored Procedurea
     * @param string $json Parameters
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceStoredProcedure(string $rid_id, string $rid_col, string $rid_sproc, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'sprocs', $rid_sproc);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/sprocs/{$rid_sproc}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteStoredProcedure (MethodNotAllowed: MUST FIX)
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_sproc Resource ID of Stored Procedurea
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteStoredProcedure(string $rid_id, string $rid_col, string $rid_sproc)
    {
        $headers = $this->getAuthHeaders('DELETE', 'sprocs', $rid_sproc);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/sprocs/{$rid_sproc}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listUserDefinedFunctions
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listUserDefinedFunctions(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('GET', 'udfs', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/udfs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createUserDefinedFunction
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $json JSON of function
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createUserDefinedFunction(string $rid_id, string $rid_col, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'udfs', $rid_col);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/udfs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceUserDefinedFunction
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_udf Resource ID of User Defined Function
     * @param string $json Parameters
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceUserDefinedFunction(string $rid_id, string $rid_col, string $rid_udf, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'udfs', $rid_udf);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/udfs/{$rid_udf}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteUserDefinedFunction
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_udf Resource ID of User Defined Function
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteUserDefinedFunction(string $rid_id, string $rid_col, string $rid_udf)
    {
        $headers = $this->getAuthHeaders('DELETE', 'udfs', $rid_udf);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/udfs/{$rid_udf}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * listTriggers
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listTriggers(string $rid_id, string $rid_col)
    {
        $headers = $this->getAuthHeaders('GET', 'triggers', $rid_col);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/triggers", "GET", $headers)->getBody()->getContents();
    }

    /**
     * createTrigger
     *
     * @link http://msdn.microsoft.com/en-us/library/azure/dn803933.aspx
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $json JSON of function
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createTrigger(string $rid_id, string $rid_col, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'triggers', $rid_col);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/triggers", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * replaceTrigger
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_trigger Resource ID of Trigger
     * @param string $json Parameters
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceTrigger(string $rid_id, string $rid_col, string $rid_trigger, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'triggers', $rid_trigger);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/triggers/{$rid_trigger}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * deleteTrigger
     *
     * @link http://
     * @access public
     * @param string $rid_id Resource ID
     * @param string $rid_col Resource Collection ID
     * @param string $rid_trigger Resource ID of Trigger
     * @return string JSON response
     * @throws GuzzleException
     */
    public function deleteTrigger(string $rid_id, string $rid_col, string $rid_trigger)
    {
        $headers = $this->getAuthHeaders('DELETE', 'triggers', $rid_trigger);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$rid_id}/colls/{$rid_col}/triggers/{$rid_trigger}", "DELETE", $headers)->getBody()->getContents();
    }

}
