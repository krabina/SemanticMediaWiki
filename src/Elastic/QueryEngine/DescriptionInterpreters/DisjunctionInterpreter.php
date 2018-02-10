<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\Disjunction;
use SMW\Options;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class DisjunctionInterpreter {

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
	 * @param Disjunction $description
	 *
	 * @return array
	 */
	public function interpretDescription( Disjunction $description, $isConjunction = false ) {

		$this->queryBuilder->addDescriptionLog( $description );

		$params = [];
		$notConditionFields = [];

		foreach ( $description->getDescriptions() as $desc ) {
			if ( ( $param = $this->queryBuilder->interpretDescription( $desc, true ) ) !== [] ) {

				// @see SomePropertyInterpreter
				// Collect a possible negation condition in case `must_not.property.exists`
				// is set (which is the SMW default mode) to allow wrapping an
				// additional conditions around an OR when the existence of the
				// queried property is required
				if ( isset( $desc->notConditionField ) ) {
					$notConditionFields[] = $desc->notConditionField;
				}

				$params[] = $param;
			}
		}

		if ( $params === [] ) {
			return [];
		}

		$fieldMapper = $this->queryBuilder->getFieldMapper();
		$params = $fieldMapper->bool( 'should', $params );

		$notConditionFields = array_keys( array_flip( $notConditionFields ) );
		$existsConditions = [];

		foreach ( $notConditionFields as $field ) {
			$existsConditions[] = $fieldMapper->exists( $field );
		}

		// This one was a demanding case to model to ensure that T:Q0905#5 and
		// T:Q1106#4 can both be satisfied with a !/OR condition. We wrap
		// the intermediary `should` clause in an extra `must` to ensure those
		// properties are exists for the returned documents.
		//
		// Use case: `[[Category:E-Q1106]]<q>[[Has restricted status record::!~cl*]]
		// OR [[Has restricted status record::!~*in*]]</q>` and `[[Category:Q0905]]
		// [[!Example/Q0905/1]] <q>[[Has page::123]] OR [[Has page::!ABCD]]</q>`
		if ( $existsConditions !== [] ) {
			$params = $fieldMapper->bool( 'must', [ $params, $existsConditions ] );
		}

		return $params;
	}

}
