<?php

namespace Phuze\PhpCosmos;

use \GuzzleHttp\Client;
use \GuzzleHttp\Exception\GuzzleException;
use \GuzzleHttp\Exception\ClientException;
use \GuzzleHttp\Exception\TransferException;
use \Psr\Http\Message\ResponseInterface;
use \Psr\Log\LoggerInterface;

class CosmosDb
{
    const VERSION = '4.0.1';

    /** @var string */
    private $host;

    /** @var string */
    private $privateKey;

    /** @var Client|null */
    private $httpClient = null;

    /** @var LoggerInterface|null */
    private $logger = null;

    /** @var array partition key ranges, cached by database and collection _rid */
    private $pkRanges = [];

    /** @var int */
    private $maxThrottleRetries = 0;

    /** @var int */
    private $maxThrottleWaitMs = 0;

    /** @var array guzzle options, set with setHttpClientOptions() */
    public $httpClientOptions = [];

    /** @var bool print each request and its response */
    public $debug = false;

    /**
     * @param string $host account endpoint; ie: https://myaccount.documents.azure.com
     * @param string $privateKey the account's primary or secondary key
     */
    public function __construct(string $host, string $privateKey)
    {
        $this->host = $host;
        $this->privateKey = $privateKey;
    }

    /**
     * Set the Guzzle HTTP client options using an associative array.
     * These are merged over the defaults (60s timeout, 5s connect timeout).
     *
     * @param array $options
     * @return void
     */
    public function setHttpClientOptions(array $options = [])
    {
        $this->httpClientOptions = $options;

        # rebuild the client on the next request so the new options apply
        $this->httpClient = null;
    }

    /**
     * Retry requests rejected with 429 (rate limited). This is off by default,
     * so a 429 is thrown to the caller. Each retry waits for the time Cosmos DB
     * asks for in the x-ms-retry-after-ms header. The defaults match Microsoft's
     * own SDKs.
     *
     * @param int $maxRetries maximum retries per request. 0 disables retrying.
     * @param int $maxWaitMs maximum total time to wait across retries of one request, in milliseconds.
     * @return void
     */
    public function setRetryOptions(int $maxRetries = 9, int $maxWaitMs = 30000)
    {
        $this->maxThrottleRetries = $maxRetries;
        $this->maxThrottleWaitMs = $maxWaitMs;
    }

    /**
     * Set a PSR-3 logger. Every request is logged at debug level with its
     * status, duration, request charge and activity id. Retries are logged
     * at warning level.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get the Guzzle client. It's created once and reused, so requests share
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
     * Get the cURL options that keep pooled connections alive. Azure drops
     * connections that sit idle for about 4 minutes, and TCP keep-alive probes
     * stop a pooled connection from going stale between requests.
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
     * Build the headers every request needs, including an authorization token
     * signed with the account key.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/access-control-on-cosmosdb-resources
     * @param string $verb request method; ie: GET, POST, PUT, PATCH or DELETE
     * @param string $resourceType resource type; ie: dbs, colls or docs. empty for the account itself
     * @param string $resourceId _rid of the resource, or of its parent when creating, listing or querying. empty at the account level
     * @return array request headers
     */
    private function getAuthHeaders(string $verb, string $resourceType, string $resourceId)
    {
        $date = gmdate('D, d M Y H:i:s T', strtotime('+2 minutes'));
        $tokenType = 'master';
        $tokenVersion = '1.0';
        $apiVersion = '2018-12-31';

        $key = base64_decode($this->privateKey);
        $stringToSign = $verb . "\n" .
            $resourceType . "\n" .
            $resourceId . "\n" .
            $date . "\n" .
            "\n";

        # every request addresses its resource by _rid, and a _rid is signed
        # lowercased, so the whole string can be. a name-based link, such as
        # dbs/MyDb, would have to keep its case
        $sig = base64_encode(hash_hmac('sha256', strtolower($stringToSign), $key, true));

        return [
            'Accept' => 'application/json',
            'User-Agent' => 'cosmos.php.sdk/' . self::VERSION,
            'Cache-Control' => 'no-cache',
            'x-ms-date' => $date,
            'x-ms-version' => $apiVersion,
            'authorization' => urlencode("type={$tokenType}&ver={$tokenVersion}&sig={$sig}")
        ];
    }

