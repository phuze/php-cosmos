<?php

namespace Phuze\PhpCosmos;

use \Exception;

class QueryBuilder
{
    /** @var CosmosDbCollection|null */
    private $collection = null;

    /** @var string|null */
    private $partitionKey = null;

    /** @var mixed */
    private $partitionValue = null;

    /** @var string */
    private $queryString = "";

    /** @var string */
    private $fields = "";

    /** @var string */
    private $from = "c";

    /** @var string */
    private $join = "";

    /** @var string */
    private $where = "";

    /** @var string|null */
    private $order = null;

    /** @var int|null */
    private $limit = null;

    /** @var array trigger ids, by operation and type */
    private $triggers = [];

    /** @var array */
    private $params = [];

    /** @var string[]|string|null raw responses from the last request */
    private $response = null;

    /** @var bool whether the last query was findAll() */
    private $multipleResults = false;

    /**
     * Create a new query builder.
     *
     * @return static
     */
    public static function instance()
    {
        return new static();
    }

    /**
     * Set the collection to query, save to or delete from.
     *
     * @param CosmosDbCollection $collection
     * @return $this
     */
    public function setCollection(CosmosDbCollection $collection)
    {
        $this->collection = $collection;
        return $this;
    }

    /**
     * Set the fields to select, instead of *. An array of property names becomes
     * c["name"] selectors, and a string is used as is.
     *
     * @param array|string $fields ie: ['id', 'name'] or "c.id, c.name"
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
     * Set the FROM clause, which defaults to c.
     *
     * @param string $from
     * @return $this
     */
    public function from(string $from)
    {
        $this->from = $from;
        return $this;
    }

    /**
     * Add a JOIN clause.
     *
     * @param string $join ie: JOIN t IN c.tags
     * @return $this
     */
    public function join(string $join)
    {
        $this->join .= " {$join} ";
        return $this;
    }

    /**
     * Add a condition. Conditions are combined with AND.
     *
     * @param string $where ie: c.age > @age
     * @return $this
     */
    public function where(string $where)
    {
        if (empty($where)) return $this;
        $this->where .= !empty($this->where) ? " and {$where} " : "{$where}";

        return $this;
    }

    /**
     * Add a condition that a field starts with a value.
     *
     * @param string $field ie: c.name
     * @param mixed $value quoted as a string
     * @return $this
     */
    public function whereStartsWith(string $field, $value)
    {
        return $this->where("STARTSWITH($field, {$this->quote($value)})");
    }

    /**
     * Add a condition that a field ends with a value.
     *
     * @param string $field ie: c.name
     * @param mixed $value quoted as a string
     * @return $this
     */
    public function whereEndsWith(string $field, $value)
    {
        return $this->where("ENDSWITH($field, {$this->quote($value)})");
    }

    /**
     * Add a condition that a field contains a value.
     *
     * @param string $field ie: c.name
     * @param mixed $value quoted as a string
     * @return $this
     */
    public function whereContains(string $field, $value)
    {
        return $this->where("CONTAINS($field, {$this->quote($value)})");
    }

    /**
     * Add a condition that a field matches one of the values. An empty array adds nothing.
     *
     * @param string $field ie: c.country
     * @param array $values each quoted as a string
     * @return $this
     */
    public function whereIn(string $field, array $values)
    {
        if (empty($values)) return $this;

        return $this->where("$field IN(" . implode(", ", array_map([$this, 'quote'], $values)) . ")");
    }

    /**
     * Add a condition that a field matches none of the values. An empty array adds nothing.
     *
     * @param string $field ie: c.country
     * @param array $values each quoted as a string
     * @return $this
     */
    public function whereNotIn(string $field, array $values)
    {
        if (empty($values)) return $this;

        return $this->where("$field NOT IN(" . implode(", ", array_map([$this, 'quote'], $values)) . ")");
    }

    /**
     * Quote a value as a Cosmos DB SQL string literal, escaping quotes and backslashes.
     *
     * @param mixed $value
     * @return string
     */
    private function quote($value)
    {
        $quoted = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($quoted === false) {
            throw new \InvalidArgumentException('Unable to quote value: ' . json_last_error_msg());
        }
        return $quoted;
    }

    /**
     * Set the ORDER BY clause.
     *
     * @param string $order ie: c.name ASC
     * @return $this
     */
    public function order(string $order)
    {
        $this->order = $order;
        return $this;
    }

