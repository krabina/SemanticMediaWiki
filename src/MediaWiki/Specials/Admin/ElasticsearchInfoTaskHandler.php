<?php

namespace SMW\MediaWiki\Specials\Admin;

use SMW\ApplicationFactory;
use SMW\Message;
use SMW\NamespaceManager;
use Html;
use WebRequest;
use SMW\Elastic\Connection\Client as ElasticClient;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ElasticsearchInfoTaskHandler extends TaskHandler {

	/**
	 * @var OutputFormatter
	 */
	private $outputFormatter;

	/**
	 * @since 3.0
	 *
	 * @param OutputFormatter $outputFormatter
	 */
	public function __construct( OutputFormatter $outputFormatter ) {
		$this->outputFormatter = $outputFormatter;
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function getSection() {
		return self::SECTION_SUPPLEMENT;
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function hasAction() {
		return true;
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function isTaskFor( $task ) {

		$actions = [
			'elastic',
			'elastic/settings',
			'elastic/indices',
			'elastic/statistics',
			'elastic/mappings'
		];

		return in_array( $task, $actions );
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function getHtml() {

		$connection = $this->getStore()->getConnection( 'elastic' );

		if ( !$connection->ping() ) {
			return '';
		}

		$link = $this->outputFormatter->createSpecialPageLink(
			$this->getMessageAsString( 'smw-admin-supplementary-elastic-title' ),
			[ 'action' => 'elastic' ]
		);

		return Html::rawElement(
			'li',
			[],
			$this->getMessageAsString(
				[
					'smw-admin-supplementary-elastic-intro',
					$link
				]
			)
		);
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function handleRequest( WebRequest $webRequest ) {

		$connection = $this->getStore()->getConnection( 'elastic' );
		$action = $webRequest->getText( 'action' );

		$taskHandlers = [
			new ElasticsearchSettingsTaskHandler( $this->outputFormatter ),
			new ElasticsearchIndicesTaskHandler( $this->outputFormatter ),
			new ElasticsearchStatisticsTaskHandler( $this->outputFormatter ),
			new ElasticsearchMappingsTaskHandler( $this->outputFormatter )
		];

		if ( !$connection->ping() ) {
			return $this->outputNoNodesAvailable();
		} elseif ( $action === 'elastic' ) {
			$this->outputHead();
		} else {
			foreach ( $taskHandlers as $actionTask ) {
				if ( $actionTask->isTaskFor( $action ) ) {

					$actionTask->setStore(
						$this->getStore()
					);

					return $actionTask->handleRequest( $webRequest );
				}
			}
		}

		$this->outputInfo( $taskHandlers );
	}

	private function outputNoNodesAvailable() {

		$this->outputHead();

		$html = Html::element(
			'div',
			[ 'class' => 'smw-callout smw-callout-error' ],
			'Elasticsearch has no active nodes available.'
		);

		$this->outputFormatter->addHTML( $html );
	}

	private function outputHead() {

		$this->outputFormatter->setPageTitle( 'Elasticsearch' );

		$this->outputFormatter->addParentLink(
			[ 'tab' => 'supplement' ]
		);

		$html = Html::rawElement(
			'p',
			[ 'class' => 'plainlinks' ],
			$this->getMessageAsString( array( 'smw-admin-supplementary-elastic-docu' ), Message::PARSE )
		);

		$this->outputFormatter->addHTML( $html );
	}

	private function outputInfo( $taskHandlers ) {

		$connection = $this->getStore()->getConnection( 'elastic' );

		$this->outputFormatter->addAsPreformattedText(
			$this->outputFormatter->encodeAsJson( $connection->info() )
		);

		$list = '';

		foreach ( $taskHandlers as $taskHandler ) {
			$list .= $taskHandler->getHtml();
		}

		$this->outputFormatter->addHTML(
			Html::element( 'h3', [], $this->getMessageAsString( 'smw-admin-supplementary-elastic-functions' ) )
		);

		$this->outputFormatter->addHTML(
			Html::rawElement( 'ul', [], $list )
		);
	}

}
