<?php

namespace Phuze\PhpCosmos;

class CosmosDbCollection
{
    /** @var CosmosDb */
    private $connection;

    /** @var string */
    private $dbRid;

    /** @var string */
    private $collRid;

    /**
     * Create a collection object. This is usually done by
     * CosmosDbDatabase::selectCollection().
     *
     * @param CosmosDb $connection connection to the account
     * @param string $dbRid database _rid
     * @param string $collRid collection _rid
     */
    public function __construct(CosmosDb $connection, string $dbRid, string $collRid)
    {
        $this->connection = $connection;
        $this->dbRid = $dbRid;
        $this->collRid = $collRid;
    }

    /**
     * Run a query against this collection and return every page of results.
     *
     * @param string $query SQL query; ie: SELECT * FROM c WHERE c.age > @age
     * @param array $params query parameters; ie: ['@age' => 30]
     * @param bool $isCrossPartition query across partitions
     * @param mixed $partitionValue partition key value, to query a single partition
     * @return string[] JSON response for each page
     * @throws \InvalidArgumentException if the query or a parameter can't be encoded as JSON
     */
    public function query($query, $params = [], $isCrossPartition = false, $partitionValue = null)
    {
        # parameter values keep their JSON type, so true, false, null,
        # numbers and arrays reach cosmos as themselves
        $parameters = [];
        foreach ($params as $name => $value) {
            $parameters[] = ['name' => (string)$name, 'value' => $value];
        }

        $body = json_encode(['query' => $query, 'parameters' => $parameters]);
        if ($body === false) {
            throw new \InvalidArgumentException('Unable to encode query as JSON: ' . json_last_error_msg());
        }

        return $this->connection->query($this->dbRid, $this->collRid, $body, $isCrossPartition, $partitionValue);
    }

	/**
	 * Get this collection's partition key ranges.
	 *
	 * @return object decoded response, with the ranges in PartitionKeyRanges
	 */
	public function getPkRanges()
	{
		return $this->connection->getPkRanges($this->dbRid, $this->collRid);
	}

	/**
	 * Get this collection's _rid followed by the id of each partition key range,
	 * comma separated, such as z6odAJjXSto=,0,1.
	 *
	 * @return string
	 */
	public function getPkFullRange()
	{
		return $this->connection->getPkFullRange($this->dbRid, $this->collRid);
	}

    /**
     * Create a document.
     *
     * @param string $json the document as JSON
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function createDocument($json, $partitionValue = null, array $headers = [])
    {
        return $this->connection->createDocument($this->dbRid, $this->collRid, $json, $partitionValue, $headers);
    }

    /**
     * Replace a document.
     *
     * @param string $docRid document _rid
     * @param string $json the new document as JSON
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function replaceDocument($docRid, $json, $partitionValue = null, array $headers = [])
    {
        return $this->connection->replaceDocument($this->dbRid, $this->collRid, $docRid, $json, $partitionValue, $headers);
    }

    /**
     * Partially update a document.
     *
     * @param string $docRid document _rid
     * @param string $json patch request; ie: {"operations": [...]}
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function patchDocument($docRid, $json, $partitionValue = null, array $headers = [])
    {
        return $this->connection->patchDocument($this->dbRid, $this->collRid, $docRid, $json, $partitionValue, $headers);
    }

    /**
     * Delete a document.
     *
     * @param string $docRid document _rid
     * @param mixed $partitionValue partition key value
     * @param array $headers extra headers to send with the request
     * @return string empty on success
     */
    public function deleteDocument($docRid, $partitionValue = null, array $headers = [])
    {
        return $this->connection->deleteDocument($this->dbRid, $this->collRid, $docRid, $partitionValue, $headers);
    }

    /*
      public function createUser($json)
      {
        return $this->connection->createUser($this->dbRid, $json);
      }

      public function listUsers()
      {
        return $this->connection->listUsers($this->dbRid, $rid);
      }

      public function deletePermission($uid, $pid)
      {
        return $this->connection->deletePermission($this->dbRid, $uid, $pid);
      }

      public function listPermissions($uid)
      {
        return $this->connection->listPermissions($this->dbRid, $uid);
      }

      public function getPermission($uid, $pid)
      {
        return $this->connection->getPermission($this->dbRid, $uid, $pid);
      }
    */
    
