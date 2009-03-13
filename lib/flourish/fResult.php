<?php
/**
 * Representation of a result from a query against the fDatabase class
 * 
 * @copyright  Copyright (c) 2007-2009 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fResult
 * 
 * @version    1.0.0b2
 * @changes    1.0.0b2  Updated for new fCore API [wb, 2009-02-16]
 * @changes    1.0.0b   The initial implementation [wb, 2007-09-25]
 */
class fResult implements Iterator
{
	/**
	 * Composes text using fText if loaded
	 * 
	 * @param  string  $message    The message to compose
	 * @param  mixed   $component  A string or number to insert into the message
	 * @param  mixed   ...
	 * @return string  The composed and possible translated message
	 */
	static protected function compose($message)
	{
		$args = array_slice(func_get_args(), 1);
		
		if (class_exists('fText', FALSE)) {
			return call_user_func_array(
				array('fText', 'compose'),
				array($message, $args)
			);
		} else {
			return vsprintf($message, $args);
		}
	}
	
	
	/**
	 * The number of rows affected by an `INSERT`, `UPDATE`, `DELETE`, etc
	 * 
	 * @var integer
	 */
	private $affected_rows = 0;
	
	/**
	 * The auto incremented value from the query
	 * 
	 * @var integer
	 */
	private $auto_incremented_value = NULL;
	
	/**
	 * The character set to transcode from for MSSQL queries
	 * 
	 * @var string
	 */
	private $character_set = NULL;
	
	/**
	 * The current row of the result set
	 * 
	 * @var array
	 */
	private $current_row = NULL;
	
	/**
	 * The php extension used for database interaction
	 * 
	 * @var string
	 */
	private $extension = NULL;
	
	/**
	 * The position of the pointer in the result set
	 * 
	 * @var integer
	 */
	private $pointer;
	
	/**
	 * The result resource or array
	 * 
	 * @var mixed
	 */
	private $result = NULL;
	
	/**
	 * The number of rows returned by a select
	 * 
	 * @var integer
	 */
	private $returned_rows = 0;
	
	/**
	 * The SQL query
	 * 
	 * @var string
	 */
	private $sql = '';
	
	/**
	 * The type of the database
	 * 
	 * @var string
	 */
	private $type = NULL;
	