    /**
     * Send a request to Cosmos DB. A network error with no response, such as a
     * pooled connection that went stale, is retried once. A 429 (rate limited)
     * response is retried only if enabled with setRetryOptions().
     *
     * @param string $path request path, relative to the host
     * @param string $method request method
     * @param array $headers request headers
     * @param string|null $body request body
     * @param bool $retryNetworkErrors false for requests that aren't safe to send twice
     * @return ResponseInterface
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
                # guzzle 6, 7 and 8 all throw subclasses of TransferException, but
                # which of them can carry a response differs between versions
                $error = $e;
                $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
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
     * Get how long to wait before retrying a 429 (rate limited) response, using
     * the x-ms-retry-after-ms header. Returns null if it isn't a 429, or if the
     * retry limits are reached.
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
     * Read a response body without consuming it, so it can still be read afterwards.
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
     * Decode the error body of a 4xx response.
     *
     * @param ClientException $e
     * @return object|null
     */
    private function decodeError(ClientException $e)
    {
        return json_decode($this->readBody($e->getResponse()));
    }

    /**
     * Log one request attempt at debug level.
     *
     * @param string $method
     * @param string $path
     * @param float $start microtime the attempt started
     * @param ResponseInterface|null $response
     * @param \Exception|null $error
     * @return void
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
     * Log a message, if a logger has been set.
     *
     * @param string $level PSR-3 log level
     * @param string $message
     * @param array $context
     * @return void
     */
    private function log(string $level, string $message, array $context = [])
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Format a partition key value for the x-ms-documentdb-partitionkey header.
     * JSON encoding keeps numbers and booleans typed, and escapes quotes in strings.
     *
     * @param mixed $value
     * @return string
     */
    private function getPartitionKeyHeader($value)
    {
        return json_encode([$value]);
    }

    /**
     * Select a database by name, creating it if it doesn't exist.
     *
     * @param string $dbName database name
     * @return CosmosDbDatabase|null
     * @throws GuzzleException
     */
    public function selectDB(string $dbName)
    {
        $dbRid = false;
        $object = json_decode($this->listDatabases());

        $dbList = $object->Databases;
        for ($i = 0; $i < count($dbList); $i++) {
            if ($dbList[$i]->id === $dbName) {
                $dbRid = $dbList[$i]->_rid;
            }
        }
        if (!$dbRid) {
            $object = json_decode($this->createDatabase(json_encode(['id' => $dbName])));
            $dbRid = $object->_rid;
        }

        return $dbRid ? new CosmosDbDatabase($this, $dbRid) : null;
    }

    /**
     * Get the account's details, such as its regions and consistency policy.
     *
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
     * Run a query against a collection and return every page of results. A
     * cross-partition query the gateway can't serve is run against each
     * partition key range instead.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/query-documents
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $query JSON query body; ie: {"query": "SELECT * FROM c", "parameters": []}
     * @param bool $isCrossPartition query across partitions
     * @param mixed $partitionValue partition key value, to query a single partition
     * @return string[] JSON response for each page
     * @throws GuzzleException
     */
    public function query(string $dbRid, string $collRid, string $query, bool $isCrossPartition = false, $partitionValue = null)
    {
        $headers = $this->getAuthHeaders('POST', 'docs', $collRid);
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
            return $this->getQueryResults($dbRid, $collRid, $query, $headers);
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

        return $this->queryEachPkRange($dbRid, $collRid, $query, $headers);
    }

    /**
     * Run a query against each partition key range in turn. The results of each
     * range are separate, so an ORDER BY or TOP applies within each range only.
     *
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $query JSON query body
     * @param array $headers request headers
     * @return string[] JSON response for each page
     * @throws GuzzleException
     */
    private function queryEachPkRange(string $dbRid, string $collRid, string $query, array $headers)
    {
        $refreshed = false;

        while (true) {
            try {
                $results = [];
                foreach ($this->getPkRanges($dbRid, $collRid)->PartitionKeyRanges as $range) {
                    $headers['x-ms-documentdb-partitionkeyrangeid'] = $range->id;
                    $results = array_merge($results, $this->getQueryResults($dbRid, $collRid, $query, $headers));
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
                unset($this->pkRanges[$dbRid][$collRid]);
                $this->log('info', 'Cosmos DB partition key ranges changed, refreshing');
            }
        }
    }

    /**
     * Run a query and return every page of results.
     *
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $query JSON query body
     * @param array $headers request headers
     * @return string[] JSON response for each page
     * @throws GuzzleException
     */
    private function getQueryResults(string $dbRid, string $collRid, string $query, array $headers)
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
            $result = $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs", "POST", $headers, $query);
            $results[] = $result->getBody()->getContents();
            $continuation = $result->getHeaderLine('x-ms-continuation');
            $headers['x-ms-continuation'] = $continuation;
        } while ($continuation !== '');

