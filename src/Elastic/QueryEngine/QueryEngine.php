<?php

namespace SMW\Elastic\QueryEngine;

use Psr\Log\LoggerAwareTrait;
use SMWQuery as Query;
use SMWQueryResult as QueryResult;
use SMW\Query\Language\ThingDescription;
use SMW\ApplicationFactory;
use SMW\DIWikiPage;
use SMW\QueryEngine as IQueryEngine;
use SMW\Store;
use SMW\Options;
use SMW\Elastic\Connection\Client as ElasticClient;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class QueryEngine implements IQueryEngine {

	use LoggerAwareTrait;

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var QueryFactory
	 */
	private $queryFactory;

	/**
	 * @var QueryBuilder
	 */
	private $queryBuilder;

	/**
	 * @var FieldMapper
	 */
	private $fieldMapper;

	/**
	 * @var SortBuilder
	 */
	private $sortBuilder;

	/**
	 * @var array
	 */
	private $options = [];

	/**
	 * @var array
	 */
	private $errors = [];

	/**
	 * @var array
	 */
	private $queryInfo = [];

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param Options|null $options
	 */
	public function __construct( Store $store, Options $options = null ) {
		$this->store = $store;
		$this->options = $options;

		if ( $options === null ) {
			$this->options = new Options();
		}

		$this->queryFactory = ApplicationFactory::getInstance()->getQueryFactory();
		$this->fieldMapper = new FieldMapper();

		$this->queryBuilder = new QueryBuilder(
			$store,
			new Options( $this->options->safeGet( 'query', [] ) )
		);

		$this->sortBuilder = new SortBuilder( $store );

		$this->sortBuilder->setScoreField(
			$this->options->dotGet( 'query.score.sortfield' )
		);
	}

	/**
	 * @since 3.0
	 *
	 * @param []
	 */
	public function getQueryInfo() {
		return $this->queryInfo;
	}

	/**
	 * @since 3.0
	 *
	 * @param Query $query
	 *
	 * @return QueryResult
	 */
	public function getQueryResult( Query $query ) {

//		if ( ( !$this->engineOptions->get( 'smwgIgnoreQueryErrors' ) || $query->getDescription() instanceof ThingDescription ) &&

		if ( ( $query->getDescription() instanceof ThingDescription ) &&
				$query->querymode != Query::MODE_DEBUG &&
				count( $query->getErrors() ) > 0 ) {
			return $this->queryFactory->newQueryResult( $this->store, $query, array(), false );
			// NOTE: we check this here to prevent unnecessary work, but we check
			// it after query processing below again in case more errors occurred.
		} elseif ( $query->querymode == Query::MODE_NONE || $query->getLimit() < 1 ) {
			return $this->queryFactory->newQueryResult( $this->store, $query, array(), true );
		}

		$this->errors = [];

		$this->queryInfo = [
			'smw' => [],
			'elastic' => [],
			'info' => []
		];

		list( $sort, $sortFields, $isRandom, $isConstantScore ) = $this->sortBuilder->makeSortField(
			$query
		);

		$description = $query->getDescription();
		$body = [];

		$this->queryBuilder->setSortFields(
			$sortFields
		);

		$q = $this->queryBuilder->makeFromDescription(
			$description,
			$isConstantScore
		);

		$this->errors = $this->queryBuilder->getErrors();
		$this->queryInfo['elastic'] = $this->queryBuilder->getQueryInfo();

		if ( $isRandom ) {
			$q = $this->fieldMapper->function_score_random( $q );
		}

		$body = [
			// @see https://www.elastic.co/guide/en/elasticsearch/reference/6.1/search-request-source-filtering.html
			// We only want the ID, no need for all the body
			'_source' => false,
			'from'    => $query->getOffset(),
			'size'    => $query->getLimit() + 1, // Look ahead +1,
			'query'   => $q
		];

		if ( !$isRandom && $sort !== [] ) {
			$body['sort'] = [ $sort ];
		}

		if ( $this->options->dotGet( 'query.profiling' ) ) {
			$body['profile'] = true;
		}

		$query->addErrors( $this->errors );

		$connection = $this->store->getConnection( 'elastic' );

		$index = $connection->getIndexNameByType(
			ElasticClient::TYPE_DATA
		);

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'body'  => $body
		];

		$this->queryInfo['elastic'][] = $params;

		$this->queryInfo['smw'] = [
			'query' => $query->getQueryString(),
			'sort'  => $query->getSortKeys(),
			'metrics' => [
				'query size'  => $description->getSize(),
				'query depth' => $description->getDepth()
			]
		];

		switch ( $query->querymode ) {
			case Query::MODE_DEBUG:
				$result = $this->newDebugQueryResult( $params );
			break;
			case Query::MODE_COUNT:
				$result = $this->newCountQueryResult( $query, $params );
			break;
			default:
				$result = $this->newInstanceQueryResult( $query, $params );
			break;
		}

		return $result;
	}

	private function newDebugQueryResult( $params ) {

		$params['explain'] = $this->options->dotGet( 'query.debug.explain', false );

		$connection = $this->store->getConnection( 'elastic' );
		$this->queryInfo['elastic'][] = $connection->validate( $params );

		if ( ( $log = $this->queryBuilder->getDescriptionLog() ) !== [] ) {
			$this->queryInfo['smw']['description_log'] = $log;
		}

		$info = str_replace(
			[ '[', '<', '>', '\"', '\n' ],
			[ '&#91;', '&lt;', '&gt;', '&quot;', '' ],
			json_encode( $this->queryInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		$html = \Html::rawElement(
			'pre',
			[
				'class' => 'smwpre smwpre-no-margin smw-debug-box'
			],
			\Html::rawElement(
				'div',
				[
					'class' => 'smw-debug-box-header'
				],
				'<big>ElasticStore debug output</big>'
			) . $info
		);

		return $html;
	}

	private function newCountQueryResult( $query, $params ) {

		$connection = $this->store->getConnection( 'elastic' );
		$result = $connection->count( $params );

		$queryResult = $this->queryFactory->newQueryResult(
			$this->store,
			$query,
			[],
			false
		);

		$count = isset( $result['count'] ) ? $result['count'] : 0;
		$queryResult->setCountValue( $count  );

		$this->queryInfo['info'] = $result;

		return $queryResult;
	}

	private function newInstanceQueryResult( $query, array $params ) {

		$connection = $this->store->getConnection( 'elastic' );

		list( $docs, $errors ) = $connection->search( $params );

		$query->addErrors( $errors );

		list( $results, $info, $scores, $continue ) = $this->fieldMapper->elastic_result(
			$docs,
			$query->getLimit()
		);

		$res = [];

		foreach ( $results as $id ) {

			$dataItem = $this->store->getObjectIds()->getDataItemById(
				$id
			);

			if ( $dataItem === null ) {
				$dataItem = new DIWikiPage( "SMW:UNKNOWN:$id", NS_SPECIAL );
			}

			$res[] = $dataItem;
		}

		$this->queryInfo['info'] = $info;

		$queryResult = $this->queryFactory->newQueryResult(
			$this->store,
			$query,
			$res,
			$continue
		);

		return $queryResult;
	}

}
