<?php

namespace Phuze\PhpCosmos;

use \Exception;

class QueryBuilder
{
    private $collection = "";
    private $partitionKey = null;
    private $partitionValue = null;
    private $queryString = "";
    private $fields = "";
    private $from = "c";
    private $join = "";
    private $where = "";
    private $order = null;
    private $limit = null;
    private $triggers = [];
    private $params = [];
    private $response = null;
    private $multipleResults = false;

    /**
     * Initializes the Table.
     *
     * @return static
     */
    public static function instance()
    {
        return new static();
    }

    /**
     * @param CosmosDbCollection $collection
     * @return $this
     */
    public function setCollection(CosmosDbCollection $collection)
    {
        $this->collection = $collection;
        return $this;
    }

    /**
     * @param array|string $fields
     * @return $this
     */
    public function select($fields)
    {
        if (is_array($fields))
            $fields = 'c["' . implode('"], c["', $fields) . '"]';
        $this->fields = $fields;
        return $this;
    }

    /**
     * @param string $from
     * @return $this
     */
    public function from(string $from)
    {
        $this->from = $from;
        return $this;
    }

    /**
     * @param string $join
     * @return $this
     */
    public function join(string $join)
    {
        $this->join .= " {$join} ";
        return $this;
    }

    /**
     * @param string $where
     * @return $this
     */
    public function where(string $where)
    {
        if (empty($where)) return $this;
        $this->where .= !empty($this->where) ? " and {$where} " : "{$where}";

        return $this;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return QueryBuilder
     */
    public function whereStartsWith(string $field, $value)
	{
		return $this->where("STARTSWITH($field, '{$value}')");
	}

    /**
     * @param string $field
     * @param mixed $value
     * @return QueryBuilder
     */
    public function whereEndsWith(string $field, $value)
    {
		return $this->where("ENDSWITH($field, '{$value}')");
	}

    /**
     * @param string $field
     * @param mixed $value
     * @return QueryBuilder
     */
    public function whereContains(string $field, $value)
    {
		return $this->where("CONTAINS($field, '{$value}'");
	}

    /**
     * @param string $field
     * @param array $values
     * @return $this|QueryBuilder
     */
    public function whereIn(string $field, array $values)
	{
	    if (empty($values)) return $this;

		return $this->where("$field IN('".implode("', '", $values)."')");
	}

    /**
     * @param string $field
     * @param array $values
     * @return $this|QueryBuilder
     */
    public function whereNotIn(string $field, array $values)
    {
        if (empty($values)) return $this;

        return $this->where("$field NOT IN('".implode("', '", $values)."')");
    }

    /**
     * @param string $order
     * @return $this
     */
    public function order(string $order)
    {
        $this->order = $order;
        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @param array $params
     * @return $this
     */
    public function params(array $params)
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @param boolean $isCrossPartition
     * @return $this
     */
    public function findAll(bool $isCrossPartition = false)
    {
        $this->response = null;
        $this->multipleResults = true;

        $partitionValue = $this->partitionValue != null ? $this->partitionValue : null;

        $limit = $this->limit != null ? "top " . (int)$this->limit : "";
        $fields = !empty($this->fields) ? $this->fields : '*';
        $where = $this->where != "" ? "where {$this->where}" : "";
        $order = $this->order != "" ? "order by {$this->order}" : "";

        $query = "SELECT {$limit} {$fields} FROM {$this->from} {$this->join} {$where} {$order}";

        $this->response = $this->collection->query($query, $this->params, $isCrossPartition, $partitionValue);

        return $this;
    }

    /**
     * @param boolean $isCrossPartition
     * @return $this
     */
    public function find(bool $isCrossPartition = false)
    {
        $this->response = null;
        $this->multipleResults = false;

        $partitionValue = $this->partitionValue != null ? $this->partitionValue : null;

        $fields = !empty($this->fields) ? $this->fields : '*';
        $where = $this->where != "" ? "where {$this->where}" : "";
        $order = $this->order != "" ? "order by {$this->order}" : "";

        $query = "SELECT top 1 {$fields} FROM {$this->from} {$this->join} {$where} {$order}";

        $this->response = $this->collection->query($query, $this->params, $isCrossPartition, $partitionValue);

        return $this;
    }

    /**
     * @param $fieldName
     * @return $this
     */
    public function setPartitionKey($fieldName)
    {
        $this->partitionKey = $fieldName;

        return $this;
    }

    /**
     * @return null
     */
    public function getPartitionKey()
	{
		return $this->partitionKey;
    }
    
    /**
     * @param $fieldName
     * @return $this
     */
    public function setPartitionValue($fieldName)
    {
        $this->partitionValue = $fieldName;

        return $this;
    }

    /**
     * @return null
     */
    public function getPartitionValue()
	{
		return $this->partitionValue;
	}

    /**
     * @param string $fieldName
     * @return $this
     */
    public function setQueryString(string $fieldName)
    {
        $this->queryString .= $fieldName;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getQueryString()
    {
		return $this->queryString;
	}

    /**
     * @param string $partitionKey
     * @return bool
     */
    public function isNested(string $partitionKey)
    {
        # strip any slashes from the beginning
        # and end of the partition key
        $partitionKey = trim($partitionKey, '/');

        # if the partition key contains slashes or dots,
        # the user is referencing a nested value
        if (
            strpos($partitionKey, '/') !== false
            || strpos($partitionKey, '.') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Find and set the partition value
     * 
     * @param object document
     * @param bool if true, return property structure formatted for use in Azure query string
     * @return string partition value
     */
    public function findPartitionValue(object $document)
    {
        # if the user supplied a partition value using setPartitionValue(),
        # use it rather than trying to match one elsewhere
        if(!empty($this->partitionValue)) {
            return $this->partitionValue;
        }

        # if the partition key contains slashes or dots, the user
        # is referencing a nested value, so we should find it
        if ($this->isNested($this->partitionKey)) {

            # explode the key into its properties
            # accept either slash or dot form; ie:
            #   /something/property
            #   something.property
            #
            # note: this syntax disparity comes from the way partition keys
            #       are sometimes displayed within the Azure portal. It can
            #       lead customers to interpret what the format should be.
            if (strpos($this->partitionKey, '/') !== false) {
                $properties = array_values(array_filter(explode("/", $this->partitionKey)));
            }
            elseif (strpos($this->partitionKey, '.') !== false) {
                $properties = array_values(array_filter(explode(".", $this->partitionKey)));
            }

            # when deleting a document, we first query in order to find the document _rid.
            # the query selects both the c._rid and our partition key as a property (ie: c.country).
            # keep in mind that our partion key may also refer to a nested value (ie: c.customer.country)

            # if our response object matches our document property structure, then navigate
            # the object to grab our partition value.
            #    {
            #       customer: {
            #           country: 'Canada'
            #       },
            #       _rid: 'EAhKANCYa+eEhB4AAAAAAA=='
            #    }
            #
            if(
                property_exists((object)$document, $properties[0])
                || array_key_exists($properties[0], (array)$document)
            ) {
                $nested = clone $document;
                foreach( $properties as $p ) {
                    $nested = (object)$nested->{$p};
                }
                if($nested->scalar && !empty($nested->scalar)) {
                    return $nested->scalar;
                }
            }

            # otherwise if our response object is flattened,
            # then look for the last property of our nested parition key.
            #    {
            #       country: 'Canada',
            #       _rid: 'EAhKANCYa+eEhB4AAAAAAA=='
            #    }
            #
            $lastProperty = end($properties);
            if(
                array_key_exists($lastProperty, (array)$document)
                || property_exists($document, $lastProperty)
            ) {
                return $document->{$lastProperty};
            }

            /*
            # debug
            echo "=============== DEBUG (QueryBuilder::findPartitionValue) ===============".PHP_EOL;
            echo json_encode([
                'isNested'          => $this->isNested($this->partitionKey),
                'properties'        => $properties,
                '$document->scalar' => $document->scalar,
            ], JSON_PRETTY_PRINT).PHP_EOL;
            */
        }
        # otherwise, assume the key is in the root of the
        # document and return the value of the property key
        else {
            return $document->{$this->partitionKey};
        }
    }

    /**
     * @param $document
     * @return string|null
     * @throws Exception
     */
    public function save($document)
    {
        $document = (object)$document;

        $rid = is_object($document) && isset($document->_rid) ? $document->_rid : null;
        $partitionValue = $this->partitionKey != null ? $this->findPartitionValue($document) : null;
        $document = json_encode($document);

        $result = $rid ?
            $this->collection->replaceDocument($rid, $document, $partitionValue, $this->triggersAsHeaders("replace")) :
            $this->collection->createDocument($document, $partitionValue, $this->triggersAsHeaders("create"));
        $resultObj = json_decode($result);

        if (isset($resultObj->code) && isset($resultObj->message)) {
            throw new Exception("$resultObj->code : $resultObj->message");
        }

        return $resultObj->_rid ?? null;
    }

    /**
     * @param boolean $isCrossPartition
     * @return boolean
     */
    public function delete($isCrossPartition = false)
    {
        $this->response = null;

        $select = $this->fields != ""
            ? $this->fields
            : "c._rid" . ($this->partitionKey != null ? ", c." . $this->partitionKey : "");

        $document = $this->select($select)->find($isCrossPartition)->toObject();

        if ($document) {

            /*
            # debug
            echo "=============== DEBUG (QueryBuilder::delete) ===============".PHP_EOL;
            echo json_encode([
                '$this->fields'         => $this->fields,
                '$this->partitionKey'   => $this->partitionKey,
                '$select'               => $select,
                '$document'             => $document,
                'findPartitionValue()'  => $this->findPartitionValue($document),
            ], JSON_PRETTY_PRINT).PHP_EOL;
            */

            $this->response = $this->collection->deleteDocument(
                $document->_rid,
                $this->findPartitionValue($document),
                $this->triggersAsHeaders("delete")
            );
            return true;
        }

        return false;
    }

    /**
     * @param boolean $isCrossPartition
     * @return boolean
     */
    public function deleteAll(bool $isCrossPartition = false)
    {
        $this->response = null;

        $select = ($this->fields != "")
            ? $this->fields
            : "c._rid" . ($this->partitionKey != null ? ", c." . $this->partitionKey : "");
        $response = [];
        foreach ((array)$this->select($select)->findAll($isCrossPartition)->toObject() as $document) {
            $partitionValue = $this->partitionKey != null ? $this->findPartitionValue($document) : null;
            $response[] = $this->collection->deleteDocument($document->_rid, $partitionValue, $this->triggersAsHeaders("delete"));
        }

        $this->response = $response;
        return true;
    }

    /* triggers */

    /**
     * @param string $operation
     * @param string $type
     * @param string $id
     * @return QueryBuilder
     * @throws Exception
     */
    public function addTrigger(string $operation, string $type, string $id)
    {
        $operation = strtolower($operation);
        if (!in_array($operation, ["all", "create", "delete", "replace"]))
            throw new Exception("Trigger: Invalid operation \"{$operation}\"");

        $type = strtolower($type);
        if (!in_array($type, ["post", "pre"]))
            throw new Exception("Trigger: Invalid type \"{$type}\"");

        if (!isset($this->triggers[$operation][$type]))
            $this->triggers[$operation][$type] = [];

        $this->triggers[$operation][$type][] = $id;
        return $this;
    }

    /**
     * @param string $operation
     * @return array
     */
    protected function triggersAsHeaders(string $operation)
    {
        $headers = [];

        // Add headers for the current operation type at $operation (create|delete!replace)
        if (isset($this->triggers[$operation])) {
            foreach ($this->triggers[$operation] as $name => $ids) {
                $ids = is_array($ids) ? $ids : [$ids];
                $headers["x-ms-documentdb-{$name}-trigger-include"] = implode(",", $ids);
            }
        }

        // Add headers for the special "all" operations type that should always run
        if (isset($this->triggers["all"])) {
            foreach ($this->triggers["all"] as $name => $ids) {
                $headerKey = "x-ms-documentdb-{$name}-trigger-include";
                $ids = implode(",", is_array($ids) ? $ids : [$ids]);
                $headers[$headerKey] = isset($headers[$headerKey]) ? $headers[$headerKey] .= "," . $ids : $headers[$headerKey] = $ids;
            }
        }

        return $headers;
    }

    /* helpers */

    /**
     * @return string
     */
    public function toJson()
    {
        /*
         * If the CosmosDB result set contains many documents, CosmosDB might apply pagination. If this is detected,
         * all pages are requested one by one, until all results are loaded. These individual responses are contained
         * in $this->response. If no pagination is applied, $this->response is an array containing a single response.
         *
         * $results holds the documents returned by each of the responses.
         */
        $results = [
            '_rid' => '',
            '_count' => 0,
            'Documents' => []
        ];
        foreach ($this->response as $response) {
            $res = json_decode($response);
            $results['_rid'] = $res->_rid;
            $results['_count'] = $results['_count'] + $res->_count;
            $docs = $res->Documents ?? [];
            $results['Documents'] = array_merge($results['Documents'], $docs);
        }
        return json_encode($results);
    }

    /**
     * @param $arrayKey
     * @return mixed
     */
    public function toObject($arrayKey = null)
    {
        /*
         * If the CosmosDB result set contains many documents, CosmosDB might apply pagination. If this is detected,
         * all pages are requested one by one, until all results are loaded. These individual responses are contained
         * in $this->response. If no pagination is applied, $this->response is an array containing a single response.
         *
         * $results holds the documents returned by each of the responses.
         */
        $results = [];
        foreach ((array)$this->response as $response) {
            $res = json_decode($response);
            if (isset($res->Documents)) {
                array_push($results, ...$res->Documents);
            } else {
                $results[] = $res;
            }
        }

        if ($this->multipleResults && $arrayKey != null) {
            $results = array_combine(array_column($results, $arrayKey), $results);
        }

        return $this->multipleResults ? $results : ($results[0] ?? null);
    }

    /**
     * @param $arrayKey
     * @return array|null
     */
    public function toArray($arrayKey = null)
    {
        $results = (array)$this->toObject($arrayKey);

        if ($this->multipleResults && is_array($results)) {
            array_walk($results, function(&$value) {
                $value = (array)$value;
            });
        }

        return $this->multipleResults ? $results : ((array)$results ?? null);
    }

    /**
     * @param $fieldName
     * @param null $default
     * @return mixed
     */
    public function getValue($fieldName, $default = null)
    {
        $obj = $this->toObject();
        return isset($obj->{$fieldName}) ? $obj->{$fieldName} : $default;
    }
    
}
