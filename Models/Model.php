<?php
/**
 * Sample RESTFul API Project
 *
 * @link https://github.com/enaumchuk/sample for the canonical source repository
 * @copyright Copyright (c) 2017 Edward Naumchuk <ed.naumchuk@secure12.net>
 * @author Edward Naumchuk <ed.naumchuk@secure12.net>
 */
namespace APP\Models;

abstract class Model extends ModelAbstract
{
	protected $db = null;
	protected $db_name = null;
	protected $table_name = null;
	//protected $id = null;

	protected $storage_path = 'core/storage/app/files/';
	protected $asset_path = 'assets/app/assets/';

	//protected $object_fields = array();
	protected $expression_fields = array();

	/*const DRAFT = "10";
	const ACTIVE = "20";
	const PROCESSING = "25";
	const INACTIVE = "30";
	const SUBMITTED = '40';
	const COMPLETED = '50';
	const ASSIGNED = '60';
	const CANCELLED = '70';*/

	public function __construct()
	{
		$this->setDbConnection();
	}

	protected function setDbConnection($conn_name = null)
	{
		$this->db =& \APP\Common\DbConnection::getConnection($conn_name);
		$this->db_name = \APP\Common\DbConnection::getConnDbName($conn_name);
	}

	/**
	 * get current database name
	 */
	public function getDbName()
	{
		return $this->db_name;
	}

	/**
	 * escape string for db statement
	 *
	 * @param $string
	 */
	public function escapeString($string)
	{
		return $this->db->real_escape_string((string) $string);
	}

	/**
	 * get a single record
	 *
	 * @param mixed $object_id
	 */
	public function get($object_id)
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// we need id and table name
		if (empty($this->id) || empty($this->table_name)) {
			return false;
		}

		$result_arr = array();

		if (empty($object_id)) {
			return $result_arr;
		}

		// sanitize id
		$object_id = intval($object_id);

		// get data
		$sql_query =
			"SELECT *
			FROM `{$this->db_name}`.`{$this->table_name}`
			WHERE `{$this->id}` = {$object_id}";
		$result = $this->db->query($sql_query);
		if ($result->num_rows > 0) {
			$result_arr = $result->fetch_assoc();

			// restore arrays
			if (in_array('arr', $this->object_fields)) {
				foreach ($this->object_fields as $field => $type) {
					if (($type == 'arr') && isset($result_arr[$field]) && !empty($result_arr[$field])) {
						$result_arr[$field] = json_decode($result_arr[$field], true);
					}
				}
			}
			// restore compressed arrays
			if (in_array('arrcompressed', $this->object_fields)) {
				foreach ($this->object_fields as $field => $type) {
					if (($type == 'arrcompressed') && isset($result_arr[$field]) && !empty($result_arr[$field])) {
						$result_arr[$field] = json_decode(gzuncompress($result_arr[$field]), true);
					}
				}
			}
		}

