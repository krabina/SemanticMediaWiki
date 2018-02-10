<?php

namespace SMW\Elastic\QueryEngine\DescriptionInterpreters;

use SMW\Elastic\QueryEngine\QueryBuilder;
use SMW\Query\Language\ClassDescription;
use SMW\Query\Language\ConceptDescription;
use SMW\Query\Language\NamespaceDescription;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Disjunction;
use SMW\Query\Language\SomeProperty;
use SMW\Query\Language\ThingDescription;
use SMW\Query\Language\ValueDescription;
use SMW\Query\Language\Description;
use Maps\Semantic\ValueDescriptions\AreaDescription;
use SMW\ApplicationFactory;
use SMW\DataTypeRegistry;
use SMWDIGeoCoord as DIGeoCoord;
use SMWDITime as DITime;
use SMWDIBoolean as DIBoolean;
use SMWDInumber as DINumber;
use SMWDIBlob as DIBlob;
use SMWDIUri as DIUri;
use SMWDataItem as DataItem;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\Options;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class SomePropertyInterpreter {

	/**
	 * @var QueryBuilder
	 */
	private $queryBuilder;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var FieldMapper
	 */
	private $fieldMapper;

	/**
	 * @var TermsLookup
	 */
	private $termsLookup;

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
	 * @param SomeProperty $description
	 *
	 * @return array
	 */
	public function interpretDescription( SomeProperty $description, $isConjunction = false, $isChain = false ) {

		// Query types
		//
		// - term: query matches a single term as it is, the value is not
		//   analyzed
		// - match_phrase: query will analyze the input, all the terms must
		//   appear in the field, they must have the same order as the input
		//   value

		// Bool types
		//
		// - must: query must appear in matching documents and will contribute
		//   to the score
		// - filter: query must appear in matching documents, the score
		//   of the query will be ignored
		// - should: query should appear in the matching document

		$this->queryBuilder->addDescriptionLog( $description );

		$property = $description->getProperty();
		$pid = 'P:' . $this->queryBuilder->getID( $property );

		$hierarchy = $this->findHierarchyMembers(
			$property,
			$description->getHierarchyDepth()
		);

		$desc = $description->getDescription();
		$field = 'wpgID';

		$must_not = false;
		$should = false;
		$filter = false;

		$this->fieldMapper = $this->queryBuilder->getFieldMapper();
		$this->termsLookup = $this->queryBuilder->getTermsLookup();

		$field = $this->fieldMapper->getField( $property, 'Field' );
		$params = [];

		// [[Foo::Bar]]
		if ( $desc instanceof ValueDescription ) {
			$params = $this->interpretValueDescription( $desc, $property, $pid, $field, $must_not, $filter );
		}

		// [[Foo::+]]
		if ( $desc instanceof ThingDescription ) {
			$params = $this->interpretThingDescription( $desc, $property, $pid, $field, $must_not, $filter );
		}

		if ( $params !== [] ) {
			$params = $this->fieldMapper->hierarchy( $params, $pid, $hierarchy );
		}

		// [[-Person:: <q>[[Person.-Has friend.Person::Andy Mars]] [[Age::>>32]]</q> ]]
		if ( $desc instanceof Conjunction ) {
		//	var_dump( $desc->getQueryString(), $description->getMembership() );
			$params[] = $this->interpretConjunction( $desc, $property, $pid, $field );
		}

		// Use case: `[[Has page-2:: <q>[[Has page-1::Value 1||Value 2]]
		// [[Has text-1::Value 1||Value 2]]</q> || <q> [[Has page-2::Value 1||Value 2]]</q> ]]`
		if ( $desc instanceof Disjunction ) {
			$should = true;
			$params[] = $this->interpretDisjunction( $desc, $property, $pid, $field );
		}

		// [[Foo.Bar::Foobar]], [[Foo.Bar::<q>[[Foo::Bar]] OR [[Fobar::Foo]]</q>]]
		if ( $desc instanceof SomeProperty ) {
			$params[] = $this->interpretChain( $desc, $property, $pid, $field );
		}

		if ( $params === [] ) {
			return [];
		}

		$opType = ( $must_not ? 'must_not' : ( $should ? 'should' : ( $filter ? 'filter' : 'must' ) ) );
		$params = $this->fieldMapper->bool( $opType, $params );

		// Build an extra condition to restore strictness by making sure
		// the property exist on those matched entities
		// `[[Has text::!~foo*]]` becomes `[[Has text::!~foo*]] [[Has text::+]`
		if ( $must_not && !$desc instanceof ThingDescription ) {

			// Use case: `[[Category:Q0905]] [[!Example/Q0905/1]] <q>[[Has page::123]]
			// OR [[Has page::!ABCD]]</q>`
			$params = [ $this->fieldMapper->exists( "$pid.$field" ), $params ];

			if ( $this->options->safeGet( 'must_not.property.exists' ) ) {
				$description->notConditionField = "$pid.$field";
			}

			// Use case: `[[Has telephone number::!~*123*]]`
			if ( !$isConjunction ) {
				$params = $this->fieldMapper->bool( 'must', $params );
			}
		}

		if ( $isChain === false ) {
			return $params;
		}

		// Add reference to the previous path (if we have one) with an inverted
		// existential check to reduce the amount of matchable entities.
		//
		// Use case: `[[Has type::Record]] [[Has fields::Has date; Has status]]`
		// [[Has status record::~Dec 2007;closed]]
		if ( isset( $description->sourceChainMemberField ) && !$property->isInverse() ) {
			// [[-Has friend.Person::Foo]]
			$sourceChainMemberField = $description->sourceChainMemberField;
		}

		$params = $this->termsLookup->lookupSomeProperty(
			$description,
			$params
		);

		$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );

		return $params;
	}

	private function interpretDisjunction( $description, $property, $pid, $field ) {

		$p = [];

		foreach ( $description->getDescriptions() as $desc ) {

			$d = new SomeProperty(
				$property,
				$desc
			);

			$d->sourceChainMemberField = "$pid.wpgID";
			$t = $this->queryBuilder->interpretDescription( $d, true, true );

			if ( $t !== [] ) {
				$p[] = $t;
			}
		}

		if ( $p !== [] ) {
			return $this->fieldMapper->bool( 'should', $p );
		}

		return [];
	}

	private function interpretConjunction( $description, $property, $pid, $field ) {

		$p = [];
		$queryString = $description->getQueryString();

		foreach ( $description->getDescriptions() as $desc ) {
			$params = $this->queryBuilder->interpretDescription( $desc, true );

			if ( $params !== [] ) {
				$p[] = $params;
			}
		}

		if ( $p !== [] ) {
			// We match IDs using the term lookup which is either a resource or
			// a document field (on a txtField etc.)
			$f = strpos( $field, 'wpg' ) !== false ? "$pid.wpgID" : "_id";
			$p = $this->termsLookup->lookup( $queryString, $f, $p );
			$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );
		}

		// Inverse matches are always resource (aka wpgID) related
		if ( $property->isInverse() ) {
			$identifier = $property->getKey() . ' ← ' . $queryString;

			$p = $this->termsLookup->lookupInverse(
				$identifier, "$pid.wpgID",
				$this->fieldMapper->field_filter( "$pid.wpgID", $p )
			);

			$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );
		}

		if ( $p !== [] ) {
			return $this->fieldMapper->bool( 'must', $p );
		}

		return [];
	}

	private function interpretChain( $desc, $property, $pid, $field ) {

		$desc->sourceChainMemberField = "$pid.wpgID";
		$p = [];

		// Use case: `[[Category:Sample-1]][[Has page-1.Has page-2:: <q>
		// [[Has text-1::Value 1]] OR <q>[[Has text-2::Value 2]]
		// [[Has page-2::Value 2]]</q></q> ]]`
		if ( $desc->getDescription() instanceof Disjunction ) {

			foreach ( $desc->getDescription()->getDescriptions() as $d ) {
				$d = new SomeProperty(
					$desc->getProperty(),
					$d
				);
				$d->setMembership( $desc->getFingerprint() );
				$d->sourceChainMemberField = "$pid.wpgID";
				$t = $this->interpretDescription( $d, true, true );

				if ( $t !== [] ) {
					$p[] = $t;
				}
			}

		} else {
			$p = $this->interpretDescription( $desc, true, true );
		}

		if ( $property->isInverse() ) {
			$identifier = $property->getKey() . ' ← ' . $desc->getQueryString();

			$p = $this->termsLookup->lookupInverse(
				$identifier, "$pid.wpgID",
				$this->fieldMapper->field_filter( "$pid.wpgID", $p )
			);

			$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );
		}

		return $this->fieldMapper->bool( 'should', $p );
	}

	private function interpretThingDescription( $desc, $property, $pid, $field, &$must_not, &$filter ) {

		if ( DataTypeRegistry::getInstance()->getDataItemByType( $property->findPropertyValueType() ) === DataItem::TYPE_WIKIPAGE ) {
			$field = 'wpgID';
		}

		$filter = true;

		if ( isset( $desc->isNegation ) ) {
			$must_not = true;
		}

		return $this->fieldMapper->exists( "$pid.$field" );
	}

	private function interpretValueDescription( $desc, $property, $pid, &$field, &$must_not, &$filter ) {

		$dataItem = $desc->getDataItem();
		$comparator = $desc->getComparator();
		$value = '';

		$comparator = $comparator === SMW_CMP_PRIM_LIKE ? SMW_CMP_LIKE : $comparator;
		$comparator = $comparator === SMW_CMP_PRIM_NLKE ? SMW_CMP_NLKE : $comparator;

		$must_not = $comparator === SMW_CMP_NLKE || $comparator === SMW_CMP_NEQ;

		if ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_EQ ) {
			$field = 'wpgID';
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_NEQ ) {
			$field = 'wpgID';
			$value = $this->queryBuilder->getID( $dataItem );
		} elseif ( $dataItem instanceof DIWikiPage ) {
			$value = $dataItem->getSortKey();
		} elseif ( $dataItem instanceof DITime ) {
			$value = $dataItem->getJD();
		} elseif ( $dataItem instanceof DIBoolean ) {
			$value = $dataItem->getBoolean();
		} elseif ( $dataItem instanceof DIGeoCoord ) {
			$value = $dataItem->getSerialization();
		} elseif ( $dataItem instanceof DINumber ) {
			$value = $dataItem->getNumber();
		} elseif ( $dataItem instanceof DIUri ) {
			$value = str_replace( [ '%2A' ], [ '*' ], rawurldecode( $dataItem->getUri() ) );
		} else {
			$value = $dataItem->getSerialization();
		}

		$match = [];

		if ( $comparator === SMW_CMP_GRTR || $comparator === SMW_CMP_GEQ ) {

			// Use not analyzed field
			if ( $dataItem instanceof DIBlob ) {
				$field = "$field.keyword";
			}

			$match = $this->fieldMapper->range( "$pid.$field", $value, $comparator );
		} elseif ( $comparator === SMW_CMP_LESS || $comparator === SMW_CMP_LEQ ) {

			// Use not analyzed field
			if ( $dataItem instanceof DIBlob ) {
				$field = "$field.keyword";
			}

			$match = $this->fieldMapper->range( "$pid.$field", $value, $comparator );
		} elseif ( $dataItem instanceof DIBlob && $comparator === SMW_CMP_EQ ) {

			// #3020
			// Use a term query where possible to allow ES to create a bitset and
			// cache the lookup if possible
			if ( $property->findPropertyValueType() === '_keyw' ) {
				$match = $this->fieldMapper->term( "$pid.$field.keyword", "$value" );
				$filter = true;
			} else {
				$match = $this->fieldMapper->match( "$pid.$field", "\"$value\"" );
			}

			/*
			if ( substr( $value , -1 ) === '*' ) {
				// [[Has text::Templ*]]
				$match = $this->fieldMapper->wildcard( "$pid.$field", $value );
				$filter = true;
			} elseif ( strpos( $value, ' ' ) === false )  {
				// [[Has text::Template]]
				$match = $this->fieldMapper->term( "$pid.$field.keyword", $value );
				$filter = true;
			} else {
				// [[Has text::Template one]]
				$match = $this->fieldMapper->match_phrase( "$pid.$field", "$value" );
			}
			*/

		} elseif ( $dataItem instanceof DIUri && $comparator === SMW_CMP_EQ ) {

			if ( $this->options->safeGet( 'uri.field.case.insensitive' ) ) {
				// As EQ, use the match_phrase to ensure that each part of the
				// string is part of the match.
				// T:Q0908
				$match = $this->fieldMapper->match_phrase( "$pid.$field.lowercase", "$value" );
			} else {
				// Use the keyword field (not analyzed) so that the search
				// matches the exact term
				// T:P0419 (`http://example.org/FoO` !== `http://example.org/Foo`)
				$match = $this->fieldMapper->term( "$pid.$field.keyword", "$value" );
			}
		} elseif ( $dataItem instanceof DIBlob && $comparator === SMW_CMP_LIKE ) {
			// T:Q0102 Choose a `P:xxx.*` over a specific `P:xxx.txtField` field
			// to enforce a `DisjunctionMaxQuery` as in
			// `"(P:8316.txtField:*\\{* | P:8316.txtField.keyword:*\\{*)",`
			$fields = [ "$pid.$field", "$pid.$field.keyword" ];
			$match = $this->fieldMapper->query_string( $fields, $value );
		} elseif ( $dataItem instanceof DIBlob && $comparator === SMW_CMP_NLKE ) {

			// T:Q0904, Interpreting the meaning of `!~elastic*, +sear*` which is
			// to match non with the term `elastic*` but those that match `sear*`
			// with the conseqence that this is turned from a `must_not` to a `must`
			if ( $this->options->safeGet( 'boolean.operators' ) && ( strpos( $value, '+' ) !== false ) ) {
				$must_not = false;
				$value = "-$value";
			}

			$match = $this->fieldMapper->query_string( "$pid.$field", $value );
		} elseif ( $dataItem instanceof DIUri && $comparator === SMW_CMP_LIKE || $dataItem instanceof DIUri && $comparator === SMW_CMP_NLKE ) {

			$value = str_replace( [ 'http://', 'https://', '=' ], [ '', '', '' ], $value );

			if ( strpos( $value, 'tel:' ) !== false || strpos( $value, 'mailto:' ) !== false ) {
				$value = str_replace( [ 'tel:', 'mailto:' ], [ '', '' ], $value );
				$field = "$field.keyword";
			} elseif ( $this->options->safeGet( 'uri.field.case.insensitive' ) ) {
				$field = "$field.lowercase";
			}

			$match = $this->fieldMapper->query_string( "$pid.$field", $value );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_LIKE ) {
			$match = $this->fieldMapper->query_string( "$pid.$field", $value );
		} elseif ( $dataItem instanceof DIWikiPage && $comparator === SMW_CMP_NLKE ) {

			// T:Q0905, Interpreting the meaning of `!~elastic*, +sear*` which is
			// to match non with the term `elastic*` but those that match `sear*`
			// with the conseqence that this is turned from a `must_not` to a `must`
			if ( $this->options->safeGet( 'boolean.operators' ) && ( strpos( $value, '+' ) !== false ) ) {
				$must_not = false;
				$value = "-$value";
			}

			$match = $this->fieldMapper->query_string( "$pid.$field", $value );
		} elseif ( $dataItem instanceof DIGeoCoord && $desc instanceof AreaDescription ) {

			// Due to "QueryShardException: Geo fields do not support exact
			// searching, use dedicated geo queries instead" on EQ search,
			// the geo_point is indexed as extra field geoField.point to make
			// use of the `bounding_box` feature in ES while the standard EQ
			// search uses the geoField string representation
			$boundingBox = $desc->getBoundingBox();

			$match = $this->fieldMapper->geo_bounding_box(
				"$pid.$field.point",
				$boundingBox['north'],
				$boundingBox['west'],
				$boundingBox['south'],
				$boundingBox['east']
			);
		} elseif ( $dataItem instanceof DIGeoCoord && $comparator === SMW_CMP_EQ ) {
			$match = $this->fieldMapper->terms( "$pid.$field", $value );
		} elseif ( $comparator === SMW_CMP_LIKE ) {
			$match = $this->fieldMapper->match( "$pid.$field", $value, 'and' );
		} elseif ( $comparator === SMW_CMP_EQ ) {
			$filter = true;
			$match = $this->fieldMapper->term( "$pid.$field", $value );
		} elseif ( $comparator === SMW_CMP_NEQ ) {
			$match = $this->fieldMapper->term( "$pid.$field", $value );
		} else {
			$match = $this->fieldMapper->match( "$pid.$field", $value, 'and' );
		}

		$params = $match;

		if ( $property->isInverse() ) {

			$identifier = $property->getKey() . ' ← ' . $desc->getQueryString();

			// A simple inverse is enough to fetch the inverse match for a resource
			// [[-Has query::F0103/PageContainsAskWithTemplateUsage]]
			if ( $comparator === SMW_CMP_EQ || $comparator === SMW_CMP_NEQ ) {
				$params = $this->termsLookup->lookupInverse( $identifier, "$pid.wpgID", $value );
				$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );
			} else {

				// First we need to find entities that fullfill the condition
				// `~*Test*` to allow to match the `-Has subobject` part from
				// [[-Has subobject::~*Test*]]

				// Either use the resource or the document field
				$f = strpos( $field, 'wpg' ) !== false ? "$pid.wpgID" : "_id";
				//$f = $fieldType === '_wpg' ? "$pid.wpgID" : "_id";

				$p = $this->termsLookup->lookup( $desc->getQueryString(), $f, $params );
				$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );

				$p = $this->fieldMapper->field_filter( $f, $p );

				$params = $this->termsLookup->lookupInverse( $identifier, $f, $p );
				$this->queryBuilder->addQueryInfo( $this->termsLookup->getQueryInfo() );
			}
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

}