    /**
     * List the stored procedures in this collection.
     *
     * @return string JSON response
     */
    public function listStoredProcedures()
    {
        return $this->connection->listStoredProcedures($this->dbRid, $this->collRid);
    }

    /**
     * Run a stored procedure.
     *
     * @param string $sprocRid stored procedure _rid
     * @param string $json input parameters, as a JSON array; ie: ["Canada", 30]
     * @return string JSON response
     */
    public function executeStoredProcedure($sprocRid, $json)
    {
        return $this->connection->executeStoredProcedure($this->dbRid, $this->collRid, $sprocRid, $json);
    }

    /**
     * Create a stored procedure in this collection.
     *
     * @param string $json stored procedure definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     */
    public function createStoredProcedure($json)
    {
        return $this->connection->createStoredProcedure($this->dbRid, $this->collRid, $json);
    }

    /**
     * Replace a stored procedure.
     *
     * @param string $sprocRid stored procedure _rid
     * @param string $json new stored procedure definition
     * @return string JSON response
     */
    public function replaceStoredProcedure($sprocRid, $json)
    {
        return $this->connection->replaceStoredProcedure($this->dbRid, $this->collRid, $sprocRid, $json);
    }

    /**
     * Delete a stored procedure.
     *
     * @param string $sprocRid stored procedure _rid
     * @return string empty on success
     */
    public function deleteStoredProcedure($sprocRid)
    {
        return $this->connection->deleteStoredProcedure($this->dbRid, $this->collRid, $sprocRid);
    }

    /**
     * List the user-defined functions in this collection.
     *
     * @return string JSON response
     */
    public function listUserDefinedFunctions()
    {
        return $this->connection->listUserDefinedFunctions($this->dbRid, $this->collRid);
    }

    /**
     * Create a user-defined function in this collection.
     *
     * @param string $json function definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     */
    public function createUserDefinedFunction($json)
    {
        return $this->connection->createUserDefinedFunction($this->dbRid, $this->collRid, $json);
    }

    /**
     * Replace a user-defined function.
     *
     * @param string $udfRid user-defined function _rid
     * @param string $json new function definition
     * @return string JSON response
     */
    public function replaceUserDefinedFunction($udfRid, $json)
    {
        return $this->connection->replaceUserDefinedFunction($this->dbRid, $this->collRid, $udfRid, $json);
    }

    /**
     * Delete a user-defined function.
     *
     * @param string $udfRid user-defined function _rid
     * @return string empty on success
     */
    public function deleteUserDefinedFunction($udfRid)
    {
        return $this->connection->deleteUserDefinedFunction($this->dbRid, $this->collRid, $udfRid);
    }

    /**
     * List the triggers in this collection.
     *
     * @return string JSON response
     */
    public function listTriggers()
    {
        return $this->connection->listTriggers($this->dbRid, $this->collRid);
    }

    /**
     * Create a trigger in this collection.
     *
     * @param string $json trigger definition; ie: {"id": "...", "body": "function () { ... }", "triggerType": "Pre", "triggerOperation": "All"}
     * @return string JSON response
     */
    public function createTrigger($json)
    {
        return $this->connection->createTrigger($this->dbRid, $this->collRid, $json);
    }

    /**
     * Replace a trigger.
     *
     * @param string $triggerRid trigger _rid
     * @param string $json new trigger definition
     * @return string JSON response
     */
    public function replaceTrigger($triggerRid, $json)
    {
        return $this->connection->replaceTrigger($this->dbRid, $this->collRid, $triggerRid, $json);
    }

    /**
     * Delete a trigger.
     *
     * @param string $triggerRid trigger _rid
     * @return string empty on success
     */
    public function deleteTrigger($triggerRid)
    {
        return $this->connection->deleteTrigger($this->dbRid, $this->collRid, $triggerRid);
    }

}