		return $result_arr;
	}

	/**
	* change record's status
	*
	* @param mixed $object_id
	* @param mixed $new_status
	* @param mixed $old_status
	*/
	public function changeStatus($object_id, $new_status, $old_status = null)
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}

		$object_id = intval($object_id);
		$new_status = intval($new_status);

		// update data
		$sql_query =
			"UPDATE `{$this->db_name}`.`{$this->table_name}`
			SET `created_at` = NOW(), `status` = {$new_status}
			WHERE `{$this->id}` = {$object_id}";
		if (!empty($old_status)) {
			$sql_query .= " AND `status` = ".intval($old_status);
		}

		$result = $this->db->query($sql_query);

		if ($result) {
			$result = ($this->db->affected_rows > 0) ? true : false;
		}

		return $result;
	}

	/**
	 * make record active
	 *
	 * @param mixed $object_id
	 */
	public function markActive($object_id)
	{
		if (empty($object_id)) {
			return false;
		}

		return $this->changeStatus($object_id, self::ACTIVE);
	}

	/**
	 * mark record processing
	 *
	 * @param mixed $object_id
	 */
	public function markProcessing($object_id, $old_status = null)
	{
		if (empty($object_id)) {
			return false;
		}

		return $this->changeStatus($object_id, self::PROCESSING, $old_status);
	}

	/**
	* search record id by where clause
	* can be use to check record existance
	*
	* @param mixed $filters
	*/
	public function idSearch(array $filters)
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// we need id and table name
		if (empty($this->id) || empty($this->table_name)) {
			return false;
		}

		if (!empty($filters) && $filters != null) {
			$clause_where = "WHERE ".implode(" AND ", $filters);
		} else {
			$clause_where = "";
		}

		$sql_query =
			"SELECT `{$this->id}`
			FROM `{$this->db_name}`.`{$this->table_name}`
			{$clause_where} LIMIT 1";
		$result = $this->db->query($sql_query);
		if ($result && ($result->num_rows > 0)) {
			$result_arr = $result->fetch_assoc();
			return $result_arr[$this->id];
		} else {
			return false;
		}
	}


	/**
	 * search by slug
	 *
	 * @param mixed $slug_field
	 * @param mixed $slug
	 *
	 * @return record id or false
	 */
	public function slugSearch($slug_field, $slug)
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// we need id and table name
		if (empty($this->id) || empty($this->table_name)) {
			return false;
		}

		$sql_query =
			"SELECT `{$this->id}`
			FROM `{$this->db_name}`.`{$this->table_name}`
			WHERE `{$slug_field}` = '".$this->db->real_escape_string($slug)."'";
		$result = $this->db->query($sql_query);
		if ($result && ($result->num_rows > 0)) {
			$result_arr = $result->fetch_assoc();
			return $result_arr[$this->id];
		} else {
			return false;
		}
	}

	/**
	 * remove records by filter clause
	 *
	 * @param mixed $filters
	 *
	 * @return affected rows or false
	 */
	public function remove(array $filters)
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// we need table name
		if (empty($this->table_name)) {
			return false;
		}

		$arr_filters = array();
		foreach ($filters as $key => $value) {
			$arr_filters[] = "`{$key}`='".$this->db->real_escape_string($value)."'";
		}

		$sql_query =
			"DELETE	FROM `{$this->db_name}`.`{$this->table_name}`
			WHERE ".implode(" AND ", $arr_filters);
		$result = $this->db->query($sql_query);
		if ($result) {
			return $this->db->affected_rows;
		}
		return $result;
	}

	/**
	 * fetch group of records by criteria
	 *
	 * @param mixed $object_id
	 */
	public function fetch($field_list = array(), $offset = 0, $limit = null, $sort = array(), $filters = array(), $search_fields = array(), $search_value = null, $joins = array())
	{
		// need to setup the object first
		if (empty($this->object_fields)) {
			$this->setupObject();
		}

		// analyze parameters
		if (!empty($field_list)) {
			$fields = "`{$this->table_name}`.`".implode("`,`{$this->table_name}`.`", $field_list)."`";
		} else {
			$fields = "`{$this->table_name}`.*";
			$field_list = array_keys($this->object_fields);
		}

		// create join condition
		$join_condition = '';
		if (!empty($joins)) {
			foreach ($joins as $join_key => $join_data) {
				if (!empty($join_data['fields'])) {
					$fields .= ",".$join_data['fields'];
				}
				$join_condition .=
					" LEFT JOIN `{$this->db_name}`.`{$join_data['table']}` AS {$join_key}
					ON ".$join_data['condition']."
					";
			}
		}

		// array fields
		$arr_fields = array();
		foreach ($field_list as $field) {
			if (isset($this->object_fields[$field]) && ($this->object_fields[$field] == 'arr')) {
				$arr_fields[] = $field;
			}
		}
		// compressed array fields
		$arr_compressed_fields = array();
		foreach ($field_list as $field) {
			if (isset($this->object_fields[$field]) && ($this->object_fields[$field] == 'arrcompressed')) {
				$arr_compressed_fields[] = $field;
			}
		}

		// set where clause
		if (!empty($search_value) && !empty($search_fields)) {
			$search_value = "'%".$this->db->real_escape_string($search_value)."%'";
			$search_clause = "(".implode(" LIKE {$search_value} OR ", $search_fields)." LIKE {$search_value} )";
		} else {
			$search_clause = "";
		}

		if (!empty($filters) && $filters != null) {
			$clause_where = "WHERE ";
			foreach ($filters as $filter_data) {
				if (substr_count($filter_data, ".") == 0) {
					$clause_where .= "`{$this->table_name}`.".$filter_data." AND ";
				} else {
					$clause_where .= $filter_data." AND ";
				}
			}
			$clause_where = substr($clause_where, 0, -4);
		} else {
			$clause_where = "";
		}

		/*
		if (!empty($filters) && $filters != null) {
			$clause_where = "`{$this->table_name}`.".implode(" AND `{$this->table_name}`.", $filters);
		} else {
			$clause_where = "";
		}
*/
		if ($clause_where == "" && $search_clause != "") {
			$clause_where = " WHERE ";
		} elseif ($search_clause != "") {
			$clause_where .= " AND ";
		}


		// set sort parameters
		if (!empty($sort)) {
			$clause_sort = "ORDER BY ".implode(",", $sort);
		} else {
			$clause_sort = "";
		}
		// set limit parameter
		if (!empty($limit)) {
			$limit = "LIMIT ".(empty($offset) ? "" : intval($offset).",").intval($limit);
		} else {
			$limit = "";
		}


		$result_arr = array();

		// get data
		$sql_query =
			"SELECT {$fields}
			FROM `{$this->db_name}`.`{$this->table_name}`
			{$join_condition} {$clause_where}  {$search_clause}  {$clause_sort} {$limit}";
		$result = $this->db->query($sql_query);
		if ($result && ($result->num_rows > 0)) {
			$result_arr = $result->fetch_all(MYSQLI_ASSOC);
			// restore array fields
			if (!empty($arr_fields) || !empty($arr_compressed_fields)) {
				foreach ($result_arr as $key => $record) {
					// restore array fields
					foreach ($arr_fields as $arr_field) {
						if (!is_null($record[$arr_field])) {
							$result_arr[$key][$arr_field] = json_decode($record[$arr_field], true);
							if (!is_array($result_arr[$key][$arr_field])) {
								$result_arr[$key][$arr_field] = [];
							}
						}
					}
					// restore compressed array fields
					foreach ($arr_compressed_fields as $arr_field) {
						if (!is_null($record[$arr_field])) {
							$result_arr[$key][$arr_field] = json_decode(gzuncompress($record[$arr_field]), true);
							if (!is_array($result_arr[$key][$arr_field])) {
								$result_arr[$key][$arr_field] = [];
							}
						}
					}
				}
			}
		}

		return $result_arr;
	}

	/**
	 * count group of records by criteria
	 *
	 * @param mixed $object_id
	 */
	public function count($filters = array(), $search_fields = array(), $search_value = null, $joins = array(), $groups = array(), $orders = array())
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// set where clause
		if (!empty($search_value) && !empty($search_fields)) {
			$search_value = "'%".$this->db->real_escape_string($search_value)."%'";
			$search_clause = implode(" LIKE {$search_value} OR ", $search_fields)." LIKE {$search_value} ";
		} else {
			$search_clause = "";
		}
		if (!empty($filters) && $filters != null) {
			$clause_where = "WHERE ";
			foreach ($filters as $filter_data) {
				if (substr_count($filter_data, ".") == 0) {
					$clause_where .= "`{$this->table_name}`.".$filter_data." AND ";
				} else {
					$clause_where .= $filter_data." AND ";
				}
			}
			$clause_where = substr($clause_where, 0, -4);
		} else {
			$clause_where = "";
		}

		if ($clause_where == "" && $search_clause != "") {
			$clause_where = " WHERE ";
		} elseif ($search_clause != "") {
			$clause_where .= " AND ";
		}
		// create join condition
		$join_condition = '';
		if (!empty($joins)) {
			foreach ($joins as $join_key => $join_data) {
				$join_condition .=
					" LEFT JOIN `{$this->db_name}`.`{$join_data['table']}` AS {$join_key}
					ON ".$join_data['condition']."
					";
			}
		}

		// create order condition
		$order_condition = '';
		if (!empty($orders)) {
			$order_condition = " ORDER BY ".implode(",", $orders);
		}

		// create group condition
		$group_condition = '';
		if (!empty($groups)) {
			$group_condition = " GROUP BY ".implode(",", $groups);
		}

		// get data
		$sql_query =
			"SELECT COUNT(*) AS cnt_rec
			FROM `{$this->db_name}`.`{$this->table_name}`
			{$join_condition} {$clause_where} {$search_clause}
			{$group_condition}{$order_condition}";
		$result = $this->db->query($sql_query);
		if ($result && ($result->num_rows > 0)) {
			$record = $result->fetch_assoc();
			return $record['cnt_rec'];
		}
		return 0;
	}

	/**
	 * save a record
	 *
	 * @param Array $data_arr - array key=>value of fields to save
	 * @param Array $expr_overrides - array key=> (string) mysql expression
	 */
	public function save(array $data_arr = array(), array $expr_overrides = array())
	{
		if (empty($this->object_fields)) {
			$this->setupObject();
		}
		// we need id and table name
		if (empty($this->id) || empty($this->table_name)) {
			return false;
		}

		// prepare the object
		$save_arr = array();
		/*if (!isset($data_arr['status'])) {
			$data_arr['status'] = self::ACTIVE;
		}*/

		// normalize fields
		foreach ($data_arr as $data_field => $data_value) {
			if (isset($this->object_fields[$data_field])) {
				if (($data_field == $this->id) && is_null($data_value)) {
					continue;
				}
				switch ($this->object_fields[$data_field]) {
					case 'int':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : intval($data_value));
						break;
					case 'str':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : '"'.$this->db->real_escape_string($data_value).'"');
						break;
					case 'dec':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : '"'.sprintf("%F", (float) $data_value).'"');
						break;
					case 'arr':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : '"'.$this->db->real_escape_string(json_encode($data_value)).'"');
						break;
					case 'arrcompressed':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : '"'.$this->db->real_escape_string(gzcompress(json_encode($data_value))).'"');
						break;
					case 'bool':
						$save_arr[$data_field] = (is_null($data_value) ? 'NULL' : (empty($data_value) ? 0 : 1));
						break;
					default:
						$save_arr[$data_field] = 'NULL';
						break;
				}
			}
		}

		// add expression overrides
		if (!empty($expr_overrides)) {
			foreach ($expr_overrides as $data_field => $data_expression) {
				if (isset($this->object_fields[$data_field])) {
					$save_arr[$data_field] = $data_expression;
				}
			}
		}

		// return false if nothing to save
		if (empty($save_arr)) {
			return false;
		}

		if (isset($save_arr[$this->id])) {
			// update statement
			$id_value = $save_arr[$this->id];
			unset($save_arr[$this->id]);
			// prepare array
			if (empty($save_arr)) {
				return false;
			}
			foreach ($save_arr as $key => $value) {
				$save_arr[$key] = "`{$key}`={$value}";
			}

			$sql_query =
				"UPDATE	`{$this->db_name}`.`{$this->table_name}`
				SET		".implode(',', $save_arr)."
				WHERE 	`{$this->id}` = {$id_value}";
		} else {
			// insert statement
			$id_value = null;
			$fields_str = array();
			foreach ($save_arr as $key => $value) {
				$fields_str[] = "`{$key}`";
			}
			//$fields
			$sql_query =
				"INSERT INTO `{$this->db_name}`.`{$this->table_name}`
						(".implode(',', $fields_str).")
				VALUES (".implode(',', $save_arr).")";
		}
		$result = $this->db->query($sql_query);
		if ($result !== false) {
			$result = (isset($id_value) ? $id_value : $this->db->insert_id);
			return $result;
		}
		return false;
	}
}
