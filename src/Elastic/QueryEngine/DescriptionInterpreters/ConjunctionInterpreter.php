<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\Conjunction;
use SMW\Options;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ConjunctionInterpreter {

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
	 * @param Conjunction $description
	 *
	 * @return array
	 */
	public function interpretDescription( Conjunction $description, $isConjunction = false ) {

		$this->queryBuilder->addDescriptionLog( $description );

		$params = [];
		$fieldMapper = $this->queryBuilder->getFieldMapper();

		foreach ( $description->getDescriptions() as $desc ) {
			if ( ( $param = $this->queryBuilder->interpretDescription( $desc, true ) ) !== [] ) {
				$params[] = $param;
			}
		}

		if ( $params === [] ) {
			return [];
		}

		return $fieldMapper->bool( 'must', $params );
	}

}