        return $results;
    }

    /**
     * Get a collection's partition key ranges. They're cached for the life of this object.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-partition-key-ranges
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return object decoded response, with the ranges in PartitionKeyRanges
     * @throws GuzzleException
     */
    public function getPkRanges(string $dbRid, string $collRid)
    {
        if (!isset($this->pkRanges[$dbRid][$collRid])) {
            $headers = $this->getAuthHeaders('GET', 'pkranges', $collRid);
            $headers['Accept'] = 'application/json';
            $headers['x-ms-max-item-count'] = -1;
            $result = $this->request("/dbs/{$dbRid}/colls/{$collRid}/pkranges", "GET", $headers);
            $this->pkRanges[$dbRid][$collRid] = json_decode($result->getBody()->getContents());
        }

        return $this->pkRanges[$dbRid][$collRid];
    }

    /**
     * Get the collection _rid followed by the id of each partition key range,
     * comma separated, such as z6odAJjXSto=,0,1.
     *
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string
     * @throws GuzzleException
     */
	public function getPkFullRange($dbRid, $collRid)
    {
		$result = $this->getPkRanges($dbRid, $collRid);
		$ids = array_column($result->PartitionKeyRanges, "id");
		return $result->_rid . "," . implode(",", $ids);
	}

    /**
     * List the databases in the account.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-databases
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
     * Get a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-a-database
     * @param string $dbRid database _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getDatabase(string $dbRid)
    {
        $headers = $this->getAuthHeaders('GET', 'dbs', $dbRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-database
     * @param string $json database definition; ie: {"id": "mydb"}
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
     * Replace a database.
     *
     * @param string $dbRid database _rid
     * @param string $json new database definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceDatabase(string $dbRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'dbs', $dbRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a database, and everything in it.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-database
     * @param string $dbRid database _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteDatabase(string $dbRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'dbs', $dbRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the users in a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-users
     * @param string $dbRid database _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listUsers(string $dbRid)
    {
        $headers = $this->getAuthHeaders('GET', 'users', $dbRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Get a user.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-a-user
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getUser(string $dbRid, string $userRid)
    {
        $headers = $this->getAuthHeaders('GET', 'users', $userRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users/{$userRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a user in a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-user
     * @param string $dbRid database _rid
     * @param string $json user definition; ie: {"id": "someone"}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createUser(string $dbRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'users', $dbRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/users", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Replace a user.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-user
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @param string $json new user definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceUser(string $dbRid, string $userRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'users', $userRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/users/{$userRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a user.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-user
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteUser(string $dbRid, string $userRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'users', $userRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users/{$userRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the collections in a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-collections
     * @param string $dbRid database _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listCollections(string $dbRid)
    {
        $headers = $this->getAuthHeaders('GET', 'colls', $dbRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Get a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-a-collection
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getCollection(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('GET', 'colls', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a collection in a database.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-collection
     * @param string $dbRid database _rid
     * @param string $json collection definition; ie: {"id": "Users", "partitionKey": {"paths": ["/country"], "kind": "Hash"}}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createCollection(string $dbRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'colls', $dbRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a collection, and every document in it.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-collection
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteCollection(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'colls', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the documents in a collection. Only the first page of results is returned.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-documents
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listDocuments(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('GET', 'docs', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Get a document.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getDocument(string $dbRid, string $collRid, string $docRid, $partitionValue = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('GET', 'docs', $docRid);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = '0';
        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a document in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $json the document as JSON
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createDocument(string $dbRid, string $collRid, string $json, $partitionValue = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('POST', 'docs', $collRid);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Create a document, or replace the one with the same id and partition
     * key value.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $json the document as JSON
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function upsertDocument(string $dbRid, string $collRid, string $json, $partitionValue = null, array $headers = [])
    {
        $headers['x-ms-documentdb-is-upsert'] = 'True';
        return $this->createDocument($dbRid, $collRid, $json, $partitionValue, $headers);
    }

    /**
     * Replace a document.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $json the new document as JSON
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceDocument(string $dbRid, string $collRid, string $docRid, string $json, $partitionValue = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('PUT', 'docs', $docRid);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Partially update a document. It isn't retried on a network error, because
     * operations such as incr aren't safe to apply twice.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/patch-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $json patch request; ie: {"operations": [...]}
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     * @throws GuzzleException
     */
    public function patchDocument(string $dbRid, string $collRid, string $docRid, string $json, $partitionValue = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('PATCH', 'docs', $docRid);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = strlen($json);
        $headers['Content-Type'] = 'application/json_patch+json';
        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}", "PATCH", $headers, $json, false)->getBody()->getContents();
    }

    /**
     * Delete a document.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-document
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteDocument(string $dbRid, string $collRid, string $docRid, $partitionValue = null, array $headers = [])
    {
        $authHeaders = $this->getAuthHeaders('DELETE', 'docs', $docRid);
        $headers = array_merge($headers, $authHeaders);
        $headers['Content-Length'] = '0';
        if ($partitionValue !== null) {
            $headers['x-ms-documentdb-partitionkey'] = $this->getPartitionKeyHeader($partitionValue);
        }

        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List a document's attachments.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-attachments
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listAttachments(string $dbRid, string $collRid, string $docRid)
    {
        $headers = $this->getAuthHeaders('GET', 'attachments', $docRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}/attachments", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Get an attachment.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/attachments
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $attachmentRid attachment _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getAttachment(string $dbRid, string $collRid, string $docRid, string $attachmentRid)
    {
        $headers = $this->getAuthHeaders('GET', 'attachments', $attachmentRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}/attachments/{$attachmentRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create an attachment by uploading raw media to a document.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-an-attachment
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $contentType media type; ie: image/png
     * @param string $filename file name, sent in the Slug header
     * @param string $file raw media
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createAttachment(string $dbRid, string $collRid, string $docRid, string $contentType, string $filename, string $file)
    {
        $headers = $this->getAuthHeaders('POST', 'attachments', $docRid);
        $headers['Content-Length'] = strlen($file);
        $headers['Content-Type'] = $contentType;
        $headers['Slug'] = urlencode($filename);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}/attachments", "POST", $headers, $file)->getBody()->getContents();
    }

    /**
     * Replace an attachment.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-an-attachment
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $attachmentRid attachment _rid
     * @param string $contentType media type; ie: image/png
     * @param string $filename file name, sent in the Slug header
     * @param string $file raw media
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceAttachment(string $dbRid, string $collRid, string $docRid, string $attachmentRid, string $contentType, string $filename, string $file)
    {
        $headers = $this->getAuthHeaders('PUT', 'attachments', $attachmentRid);
        $headers['Content-Length'] = strlen($file);
        $headers['Content-Type'] = $contentType;
        $headers['Slug'] = urlencode($filename);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}/attachments/{$attachmentRid}", "PUT", $headers, $file)->getBody()->getContents();
    }

    /**
     * Delete an attachment.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-attachments
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $docRid document _rid
     * @param string $attachmentRid attachment _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteAttachment(string $dbRid, string $collRid, string $docRid, string $attachmentRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'attachments', $attachmentRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/docs/{$docRid}/attachments/{$attachmentRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the offers in the account. An offer holds the provisioned throughput
     * of a database or collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-offers
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
     * Get an offer.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-an-offer
     * @param string $offerRid offer _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getOffer(string $offerRid)
    {
        $headers = $this->getAuthHeaders('GET', 'offers', $offerRid);
        $headers['Content-Length'] = '0';
        return $this->request("/offers/{$offerRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Replace an offer, for example to change a collection's throughput.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-an-offer
     * @param string $offerRid offer _rid
     * @param string $json new offer definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceOffer(string $offerRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'offers', $offerRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/offers/{$offerRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Query the offers in the account.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/querying-offers
     * @param string $json JSON query body; ie: {"query": "SELECT * FROM root", "parameters": []}
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
     * List a user's permissions.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-permissions
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listPermissions(string $dbRid, string $userRid)
    {
        $headers = $this->getAuthHeaders('GET', 'permissions', $userRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users/{$userRid}/permissions", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a permission for a user.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-permission
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @param string $json permission definition; ie: {"id": "...", "permissionMode": "Read", "resource": "..."}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createPermission(string $dbRid, string $userRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'permissions', $userRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/users/{$userRid}/permissions", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Get a permission.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/get-a-permission
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @param string $permissionRid permission _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function getPermission(string $dbRid, string $userRid, string $permissionRid)
    {
        $headers = $this->getAuthHeaders('GET', 'permissions', $permissionRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users/{$userRid}/permissions/{$permissionRid}", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Replace a permission.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-permission
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @param string $permissionRid permission _rid
     * @param string $json new permission definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replacePermission(string $dbRid, string $userRid, string $permissionRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'permissions', $permissionRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/users/{$userRid}/permissions/{$permissionRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a permission.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-permission
     * @param string $dbRid database _rid
     * @param string $userRid user _rid
     * @param string $permissionRid permission _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deletePermission(string $dbRid, string $userRid, string $permissionRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'permissions', $permissionRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/users/{$userRid}/permissions/{$permissionRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the stored procedures in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-stored-procedures
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listStoredProcedures(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('GET', 'sprocs', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/sprocs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Run a stored procedure. It isn't retried on a network error, because a
     * stored procedure isn't necessarily safe to run twice.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/execute-a-stored-procedure
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $sprocRid stored procedure _rid
     * @param string $json input parameters, as a JSON array; ie: ["Canada", 30]
     * @return string JSON response
     * @throws GuzzleException
     */
    public function executeStoredProcedure(string $dbRid, string $collRid, string $sprocRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'sprocs', $sprocRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/sprocs/{$sprocRid}", "POST", $headers, $json, false)->getBody()->getContents();
    }

    /**
     * Create a stored procedure in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-stored-procedure
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $json stored procedure definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createStoredProcedure(string $dbRid, string $collRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'sprocs', $collRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/sprocs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Replace a stored procedure.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-stored-procedure
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $sprocRid stored procedure _rid
     * @param string $json new stored procedure definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceStoredProcedure(string $dbRid, string $collRid, string $sprocRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'sprocs', $sprocRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/sprocs/{$sprocRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a stored procedure.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-stored-procedure
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $sprocRid stored procedure _rid
     * @return string empty on success
     * @throws GuzzleException
     * @todo check whether this still fails with 405 (MethodNotAllowed)
     */
    public function deleteStoredProcedure(string $dbRid, string $collRid, string $sprocRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'sprocs', $sprocRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/sprocs/{$sprocRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the user-defined functions in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-user-defined-functions
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listUserDefinedFunctions(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('GET', 'udfs', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/udfs", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a user-defined function in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-user-defined-function
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $json function definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createUserDefinedFunction(string $dbRid, string $collRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'udfs', $collRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/udfs", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Replace a user-defined function.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-user-defined-function
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $udfRid user-defined function _rid
     * @param string $json new function definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceUserDefinedFunction(string $dbRid, string $collRid, string $udfRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'udfs', $udfRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/udfs/{$udfRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a user-defined function.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-user-defined-function
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $udfRid user-defined function _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteUserDefinedFunction(string $dbRid, string $collRid, string $udfRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'udfs', $udfRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/udfs/{$udfRid}", "DELETE", $headers)->getBody()->getContents();
    }

    /**
     * List the triggers in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/list-triggers
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @return string JSON response
     * @throws GuzzleException
     */
    public function listTriggers(string $dbRid, string $collRid)
    {
        $headers = $this->getAuthHeaders('GET', 'triggers', $collRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/triggers", "GET", $headers)->getBody()->getContents();
    }

    /**
     * Create a trigger in a collection.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/create-a-trigger
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $json trigger definition; ie: {"id": "...", "body": "function () { ... }", "triggerType": "Pre", "triggerOperation": "All"}
     * @return string JSON response
     * @throws GuzzleException
     */
    public function createTrigger(string $dbRid, string $collRid, string $json)
    {
        $headers = $this->getAuthHeaders('POST', 'triggers', $collRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/triggers", "POST", $headers, $json)->getBody()->getContents();
    }

    /**
     * Replace a trigger.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/replace-a-trigger
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $triggerRid trigger _rid
     * @param string $json new trigger definition
     * @return string JSON response
     * @throws GuzzleException
     */
    public function replaceTrigger(string $dbRid, string $collRid, string $triggerRid, string $json)
    {
        $headers = $this->getAuthHeaders('PUT', 'triggers', $triggerRid);
        $headers['Content-Length'] = strlen($json);
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/triggers/{$triggerRid}", "PUT", $headers, $json)->getBody()->getContents();
    }

    /**
     * Delete a trigger.
     *
     * @link https://learn.microsoft.com/en-us/rest/api/cosmos-db/delete-a-trigger
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     * @param string $triggerRid trigger _rid
     * @return string empty on success
     * @throws GuzzleException
     */
    public function deleteTrigger(string $dbRid, string $collRid, string $triggerRid)
    {
        $headers = $this->getAuthHeaders('DELETE', 'triggers', $triggerRid);
        $headers['Content-Length'] = '0';
        return $this->request("/dbs/{$dbRid}/colls/{$collRid}/triggers/{$triggerRid}", "DELETE", $headers)->getBody()->getContents();
    }

}
