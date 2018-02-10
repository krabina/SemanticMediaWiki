<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\ClassDescription;
use SMW\Query\Language\Description;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Disjunction;
use SMW\ApplicationFactory;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\Options;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ClassDescriptionInterpreter {

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
	 * @param ClassDescription $description
	 *
	 * @return array
	 */
	public function interpretDescription( ClassDescription $description, $isConjunction = false ) {

		$this->queryBuilder->addDescriptionLog( $description);

		$pid = 'P:' . $this->queryBuilder->getID( new DIProperty( '_INST' ) );
		$field = 'wpgID';

		$dataItems = $description->getCategories();
		$hierarchyDepth = $description->getHierarchyDepth();

		$isNegation = false;
		$should = !$isConjunction;
		$params = [];

		if ( isset( $description->isNegation ) ) {
			$isNegation = $description->isNegation;
		}

		// More than one member per list means OR
		if ( count( $dataItems ) > 1 ) {
			$should = true;
		}

		$fieldMapper = $this->queryBuilder->getFieldMapper();
		$hierarchyLookup = $this->queryBuilder->getHierarchyLookup();

		foreach ( $dataItems as $dataItem ) {
			$value = $this->queryBuilder->getID( $dataItem );

			$p = $fieldMapper->term( "$pid.$field", $value );
			$hierarchy = [];

			if ( ( $members = $hierarchyLookup->getConsecutiveHierarchyList( $dataItem ) ) !== [] ) {

				if ( $hierarchyDepth !== null ) {
					$members = $hierarchyDepth == 0 ? [] : array_slice( $members, 0, $hierarchyDepth );
				}

				$ids = [];

				foreach ( $members as $member ) {
					$ids[] = $this->queryBuilder->getID( $member );
				}

				$hierarchy[] = $fieldMapper->terms( "$pid.$field", $ids );
			}

			// Hierarchies cannot be build as part of the normal index process
			// therefore use the consecutive list to build a chain of disjunctive
			// (should === OR) queries to match members of the list
			if ( $hierarchy !== [] ) {
				$params[] = $fieldMapper->bool( 'should', array_merge( [ $p ], $hierarchy ) );
			} else {
				$params[] = $p;
			}
		}

		// Feature that doesn't work with the SQLStore!!
		// `[[Category:CatTest]][[Category:!CatTest1]]`
		if ( $isNegation ) {
			$params = $fieldMapper->bool( 'must_not', $params );
		}

		// ??!! If the description contains more than one category then it is
		// interpret as OR (same as the SQLStore) and only in the case of an AND
		// it is represented as Conjunction description
		$params = $fieldMapper->bool( ( $should ? 'should' : 'must' ), $params );

		return $params;
	}


}
