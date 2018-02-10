<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\NamespaceDescription;
use SMW\Options;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class NamespaceDescriptionInterpreter {

	/**
	 * @var QueryBuilder
	 */
	private $queryBuilder;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @since 3.0
	 *
	 * @param QueryBuilder $queryBuilder
	 * @param Options $options
	 */
	public function __construct( QueryBuilder $queryBuilder, Options $options ) {
		$this->queryBuilder = $queryBuilder;
		$this->options = $options;
	}

	/**
	 * @since 3.0
	 *
	 * @param NamespaceDescription $description
	 *
	 * @return array
	 */
	public function interpretDescription( NamespaceDescription $description, $isConjunction = false ) {

		$this->queryBuilder->addDescriptionLog( $description );

		$params = [];
		$fieldMapper = $this->queryBuilder->getFieldMapper();

		$namespace = $description->getNamespace();
		$params = $fieldMapper->term( 'subject.namespace', $namespace );

		if ( !$isConjunction ) {
			$params = $fieldMapper->bool( 'filter', $params );
		}

		return $params;
	}

}
