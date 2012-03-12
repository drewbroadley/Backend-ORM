<?

/*
 * Class: Backend
 *
 * Description:
 * Handles all of the page requests and details
 *
 */

class Backend_ORM extends Backend {

    private $sql = null;
    private $fields;
    private $field_count;
    private $tables;
    private $cond = array();
    private $order = array();
    private $limit;
    private $altered = array();

    /*
      private $rows = array();
      private $row = array();
      private $row_count;
      private $row_current;
     */
    private $field_type = array(
	'/^(.*INT|DECIMAL|FLOAT|DOUBLE|REAL|BIT|BOOL|SERIAL)$/' => 'int',
	'/^(DATE.*|TIME.*|YEAR)$/' => 'datetime',
	'/^(GEO.*|.*POINT|MULTI.*|POLYGON|LINESTRING)$/' => 'spatial',
	'/.*/' => 'string'
    );
    public $error = array();
    private $operators = array(
	'mysql' => "(REGEXP|RLIKE|LIKE|NOT LIKE|[\=\>\!\<]+)",
    );

    function Backend_ORM() {
	$this->schema = $this->_schema_get();

	$this->fields = array();
	$this->field_count = 1;
	$this->tables = array(
	    'origin' => null,
	    'join' => array(),
	);

	$this->cond = array();
	$this->order = array();
	$this->altered = array();
	$this->rows = array();
	$this->row_count = 1;
	$this->row_current = 0;

	if (func_num_args() > 0) {
	    list($table, $condition) = func_get_args();

	    $this->tables['origin'] = $table;

	    if ($condition) {
		$this->cond($condition);
	    } else {
		$this->altered[0] = null;
	    }

	    $this->join($table);
	}
    }

    function rows() {

	return $this->rows;
    }

    function num_rows() {
	return count($this->rows);
    }

    function get($condition = null, $expires = -1) {
	if ($condition) {
	    $this->cond($condition);
	}

	$fields = array();
	$joins = array();
	$conds = array();

	$sql = "SELECT ";
	foreach ($this->fields['index'] as $field) {
	    $fields[] = "`" . $field[0] . "`.`" . $field[1] . "` ";
	}
	$sql .= join(", ", $fields) . " ";
	$sql .= "\nFROM " . $this->tables['origin'] . " ";

	//Log::dump($this->tables);

	foreach (array_splice($this->tables['join'], 1, (count($this->tables['join']) - 1)) as $join) {
	    $joins[] = strtoupper($join[4]) . " JOIN `" . $join[0] . "` ON `" . $join[2] . "`.`" . $join[3] . "` = `" . $join[0] . "`.`" . $join[1] . "` ";
	}

	$sql .= join("\n", $joins);

	if (count($this->cond) > 0) {
	    $sql .= "\nWHERE ";

	    foreach ($this->cond as $cond) {
		if (is_string($cond) && preg_match("/^\{.*\}$/", $cond)) {
		    $conds[] = preg_replace("/^\{(.*)\}$/", "$1", $cond);
		} else {
		    $conds[] = "`" . $cond[0] . "`.`" . $cond[1] . "` " . $cond[2] . " '" . addslashes($cond[3]) . "'";
		}
	    }
	    $sql .= join(" AND ", $conds);
	}

	if (count($this->order) > 0) {
	    $sql .= " ORDER BY ";
	    $order_sql = array();
	    foreach ($this->order as $order) {
		if ($order[1] != "RAND()") {
		    $order_sql[] = $order[0] . ".`" . $order[1] . "` " . $order[2];
		} else {
		    $order_sql[] = "RAND()";
		}
	    }
	    $sql .= join(", ", $order_sql);
	}


	if (preg_match("/[0-9]+/", $this->limit)) {
	    $sql .= " LIMIT " . mysql_escape_string($this->limit);
	}

	//print "SQL: $sql<br/>";

	$sql_checksum = md5($sql);

	$this->row_current = 0;

	if (Backend_Cache::get('_sql_' . $sql_checksum, $expires)) {
	    $sql_cache = Backend_Cache::get('_sql_' . $sql_checksum, $expires);

	    $this->query = $sql_cache['query'];
	    //$this->row_current = $sql_cache['row_current'];
	    $this->row_count = (int) $sql_cache['row_count'];
	    $this->rows = $sql_cache['rows'];
	} else {
	    $this->query = self::query($sql);

	    $this->row_count = 0;
	    $this->rows = array();

	    while ($row = $this->query->fetch(PDO::FETCH_NUM)) {
		$this->rows[($this->row_count + 1)] = $row;
		$this->row_count++;
	    }

	    $sql_cache = array(
		'query' => $this->query,
		'row_count' => $this->row_count,
		'rows' => $this->rows
	    );

	    Backend_Cache::set('_sql_' . $sql_checksum, $sql_cache);
	}


	//$this->next();
    }