	/**
	 * The SQL from before translation - only applicable to translated queries
	 * 
	 * @var string
	 */
	private $untranslated_sql = NULL;
	
	
	/**
	 * Sets the PHP extension the query occured through
	 * 
	 * @internal
	 * 
	 * @param  string $type           The type of database: `'mssql'`, `'mysql'`, `'postgresql'`, `'sqlite'`
	 * @param  string $extension      The database extension used: `'array'`, `'mssql'`, `'mysql'`, `'mysqli'`, `'pgsql'`, `'sqlite'`
	 * @param  string $character_set  MSSQL only: the character set to transcode from since MSSQL doesn't do UTF-8
	 * @return fResult
	 */
	public function __construct($type, $extension, $character_set=NULL)
	{
		$valid_types = array('mssql', 'mysql', 'postgresql', 'sqlite');
		if (!in_array($type, $valid_types)) {
			throw new fProgrammerException(
				'The database type specified, %1$s, is invalid. Must be one of: %2$s.',
				$type,
				join(', ', $valid_types)
			);
		}
		
		// Certain extensions don't offer a buffered query, so it is emulated using an array
		if (in_array($extension, array('odbc', 'pdo', 'sqlsrv'))) {
			$extension = 'array';
		}
		
		$valid_extensions = array('array', 'mssql', 'mysql', 'mysqli', 'pgsql', 'sqlite');
		if (!in_array($extension, $valid_extensions)) {
			throw new fProgrammerException(
				'The database extension specified, %1$s, is invalid. Must be one of: %2$s.',
				$extension,
				join(', ', $valid_extensions)
			);
		}
		
		$this->type          = $type;
		$this->extension     = $extension;
		$this->character_set = $character_set;
	}
	
	
	/**
	 * Frees up the result object to save memory
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	public function __destruct()
	{
		if (!is_resource($this->result) && !is_object($this->result)) {
			return;
		}
		
		if ($this->extension == 'mssql') {
			mssql_free_result($this->result);
		} elseif ($this->extension == 'mysql') {
			mysql_free_result($this->result);
		} elseif ($this->extension == 'mysqli') {
			mysqli_free_result($this->result);
		} elseif ($this->extension == 'pgsql') {
			pg_free_result($this->result);
		} elseif ($this->extension == 'sqlite') {
			// SQLite doesn't have a way to free a result
		}
	}
	
	
	/**
	 * All requests that hit this method should be requests for callbacks
	 * 
	 * @param  string $method  The method to create a callback for
	 * @return callback  The callback for the method requested
	 */
	public function __get($method)
	{
		return array($this, $method);		
	}
	
	
	/**
	 * Gets the next row from the result and assigns it to the current row
	 * 
	 * @return void
	 */
	private function advanceCurrentRow()
	{
		if ($this->extension == 'mssql') {
			$row = mssql_fetch_assoc($this->result);
			$row = $this->fixDblibMSSQLDriver($row);
			
			// This is an unfortunate fix that required for databases that don't support limit
			// clauses with an offset. It prevents unrequested columns from being returned.
			if ($this->untranslated_sql !== NULL && isset($row['__flourish_limit_offset_row_num'])) {
				unset($row['__flourish_limit_offset_row_num']);
			}
				
		} elseif ($this->extension == 'mysql') {
			$row = mysql_fetch_assoc($this->result);
		} elseif ($this->extension == 'mysqli') {
			$row = mysqli_fetch_assoc($this->result);
		} elseif ($this->extension == 'pgsql') {
			$row = pg_fetch_assoc($this->result);
		} elseif ($this->extension == 'sqlite') {
			$row = sqlite_fetch_array($this->result, SQLITE_ASSOC);
		} elseif ($this->extension == 'array') {
			$row = $this->result[$this->pointer];
		}
		
		// This decodes the data coming out of MSSQL into UTF-8
		if ($this->type == 'mssql') {
			if ($this->character_set) {
				foreach ($row as $key => $value) {
					if (!is_string($value) || strpos($key, '__flourish_mssqln_') === 0) {
						continue;
					} 		
					$row[$key] = iconv($this->character_set, 'UTF-8', $value);
				}
			}
			$row = $this->decodeMSSQLNationalColumns($row);
		} 
		
		$this->current_row = $row;
	}
	
	
	/**
	 * Returns the number of rows affected by the query
	 * 
	 * @return integer  The number of rows affected by the query
	 */
	public function countAffectedRows()
	{
		return $this->affected_rows;
	}
	
	
	/**
	 * Returns the number of rows returned by the query
	 * 
	 * @return integer  The number of rows returned by the query
	 */
	public function countReturnedRows()
	{
		return $this->returned_rows;
	}
	
	
	/**
	 * Returns the current row in the result set (required by iterator interface)
	 * 
	 * @throws fNoRowsException
	 * @throws fNoRemainingException
	 * @internal
	 * 
	 * @return array  The current row
	 */
	public function current()
	{
		if(!$this->returned_rows) {
			throw new fNoRowsException('The query did not return any rows');
		}
		
		if (!$this->valid()) {
			throw new fNoRemainingException('There are no remaining rows');
		}
		
		// Primes the result set
		if ($this->pointer === NULL) {
			$this->pointer = 0;
			$this->advanceCurrentRow();
		}
		
		return $this->current_row;
	}
	
	
	/**
	 * Decodes national (unicode) character data coming out of MSSQL into UTF-8
	 * 
	 * @param  array $row  The row from the database
	 * @return array  The fixed row
	 */
	private function decodeMSSQLNationalColumns($row)
	{
		if (strpos($this->sql, '__flourish_mssqln_') === FALSE) {
			return $row;
		}
		
		$columns = array_keys($row);
		
		foreach ($columns as $column) {
			if (substr($column, 0, 18) != '__flourish_mssqln_') {
				continue;
			}	
			
			$real_column = substr($column, 18);
			
			$row[$real_column] = iconv('ucs-2le', 'utf-8', $row[$column]);
			unset($row[$column]);
		}
		
		return $row;
	}
	
	
	/**
	 * Returns all of the rows from the result set
	 * 
	 * @return array  The array of rows
	 */
	public function fetchAllRows()
	{
		$this->seek(0);
		
		$all_rows = array();
		foreach ($this as $row) {
			$all_rows[] = $row;
		}
		return $all_rows;
	}
	
	
	/**
	 * Returns the row next row in the result set (where the pointer is currently assigned to)
	 * 
	 * @throws fNoRowsException
	 * @throws fNoRemainingException
	 * 
	 * @return array  The associative array of the row
	 */
	public function fetchRow()
	{
		$row = $this->current();
		$this->next();
		return $row;
	}
	
	
	/**
	 * Wraps around ::fetchRow() and returns the first field from the row instead of the whole row
	 * 
	 * @throws fNoRowsException
	 * @throws fNoRemainingException
	 * 
	 * @return string|number  The first scalar value from ::fetchRow()
	 */
	public function fetchScalar()
	{
		$row = $this->fetchRow();
		return array_shift($row);
	}
	
	
	/**
	 * Warns the user about bugs in the DBLib driver for MSSQL, fixes some bugs
	 * 
	 * @param  array $row  The row from the database
	 * @return array  The fixed row
	 */
	private function fixDblibMSSQLDriver($row)
	{
		static $using_dblib = NULL;
		
		if ($using_dblib === NULL) {
		
			// If it is not a windows box we are definitely not using dblib
			if (!fCore::checkOS('windows')) {
				$using_dblib = FALSE;
			
			// Check this windows box for dblib
			} else {
				ob_start();
				phpinfo(INFO_MODULES);
				$module_info = ob_get_contents();
				ob_end_clean();
				
				$using_dblib = preg_match('#FreeTDS#ims', $module_info, $match);
			}
		}
		
		if (!$using_dblib) {
			return $row;
		}
		
		foreach ($row as $key => $value) {
			if ($value == ' ') {
				$row[$key] = '';
				trigger_error(
					self::compose(
						'A single space was detected coming out of the database and was converted into an empty string - see %s for more information',
						'http://bugs.php.net/bug.php?id=26315'
					),
					E_USER_NOTICE
				);
			}
			if (strlen($key) == 30) {
				trigger_error(
					self::compose(
						'A column name exactly 30 characters in length was detected coming out of the database - this column name may be truncated, see %s for more information.',
						'http://bugs.php.net/bug.php?id=23990'
					),
					E_USER_NOTICE
				);
			}
			if (strlen($value) == 256) {
				trigger_error(
					self::compose(
						'A value exactly 255 characters in length was detected coming out of the database - this value may be truncated, see %s for more information.',
						'http://bugs.php.net/bug.php?id=37757'
					),
					E_USER_NOTICE
				);
			}
		}
		
		return $row;
	}
	
	
	/**
	 * Returns the last auto incremented value for this database connection. This may or may not be from the current query.
	 * 
	 * @return integer  The auto incremented value
	 */
	public function getAutoIncrementedValue()
	{
		return $this->auto_incremented_value;
	}
	
	
	/**
	 * Returns the result
	 * 
	 * @internal
	 * 
	 * @return mixed  The result of the query
	 */
	public function getResult()
	{
		return $this->result;
	}
	
	
	/**
	 * Returns the SQL used in the query
	 * 
	 * @return string  The SQL used in the query
	 */
	public function getSQL()
	{
		return $this->sql;
	}
	
	
	/**
	 * Returns the SQL as it was before translation
	 * 
	 * @return string  The SQL from before translation
	 */
	public function getUntranslatedSQL()
	{
		return $this->untranslated_sql;
	}
	
	
	/**
	 * Returns the current row number (required by iterator interface)
	 * 
	 * @throws fNoRowsException
	 * @internal
	 * 
	 * @return integer  The current row number
	 */
	public function key()
	{
		if ($this->pointer === NULL) {
			$this->current();
		}
		
		return $this->pointer;
	}
	
	
	/**
	 * Advances to the next row in the result (required by iterator interface)
	 * 
	 * @throws fNoRowsException
	 * @internal
	 * 
	 * @return void
	 */
	public function next()
	{
		if ($this->pointer === NULL) {
			$this->current();
		}
		
		$this->pointer++;
		
		if ($this->valid()) {
			$this->advanceCurrentRow();
		} else {
			$this->current_row = NULL;
		}
	}
	
	
	/**
	 * Rewinds the query (required by iterator interface)
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	public function rewind()
	{
		try {
			$this->seek(0);
		} catch (Exception $e) { }
	}
	
	
	/** 
	 * Seeks to the specified zero-based row for the specified SQL query
	 * 
	 * @throws fNoRowsException
	 * 
	 * @param  integer $row  The row number to seek to (zero-based)
	 * @return void
	 */
	public function seek($row)
	{
		if(!$this->returned_rows) {
			throw new fNoRowsException('The query did not return any rows');
		}
		
		if ($row >= $this->returned_rows || $row < 0) {
			throw new fProgrammerException('The row requested does not exist');
		}
		
		$this->pointer = $row;
					
		if ($this->extension == 'mssql') {
			$success = mssql_data_seek($this->result, $row);
		} elseif ($this->extension == 'mysql') {
			$success = mysql_data_seek($this->result, $row);
		} elseif ($this->extension == 'mysqli') {
			$success = mysqli_data_seek($this->result, $row);
		} elseif ($this->extension == 'pgsql') {
			$success = pg_result_seek($this->result, $row);
		} elseif ($this->extension == 'sqlite') {
			$success = sqlite_seek($this->result, $row);
		} elseif ($this->extension == 'array') {
			// Do nothing since we already changed the pointer
			$success = TRUE;
		}
		
		if (!$success) {
			throw new fSQLException(
				'There was an error seeking to row %s',
				$row
			);
		}
		
		$this->advanceCurrentRow();
	}
	
	
	/**
	 * Sets the number of affected rows
	 * 
	 * @internal
	 * 
	 * @param  integer $affected_rows  The number of affected rows
	 * @return void
	 */
	public function setAffectedRows($affected_rows)
	{
		$this->affected_rows = (int) $affected_rows;
	}
	
	
	/**
	 * Sets the auto incremented value
	 * 
	 * @internal
	 * 
	 * @param  integer $auto_incremented_value  The auto incremented value
	 * @return void
	 */
	public function setAutoIncrementedValue($auto_incremented_value)
	{
		$this->auto_incremented_value = ($auto_incremented_value == 0) ? NULL : $auto_incremented_value;
	}
	
	
	/**
	 * Sets the result from the query
	 * 
	 * @internal
	 * 
	 * @param  mixed $result  The result from the query
	 * @return void
	 */
	public function setResult($result)
	{
		$this->result = $result;
	}
	
	
	/**
	 * Sets the number of rows returned
	 * 
	 * @internal
	 * 
	 * @param  integer $returned_rows  The number of rows returned
	 * @return void
	 */
	public function setReturnedRows($returned_rows)
	{
		$this->returned_rows = (int) $returned_rows;
		if ($this->returned_rows) {
			$this->affected_rows = 0;
		}
	}
	
	
	/**
	 * Sets the SQL used in the query
	 * 
	 * @internal
	 * 
	 * @param  string $sql  The SQL used in the query
	 * @return void
	 */
	public function setSQL($sql)
	{
		$this->sql = $sql;
	}
	
	
	/**
	 * Sets the SQL from before translation
	 * 
	 * @internal
	 * 
	 * @param  string $untranslated_sql  The SQL from before translation
	 * @return void
	 */
	public function setUntranslatedSQL($untranslated_sql)
	{
		$this->untranslated_sql = $untranslated_sql;
	}
	
	
	/**
	 * Throws an fNoResultException if the query did not return any rows
	 * 
	 * @throws fNoRowsException
	 * 
	 * @param  string $message  The message to use for the exception if there are no rows in this result set
	 * @return void
	 */
	public function tossIfNoRows($message=NULL)
	{
		if (!$this->returned_rows && !$this->affected_rows) {
			if ($message === NULL) {
				$message = self::compose('No rows were returned or affected by the query');	
			}
			throw new fNoRowsException($message);
		}
	}
	
	
	/**
	 * Returns if the query has any rows left
	 * 
	 * @return boolean  If the iterator is still valid
	 */
	public function valid()
	{
		if (!$this->returned_rows) {
			return FALSE;
		}
		
		if ($this->pointer === NULL) {
			return TRUE;
		}
		
		return ($this->pointer < $this->returned_rows);
	}
}



/**
 * Copyright (c) 2007-2009 Will Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */