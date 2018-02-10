<?php

namespace SMW\Elastic\Indexer;

use SMW\SQLStore\SQLStore;
use SMW\Store;
use SMW\SQLStore\PropertyTableRowMapper;
use SMW\Elastic\Connection\Client as ElasticClient;
use Onoi\MessageReporter\MessageReporterAwareTrait;
use SMW\ApplicationFactory;
use SMW\SemanticData;
use RuntimeException;
use Exception;

/**
 * @private
 *
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class Rebuilder {

	use MessageReporterAwareTrait;

	/**
	 * @var ElasticClient
	 */
	private $client;

	/**
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * @var PropertyTableRowMapper
	 */
	private $propertyTableRowMapper;

	/**
	 * @var array
	 */
	private $settings = [];

	/**
	 * @var array
	 */
	private $versions = [];

	/**
	 * @since 3.0
	 *
	 * @param ElasticClient $client
	 * @param Indexer $indexer
	 * @param PropertyTableRowMapper $propertyTableRowMapper
	 */
	public function __construct( ElasticClient $client, Indexer $indexer, PropertyTableRowMapper $propertyTableRowMapper ) {
		$this->client = $client;
		$this->indexer = $indexer;
		$this->propertyTableRowMapper = $propertyTableRowMapper;
	}

	/**
	 * @since 3.0
	 *
	 * @return boolean
	 */
	public function ping() {
		return $this->client->ping();
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param array $conditions
	 *
	 * @return array
	 */
	public function select( Store $store, array $conditions ) {

		$connection = $store->getConnection( 'mw.db' );

		$res = $connection->select(
			SQLStore::ID_TABLE,
			[
				'smw_id',
				'smw_iw'
			],
			$conditions,
			__METHOD__,
			[ 'ORDER BY' => 'smw_id' ]
		);

		$last = $connection->selectField(
			SQLStore::ID_TABLE,
			'MAX(smw_id)',
			'',
			__METHOD__
		);

		return [ $res, $last ];
	}

	/**
	 * @since 3.0
	 *
	 * @return boolean
	 */
	public function rollover() {

		if ( $this->versions === [] ) {
			return false;
		}

		$res = $this->rolloverByType(
			ElasticClient::TYPE_DATA
		);

		$this->rolloverByType( ElasticClient::TYPE_LOOKUP );

		return $res;
	}

	/**
	 * @since 3.0
	 */
	public function prepare() {
		$this->doPrepareByType( ElasticClient::TYPE_DATA );
		$this->doPrepareByType( ElasticClient::TYPE_LOOKUP );
	}

	/**
	 * @since 3.0
	 */
	public function deleteIndices() {
		$this->deleteIndexByType( ElasticClient::TYPE_DATA );
		$this->deleteIndexByType( ElasticClient::TYPE_LOOKUP );

		$this->messageReporter->reportMessage( "\n   ... setting up indices and aliases ..." );
		$this->indexer->setup();
	}

	/**
	 * @since 3.0
	 */
	public function createIndices() {
		$this->createIndexByType( ElasticClient::TYPE_DATA );
		$this->createIndexByType( ElasticClient::TYPE_LOOKUP );
	}

	/**
	 * @since 3.0
	 */
	public function setDefaults() {

		if ( !$this->client->hasIndex( ElasticClient::TYPE_DATA ) ) {
			return false;
		}

		$this->setDefaultsByType( ElasticClient::TYPE_DATA );
		$this->setDefaultsByType( ElasticClient::TYPE_LOOKUP );

		return true;
	}

	/**
	 * @since 3.0
	 *
	 * @param integer $id
	 */
	public function delete( $id ) {

		$index = $this->client->getIndexNameByType( ElasticClient::TYPE_DATA );

		if ( isset( $this->versions[ElasticClient::TYPE_DATA] ) ) {
			$index = $index . '-' . $this->versions[ElasticClient::TYPE_DATA];
		}

		$params = [
			'index' => $index,
			'type' => ElasticClient::TYPE_DATA,
			'id' => $id
		];

		try {
			$this->client->delete( $params );
		} catch ( Exception $e ) {
			// Do nothing
		}
	}

	/**
	 * @since 3.0
	 *
	 * @param integer $id
	 * @param SemanticData $semanticData
	 */
	public function rebuild( $id, SemanticData $semanticData ) {

		$changeOp = $this->propertyTableRowMapper->newChangeOp(
			$id,
			$semanticData
		);

		if ( isset( $this->versions[ElasticClient::TYPE_DATA] ) ) {
			$this->indexer->setVersion( $this->versions[ElasticClient::TYPE_DATA] );
		}

		$this->indexer->replicate( $changeOp->newChangeDiff() );
	}

	/**
	 * @since 3.0
	 */
	public function refresh() {

		if ( !$this->client->hasIndex( ElasticClient::TYPE_DATA ) ) {
			return false;
		}

		$this->refreshIndexByType( ElasticClient::TYPE_DATA );
		$this->refreshIndexByType( ElasticClient::TYPE_LOOKUP );

		return true;
	}

	private function doPrepareByType( $type ) {

		$index = $this->client->getIndexNameByType( $type );

		if ( isset( $this->versions[$type] ) ) {
			$index = "$index-" . $this->versions[$type];
		}

	 	// @see https://www.elastic.co/guide/en/elasticsearch/reference/current/tune-for-indexing-speed.html
		$params = [
			'index' => $index,
			'body' => [
				'settings' => [
					'number_of_replicas' => 0,
					'refresh_interval' => -1
				]
			]
		];

		$this->client->putSettings( $params );
	}

	private function refreshIndexByType( $type ) {

		$index = $this->client->getIndexNameByType( $type );

		$this->client->refresh(
			[ 'index' => $index ]
		);
	}

	private function setDefaultsByType( $type ) {

		$indices = $this->client->indices();

		$index = $this->client->getIndexNameByType(
			$type
		);

		$this->messageReporter->reportMessage( "\n   ... closing" );

		// Certain changes ( ... to define new analyzers ...) requires to close
		// and reopen an index
		$indices->close( [ 'index' => $index ] );

		$indexDef = $this->client->getIndexDefByType(
			$type
		);

		$indexDef = json_decode( $indexDef, true );

		// Cannot be altered by a simple settings update and requires a complete
		// rebuild
		unset( $indexDef['settings']['number_of_shards'] );

		$params = [
			'index' => $index,
			'body' => [
				'settings' => $indexDef['settings']
			]
		];

		$this->client->putSettings( $params );

		$params = [
			'index' => $index,
			'type'  => $type,
			'body'  => $indexDef['mappings']
		];

		$this->client->putMapping( $params );

		$this->messageReporter->reportMessage( ", opening index '$type' ... " );
		$indices->open( [ 'index' => $index ] );

		$this->client->releaseLock( $type );
	}

	private function deleteIndexByType( $type ) {

		$index = $this->client->getIndexNameByType( $type );
		$indices = $this->client->indices();

		if ( $indices->exists( [ 'index' => "$index-v1" ] ) ) {
			$indices->delete( [ 'index' => "$index-v1" ] );
		}

		if ( $indices->exists( [ 'index' => "$index-v2" ] ) ) {
			$indices->delete( [ 'index' => "$index-v2" ] );
		}

		if ( $indices->exists( [ 'index' => "$index" ] ) ) {
			$indices->delete( [ 'index' => "$index" ] );
		}

		$this->client->releaseLock( $type );
	}

	private function createIndexByType( $type ) {

		// If for some reason a recent rebuild didn't finish, use
		// the locked version as master
		if ( ( $version = $this->client->getLock( $type ) ) === false ) {
			$version = $this->client->createIndex( $type );
		}

		if ( !$this->client->hasIndex( $type ) ) {
			$version = $this->client->createIndex( $type );
		}

		$index = $this->client->getIndexNameByType( $type );
		$indices = $this->client->indices();

		// No Alias available, create one before the rollover
		if ( !$indices->exists( [ 'index' => "$index" ] ) ) {
			$actions = [
				[ 'add' => [ 'index' => "$index-$version", 'alias' => $index ] ]
			];

			$params['body'] = [ 'actions' => $actions ];

			$indices->updateAliases( $params );
		}

		$this->versions[$type] = $version;
		$this->client->setLock( $type, $version );
	}

	private function rolloverByType( $type ) {

		$version = $this->versions[$type];

		$index = $this->client->getIndexNameByType( $type );
		$indices = $this->client->indices();

		$params = [];
		$actions = [];

		$old = $version === 'v2' ? 'v1' : 'v2';
		$check = false;

		if ( $indices->exists( [ 'index' => "$index-$old" ] ) ) {
			$actions = [
				[ 'remove' => [ 'index' => "$index-$old", 'alias' => $index ] ],
				[ 'add' => [ 'index' => "$index-$version", 'alias' => $index ] ]
			];

			$check = true;
		} else {
			// No old index
			$old = $version;

			$actions = [
				[ 'add' => [ 'index' => "$index-$version", 'alias' => $index ] ]
			];
		}

		$params['body'] = [ 'actions' => $actions ];

		$indices->updateAliases( $params );

		if ( $check && $indices->exists( [ 'index' => "$index-$old" ] ) ) {
			$indices->delete( [ "index" => "$index-$old" ] );
		}

		$this->client->releaseLock( $type );

		return [ $version, $old ];
	}

}