    function __set($field, $value) {
	return $this->f($field, $value);
    }

    function __get($field) {
	return $this->f($field);
    }

    function f($field, $value = null, $new = false) {
	if ($this->row_current == 0) {
	    $this->next();
	}

	if ($this->schema[$this->tables['origin']]['primary'] == $field && $value && !$new) {
	    //return null;
	}

	if (!$this->fields['field'][$field]) {
	    return null;
	}

	if (!is_array($field)) {
	    if ($this->fields['index'][$this->fields['field'][$field]]) {
		$field = $this->fields['field'][$field];
	    } else {
		return null;
	    }
	}

	if (isset($value) && array_key_exists(($field - 1), $this->row)) {
	    if (!$this->altered[$this->row_current]) {
		$this->altered[$this->row_current] = $this->row;
	    }

	    $this->row[($field - 1)] = $value;
	} else if (isset($value) && !$this->row[($field - 1)]) {

	    //$this->row_current = 0;

	    if (!is_array($this->altered)) {
		$this->altered = array();
	    }

	    $this->row[($field - 1)] = $value;

	    if (!$this->altered[$this->row_current]) {
		$this->altered[$this->row_current] = $this->row;
	    }
	}

	return $this->row[($field - 1)];
    }

    function reset() {
	$this->row_current = 0;
	$this->row = array();
    }

    function next() {
	if ($this->rows[($this->row_current + 1)]) {
	    $this->row_current++;
	    $this->row = &$this->rows[$this->row_current];

	    return $this->render($this->row_current);
	}

	return false;
    }

    function prev() {
	if ($this->rows[($this->row_current - 1)]) {
	    $this->row_current--;
	    $this->row = &$this->rows[$this->row_current];

	    return $this->render($this->row_current);
	}
	return false;
    }

    function count() {
	return (int) $this->row_count;
    }

    function render($row_number = null) {
	$row = array();
	$row_number = ($row_number) ? $row_number : $this->row_current;

	//Log::dump($this->fields['index']);

	$row = new Backend_ORM_Row($this->rows[$row_number], &$this->fields);

	return $row;
    }

    function cond() {
	if (func_num_args() == 0) {
	    return;
	}

	if (func_num_args() == 1) {
	    if (is_array(func_get_arg(0))) {
		foreach (func_get_arg(0) as $cond) {
		    $this->cond($cond);
		}

		return;
	    } else {
		$table = $this->tables['origin'];
		$condition = func_get_arg(0);
	    }
	} else {
	    list($table, $condition) = func_get_args();
	}

	if (!@preg_match("/" . $this->operators['mysql'] . "/", $condition)) {
	    $this->cond[] = array($table, $this->schema[$table]['primary'], "=", $condition);
	} else {
	    //print "Condition: " . $condition . "\n";
	    if (preg_match("/^\s*\{.*\}\s*$/", $condition)) {
		$this->cond[] = $condition;
	    } else {
		preg_match("/^(.+?)\s*" . $this->operators['mysql'] . "\s*(.+?)$/i", $condition, $condition_match);

		$this->cond[] = array_merge(array($table), array_splice($condition_match, 1, 3));
	    }
	}
    }

    function order($field, $dir = "ASC") {
	$this->order[] = array($this->tables['origin'], $field, $dir);
    }

    function limit($limit) {
	$this->limit = $limit;
    }

    function save($row_id = null) {
	//Log::dump($this->altered);

	foreach ($this->altered as $row_number => $row_original) {
	    if (!is_array($row_original)) {
		continue;
	    }

	    $row = $this->rows[$row_number];

	    $table_field_start = 1;
	    $table_current = '';
	    $fields_count = count($this->fields['index']);

	    for ($key = 1; $key <= $fields_count; $key++) {
		//print "Key: " . $key . "/". $fields_count . "<br/>";
		if (!$table_current) {
		    //print "Table Current: " . $this->fields['index'][$key][0];
		    //Log::dump($this->fields['index'][$key][0]);
		    $table_current = $this->fields['index'][$key][0];
		    $primary_key = array($this->tables['origin'], $this->schema[$this->tables['origin']]['primary']);
		}

		if (($table_current != $this->fields['index'][$key][0]) || $key == $fields_count) {
		    $table_current = $this->fields['index'][$key][0];
		    $table_field_start = $key;

		    // Create new Item
		    if ($row_number == 0) {
			return $this->save_insert($table_current);
		    }
		    // Edit existing Item
		    else {
			return $this->save_update($table_current, $row_original, $row);
		    }
		}

		//print "Col: #" . $key . "(" . $table_current . "): " . $this->fields['index'][$key][0] . "." . $this->fields['index'][$key][1] ." = " . $row[$key] . " <br/>";
	    }
	}
    }

