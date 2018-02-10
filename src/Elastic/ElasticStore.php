<?php

namespace SMW\Elastic;

use SMW\SQLStore\SQLStore;
use SMW\ApplicationFactory;
use SMW\SemanticData;
use SMW\DIWikiPage;
use SMW\Options;
use SMWQuery as Query;
use Hooks;
use Title;
use RuntimeException;

/**
 * @private
 *
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ElasticStore extends SQLStore {

	/**
	 * @var ElasticFactory
	 */
	private $elasticFactory;

	/**
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * @var QueryEngine
	 */
	private $queryEngine;

	/**
	 * @since 3.0
	 */
	public function __construct() {
		parent::__construct();
		$this->elasticFactory = new ElasticFactory();
	}

	/**
	 * @see SQLStore::deleteSubject
	 * @since 3.0
	 *
	 * @param Title $title
	 */
	public function deleteSubject( Title $title ) {
		parent::deleteSubject( $title );

		if ( $this->indexer === null ) {
			$this->indexer = $this->elasticFactory->newIndexer( $this, $this->messageReporter );
		}

		$this->indexer->setOrigin( 'ElasticStore::DeleteSubject' );
		$idList = [];

		if ( isset( $this->extensionData['delete.list'] ) ) {
			$idList = $this->extensionData['delete.list'];
		}

		$this->indexer->delete( $idList, $title->getNamespace() === SMW_NS_CONCEPT );

		unset( $this->extensionData['delete.list'] );
	}

	/**
	 * @see SQLStore::changeTitle
	 * @since 3.0
	 *
	 * @param Title $oldtitle
	 * @param Title $newtitle
	 * @param integer $pageid
	 * @param integer $redirid
	 */
	public function changeTitle( Title $oldTitle, Title $newTitle, $pageId, $redirectId = 0 ) {
		parent::changeTitle( $oldTitle, $newTitle, $pageId, $redirectId );

		$id = $this->getObjectIds()->getSMWPageID(
			$oldTitle->getDBkey(),
			$oldTitle->getNamespace(),
			'',
			'',
			false
		);

		if ( $this->indexer === null ) {
			$this->indexer = $this->elasticFactory->newIndexer( $this, $this->messageReporter );
		}

		$this->indexer->setOrigin( 'ElasticStore::ChangeTitle' );
		$idList = [ $id ];

		if ( isset( $this->extensionData['delete.list'] ) ) {
			$idList = array_merge( $idList, $this->extensionData['delete.list'] );
		}

		$this->indexer->delete( $idList );

		// Use case [[Foo]] redirects to #REDIRECT [[Bar]] with Bar not yet being
		// materialized and with the update not having created any reference,
		// fulfill T:Q0604 by allowing to create a minimized document body
		if ( $newTitle->exists() === false ) {
			$id = $this->getObjectIds()->getSMWPageID(
				$newTitle->getDBkey(),
				$newTitle->getNamespace(),
				'',
				'',
				false
			);

			$dataItem = DIWikiPage::newFromTitle( $newTitle );
			$dataItem->setId( $id );

			$this->indexer->create( $dataItem );
		}

		unset( $this->extensionData['delete.list'] );
	}

	/**
	 * @see SQLStore::fetchQueryResult
	 * @since 3.0
	 *
	 * @param Query $query
	 *
	 * @return QueryResult
	 */
	public function getQueryResult( Query $query ) {

		$result = null;
		$time = -microtime( true );

		if ( $this->queryEngine === null ) {
			$this->queryEngine = $this->elasticFactory->newQueryEngine( $this );
		}

		$connection = $this->getConnection( 'elastic' );

		if ( $connection->getConfig()->dotGet( 'query.no.connection.fallback' ) && !$connection->ping() ) {
			return parent::getQueryResult( $query );
		}

		$params = [
			$this,
			$query,
			&$result,
			$this->queryEngine
		];

		if ( Hooks::run( 'SMW::Store::BeforeQueryResultLookupComplete', $params ) ) {
			$result = $this->queryEngine->getQueryResult( $query );
		}

		$params = [
			$this,
			&$result
		];

		Hooks::run( 'SMW::ElasticStore::AfterQueryResultLookupComplete', $params );
		Hooks::run( 'SMW::Store::AfterQueryResultLookupComplete', $params );

		$query->setOption( Query::PROC_QUERY_TIME, microtime( true ) + $time );

		return $result;
	}

	/**
	 * @see SQLStore::doDataUpdate
	 * @since 3.0
	 *
	 * @param SemanticData $semanticData
	 */
	protected function doDataUpdate( SemanticData $semanticData ) {
		parent::doDataUpdate( $semanticData );

		$time = -microtime( true );

		if ( $this->indexer === null ) {
			$this->indexer = $this->elasticFactory->newIndexer( $this, $this->messageReporter );
		}

		$this->indexer->setOrigin( 'ElasticStore::DoDataUpdate' );

		if ( isset( $this->extensionData['delete.list'] ) ) {
			$this->indexer->delete( $this->extensionData['delete.list'] );
		}

		if ( !isset( $this->extensionData['change.diff'] ) ) {
			throw new RuntimeException( "Unable to replicate, missing a `change.diff` object!" );
		}

		$this->indexer->safeReplicate(
			$this->extensionData['change.diff']
		);

		unset( $this->extensionData['delete.list'] );
		unset( $this->extensionData['change.diff'] );

		$context = [
			'method' => __METHOD__,
			'role' => 'production',
			'procTime' => microtime( true ) + $time,
		];

		$this->logger->info( '[Store] ElasticStore::doDataUpdate completed (procTime in sec: {procTime})', $context );
	}

	/**
	 * @see SQLStore::setup
	 * @since 1.8
	 *
	 * {@inheritDoc}
	 */
	public function setup( $verbose = true ) {

		if ( $this->indexer === null ) {
			$this->indexer = $this->elasticFactory->newIndexer( $this, $this->messageReporter );
		}

		$this->indexer->setup();

		if ( $verbose ) {
			$this->messageReporter->reportMessage( "\n" );
			$this->messageReporter->reportMessage( 'Selected query engine: "SMWElasticStore"' );
			$this->messageReporter->reportMessage( "\n" );
			$this->messageReporter->reportMessage( "\nSetting up indices ...\n" );
			$this->messageReporter->reportMessage( "   ... done.\n" );
		}

		parent::setup( $verbose );
	}

	/**
	 * @see SQLStore::doDataUpdate
	 * @since 3.0
	 */
	public function clear() {
		parent::clear();
		$this->indexer = null;
		$this->queryEngine = null;
	}

}
