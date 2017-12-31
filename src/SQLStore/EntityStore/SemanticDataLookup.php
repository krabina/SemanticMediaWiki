<?php

namespace SMW\SQLStore\EntityStore;

use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\EntityLookup;
use SMW\SQLStore\SQLStore;
use SMWDataItem as DataItem;
use SMW\RequestOptions;
use Onoi\Cache\Cache;
use Onoi\Cache\NullCache;
use SMW\SemanticData;
use SMW\SQLStore\PropertyTableDefinition;
use SMW\SQLStore\TableBuilder\FieldType;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class SemanticDataLookup {

	use LoggerAwareTrait;

	/**
	 * @var SQLStore
	 */
	private $store;

	/**
	 * @var Cache
	 */
	private $cache;

	/**
	 * Cache for SemanticData dataItems, indexed by SMW ID.
	 *
	 * @var array
	 */
	private static $data = [];

	/**
	 * Like SMWSQLStore3::data, but containing flags indicating
	 * completeness of the SemanticData objs.
	 *
	 * @var array
	 */
	private static $state = [];

	/**
	 * >0 while getSemanticData runs, used to prevent nested calls from clearing
	 * the cache while another call runs and is about to fill it with data
	 *
	 * @var int
	 */
	private static $lookupCount = 0;

	/**
	 * @since 3.0
	 *
	 * @param SQLStore $store
	 * @param Cache|null $cache
	 */
	public function __construct( SQLStore $store, Cache $cache = null ) {
		$this->store = $store;
		$this->cache = $cache;

		if ( $this->cache === null ) {
			$this->cache = new NullCache();
		}
	}

	/**
	 * @since 3.0
	 */
	public function lockCache() {
		self::$lookupCount++;
	}

	/**
	 * @since 3.0
	 */
	public function unlockCache() {
		self::$lookupCount--;
	}

	/**
	 * @since 3.0
	 *
	 * @param integer $id
	 */
	public function invalidateCache( $id ) {
		unset( self::$data[$id] );
		unset( self::$state[$id] );
	}

	/**
	 * @since 3.0
	 */
	public static function clear() {
		self::$data = [];
		self::$state = [];
		self::$lookupCount = 0;
	}

	/**
	 * Helper method to make sure there is a cache entry for the data about
	 * the given subject with the given ID.
	 *
	 * @todo The management of this cache should be revisited.
	 *
	 * @since 3.0
	 *
	 * @param int $id
	 * @param DIWikiPage $subject
	 */
	public function initLookupCache( $id, DIWikiPage $subject ) {

		// *** Prepare the cache ***//
		if ( !isset( self::$data[$id] ) ) {
			self::$data[$id] = new StubSemanticData( $subject, $this->store, false );
			self::$state[$id] = [];
		}

		// Issue #622
		// If a redirect was cached preceding this request and points to the same
		// subject id ensure that in all cases the requested subject matches with
		// the selected DB id
		if ( self::$data[$id]->getSubject()->getHash() !== $subject->getHash() ) {
			self::$data[$id] = new StubSemanticData( $subject, $this->store, false );
			self::$state[$id] = [];
		}

		// prevent memory leak;
		// It is not so easy to find the sweet spot between cache size and performance gains (both memory and time),
		// The value of 20 was chosen by profiling runtimes for large inline queries and heavily annotated pages.
		// However, things might have changed in the meantime ...
		if ( ( count( self::$data ) > 20 ) && ( self::$lookupCount == 1 ) ) {
			self::$data = array( $id => self::$data[$id] );
			self::$state = array( $id => self::$state[$id] );
		}
	}

	/**
	 * Set the semantic data lookup cache to hold exactly the given value for the
	 * given ID.
	 *
	 * @since 3.0
	 *
	 * @param integer $id
	 * @param SemanticData $semanticData
	 */
	public function setLookupCache( $id, SemanticData $semanticData ) {

		self::$data[$id] = StubSemanticData::newFromSemanticData(
			$semanticData,
			$this->store
		);

		self::$state[$id] = [];
		$propertyTables = $this->store->getPropertyTables();

		foreach ( $propertyTables as $tableId => $propertyTableDef ) {
			self::$state[$id][$propertyTableDef->getName()] = true;
		}
	}

	/**
	 * Helper method to make sure there is a cache entry for the data about
	 * the given subject with the given ID.
	 *
	 * @todo The management of this cache should be revisited.
	 *
	 * @since 3.0
	 *
	 * @param int $id
	 * @param DIWikiPage $subject
	 */
	public function getSemanticDataById( $id ) {

		if ( !isset( self::$data[$id] ) ) {
			throw new RuntimeException( 'Data are not initialized.' );
		}

		return self::$data[$id];
	}

	/**
	 * Fetch the data about one subject for one particular table
	 *
	 * @param integer $id
	 * @param DIWikiPage $subject
	 * @param PropertyTableDefinition $proptable
	 *
	 * @return SemanticData
	 */
	public function getSemanticDataFromTable( $id, DIWikiPage $subject, PropertyTableDefinition $proptable, RequestOptions $requestOptions = null ) {

		// Do not clear the cache when called recursively.
		$this->lockCache();
		$this->initLookupCache( $id, $subject );

		// @see also setLookupCache
		$name = $proptable->getName();

		if ( isset( self::$state[$id][$name] ) ) {
			$this->unlockCache();
			return self::$data[$id];
		}

		$data = $this->fetchSemanticData( $id, $subject, $proptable, true, $requestOptions );

		foreach ( $data as $d ) {
			self::$data[$id]->addPropertyStubValue( reset( $d ), end( $d ) );
		}

		self::$state[$id][$name] = true;

		$this->unlockCache();

		return self::$data[$id];
	}

	/**
	 * Helper function for reading all data for from a given property table
	 * (specified by an SMWSQLStore3Table dataItem), based on certain
	 * restrictions. The function can filter data based on the subject (1)
	 * or on the property it belongs to (2) -- but one of those must be
	 * done. The Boolean $issubject is true for (1) and false for (2).
	 *
	 * In case (1), the first two parameters are taken to refer to a
	 * subject; in case (2) they are taken to refer to a property. In any
	 * case, the retrieval is limited to the specified $proptable. The
	 * parameters are an internal $id (of a subject or property), and an
	 * $dataItem (being an DIWikiPage or SMWDIProperty). Moreover, when
	 * filtering by property, it is assumed that the given $proptable
	 * belongs to the property: if it is a table with fixed property, it
	 * will not be checked that this is the same property as the one that
	 * was given in $dataItem.
	 *
	 * In case (1), the result in general is an array of pairs (arrays of
	 * size 2) consisting of a property key (string), and DB keys (array if
	 * many, string if one) from which a datvalue dataItem for this value can
	 * be built. It is possible that some of the DB keys are based on
	 * internal dataItems; these will be represented by similar result arrays
	 * of (recursive calls of) fetchSemanticData().
	 *
	 * In case (2), the result is simply an array of DB keys (array)
	 * without the property keys. Container dataItems will be encoded with
	 * nested arrays like in case (1).
	 *
	 * @param integer $id
	 * @param DataItem $dataItem
	 * @param PropertyTableDefinition $propTable
	 * @param boolean $isSubject
	 * @param RequestOptions $requestOptions
	 *
	 * @return array
	 */
	public function fetchSemanticData( $id, DataItem $dataItem = null, PropertyTableDefinition $propTable, $isSubject = true, RequestOptions $requestOptions = null ) {
		// stop if there is not enough data:
		// properties always need to be given as dataItem,
		// subjects at least if !$proptable->idsubject
		if ( ( $id == 0 ) ||
			( is_null( $dataItem ) && ( !$isSubject || !$propTable->usesIdSubject() ) ) ||
			( $propTable->getDIType() === null ) ) {
			return [];
		}

		$result = [];

		// Build something like:
		//
		// SELECT o_id AS id0,o0.smw_title AS v0,o0.smw_namespace AS v1,o0.smw_iw AS v2,o0.smw_sortkey AS v3,o0.smw_subobject AS v4
		// FROM `smw_fpt_sobj`
		// INNER JOIN `smw_object_ids` AS o0 ON o_id=o0.smw_id
		// WHERE s_id='852'
		// LIMIT 4
		//
		// or
		//
		// SELECT p.smw_title as prop,o_blob AS v0,o_hash AS v1 FROM `smw_di_blob`
		// INNER JOIN `smw_object_ids` AS p ON p_id=p.smw_id
		// WHERE s_id='80' AND p.smw_iw!=':smw' AND p.smw_iw!=':smw-delete'

		$query = [
			'table' => '',
			'fields' => '',
			'conditions' => '',
			'options' => ''
		];

		$this->buildQueryInfo(
			$query,
			$id,
			$isSubject,
			$propTable,
			$dataItem
		);

		$valueCount = 0;
		$fieldname = '';

		$diHandler = $this->store->getDataItemHandlerForDIType(
			$propTable->getDiType()
		);

		$valueField = $diHandler->getIndexField();
		$labelField = $diHandler->getLabelField();

		$fields = $diHandler->getFetchFields();

		$this->buildFieldsInfo(
			$query,
			$valueField,
			$labelField,
			$fields,
			$valueCount,
			$fieldname
		);

 		// Apply sorting/string matching; only with given property
		if ( !$isSubject ) {
			$query['conditions'] .= $this->store->getSQLConditions(
				$requestOptions,
				$valueField,
				$labelField,
				$query['conditions'] !== ''
			);

			$query['options'] = $this->store->getSQLOptions( $requestOptions, $valueField ) + [ 'DISTINCT' ];
		} else {
			$valueField = '';

			// Don't use DISTINCT for value of one subject:
			$query['options'] = $this->store->getSQLOptions( $requestOptions, $valueField );
		}

		$connection = $this->store->getConnection( 'mw.db' );

		$res = $connection->select(
			$query['table'],
			$query['fields'],
			$query['conditions'],
			__METHOD__,
			$query['options']
		);

		foreach ( $res as $row ) {
			$propertykey = '';

			// use joined or predefined property name
			if ( $isSubject ) {
				$propertykey = $propTable->isFixedPropertyTable() ? $propTable->getFixedProperty() : $row->prop;
			}

			$this->resultFromRow(
				$result,
				$row,
				$fields,
				$fieldname,
				$valueCount,
				$isSubject,
				$propertykey
			);
		}

		$connection->freeResult( $res );

		return $result;
	}

	private function buildQueryInfo( &$query, $id, $isSubject, $propTable, $dataItem ) {

		$connection = $this->store->getConnection( 'mw.db' );

		$query['table'] = $connection->tableName(
			$propTable->getName()
		);

		// Restrict property only
		if ( !$isSubject && !$propTable->isFixedPropertyTable() ) {
			$query['conditions'] .= 'p_id=' . $connection->addQuotes( $id );
		}

		// Restrict subject, select property
		if ( $isSubject && $propTable->usesIdSubject() ) {
			$query['conditions'] .= 's_id=' . $connection->addQuotes( $id );
		} elseif ( $isSubject ) {
			$query['conditions'] .= 's_title=' . $connection->addQuotes( $dataItem->getDBkey() );
			$query['conditions'] .= ' AND s_namespace=' . $connection->addQuotes( $dataItem->getNamespace() );
		}

		// Select property name
		// In case of a fixed property, no select needed
		if ( $isSubject && !$propTable->isFixedPropertyTable() ) {
			$query['table'] .= ' INNER JOIN ' . $connection->tableName( SQLStore::ID_TABLE ) . ' AS p ON p_id=p.smw_id';
			$query['fields'] .= 'p.smw_title as prop';

			// Avoid displaying any property that has been marked deleted or outdated
			$query['conditions'] .= " AND p.smw_iw!=" . $connection->addQuotes( SMW_SQL3_SMWIW_OUTDATED );
			$query['conditions'] .= " AND p.smw_iw!=" . $connection->addQuotes( SMW_SQL3_SMWDELETEIW );
		}
	}

	private function buildFieldsInfo( &$query, $valueField, $labelField, $fields, &$valueCount, &$fieldname ) {

		$connection = $this->store->getConnection( 'mw.db' );

		// Select dataItem column(s)
		foreach ( $fields as $fieldname => $fieldType ) {

			 // Get data from ID table
			if ( $fieldType === FieldType::FIELD_ID ) {
				$query['table'] .= ' INNER JOIN ' . $connection->tableName( SQLStore::ID_TABLE );
				$query['table'] .= " AS o$valueCount ON $fieldname=o$valueCount.smw_id";

				$query['fields'] .= $query['fields'] !== '' ? ',' : '';
				$query['fields'] .=	"$fieldname AS id$valueCount";
				$query['fields'] .=	",o$valueCount.smw_title AS v$valueCount";
				$query['fields'] .=	",o$valueCount.smw_namespace AS v" . ( $valueCount + 1 );
				$query['fields'] .=	",o$valueCount.smw_iw AS v" . ( $valueCount + 2 );
				$query['fields'] .=	",o$valueCount.smw_sortkey AS v" . ( $valueCount + 3 );
				$query['fields'] .=	",o$valueCount.smw_subobject AS v" . ( $valueCount + 4 );

				if ( $valueField == $fieldname ) {
					$valueField = "o$valueCount.smw_sortkey";
				}
				if ( $labelField == $fieldname ) {
					$labelField = "o$valueCount.smw_sortkey";
				}

				$valueCount += 4;
			} else {
				$query['fields'] .= $query['fields'] !== '' ? ',' : '';
				$query['fields'] .=	"$fieldname AS v$valueCount";
			}

			$valueCount += 1;
		}

		// Postgres
		// Function: SMWSQLStore3Readers::fetchSemanticData
		// Error: 42P10 ERROR: for SELECT DISTINCT, ORDER BY expressions must appear in select list
		if ( strpos( $query['fields'], $valueField ) === false ) {
			$query['fields'] .= ", $valueField AS v" . ( $valueCount + 1 );
		}
	}

	private function resultFromRow( &$result, $row, $fields, $fieldname, $valueCount, $isSubject, $propertykey ) {

		$hash = '';

		if ( $isSubject ) { // use joined or predefined property name
			$hash = $propertykey;
		}

		// Use enclosing array only for results with many values:
		if ( $valueCount > 1 ) {
			$valueKeys = [];
			for ( $i = 0; $i < $valueCount; $i += 1 ) { // read the value fields from the current row
				$fieldname = "v$i";
				$valueKeys[] = $row->$fieldname;
			}
		} else {
			$valueKeys = $row->v0;
		}

		// #Issue 615
		// If the iw field contains a redirect marker then remove it
		if ( isset( $valueKeys[2] ) && ( $valueKeys[2] === SMW_SQL3_SMWREDIIW || $valueKeys[2] === SMW_SQL3_SMWDELETEIW ) ) {
			$valueKeys[2] = '';
		}

		// The hash prevents from inserting duplicate entries of the same content
		if ( $valueCount > 1 ) {
			$hash = md5( $hash . implode( '#', $valueKeys ) );
		} else {
			$hash = md5( $hash . $valueKeys );
		}

		// Filter out any accidentally retrieved internal things (interwiki starts with ":"):
		if ( $valueCount < 3 ||
			implode( '', $fields ) !== FieldType::FIELD_ID ||
			$valueKeys[2] === '' ||
			$valueKeys[2]{0} != ':' ) {

			if ( isset( $result[$hash] ) ) {
				$this->reportDuplicate( $propertykey, $valueKeys );
			}

			if ( $isSubject ) {
				$result[$hash] = array( $propertykey, $valueKeys );
			} else{
				$result[$hash] = $valueKeys;
			}
		}
	}

	private function reportDuplicate( $propertykey, $valueKeys ) {
		$this->logger->info(
			__METHOD__ . " Duplicate entry for {propertykey} with {valueKeys}",
			[
				'propertykey' => $propertykey,
				'valueKeys' => ( is_array( $valueKeys ) ? implode( ',', $valueKeys ) : $valueKeys )
			]
		);
	}

}