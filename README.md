# PHPMongoQuery

Mongo queries in PHP

PHPMongoQuery implements MongoDB queries in PHP, allowing developers to query a 'document' (an array containing data) against a Mongo query object, returning a boolean value for pass or fail.  Additionally a set of documents can be queried to filter them, as queries are used in MongoDB.

This code is used at Akkroo to allow the construction of advanced form logic queries in the form field definition.

## Usage

```php
<?php

use Akkroo\PHPMongoQuery;

$query = array('a' => 'foo', 'b' => array('$ne' => 'bar'));
$document = array(
			'id' => 1,
			'a' => 'foo',
			'b' => 'barr'
		);
var_dump(PHPMongoQuery::executeQuery($query, $document));
```

This will output

```
bool(true)
```

## Methods

### find($query, $documents, $options)

Perform a query on a set of documents to filter out only those which pass the query

### executeQuery($query, $documents, $options)

Execute a query on a single document, returning a boolean value for pass or fail

### appendFieldSpecifier($query, $append)

Append a field specifier to any field queries.  For example, your query may have been written as follows:

	$query = array('a' => 'foo');

However, the actual document structure is

	$document = array('a' => array('value' => 'foo'), 'b' => array('value' => 'bar'));

So you need to append the 'value' specifier to the query field specifiers for the query to work.  For example:

	$newQuery = PHPMongoQuery::appendFieldSpecifier($query, 'value');
	// $newQuery is array('a.value' => 'foo');

### getDependentFields($query)

Parse a query to find all the fields it depends on.  This is useful for listening to when those values change, so the query is only repeated when the result could have changed.  For example:

	$query = array('a' => 'foo');
	$dependentFields = PHPMongoQuery::getDependentFields($query);
	// $dependentFields is array('a');