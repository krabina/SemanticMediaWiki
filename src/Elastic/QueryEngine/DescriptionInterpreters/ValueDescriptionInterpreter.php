<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\ValueDescription;
use SMW\Query\Language\Description;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\Options;
use SMWDIGeoCoord as DIGeoCoord;
use SMWDITime as DITime;
use SMWDIBoolean as DIBoolean;
use SMWDInumber as DINumber;
use SMWDIBlob as DIBlob;
use SMWDIUri as DIUri;
use SMWDataItem as DataItem;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class ValueDescriptionInterpreter {

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
	 * @param ValueDescription $description
	 *
	 * @return array
	 */
	public function interpretDescription( ValueDescription $description, $isConjunction = false ) {

		$this->queryBuilder->addDescriptionLog( $description );

		$dataItem = $description->getDataItem();
		$comparator = $description->getComparator();

		$property = $description->getProperty();
		$fieldMapper = $this->queryBuilder->getFieldMapper();

		$params = [];
		$pid = false;
		$filter = false;

		if ( $property === null ) {
			$field = "subject.sortkey";
		} else {
			$pid = 'P:' . $this->queryBuilder->getID( $property );

			if ( $property->isInverse() ) {
				// Want to know if this case happens and if so we need to handle
				// it somewhow ...
				throw new RuntimeException( "ValueDescription with an inverted property! PID: $pid, " . $description->getQueryString() );
			} else {
				$field = $fieldMapper->getField( $property, 'Field' );
			}

			$field = "$pid.$field";
		}

		//$description->getHierarchyDepth(); ??
		$hierarchyDepth = null;

		$hierarchy = $this->findHierarchyMembers(
			$property,
			$hierarchyDepth
		);

		if ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_EQ && $property === null ) {
			// We want an exact match!
			$field = '_id';
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_NEQ && $property === null ) {
			// We want an exact match!
			$field = '_id';
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_EQ ) {
			$field = "$pid.wpgID";
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_NEQ ) {
			$field = "$pid.wpgID";
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage ) {
			$value = $dataItem->getSortKey();
		} elseif ( $dataItem instanceof DITime ) {
			$field = "$field.keyword";
			$value = $dataItem->getJD();
		} elseif ( $dataItem instanceof DIBoolean ) {
			$value = $dataItem->getBoolean();
		} elseif ( $dataItem instanceof DINumber ) {
			$value = $dataItem->getNumber();
		} else {
			$value = $dataItem->getSerialization();
		}

		if ( $dataItem instanceof DIWikiPage && $this->isRange( $comparator ) ) {
			$params = $fieldMapper->range( "$field.keyword", $value, $comparator );
		} elseif ( $dataItem instanceof DIWikiPage && $dataItem->getDBKey() === 'NO_SUBOBJECT' ) {
			$params = $fieldMapper->term( "subject.subobject.keyword", '' );
			$filter = true;
		} elseif ( $dataItem instanceof DIBlob && $comparator === SMW_CMP_EQ ) {
			$params = $fieldMapper->match( "$field", "\"$value\"" );
		} elseif ( $comparator === SMW_CMP_EQ || $comparator === SMW_CMP_NEQ ) {
			$params = $fieldMapper->terms( "$field", $value );
		} elseif ( $comparator === SMW_CMP_LIKE || $comparator === SMW_CMP_NLKE ) {

			$hasWildcard = strpos( $value, '*' ) !== false;

			// [[~~ ... ]] (wide proximity)
			if ( $value{0} === '~' ) {
				// QueryShardException[Failed to parse query [~* ...
				$value = substr( $value, 1 );

				if ( !$hasWildcard && $this->options->safeGet( 'wide.proximity.match_phrase', true ) ) {
					$value = "\"$value\"";
				}

				$field = $this->options->safeGet( 'wide.proximity.fields', [ 'text_all' ] );
			}

			// Wildcard? use the query_string
			if ( $hasWildcard ) {
				$params = $fieldMapper->query_string( $field, $value );
			} else {
				$params = $fieldMapper->match( $field, $value );
			}
		} elseif ( $this->isRange( $comparator ) ) {
			$params = $fieldMapper->range( $field, $value, $comparator );
		} else {
			$params = $fieldMapper->match( $field, $value );
		}

		if ( $params !== [] && $pid ) {
			$params = $fieldMapper->hierarchy( $params, $pid, $hierarchy );
		}

		if ( $this->isNot( $comparator ) ) {
			$params = $fieldMapper->bool( 'must_not', $params );
		}

		if ( !$isConjunction ) {
			$params = $fieldMapper->bool( ( $this->isNot( $comparator ) ? 'must_not' : ( $filter ? 'filter' : 'must' ) ), $params );
		}

		return $params;
	}

	private function findHierarchyMembers( $property, $hierarchyDepth ) {

		$hierarchy = [];
		$hierarchyLookup = $this->queryBuilder->getHierarchyLookup();

		if ( $property !== null && ( $members = $hierarchyLookup->getConsecutiveHierarchyList( $property ) ) !== [] ) {

			if ( $hierarchyDepth !== null ) {
				$members = $hierarchyDepth == 0 ? [] : array_slice( $members, 0, $hierarchyDepth );
			}

			foreach ( $members as $member ) {
				$hierarchy[] = $this->queryBuilder->getID( $member );
			}
		}

		return $hierarchy;
	}

	private function isRange( $comparator ) {
		return $comparator === SMW_CMP_GRTR || $comparator === SMW_CMP_GEQ || $comparator === SMW_CMP_LESS || $comparator === SMW_CMP_LEQ;
	}

	private function isNot( $comparator ) {
		return $comparator === SMW_CMP_NLKE || $comparator === SMW_CMP_NEQ;
	}

}
