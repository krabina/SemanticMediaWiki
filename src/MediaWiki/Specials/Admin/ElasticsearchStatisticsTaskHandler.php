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
class ElasticsearchStatisticsTaskHandler extends TaskHandler {

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
		return $task === 'elastic/statistics';
	}

	/**
	 * @since 3.0
	 *
	 * {@inheritDoc}
	 */
	public function getHtml() {

		$link = $this->outputFormatter->createSpecialPageLink(
			$this->getMessageAsString( 'smw-admin-supplementary-elastic-statistics-title' ),
			[ 'action' => 'elastic/statistics' ]
		);

		return Html::rawElement(
			'li',
			[],
			$this->getMessageAsString(
				[
					'smw-admin-supplementary-elastic-statistics-intro',
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

		$this->outputFormatter->setPageTitle( 'Elasticsearch statistics' );

		$this->outputFormatter->addParentLink(
			[ 'action' => 'elastic' ],
			'smw-admin-supplementary-elastic-title'
		);

		$this->outputInfo();
	}

	private function outputInfo() {

		$connection = $this->getStore()->getConnection( 'elastic' );

		$html = Html::rawElement(
			'p',
			[
				'class' => 'plainlinks'
			],
			$this->getMessageAsString(
				[
					'smw-admin-supplementary-elastic-statistics-docu',
				],
				Message::PARSE
			)
		);

		$this->outputFormatter->addHtml( $html );

		$this->outputFormatter->addAsPreformattedText(
			$this->outputFormatter->encodeAsJson( $connection->stats() )
		);
	}

}
