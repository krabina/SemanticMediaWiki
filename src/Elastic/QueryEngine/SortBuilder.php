<?php

namespace SMW\Elastic\QueryEngine;

use SMW\Store;
use Psr\Log\LoggerAwareTrait;
use SMWQuery as Query;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\DataTypeRegistry;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class SortBuilder {

	use LoggerAwareTrait;

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var string
	 */
	private $scoreField;

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * @since 3.0
	 *
	 * @param string $scoreField
	 */
	public function setScoreField( $scoreField ) {
		$this->scoreField = $scoreField;
	}

	/**
	 * @since 3.0
	 *
	 * @param Query $query
	 *
	 * @return array
	 */
	public function makeSortField( Query $query ) {

		// @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-sort.html#_memory_considerations
		// "... the relevant sorted field values are loaded into memory. This means
		// that per shard, there should be enough memory ... string based types,
		// the field sorted on should not be analyzed / tokenized ... numeric
		// types it is recommended to explicitly set the type to narrower types"

		if ( $query->getRelevanceOrder() !== null ) {
			return [ [ '_score' => [ 'order' => $query->getRelevanceOrder() ] ], [], false, false];
		}

		return $this->getFields( $query->getSortKeys() );
	}

	private function getFields( array $sortKeys ) {

		$isRandom = false;
		$isConstantScore = true;
		$sort = [];
		$sortFields = [];

		foreach ( $sortKeys as $key => $order ) {
			$order = strtolower( $order );
			$isRandom = strpos( $order, 'rand' ) !== false;

			if ( strtolower( $key ) === $this->scoreField ) {
				$key = '_score';
				$isConstantScore = false;
			}

			if ( $key === '' || $key === '#' ) {
				$this->addDefaultField( $sort, $order );
			} else {
				$this->addField( $sort, $sortFields, $key, $order );
			}
		}

		return [ $sort, $sortFields, $isRandom, $isConstantScore ];
	}

	private function addDefaultField( &$sort, $order ) {
		$sort['subject.sortkey.sort'] = [ 'order' => $order ];

		// Add extra title in case an entity uses the same sortkey to clarify
		// an extra criteria, @see T:P0416#8
		$sort['subject.title.sort'] = [ 'order' => $order ];
	}

	private function addField( &$sort, &$sortFields, $key, $order ) {

		$dataTypeRegistry = DataTypeRegistry::getInstance();
		$chain = false;

		// Chain?
		if ( strpos( $key, '.' ) !== false ) {
			$list = explode( '.', $key );
			$last = current( $list );
		} else {
			$list = [ $key ];
		}

		foreach ( $list as $key ) {

			if ( $key === '_score' ) {
				$field = '_score';
			} else {
				$property = DIProperty::newFromUserLabel( $key );

				// Remove leading `_` from for example `_txt`
				$field = str_replace(
					[ '_' ],
					[ '' ],
					$dataTypeRegistry->getFieldType( $property->findPropertyValueType() )
				) . 'Field';

				$pid = 'P:' . (int)$this->store->getObjectIds()->getSMWPropertyID( $property );

				// Only record the last key to be used as possible existential
				// enforcement
				if ( $chain === false ) {
					$sortFields[] = "$pid.$field";
				}

				// Use the keyword field on mapped fields (as there not being
				// analyzed)
				if ( strpos( $field, 'txt' ) !== false || strpos( $field, 'wpgField' ) !== false || strpos( $field, 'uriField' ) !== false ) {
					$field = "$field.sort";
				}

				$field = "$pid.$field";
			}

			if ( !isset( $sort[$field] ) ) {
				$sort[$field] = [ 'order' => $order ];
			}

			$chain = true;
		}
	}

}