    /**
     * Limit the number of results findAll() returns.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the query parameters.
     *
     * @param array $params ie: ['@age' => 30]
     * @return $this
     */
    public function params(array $params)
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Run the query for every matching document. Read the results with
     * toArray(), toObject() or toJson().
     *
     * @param bool $isCrossPartition query across partitions
     * @return $this
     */
    public function findAll(bool $isCrossPartition = false)
    {
        $this->response = null;
        $this->multipleResults = true;

        $partitionValue = $this->partitionValue;

        $limit = $this->limit != null ? "top " . (int)$this->limit : "";
        $fields = !empty($this->fields) ? $this->fields : '*';
        $where = $this->where != "" ? "where {$this->where}" : "";
        $order = $this->order != "" ? "order by {$this->order}" : "";

        $query = "SELECT {$limit} {$fields} FROM {$this->from} {$this->join} {$where} {$order}";

        $this->response = $this->collection->query($query, $this->params, $isCrossPartition, $partitionValue);

        return $this;
    }

    /**
     * Run the query for the first matching document. Read the result with
     * toArray(), toObject() or getValue().
     *
     * @param bool $isCrossPartition query across partitions
     * @return $this
     */
    public function find(bool $isCrossPartition = false)
    {
        $this->response = null;
        $this->multipleResults = false;

        $partitionValue = $this->partitionValue;

        $fields = !empty($this->fields) ? $this->fields : '*';
        $where = $this->where != "" ? "where {$this->where}" : "";
        $order = $this->order != "" ? "order by {$this->order}" : "";

        $query = "SELECT top 1 {$fields} FROM {$this->from} {$this->join} {$where} {$order}";

        $this->response = $this->collection->query($query, $this->params, $isCrossPartition, $partitionValue);

        return $this;
    }

    /**
     * Set the collection's partition key, which is used to find a document's
     * partition value when saving or deleting.
     *
     * @param string $partitionKey partition key path; ie: /country or customer.country
     * @return $this
     */
    public function setPartitionKey($partitionKey)
    {
        $this->partitionKey = $partitionKey;

        return $this;
    }

    /**
     * Get the partition key set with setPartitionKey().
     *
     * @return string|null
     */
    public function getPartitionKey()
	{
		return $this->partitionKey;
    }
    
    /**
     * Set the partition key value, so queries only search that partition. It's
     * also used by save(), patch() and delete(), ahead of any value in the document.
     *
     * @param mixed $partitionValue partition key value; ie: Canada
     * @return $this
     */
    public function setPartitionValue($partitionValue)
    {
        $this->partitionValue = $partitionValue;

        return $this;
    }

    /**
     * Get the partition key value set with setPartitionValue().
     *
     * @return mixed
     */
    public function getPartitionValue()
	{
		return $this->partitionValue;
	}

    /**
     * Append to a stored query string, which getQueryString() returns. It isn't
     * used when running a query.
     *
     * @param string $queryString text to append
     * @return $this
     */
    public function setQueryString(string $queryString)
    {
        $this->queryString .= $queryString;
        return $this;
    }

    /**
     * Get the query string built with setQueryString().
     *
     * @return string
     */
    public function getQueryString()
    {
		return $this->queryString;
	}

    /**
     * Check whether a partition key refers to a nested property, such as
     * /customer/country or customer.country.
     *
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
     * Split the partition key into its property names. Either slash or dot
     * form is accepted:
     *   /something/property
     *   something.property
     *
     * Note: this syntax disparity comes from the way partition keys
     *       are sometimes displayed within the Azure portal, which can
     *       leave customers unsure which format to use.
     *
     * @return array property names; empty if no partition key is set
     */
    private function getPartitionKeyProperties()
    {
        if ($this->partitionKey === null || $this->partitionKey === '') {
            return [];
        }

        $separator = strpos($this->partitionKey, '/') !== false ? '/' : '.';

        return array_values(array_filter(explode($separator, $this->partitionKey), 'strlen'));
    }

    /**
     * Get the partition key as a query expression, so "/customer/country"
     * becomes c.customer.country.
     *
     * @return string|null null if no partition key is set
     */
    private function getPartitionKeySelector()
    {
        $properties = $this->getPartitionKeyProperties();
        if (empty($properties)) {
            return null;
        }

        $selector = 'c';
        foreach ($properties as $p) {
            $selector .= preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $p) ? ".{$p}" : '[' . json_encode($p) . ']';
        }

        return $selector;
    }

    /**
     * Follow a path of property names through a decoded document.
     *
     * @param mixed $data
     * @param array $properties
     * @param bool $found set to whether the whole path exists
     * @return mixed the value at the end of the path
     */
    private function getPropertyValue($data, array $properties, &$found)
    {
        foreach ($properties as $p) {
            if (is_object($data) && property_exists($data, $p)) {
                $data = $data->{$p};
            }
            elseif (is_array($data) && array_key_exists($p, $data)) {
                $data = $data[$p];
            }
            else {
                $found = false;
                return null;
            }
        }

        $found = true;
        return $data;
    }

    /**
     * Find a document's partition value. A value set with setPartitionValue()
     * is used first.
     *
     * @param object|array $document
     * @return mixed partition value, or null if it can't be found
     */
    public function findPartitionValue($document)
    {
        # if the user supplied a partition value using setPartitionValue(),
        # use it rather than trying to match one elsewhere
        if ($this->partitionValue !== null) {
            return $this->partitionValue;
        }

        # no partition key, so there's no value to find
        $properties = $this->getPartitionKeyProperties();
        if (empty($properties)) {
            return null;
        }

        # if our document matches the partition key's property structure,
        # navigate it to grab our partition value.
        #    {
        #       customer: {
        #           country: 'Canada'
        #       },
        #       _rid: 'EAhKANCYa+eEhB4AAAAAAA=='
        #    }
        #
        $value = $this->getPropertyValue($document, $properties, $found);
        if ($found) {
            return $value;
        }

        # when deleting a document, we first query in order to find the document _rid.
        # the query selects both the c._rid and our partition key (ie: c.customer.country),
        # and cosmos returns a nested property flattened under its last name.
        #    {
        #       country: 'Canada',
        #       _rid: 'EAhKANCYa+eEhB4AAAAAAA=='
        #    }
        #
        if (count($properties) > 1) {
            $value = $this->getPropertyValue($document, [end($properties)], $found);
            if ($found) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Create a document, or replace it if it has a _rid.
     *
     * @param object|array $document
     * @return string|null the saved document's _rid
     * @throws Exception
     */
    public function save($document)
    {
        $document = (object)$document;
        $rid = is_object($document) && isset($document->_rid) ? $document->_rid : null;
        $partitionValue = $this->findPartitionValue($document);
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
     * Create a document, or replace the one with the same id and partition
     * value. Unlike save(), the document doesn't need a _rid to be replaced.
     *
     * @param object|array $document
     * @return string|null the saved document's _rid
     * @throws Exception
     */
    public function upsert($document)
    {
        $document = (object)$document;
        $partitionValue = $this->findPartitionValue($document);
        $document = json_encode($document);

        $result = $this->collection->upsertDocument($document, $partitionValue, $this->triggersAsHeaders("upsert"));
        $resultObj = json_decode($result);

        if (isset($resultObj->code) && isset($resultObj->message)) {
            throw new Exception("$resultObj->code : $resultObj->message");
        }

        return $resultObj->_rid ?? null;
    }

    /* patch */

    /**
     * Partially update a document. The partition value must be set with
     * setPartitionValue() if the collection is partitioned. If where() has
     * been set, the patch only applies if the document matches it.
     *
     * @link https://learn.microsoft.com/en-us/azure/cosmos-db/partial-document-update
     * @param string $docRid document _rid
     * @param array $patchOps operations built with the getPatchOp*() methods, max 10 per request
     * @return string|null the patched document's _rid
     * @throws Exception
     */
    public function patch(string $docRid, array $patchOps)
    {
        if (count($patchOps) > 10) {
            # throw the error cosmos would return, rather than waste a request
            throw new Exception("400 : PATCH supports maximum of 10 operations per request");
        }

        $updates = [];
        if ($this->where != "") {
            $updates['condition'] = "from {$this->from} where {$this->where}";
        }
        $updates['operations'] = array_values($patchOps);

        $result = $this->collection->patchDocument($docRid, json_encode($updates), $this->partitionValue, $this->triggersAsHeaders("patch"));
        $resultObj = json_decode($result);

        if (isset($resultObj->code) && isset($resultObj->message)) {
            throw new Exception("$resultObj->code : $resultObj->message");
        }

        return $resultObj->_rid ?? null;
    }

    /**
     * Add a property, or insert into an array. An existing property is replaced.
     *
     * @param string $path JSON pointer; ie: /address/city. escape ~ as ~0 and / within a property name as ~1
     * @param mixed $value
     * @return array
     */
    public function getPatchOpAdd(string $path, $value)
    {
        return ['op' => 'add', 'path' => $path, 'value' => $value];
    }

    /**
     * Set a property, creating it if it doesn't exist. On an array index, it
     * replaces that element.
     *
     * @param string $path JSON pointer; ie: /address/city
     * @param mixed $value
     * @return array
     */
    public function getPatchOpSet(string $path, $value)
    {
        return ['op' => 'set', 'path' => $path, 'value' => $value];
    }

    /**
     * Replace a property. This fails if it doesn't exist.
     *
     * @param string $path JSON pointer; ie: /address/city
     * @param mixed $value
     * @return array
     */
    public function getPatchOpReplace(string $path, $value)
    {
        return ['op' => 'replace', 'path' => $path, 'value' => $value];
    }

    /**
     * Remove a property or array element. This fails if it doesn't exist.
     *
     * @param string $path JSON pointer; ie: /address/city
     * @return array
     */
    public function getPatchOpRemove(string $path)
    {
        return ['op' => 'remove', 'path' => $path];
    }

    /**
     * Increment a number by the given amount. Use a negative value to decrement.
     *
     * @param string $path JSON pointer; ie: /stock
     * @param int|float $value
     * @return array
     */
    public function getPatchOpIncrement(string $path, $value)
    {
        return ['op' => 'incr', 'path' => $path, 'value' => $value];
    }

    /**
     * Move a property to another path, removing it from the original.
     *
     * @param string $fromPath JSON pointer to move from; ie: /address/town
     * @param string $toPath JSON pointer to move to; ie: /address/city
     * @return array
     */
    public function getPatchOpMove(string $fromPath, string $toPath)
    {
        return ['op' => 'move', 'from' => $fromPath, 'path' => $toPath];
    }

    /* delete */

    /**
     * Get the fields a delete queries for: the _rid, plus the partition key to
     * delete it by.
     *
     * @return string
     */
    private function getDeleteSelect()
    {
        if ($this->fields != "") {
            return $this->fields;
        }

        $selector = $this->getPartitionKeySelector();

        return "c._rid" . ($selector !== null ? ", {$selector}" : "");
    }

    /**
     * Delete the first document matching the query.
     *
     * @param bool $isCrossPartition query across partitions
     * @return bool false if no document matched
     */
    public function delete($isCrossPartition = false)
    {
        $this->response = null;

        $document = $this->select($this->getDeleteSelect())->find($isCrossPartition)->toObject();

        if ($document) {
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
     * Delete every document matching the query.
     *
     * @param bool $isCrossPartition query across partitions
     * @return bool always true, even if no document matched
     */
    public function deleteAll(bool $isCrossPartition = false)
    {
        $this->response = null;

        $response = [];
        foreach ((array)$this->select($this->getDeleteSelect())->findAll($isCrossPartition)->toObject() as $document) {
            $response[] = $this->collection->deleteDocument(
                $document->_rid,
                $this->findPartitionValue($document),
                $this->triggersAsHeaders("delete")
            );
        }

        $this->response = $response;
        return true;
    }

    /* triggers */

    /**
     * Run a pre or post trigger with an operation.
     *
     * @param string $operation all, create, delete, replace, patch or upsert
     * @param string $type pre or post
     * @param string $id trigger id
     * @return $this
     * @throws Exception if the operation or type isn't valid
     */
    public function addTrigger(string $operation, string $type, string $id)
    {
        $operation = strtolower($operation);
        if (!in_array($operation, ["all", "create", "delete", "replace", "patch", "upsert"]))
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
     * Get the trigger headers for an operation, including triggers added for
     * all operations.
     *
     * @param string $operation create, delete, replace, patch or upsert
     * @return array
     */
    protected function triggersAsHeaders(string $operation)
    {
        $headers = [];

        // Add headers for the current operation type at $operation (create|delete|replace|patch|upsert)
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
     * Get the results as one JSON response, with the documents from every page combined.
     *
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
     * Get the results as objects: an array of documents after findAll(), or a
     * single document (or null) after find().
     *
     * @param string|null $arrayKey property to key the results by, after findAll()
     * @return array|object|null
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
                # array_merge rather than array_push(...), which needs at least
                # one document on php before 7.3
                $results = array_merge($results, $res->Documents);
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
     * Get the results as arrays: an array of documents after findAll(), or a
     * single document after find(), which is empty if none matched.
     *
     * @param string|null $arrayKey property to key the results by, after findAll()
     * @return array
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
     * Get a field of the document found by find().
     *
     * @param string $fieldName
     * @param mixed $default returned if the field isn't set
     * @return mixed
     */
    public function getValue($fieldName, $default = null)
    {
        $obj = $this->toObject();
        return isset($obj->{$fieldName}) ? $obj->{$fieldName} : $default;
    }
    
}
