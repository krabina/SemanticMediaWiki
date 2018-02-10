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
class ElasticsearchIndicesTaskHandler extends TaskHandler {

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
		return $task === 'elastic/indices';
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function getHtml() {

		$link = $this->outputFormatter->createSpecialPageLink(
			$this->getMessageAsString( 'smw-admin-supplementary-elastic-indices-title' ),
			[ 'action' => 'elastic/indices' ]
		);

		return Html::rawElement(
			'li',
			[],
			$this->getMessageAsString(
				[
					'smw-admin-supplementary-elastic-indices-intro',
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

		$this->outputFormatter->setPageTitle( 'Elasticsearch indices' );

		$this->outputFormatter->addParentLink(
			[ 'action' => 'elastic' ],
			'smw-admin-supplementary-elastic-title'
		);

		$this->outputInfo();
	}

	private function outputInfo() {

		$connection = $this->getStore()->getConnection( 'elastic' );

		$indices = $connection->cat( 'indices' );
		ksort( $indices );

		$this->outputFormatter->addAsPreformattedText(
			$this->outputFormatter->encodeAsJson( $indices )
		);
	}

}
