<?php

namespace Phuze\PhpCosmos;

class CosmosDbDatabase
{
    private $document_db;
    private $rid_db;

    public function __construct($document_db, $rid_db)
    {
        $this->document_db = $document_db;
        $this->rid_db = $rid_db;
    }

    /**
     * selectCollection
     *
     * @access public
     * @param string $col_name Collection name
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
            $col_body["id"] = $col_name;
            if ($partitionKey) {
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
