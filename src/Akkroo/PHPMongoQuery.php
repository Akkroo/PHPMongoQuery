<?php

/*
 * Copyright (C) 2013 Akkroo Solutions Ltd
 * 
 */

namespace Akkroo;

use Exception;

abstract class PHPMongoQuery {
	
	/**
	 * Execute a Mongo query on a document
	 * 
	 * @param mixed $query		A boolean value or an array defining a query
	 * @param array $document	The document to query
	 * @param array $options	Any options:
	 *	'unknownOperatorCallback' - a callback to be called if an operator can't be found.  The function definition is function($operator, $operatorValue, $field, $document). return true or false. 
	 * @return boolean
	 * @throws Exception
	 */
	public static function executeQuery($query, array &$document, array $options = []) {
		if(DEBUG) {
			$logger = newLogger('PHPMongoQuery');
			$logger->debug('executeQuery called', ['query' => $query, 'document' => $document]);
		}
		
		if(!is_array($query)) return (bool)$query;
		
		return self::_executeQuery($query, $document, $options);
	}
	
	/**
	 * Internal execute query
	 * 
	 * This expects an array from the query and has an additional logical operator (for the root query object the logical operator is always $and so this is not required)
	 * 
	 * @param array $query
	 * @param array $document
	 * @param array $options
	 * @param type $logicalOperator
	 * @return boolean
	 * @throws Exception
	 */
	private static function _executeQuery(array $query, array &$document, array $options, $logicalOperator = '$and') {
		if($logicalOperator !== '$and' && (!count($query) || !isset($query[0])))
			throw new Exception($logicalOperator.' requires nonempty array');
		if(DEBUG) {
			$logger = newLogger('PHPMongoQuery');
			$logger->debug('_executeQuery called', ['query' => $query, 'document' => $document, 'logicalOperator' => $logicalOperator]);
		}
		foreach($query as $k => $q) {
			$pass = true;
			if(is_string($k) && substr($k, 0, 1) === '$') {
				// key is an operator at this level, except $not, which can be at any level
				if($k === '$not')
					$pass = !self::_executeQuery($q, $document, $options);
				else
					$pass = self::_executeQuery($q, $document, $options, $k);
			} else if($logicalOperator === '$and') { // special case for $and
				if(is_int($k)) { // $q is an array of query objects
					$pass = self::_executeQuery($q, $document, $options);
				} else if(is_array($q)) { // query is array, run all queries on field.  All queries must match. e.g { 'age': { $gt: 24, $lt: 52 } }
					$pass = self::_executeQueryOnElement($q, $k, $document);
				} else {
					// key value means equality
					$pass = self::_executeOperatorOnElement('$e', $q, $k, $document);
				}
			} else { // $q is array of query objects e.g '$or' => [{'fullName' => 'Nick'}]
				$pass = self::_executeQuery($q, $document, $options, '$and');
			}
			switch($logicalOperator) {
				case '$and': // if any fail, query fails
					if(!$pass) return false;
					break;
				case '$or': // if one succeeds, query succeeds
					if($pass) return true;
					break;
				case '$nor': // if one succeeds, query fails
					if($pass) return false;
					break;
				default:
					$logger->warning('_executeQuery could not find logical operator', ['query' => $query, 'document' => $document, 'logicalOperator' => $logicalOperator]);
					return false;
			}
		}
		switch($logicalOperator) {
			case '$and': // all succeeded, query succeeds
				return true;
			case '$or': // all failed, query fails
				return false;
			case '$nor': // all failed, query succeeded
				return true;
			default:
				$logger->warning('_executeQuery could not find logical operator', ['query' => $query, 'document' => $document, 'logicalOperator' => $logicalOperator]);
				return false;
		}
		throw new Exception('Reached end of _executeQuery without returning a value');
	}
	
	/**
	 * Execute a query object on an element
	 * 
	 * @param array $query
	 * @param string $field
	 * @param array $document
	 * @return boolean
	 * @throws Exception
	 */
	private static function _executeQueryOnElement(array $query, $element, array &$document) {
		if(DEBUG) {
			$logger = newLogger('PHPMongoQuery');
			$logger->debug('executeQueryOnElement called', ['query' => $query, 'element' => $element, 'document' => $document]);
		}
		// iterate through query operators
		foreach($query as $op => $opVal) {
			if(!self::_executeOperatorOnElement($op, $opVal, $element, $document)) return false;
		}
		return true;
	}
	
