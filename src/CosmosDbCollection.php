<?php

namespace Phuze\PhpCosmos;

class CosmosDbCollection
{
    /** @var CosmosDb */
    private $document_db;

    /** @var string */
    private $rid_db;

    /** @var string */
    private $rid_col;

    /**
     * Create a collection object. This is usually done by
     * CosmosDbDatabase::selectCollection().
     *
     * @param CosmosDb $document_db connection to the account
     * @param string $rid_db database _rid
     * @param string $rid_col collection _rid
     */
    public function __construct(CosmosDb $document_db, string $rid_db, string $rid_col)
    {
        $this->document_db = $document_db;
        $this->rid_db = $rid_db;
        $this->rid_col = $rid_col;
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

        return $this->document_db->query($this->rid_db, $this->rid_col, $body, $isCrossPartition, $partitionValue);
    }

	/**
	 * Get this collection's partition key ranges.
	 *
	 * @return object decoded response, with the ranges in PartitionKeyRanges
	 */
	public function getPkRanges()
	{
		return $this->document_db->getPkRanges($this->rid_db, $this->rid_col);
	}

	/**
	 * Get this collection's _rid followed by the id of each partition key range,
	 * comma separated, such as z6odAJjXSto=,0,1.
	 *
	 * @return string
	 */
	public function getPkFullRange()
	{
		return $this->document_db->getPkFullRange($this->rid_db, $this->rid_col);
	}

    /**
     * Create a document.
     *
     * @param string $json the document as JSON
     * @param mixed $partitionKey partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function createDocument($json, $partitionKey = null, array $headers = [])
    {
        return $this->document_db->createDocument($this->rid_db, $this->rid_col, $json, $partitionKey, $headers);
    }

    /**
     * Replace a document.
     *
     * @param string $rid document _rid
     * @param string $json the new document as JSON
     * @param mixed $partitionKey partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function replaceDocument($rid, $json, $partitionKey = null, array $headers = [])
    {
        return $this->document_db->replaceDocument($this->rid_db, $this->rid_col, $rid, $json, $partitionKey, $headers);
    }

    /**
     * Partially update a document.
     *
     * @param string $rid document _rid
     * @param string $json patch request; ie: {"operations": [...]}
     * @param mixed $partitionKey partition key value
     * @param array $headers extra headers to send with the request
     * @return string JSON response
     */
    public function patchDocument($rid, $json, $partitionKey = null, array $headers = [])
    {
        return $this->document_db->patchDocument($this->rid_db, $this->rid_col, $rid, $json, $partitionKey, $headers);
    }

    /**
     * Delete a document.
     *
     * @param string $rid document _rid
     * @param mixed $partitionKey partition key value
     * @param array $headers extra headers to send with the request
     * @return string empty on success
     */
    public function deleteDocument($rid, $partitionKey = null, array $headers = [])
    {
        return $this->document_db->deleteDocument($this->rid_db, $this->rid_col, $rid, $partitionKey, $headers);
    }

    /*
      public function createUser($json)
      {
        return $this->document_db->createUser($this->rid_db, $json);
      }

      public function listUsers()
      {
        return $this->document_db->listUsers($this->rid_db, $rid);
      }

      public function deletePermission($uid, $pid)
      {
        return $this->document_db->deletePermission($this->rid_db, $uid, $pid);
      }

      public function listPermissions($uid)
      {
        return $this->document_db->listPermissions($this->rid_db, $uid);
      }

      public function getPermission($uid, $pid)
      {
        return $this->document_db->getPermission($this->rid_db, $uid, $pid);
      }
    */
    
    /**
     * List the stored procedures in this collection.
     *
     * @return string JSON response
     */
    public function listStoredProcedures()
    {
        return $this->document_db->listStoredProcedures($this->rid_db, $this->rid_col);
    }

    /**
     * Run a stored procedure.
     *
     * @param string $sproc_name stored procedure _rid
     * @param string $json input parameters, as a JSON array; ie: ["Canada", 30]
     * @return string JSON response
     */
    public function executeStoredProcedure($sproc_name, $json)
    {
        return $this->document_db->executeStoredProcedure($this->rid_db, $this->rid_col, $sproc_name, $json);
    }

    /**
     * Create a stored procedure in this collection.
     *
     * @param string $json stored procedure definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     */
    public function createStoredProcedure($json)
    {
        return $this->document_db->createStoredProcedure($this->rid_db, $this->rid_col, $json);
    }

    /**
     * Replace a stored procedure.
     *
     * @param string $sproc_name stored procedure _rid
     * @param string $json new stored procedure definition
     * @return string JSON response
     */
    public function replaceStoredProcedure($sproc_name, $json)
    {
        return $this->document_db->replaceStoredProcedure($this->rid_db, $this->rid_col, $sproc_name, $json);
    }

    /**
     * Delete a stored procedure.
     *
     * @param string $sproc_name stored procedure _rid
     * @return string empty on success
     */
    public function deleteStoredProcedure($sproc_name)
    {
        return $this->document_db->deleteStoredProcedure($this->rid_db, $this->rid_col, $sproc_name);
    }

    /**
     * List the user-defined functions in this collection.
     *
     * @return string JSON response
     */
    public function listUserDefinedFunctions()
    {
        return $this->document_db->listUserDefinedFunctions($this->rid_db, $this->rid_col);
    }

    /**
     * Create a user-defined function in this collection.
     *
     * @param string $json function definition; ie: {"id": "...", "body": "function () { ... }"}
     * @return string JSON response
     */
    public function createUserDefinedFunction($json)
    {
        return $this->document_db->createUserDefinedFunction($this->rid_db, $this->rid_col, $json);
    }

    /**
     * Replace a user-defined function.
     *
     * @param string $udf user-defined function _rid
     * @param string $json new function definition
     * @return string JSON response
     */
    public function replaceUserDefinedFunction($udf, $json)
    {
        return $this->document_db->replaceUserDefinedFunction($this->rid_db, $this->rid_col, $udf, $json);
    }

    /**
     * Delete a user-defined function.
     *
     * @param string $udf user-defined function _rid
     * @return string empty on success
     */
    public function deleteUserDefinedFunction($udf)
    {
        return $this->document_db->deleteUserDefinedFunction($this->rid_db, $this->rid_col, $udf);
    }

    /**
     * List the triggers in this collection.
     *
     * @return string JSON response
     */
    public function listTriggers()
    {
        return $this->document_db->listTriggers($this->rid_db, $this->rid_col);
    }

    /**
     * Create a trigger in this collection.
     *
     * @param string $json trigger definition; ie: {"id": "...", "body": "function () { ... }", "triggerType": "Pre", "triggerOperation": "All"}
     * @return string JSON response
     */
    public function createTrigger($json)
    {
        return $this->document_db->createTrigger($this->rid_db, $this->rid_col, $json);
    }

    /**
     * Replace a trigger.
     *
     * @param string $trigger trigger _rid
     * @param string $json new trigger definition
     * @return string JSON response
     */
    public function replaceTrigger($trigger, $json)
    {
        return $this->document_db->replaceTrigger($this->rid_db, $this->rid_col, $trigger, $json);
    }

    /**
     * Delete a trigger.
     *
     * @param string $trigger trigger _rid
     * @return string empty on success
     */
    public function deleteTrigger($trigger)
    {
        return $this->document_db->deleteTrigger($this->rid_db, $this->rid_col, $trigger);
    }

}
