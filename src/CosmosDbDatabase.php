<?php

namespace Phuze\PhpCosmos;

class CosmosDbDatabase
{
    /** @var CosmosDb */
    private $connection;

    /** @var string */
    private $dbRid;

    /**
     * Create a database object. This is usually done by CosmosDb::selectDB().
     *
     * @param CosmosDb $connection connection to the account
     * @param string $dbRid database _rid
     */
    public function __construct($connection, $dbRid)
    {
        $this->connection = $connection;
        $this->dbRid = $dbRid;
    }

    /**
     * Select a collection by name, creating it if it doesn't exist.
     *
     * @param string $collName collection name
     * @param string|null $partitionKey partition key path used if the collection is created; ie: "/country" or "billing.country"
     * @return CosmosDbCollection|false
     */
    public function selectCollection($collName, $partitionKey = null)
    {
        $collRid = false;
        $object = json_decode($this->connection->listCollections($this->dbRid));
        $collList = $object->DocumentCollections;
        for ($i = 0; $i < count($collList); $i++) {
            if ($collList[$i]->id === $collName) {
                $collRid = $collList[$i]->_rid;
            }
        }
        if (!$collRid) {
            $collDefinition = ["id" => $collName];
            if ($partitionKey) {
                # cosmos requires a path starting with a slash, so a key without
                # one (ie: "country" or "billing.country") was always rejected.
                # convert it to path form; ie: "/country" or "/billing/country"
                if (strpos($partitionKey, '/') !== 0) {
                    $partitionKey = '/' . str_replace('.', '/', $partitionKey);
                }
                $collDefinition["partitionKey"] = [
                    "paths" => [$partitionKey],
                    "kind" => "Hash"
                ];
            }
            $object = json_decode($this->connection->createCollection($this->dbRid, json_encode($collDefinition)));
            $collRid = $object->_rid;
        }
        if ($collRid) {
            return new CosmosDbCollection($this->connection, $this->dbRid, $collRid);
        } else {
            return false;
        }
    }

}
