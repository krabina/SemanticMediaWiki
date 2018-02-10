<?php

namespace SMW\Elastic;

use SMW\ApplicationFactory;
use SMW\Store;
use SMW\Options;
use SMW\DIWikiPage;
use Psr\Log\LoggerInterface;
use SMW\SQLStore\PropertyTableRowMapper;
use SMW\Elastic\QueryEngine\QueryEngine;
use SMW\Elastic\Indexer\Indexer;
use SMW\Elastic\Indexer\Rebuilder;
use Onoi\MessageReporter\MessageReporter;
use Onoi\MessageReporter\NullMessageReporter;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ElasticFactory {

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param MessageReporter|null $messageReporter
	 * @param LoggerInterface|null $logger
	 *
	 * @return Indexer
	 */
	public function newIndexer( Store $store, MessageReporter $messageReporter = null, LoggerInterface $logger = null ) {

		$applicationFactory = ApplicationFactory::getInstance();
		$indexer = new Indexer( $store );

		if ( $logger === null ) {
			$logger = $applicationFactory->getMediaWikiLogger( 'smw-elastic' );
		}

		if ( $messageReporter === null ) {
			$messageReporter = new NullMessageReporter();
		}

		$indexer->setLogger(
			$logger
		);

		$indexer->setMessageReporter(
			$messageReporter
		);

		return $indexer;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param Options $options
	 *
	 * @return QueryEngine
	 */
	public function newQueryEngine( Store $store, Options $options = null ) {

		$applicationFactory = ApplicationFactory::getInstance();

		if ( $options === null ) {
			$options = new Options(
				$applicationFactory->getSettings()->get( 'smwgElasticsearchConfig' )
			);
		}

		$queryEngine = new QueryEngine(
			$store,
			$options
		);

		$queryEngine->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		return $queryEngine;
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 *
	 * @return Rebuilder
	 */
	public function newRebuilder( Store $store ) {

		$rebuilder = new Rebuilder(
			$store->getConnection( 'elastic' ),
			$this->newIndexer( $store ),
			new PropertyTableRowMapper( $store )
		);

		return $rebuilder;
	}

}