    function delete($row_id = null) {
	$table = $this->fields['index'][1][0];

	$sql = "DELETE FROM " . $table . " ";
	$sql .= " WHERE ";

	if (count($this->cond) > 0) {

	    foreach ($this->cond as $cond) {
		if (is_string($cond) && preg_match("/^\{.*\}$/", $cond)) {
		    $conds[] = preg_replace("/^\{(.*)\}$/", "$1", $cond);
		} else {
		    $conds[] = "`" . $cond[0] . "`.`" . $cond[1] . "` " . $cond[2] . " '" . addslashes($cond[3]) . "'";
		}
	    }
	    $sql .= join(" AND ", $conds);
	} else {
	    $where_field_name = ($this->schema[$table]['primary']) ? $this->schema[$table]['primary'] : $this->cond[0][1];
	    $where_field_value = $row_before[(($this->fields['table_field'][$table . "." . $where_field_name]) - 1)];


	    $sql .= $where_field_name . " = '" . addslashes($where_field_value) . "' ";
	}

	//print "SQL: $sql<br/>";

	$this->query = self::query($sql);

	return $where_field_value;
    }

    function save_update($table, $row_before, $row_after) {
	$fields_altered = array();

	foreach ($row_after as $field => $value) {
	    if ($row_before[$field] !== $row_after[$field]) {
		$fields_altered[] = $field;
	    }
	}

	$update_sql = array();
	$cond_sql = array();

	$where_field_name = ($this->schema[$table]['primary']) ? $this->schema[$table]['primary'] : $this->cond[0][1];
	$where_field_value = $row_before[(($this->fields['table_field'][$table . "." . $where_field_name]) - 1)];

	$sql = "UPDATE " . $table . " SET ";
	foreach ($fields_altered as $field) {
	    $value = (preg_match("/[\(\)]/", $row_after[$field])) ? $row_after[$field] : "'" . addslashes($row_after[$field]) . "'";

	    $update_sql[] = $this->fields['index'][(($field + 1))][1] . " = " . $value;
	}
	$sql .= join(", ", $update_sql);

	$sql .= " WHERE ";

	if (count($this->cond) > 0) {
	    foreach ($this->cond as $cond) {
		if (is_string($cond) && preg_match("/^\{.*\}$/", $cond)) {
		    $conds[] = preg_replace("/^\{(.*)\}$/", "$1", $cond);
		} else {
		    $conds[] = "`" . $cond[0] . "`.`" . $cond[1] . "` " . $cond[2] . " '" . addslashes($cond[3]) . "'";
		}
	    }
	    $sql .= join(" AND ", $conds);
	} else {
	    $where_field_name = ($this->schema[$table]['primary']) ? $this->schema[$table]['primary'] : $this->cond[0][1];
	    $where_field_value = $row_before[(($this->fields['table_field'][$table . "." . $where_field_name]) - 1)];


	    $sql .= $where_field_name . " = '" . addslashes($where_field_value) . "' ";
	}


	//print "SQL: $sql<br/>";
	$this->query = self::query($sql);

	return $where_field_value;
    }

    function save_insert($table) {
	$insert_sql = array();

	//Log::dump($this->fields['index']);

	$where_field_name = ($this->schema[$table]['primary']) ? $this->schema[$table]['primary'] : $this->cond[0][1];
	$where_field_value = $row[($field_start - $this->fields['table_field'][$table . "." . $where_field_name])];

	$sql_insert_keys = array();
	$sql_insert_values = array();

	foreach ($this->row as $field => $value) {
	    $sql_insert_keys[] = $this->fields['index'][($field + 1)][1];
	    if (@preg_match("/\w\(.*?\)/", $value)) {
		$sql_insert_values[] = $value;
	    } else {
		$sql_insert_values[] = "'" . addslashes($value) . "'";
	    }
	}


	$sql = "INSERT INTO " . $table . " (`" . join("`,`", $sql_insert_keys) . "`) VALUES (" . join(",", $sql_insert_values) . ")";


	$this->query = self::query($sql);

	return self::$connection->lastInsertId();
    }

