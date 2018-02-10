<?php

namespace SMW\Elastic\QueryEngine;

use Psr\Log\LoggerAwareTrait;
use SMWQuery as Query;
use SMW\Query\Language\SomeProperty;
use SMW\Query\Language\Description;
use SMW\ApplicationFactory;
use SMW\DIWikiPage;
use SMWDataItem as DataItem;
use SMW\Store;
use SMW\Options;
use RuntimeException;
use SMW\Elastic\Connection\Client as ElasticClient;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class TermsLookup {

	use LoggerAwareTrait;

	/**
	 * Identifies the cache namespace
	 */
	const CACHE_NAMESPACE = 'smw:elastic:lookup';

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var FieldMapper
	 */
	private $fieldMapper;

	/**
	 * @var Cache
	 */
	private $cache;

	/**
	 * @var array
	 */
	private $queryInfo = [];

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param Options $options
	 */
	public function __construct( Store $store, Options $options = null ) {
		$this->store = $store;
		$this->options = $options;

		if ( $options === null ) {
			$this->options = new Options();
		}

		$this->cache = ApplicationFactory::getInstance()->getCache();
		$this->fieldMapper = new FieldMapper();
	}

	/**
	 * @since 3.0
	 *
	 * @param []
	 */
	public function getQueryInfo() {
		return $this->queryInfo;
	}

	/**
	 * Chainable queries (or better subqueries) aren't natively supported in ES.
	 *
	 * This creates its own query and executes it as independent transaction to
	 * return a list of matchable `_id` to can be fed to the source query.
	 *
	 * @return array
	 */
	public function lookupConcept( DataItem $dataItem, Description $description, $params ) {

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_DATA
		);

		$count = 0;
		$query = $this->fieldMapper->bool( 'must', $params );

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$query = $this->fieldMapper->constant_score( $query );
		}

		// Need to execute the query since ES doesn't support subqueries
		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'body' => [
				"_source" => false,
				'query' => $query,
			],
			'size' => $this->options->safeGet( 'subquery.size', 100 )
		];

		$this->queryInfo = [
			'concept_lookup_query' => $dataItem->getHash() . ' → ' . $description->getQueryString(),
			'query' => $params
		];

		$id = md5( $dataItem->getId() );

		$threshold = $this->options->safeGet(
			'concept.terms.lookup.result.size.index.write.threshold',
			100
		);

		$ttl = $this->options->safeGet(
			'concept.terms.lookup.cache.lifetime',
			60
		);

		$key = smwfCacheKey(
			self::CACHE_NAMESPACE,
			[
				$id,
				$threshold,
				$description->getFingerprint()
			]
		);

		if ( ( $count = $this->cache->fetch( $key ) ) === false ) {
			list( $params, $count ) = $this->query( $key, $id, $params, $threshold, $ttl );
		} else {
			$params = $this->terms_filter( $id );
		}

		$this->queryInfo['count'] = $count;

		if ( $params === [] ) {
			return [];
		}

		$params = $this->fieldMapper->terms(
			'_id',
			$params
		);

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$params = $this->fieldMapper->constant_score( $params );
		}

		return $params;
	}

	/**
	 * @return array
	 */
	public function lookup( $key, $field, $params ) {

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_DATA
		);

		$count = 0;
		$query = $this->fieldMapper->bool( 'must', $params );
		$query = $this->fieldMapper->constant_score( $query );

		// Need to execute the query since ES doesn't support subqueries
		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'body' => [
				"_source" => false,
				'query' => $query,
			],
			'size' => $this->options->safeGet( 'subquery.size', 100 )
		];

		$this->queryInfo = [
			'predefined_lookup_query' => $key,
			'query' => $params
		];

		$id = 'pre:' . md5( json_encode( $params ) );

		$threshold = $this->options->safeGet(
			'subquery.terms.lookup.result.size.index.write.threshold',
			100
		);

		$ttl = $this->options->safeGet(
			'subquery.terms.lookup.cache.lifetime',
			60
		);

		$key = smwfCacheKey(
			self::CACHE_NAMESPACE,
			[
				$id,
				$threshold
			]
		);

		if ( ( $count = $this->cache->fetch( $key ) ) === false ) {
			list( $params, $count ) = $this->query( $key, $id, $params, $threshold, $ttl );
		} else {
			$params = $this->terms_filter( $id );
		}

		$this->queryInfo['count'] = $count;

		if ( $params === [] ) {
			return [];
		}

		$params = $this->fieldMapper->terms(
			$field,
			$params
		);

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$params = $this->fieldMapper->constant_score( $params );
		}

		return $params;
	}

	/**
	 * @return array
	 */
	public function lookupInverse( $key, $field, $params ) {

		$this->queryInfo = [
			'inverse_lookup_query' => $key,
			'query' => 'Failed with invalid or unmatchable ID'
		];

		if ( $params === [] || $params == 0 ) {
			return [];
		}

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_DATA
		);

		$count = 0;
		$id = 'in:' . md5( json_encode( $params ) );

		$query = $this->fieldMapper->bool( 'must', $this->fieldMapper->terms( '_id', $params ) );
		$query = $this->fieldMapper->constant_score( $query );

		// Need to execute the query since ES doesn't support subqueries
		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'body' => [
				"_source" => [ $field ],
				'query' => $query,
			],
			'size' => $this->options->safeGet( 'subquery.size', 100 )
		];

		$this->queryInfo = [
			'inverse_lookup_query' => $key,
			'query' => $params
		];

		$threshold = $this->options->safeGet(
			'subquery.terms.lookup.result.size.index.write.threshold',
			100
		);

		$ttl = $this->options->safeGet(
			'subquery.terms.lookup.cache.lifetime',
			60
		);

		$key = smwfCacheKey(
			self::CACHE_NAMESPACE,
			[
				$id,
				$threshold
			]
		);

		if ( ( $count = $this->cache->fetch( $key ) ) === false ) {
			list( $params, $count ) = $this->inverse_query( $key, $id, $field, $params, $threshold, $ttl );
		} else {
			$params = $this->terms_filter( $id );
		}

		$this->queryInfo['count'] = $count;

		$params = $this->fieldMapper->terms(
			"_id",
			$params
		);

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$params = $this->fieldMapper->constant_score( $params );
		}

		return $params;
	}

	/**
	 * Chainable queries (or better subqueries) aren't natively supported in ES.
	 *
	 * This creates its own query and executes it as independent transaction to
	 * return a list of matchable `_id` to can be fed to the source query.
	 *
	 * @return array
	 */
	public function lookupSomeProperty( SomeProperty $description, $params ) {

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_DATA
		);

		$count = 0;
		$query = $this->fieldMapper->bool( 'must', $params );

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$query = $this->fieldMapper->constant_score( $query );
		}

		// Need to execute the query since ES doesn't support subqueries
		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'body' => [
				"_source" => false,
				'query' => $query,
			],
			'size' => $this->options->safeGet( 'subquery.size', 100 )
		];

		$this->queryInfo = [
			'lookup_query' => $description->getProperty()->getKey() . ' → ' . $description->getQueryString(),
			'query' => $params
		];

		$id = md5( json_encode( $params ) );

		$threshold = $this->options->safeGet(
			'subquery.terms.lookup.result.size.index.write.threshold',
			100
		);

		$ttl = $this->options->safeGet(
			'subquery.terms.lookup.cache.lifetime',
			60
		);

		$key = smwfCacheKey(
			self::CACHE_NAMESPACE,
			[
				$id,
				$threshold
			]
		);

		if ( ( $count = $this->cache->fetch( $key ) ) === false ) {
			list( $params, $count ) = $this->query( $key, $id, $params, $threshold, $ttl );
		} else {
			$params = $this->terms_filter( $id );
		}

		if ( !isset( $description->sourceChainMemberField ) ) {
			throw new RuntimeException( "Missing `sourceChainMemberField`" );
		}

		$this->queryInfo['count'] = $count;

		if ( $params === [] ) {
			return [];
		}

		$params = $this->fieldMapper->terms(
			$description->sourceChainMemberField,
			$params
		);

		if ( $this->options->safeGet( 'subquery.constant.score', true ) ) {
			$params = $this->fieldMapper->constant_score( $params );
		}

		return $params;
	}

	private function query( $key, $id, $params, $threshold, $ttl ) {

		$connection = $this->store->getConnection( 'elastic' );
		$count = 0;

		list( $res, $errors ) = $connection->search(
			$params
		);

		list( $results, $info, $scores, $continue ) = $this->fieldMapper->elastic_result(
			$res
		);

		if ( isset( $info['total'] ) ) {
			$count = $info['total'];
		}

		if ( $count >= $threshold ) {
			$this->cache->save( $key, $count, $ttl );
		}

		if ( $count >= $threshold ) {
			$results = $this->create( $id, $results );
		}

		$this->queryInfo['query']['info'] = $info;
		$this->queryInfo['isFromCache'] = false;

		return [ $results, $count ];
	}

	private function inverse_query( $key, $id, $field, $params, $threshold, $ttl ) {

		$connection = $this->store->getConnection( 'elastic' );
		$count = 0;
		$max_score = 0;

		list( $res, $errors ) = $connection->search(
			$params
		);

		if ( !isset( $res['hits'] ) ) {
			return [ [], 0 ];
		}

		list( $pid, $field ) = explode( '.', $field );
		$result = [];

		foreach ( $res as $k => $value ) {

			if ( !isset( $value['hits'] ) ) {
				continue;
			}

			foreach ( $value['hits'] as $v ) {
				if ( isset( $v['_source'][$pid][$field] ) ) {
					$result = array_merge( $result, $v['_source'][$pid][$field] );
				}
			}
		}

		if ( isset( $res['hits']['total'] ) ) {
			$count = $res['hits']['total'];
		}

		if ( isset( $res['hits']['max_score'] ) ) {
			$max_score = $res['hits']['max_score'];
		}

		unset( $res['hits'] );
		unset( $res['_shards'] );

		$this->queryInfo['query']['info'] = $res;
		$this->queryInfo['query']['info']['max_score'] = $max_score;
		$this->queryInfo['query']['info']['total'] = count( $res );
		$this->queryInfo['isFromCache'] = false;

		if ( $result === [] ) {
			return [ [], 0 ];
		}

		$results = array_keys( array_flip( $result ) );

		if ( $count >= $threshold ) {
			$this->cache->save( $key, $count, $ttl );
		}

		if ( $count >= $threshold ) {
			$results = $this->create( $id, $results );
		}

		return [ $results, $count ];
	}

	private function create( $id, $results ) {

		$connection = $this->store->getConnection( 'elastic' );

		// https://www.elastic.co/guide/en/elasticsearch/reference/6.1/query-dsl-terms-query.html
		// Experimental to use the terms lookup feature

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_LOOKUP
		);

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_LOOKUP,
			'id'    => $id
		];

		// https://www.elastic.co/blog/terms-filter-lookup
		// From the documentation "... the terms filter will be fetched from a
		// field in a document with the specified id in the specified type and
		// index. Internally a get request is executed to fetch the values from
		// the specified path. At the moment for this feature to work the _source
		// needs to be stored ..."
		$connection->index( $params + [ 'body' => [ 'id' => $results ] ] );

		// Refresh to ensure results are available for the upcoming search
		$connection->refresh( $params );

		// Define path for the terms filter
		return $params + [ 'path' => 'id' ];
	}

	private function terms_filter( $id ) {

		$this->queryInfo['isFromCache'] = true;

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_LOOKUP
		);

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_LOOKUP,
			'id'    => $id
		];

		// Define path for the terms filter
		return $params + [ 'path' => 'id' ];
	}

}