	/**
	 * Execute a Mongo Operator on an element
	 * 
	 * @param string $operator		The operator to perform
	 * @param mixed $operatorValue	The value to provide the operator
	 * @param string $element		The target element.  Can be an object path eg price.shoes
	 * @param array $document		The document in which to find the element
	 * @param array $options		Options
	 * @return boolean				The result
	 * @throws Exception			Exceptions on invalid operators, invalid unknown operator callback, and invalid operator values
	 */
	private static function _executeOperatorOnElement($operator, $operatorValue, $element, array &$document, array $options = []) {
		if(DEBUG) {
			$logger = newLogger('PHPMongoQuery');
			$logger->debug('executeOperatorOnElement called', ['operator' => $operator, 'operatorValue' => $operatorValue, 'element' => $element, 'document' => $document]);
		}
		
		if($operator === '$not') {
			return !self::_executeQueryOnElement($operatorValue, $element, $document);
		}
		
		$elementSpecifier = explode('.', $element);
		$v =& $document;
		$exists = true;
		foreach($elementSpecifier as $es) {
			if(isset($v[$es]))
				$v =& $v[$es];
			else {
				$exists = false;
				break;
			}
		}
		
		switch($operator) {
			case '$all':
				if(!$exists) return false;
				if(!is_array($operatorValue)) throw new Exception('$all requires array');
				if(count($operatorValue) === 0) return false;
				if(!is_array($v)) {
					if(count($operatorValue) === 1)
						return $v === $operatorValue[0];
					return false;
				}
				return count(array_intersect($v, $operatorValue)) === count($operatorValue);
			case '$e':
				if(!$exists) return false;
				if(is_array($v)) return in_array($operatorValue, $v);
				if(is_string($operatorValue) && preg_match('/^\/(.*?)\/([a-z]*)$/i', $operatorValue, $matches))
					return preg_match('/'.$matches[1].'/'.$matches[2], $v);
				return $operatorValue === $v;
			case '$in':
				if(!$exists) return false;
				if(!is_array($operatorValue)) throw new Exception('$in requires array');
				if(count($operatorValue) === 0) return false;
				if(is_array($v)) return count(array_diff($v, $operatorValue)) < count($operatorValue);
				return in_array($v, $operatorValue);
			case '$lt':		return $exists && $v < $operatorValue;
			case '$lte':	return $exists && $v <= $operatorValue;
			case '$gt':		return $exists && $v > $operatorValue;
			case '$gte':	return $exists && $v >= $operatorValue;
			case '$ne':		return (!$exists && $operatorValue === null) || ($exists && $v !== $operatorValue);
			case '$nin':
				if(!$exists) return true;
				if(!is_array($operatorValue)) throw new Exception('$nin requires array');
				if(count($operatorValue) === 0) return true;
				if(is_array($v)) return !(count(array_diff($v, $operatorValue)) < count($operatorValue));
				return !in_array($v, $operatorValue);
			
			case '$exists':	return ($operatorValue && $exists) || (!$operatorValue && !$exists);
			case '$mod':
				if(!$exists) return false;
				if(!is_array($operatorValue)) throw new Exception('$mod requires array');
				if(count($operatorValue) !== 2) throw new Exception('$mod requires two parameters in array: divisor and remainder');
				return $v % $operatorValue[0] === $operatorValue[1];
				
			default:
				if(empty($options['unknownOperatorCallback']) || !is_callable($options['unknownOperatorCallback']))
					throw new Exception('Operator '.$operator.' is unknown');
				
				$res = call_user_func($options['unknownOperatorCallback'], $operator, $operatorValue, $element, $document);
				if($res === null)
					throw new Exception('Operator '.$operator.' is unknown');
				if(!is_bool($res))
					throw new Exception('Return value of unknownOperatorCallback must be boolean, actual value '.$res);
				return $res;
		}
		throw new Exception('Didn\'t return in switch');
	}
	
	/**
	 * Iterate through the query looking for field identifiers.  Append $append to the end of the identifier.
	 * 
	 * @param array $query
	 * @param string $append
	 * @return array	The new query
	 */
	public static function appendFieldSpecifier(array $query, $append) {
		foreach($query as $k => $v) {
			if(is_array($v))
				$query[$k] = self::appendFieldSpecifier($v, $append);
			if(is_int($k) || $k[0] === '$') continue;
			$query[$k.'.'.$append] = $query[$k];
			unset($query[$k]);
		}
		return $query;
	}
	
	/**
	 * Get the fields this query depends on
	 * 
	 * @param array		$query		The query to analyse
	 * @param callable	$callable	A callback function on every field, signature function($field, $fieldQuery)
	 * @return array		An array of fields this query depends on
	 */
	public static function getDependentFields(array $query, $assoc = false) {
	   $fields = [];
	   foreach($query as $k => $v) {
			if(is_array($v))
				$fields = array_merge($fields, static::getDependentFields($v));
			if(is_int($k) || $k[0] === '$') continue;
			if($assoc)
				$fields[$k] = $v;
			else
				$fields[] = $k;
	   }
	   return array_unique($fields);
	}
}