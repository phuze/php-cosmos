<?php

namespace Phuze\PhpCosmos;

class CosmosDbDatabase
{
    /** @var CosmosDb */
    private $document_db;

    /** @var string */
    private $rid_db;

    /**
     * Create a database object. This is usually done by CosmosDb::selectDB().
     *
     * @param CosmosDb $document_db connection to the account
     * @param string $rid_db database _rid
     */
    public function __construct($document_db, $rid_db)
    {
        $this->document_db = $document_db;
        $this->rid_db = $rid_db;
    }

    /**
     * Select a collection by name, creating it if it doesn't exist.
     *
     * @param string $col_name collection name
     * @param string|null $partitionKey partition key path used if the collection is created; ie: "/country" or "billing.country"
     * @return CosmosDbCollection|false
     */
    public function selectCollection($col_name, $partitionKey = null)
    {
        $rid_col = false;
        $object = json_decode($this->document_db->listCollections($this->rid_db));
        $col_list = $object->DocumentCollections;
        for ($i = 0; $i < count($col_list); $i++) {
            if ($col_list[$i]->id === $col_name) {
                $rid_col = $col_list[$i]->_rid;
            }
        }
        if (!$rid_col) {
            $col_body = ["id" => $col_name];
            if ($partitionKey) {
                # cosmos requires a path starting with a slash, so a key without
                # one (ie: "country" or "billing.country") was always rejected.
                # convert it to path form; ie: "/country" or "/billing/country"
                if (strpos($partitionKey, '/') !== 0) {
                    $partitionKey = '/' . str_replace('.', '/', $partitionKey);
                }
                $col_body["partitionKey"] = [
                    "paths" => [$partitionKey],
                    "kind" => "Hash"
                ];
            }
            $object = json_decode($this->document_db->createCollection($this->rid_db, json_encode($col_body)));
            $rid_col = $object->_rid;
        }
        if ($rid_col) {
            return new CosmosDbCollection($this->document_db, $this->rid_db, $rid_col);
        } else {
            return false;
        }
    }

}
