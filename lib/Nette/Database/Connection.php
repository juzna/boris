<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Database
 */



/**
 * Represents a connection between PHP and a database server.
 *
 * @author     David Grudl
 *
 * @property-read  ISupplementalDriver  $supplementalDriver
 * @property-read  string               $dsn
 * @property-read  PDO                  $pdo
 * @package Nette\Database
 */
class NConnection extends NObject
{
	/** @var string */
	private $dsn;

	/** @var ISupplementalDriver */
	private $driver;

	/** @var NSqlPreprocessor */
	private $preprocessor;

	/** @var NSelectionFactory */
	private $selectionFactory;

	/** @var PDO */
	private $pdo;

	/** @var array of function(Statement $result, $params); Occurs after query is executed */
	public $onQuery;



	public function __construct($dsn, $user = NULL, $password = NULL, array $options = NULL, $driverClass = NULL)
	{
		$this->pdo = $pdo = new PDO($this->dsn = $dsn, $user, $password, $options);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('NStatement', array($this)));

		$driverClass = ($tmp=$driverClass) ? $tmp : 'N' . ucfirst(str_replace('sql', 'Sql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME))) . 'Driver';
		$this->driver = new $driverClass($this, (array) $options);
		$this->preprocessor = new NSqlPreprocessor($this);
	}



	/** @return string */
	public function getDsn()
	{
		return $this->dsn;
	}



	/** @return PDO */
	public function getPdo()
	{
		return $this->pdo;
	}



	/** @return ISupplementalDriver */
	public function getSupplementalDriver()
	{
		return $this->driver;
	}



	/** @return bool */
	public function beginTransaction()
	{
		return $this->pdo->beginTransaction();
	}



	/** @return bool */
	public function commit()
	{
		return $this->pdo->commit();
	}



	/** @return bool */
	public function rollBack()
	{
		return $this->pdo->rollBack();
	}



	/**
	 * @param  string  sequence object
	 * @return string
	 */
	public function getInsertId($name = NULL)
	{
		return $this->pdo->lastInsertId($name);
	}



	/**
	 * @param  string  string to be quoted
	 * @param  int     data type hint
	 * @return string
	 */
	public function quote($string, $type = PDO::PARAM_STR)
	{
		return $this->pdo->quote($string, $type);
	}



	/**
	 * Generates and executes SQL query.
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return NStatement
	 */
	public function query($statement)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args);
	}



	/**
	 * Generates and executes SQL query.
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return int     number of affected rows
	 */
	public function exec($statement)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args)->rowCount();
	}



	/**
	 * @param  string  statement
	 * @param  array
	 * @return NStatement
	 */
	public function queryArgs($statement, array $params)
	{
		if ($params) {
			list($statement, $params) = $this->preprocessor->process($statement, $params);
		}
		return $this->pdo->prepare($statement)->execute($params);
	}



	/********************* shortcuts ****************d*g**/



	/**
	 * Shortcut for query()->fetch()
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return NRow
	 */
	public function fetch($args)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args)->fetch();
	}



	/**
	 * Shortcut for query()->fetchColumn()
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return mixed
	 */
	public function fetchColumn($args)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args)->fetchColumn();
	}



	/**
	 * Shortcut for query()->fetchPairs()
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return array
	 */
	public function fetchPairs($args)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args)->fetchPairs();
	}



	/**
	 * Shortcut for query()->fetchAll()
	 * @param  string  statement
	 * @param  mixed   [parameters, ...]
	 * @return array
	 */
	public function fetchAll($args)
	{
		$args = func_get_args();
		return $this->queryArgs(array_shift($args), $args)->fetchAll();
	}



	/********************* Selection ****************d*g**/



	/**
	 * Creates selector for table.
	 * @param  string
	 * @return NTableSelection
	 */
	public function table($table)
	{
		if (!$this->selectionFactory) {
			$this->selectionFactory = new NSelectionFactory($this);
		}
		return $this->selectionFactory->create($table);
	}



	/**
	 * @return NConnection   provides a fluent interface
	 */
	public function setSelectionFactory(NSelectionFactory $selectionFactory)
	{
		$this->selectionFactory = $selectionFactory;
		return $this;
	}



	/** @deprecated */
	function setDatabaseReflection()
	{
		trigger_error(__METHOD__ . '() is deprecated; use setSelectionFactory() instead.', E_USER_WARNING);
		return $this;
	}



	/** @deprecated */
	function setCacheStorage()
	{
		trigger_error(__METHOD__ . '() is deprecated; use setSelectionFactory() instead.', E_USER_WARNING);
	}



	/** @deprecated */
	function lastInsertId($name = NULL)
	{
		trigger_error(__METHOD__ . '() is deprecated; use getInsertId() instead.', E_USER_WARNING);
		return $this->getInsertId($name);
	}

}