    function join($table, $field = null, $condition = null, $table_parent = null, $field_parent = null, $join = "inner") {

	if ($condition) {
	    $this->cond($table, $condition);
	}

	$field = ($field) ? $field : $this->schema[$this->tables['origin']]['primary'];
	$table_parent = ($table_parent) ? $table_parent : $this->tables['origin'];
	$field_parent = ($field_parent) ? $field_parent : $field;

	$this->tables['join'][] = array($table, $field, $table_parent, $field_parent, $join);

	$field_index = 0;

	foreach ($this->schema[$table]['fields'] as $field_name => $field_def) {
	    $this->fields['index'][$this->field_count] = array($table, $field_name, $field_def);
	    $this->fields['table_field'][$table . "." . $field_name] = $this->field_count;
	    $this->fields['field'][$field_name] = $this->field_count;
	    $this->field_count++;
	}
    }

    function _schema_get($expire = 3600) {
	if (!Backend_Cache::get('_backend_orm_schema', $expire)) {
	    $tables = array();

	    $sql = "SHOW TABLES";
	    $tables_list = $this->query($sql);

	    foreach ($tables_list->fetchAll(PDO::FETCH_COLUMN, 0) as $table_name) {

		$sql = "DESC %s";
		$table = $this->query($sql, $table_name);

		foreach ($table->fetchAll() as $field) {
		    preg_match("/^(.+?)([\)\(\d]*)$/", $field['Type'], $field_match);

		    $field_type = $this->_schema_get_table_field_type($field_match[1]);
		    $field_length = preg_replace("/[^0-9]+/", "", $field_match[2]);

		    $tables[$table_name]['fields'][$field['Field']] = array(
			't' => $field_type,
			'l' => $field_length,
			'k' => $field['Key']
		    );

		    if ($field['Key'] == "PRI") {
			$tables[$table_name]['primary'] = $field['Field'];
		    }
		}
	    }
	    Backend_Cache::set('_backend_orm_schema', $tables);
	}


	$tables = Backend_Cache::get('_backend_orm_schema');

	return $tables;
    }

    function _schema_get_table_field_type($field) {
	foreach ($this->field_type as $type_regex => $type_value) {
	    //print "REGEX: " . $type_regex . " => " . $field . "<br/>";
	    if (preg_match($type_regex . "i", $field)) {
		return $type_value;
	    }
	}
    }

}

class Backend_ORM_Row {

    static $row = array();

    function Backend_ORM_Row($row, $fields) {
	self::$row = $row;

	foreach ($row as $id => $value) {
	    self::$row['_table_field'][$fields['index'][($id + 1)][0] . "." . $fields['index'][($id + 1)][1]] = $value;
	    self::$row['_field'][$fields['index'][($id + 1)][1]] = $value;
	    self::$row['_table'][$fields['index'][($id + 1)][0]][$fields['index'][($id + 1)][1]] = $value;
	}
    }

    function __get($field) {
	return $this->f($field);
    }

    function get($type = 'field') {
	switch ($type) {
	    case "table":
		return self::$row['_table'];
		break;

	    case "table_field":
		return self::$row['_table_field'];
		break;

	    default:
		return self::$row['_field'];
		break;
	}
    }

    function f($field) {
	if (self::$row['_field'][$field]) {
	    return self::$row['_field'][$field];
	}

	list($table, $field) = preg_split("/\./", $field);

	if (self::$row['_table'][$table][$field]) {
	    return self::$row['_table'][$table][$field];
	}

	if (is_array($field) && self::$row['_table'][$field[0]][$field[1]]) {
	    return self::$row['_table'][$field[0]][$field[1]];
	}
    }

}

class Backend_Cache {

    static $expire = null;

    function checksum($key) {
	return sprintf("%X", crc32($key));
    }

    function checksumFile($key) {
	return "/tmp/filecache_" . Backend_Cache::checksum($key);
    }

    function get($key, $expire = false) {
	if (file_exists(Backend_Cache::checksumFile($key))) {
	    if (
		    (!$expire || (time() - filemtime(Backend_Cache::checksumFile($key)) < $expire))
	    ) {
		$code = file_get_contents(Backend_Cache::checksumFile($key));
	    } else {
		unlink(Backend_Cache::checksumFile($key));
		$code = null;
	    }
	} else {
	    $code = null;
	}

	return json_decode($code, true);
    }

    function set($key, $value) {
	file_put_contents(Backend_Cache::checksumFile($key), json_encode($value));

	return $value;
    }

    function flush() {
	`rm -rf /tmp/filecache_*`;
    }

}