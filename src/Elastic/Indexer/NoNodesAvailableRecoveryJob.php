<?php

namespace SMW\Elastic\Indexer;

use SMW\ApplicationFactory;
use SMW\MediaWiki\Jobs\JobBase;
use SMW\Elastic\ElasticFactory;
use SMW\Elastic\Connection\Client as ElasticClient;
use SMW\SQLStore\ChangeOp\ChangeDiff;
use SMW\DIWikiPage;
use Title;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class NoNodesAvailableRecoveryJob extends JobBase {

	/**
	 * @since 3.0
	 *
	 * @param Title $title
	 * @param array $params job parameters
	 */
	public function __construct( Title $title, $params = array() ) {
		parent::__construct( 'SMW\ElasticNoNodesAvailableRecoveryJob', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * @see Job::run
	 *
	 * @since  3.0
	 */
	public function allowRetries() {
		return false;
	}

	/**
	 * @see Job::run
	 *
	 * @since  3.0
	 */
	public function run() {

		$applicationFactory = ApplicationFactory::getInstance();
		$store = $applicationFactory->getStore( '\SMW\SQLStore\SQLStore' );

		$connection = $store->getConnection( 'elastic' );

		// Make sure a node is available
		if ( $connection->hasLock( ElasticClient::TYPE_DATA ) || !$connection->ping() ) {

			if ( $connection->hasLock( ElasticClient::TYPE_DATA ) ) {
				$this->params['retryCount'] = 0;
			}

			return $this->requeueRetry( $connection->getConfig() );
		}

		$elasticFactory = new ElasticFactory();

		$this->indexer = $elasticFactory->newIndexer(
			$store
		);

		$this->indexer->setOrigin( 'SMW\Elastic\Indexer\NoNodesAvailableRecoverJob' );

		$this->indexer->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		if ( $this->hasParameter( 'delete' ) ) {
			$this->delete( $this->getParameter( 'delete' ) );
		}

		if ( $this->hasParameter( 'create' ) ) {
			$this->create( $this->getParameter( 'create' ) );
		}

		if ( $this->hasParameter( 'replicate' ) ) {
			$this->replicate(
				$applicationFactory->getCache(),
				$this->getParameter( 'replicate' )
			);
		}

		return true;
	}

	private function requeueRetry( $config ) {

		// Give up!
		if ( $this->getParameter( 'retryCount' ) >= $config->dotGet( 'replication.no.nodes.available.recovery.job.retries' ) ) {
			return true;
		}

		if ( !isset( $this->params['retryCount'] ) ) {
			$this->params['retryCount'] = 1;
		} else {
			$this->params['retryCount']++;
		}

		if ( !isset( $this->params['createdAt'] ) ) {
			$this->params['createdAt'] = time();
		}

		$job = new self( $this->title, $this->params );
		$job->setDelay( 60 * 10 );

		$job->insert();
	}

	private function delete( array $idList ) {
		$this->indexer->delete( $idList );
	}

	private function create( $hash ) {
		$this->indexer->create( DIWikiPage::doUnserialize( $hash ) );
	}

	private function replicate( $cache, $hash ) {

		$changeDiff = ChangeDiff::fetch(
			$cache,
			DIWikiPage::doUnserialize( $hash )
		);

		if ( $changeDiff !== false ) {
			$this->indexer->replicate( $changeDiff );
		}
	}

}
