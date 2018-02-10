# ElasticStore

[Requirements](#requirements) | [Features and caveats](#features-and-caveats) | [Usage](#usage) | [Settings](#settings) | [Searching](#searching) | [Monitoring](#monitoring) | [Technical notes](#technical-notes)

The `ElasticStore` is aimed to instantaneously replicate property-value pairs (see `SemanticData`) to an Elasticsearch cluster during the storage of an article and hereby enable the `QueryEngine` to retrieve answers for `#ask` queries from Elasticsearch (aka ES) instead of the default `SQLStore`.

`#ask` queries are system agnostic therefore queries that worked with the `SQLStore` (or `SPARQLStore`) are expected to work equally without having to learn a new syntax or modify existing queries.

The objective is to allow for the interface to:

- supported structured searches using the ES backend
- extend and improve existing text searches
- provide means for a scalability strategy by relying on the ES infrastructure

## Requirements

- Elasticsearch: Recommended 6.1+, Tested with 5.6.6
- Semantic MediaWiki: 3.0+
- `elasticsearch/elasticsearch` (PHP ^7.0 `~6.0` or PHP ^5.6.6 `~5.3`)

We only rely on the [elasticsearch php-api][es:php-api] (maintained by ES itself) to communicate with Elasticsearch and are therefore independent from any other MediaWiki extension that may use ES as search backend (e.g. `CirrusSearch`).

The [elasticsearch guide][es:hardware] notes "... machine with 64 GB of RAM is the ideal sweet spot, but 32 GB and 16 GB machines are also common. Less than 8 GB tends to be counterproductive ..." to make sure ES can operate and answer queries with an acceptable response time.

### Why Elasticsearch?

- It it is relatively easy to install and run an ES instance.
- ES allows to scale its cluster horizontally without requiring changes to Semantic MediaWiki or its query execution.
- It is more likely a user can provided access to an ES instance than to a `SPARQL` triple store (or a SORL/Lucence backend) given that MediaWiki provides other extensions that make use of ES and anticipate that those users are familiar with the setup and management of ES.

## Features and caveats

- Handle property type changes without the need to rebuild the entire index itself after it is ensured that all `ChangePropagation` jobs have been processed
- Inverse queries are supported `[[-Foo::Bar]]`
- Property chains and paths are supported `[[Foo.Bar::Foobar]]`
- Category and property hierarchies are supported

ES is not used as data store and therefore it is not expected that ES returns highlighted text snippets or any other data object besides document IDs that match a query condition.

A customized serialization format allows the transformation to an ES specific format and express `#ask` queries in an appropriate ES [domain language][es:dsl] to retrieve answers from its cluster.

## Usage

It is aimed in using Elasticsearch as drop-in replacement for the query answering and requires some settings and user actions before it can provide necessary functions.

- Set `$GLOBALS['smwgDefaultStore'] = 'SMWElasticStore';`
- Set `$GLOBALS['smwgElasticsearchEndpoints'] = [ ... ];`
- Run `php setupStore.php` or `php update.php`
- Rebuild the index using `php rebuildElasticIndex.php`

For ES specific settings, please consult the [elasticsearch][es:conf] manual.

### Indexing, updates, and refresh intervals

Updates to an ES index happens instantaneously during a page action to guarantee that queries can use the latest available data set.

The [index][es:create:index] help page has details about the index creation process in ES with Semantic MediaWiki providing two index types:

- the `data` index that hosts all indexable data and
- the `lookup` index to store queries used for concept, property path, and inverse match computations

#### Indexing

The `rebuildElasticIndex.php` script provides a fast way (meaning it doesn't rely on the MW parser and instead fetches information directly from the property tables) to replicate existing data from the `SQLStore` to the ES backend. The script operates in a [rollover][es:alias-zero] approach where if there is already an existing index, a new index with a different version is created, leaving the current active index untouched and allowing queries to operate while the re-index process is ongoing. Once completed, the new index switches places with the old index (which is removed from the ES cluster at this point).

It should be noted that active replication is paused for the duration of the rebuild so that changes to pages and annotations can be processed after the re-index has been completed therefore running the job scheduler is __obligatory__.

#### Safe replication

The `ElastciStore` by default is set to a `safe.replication` mode which entails that if during a page storage __no__ connection could be established to an ES cluster, a `SMW\ElasticNoNodesAvailableRecoveryJob` is scheduled. Those jobs should be executed on a regular basis to ensure that replicated data are kept in sync with the backend.

The `no.nodes.available.recovery.job.retries` is set to a maximum of retry attempts in case the job cannot establish a connection and after which the job is going to be canceled even though it could __not__ recover.

#### Refresh interval

The [`refresh_interval`][es:indexing:speed] dictates how often Elasticsearch creates new [segments][stack:segments] and it set to `1s` as default. During the rebuild process the setting is changed to `-1` as recommended by the [documentation][es:indexing:speed]. If for some reason the `refresh_interval` remained at `-1` then changes to an index will not be visible until a refresh has been commanded and to fix the situation it is suggested to run:

- `php rebuildElasticIndex.php --update-settings`
- `php rebuildElasticIndex.php --force-refresh`

## Settings

To help tune and customize various configuration aspects two settings are provided:

- `$smwgElasticsearchEndpoints`
- `$smwgElasticsearchConfig`

### Endpoints

This setting contains a list of available endpoints used by the ES cluster and is __required__ to be set in order to establish a connection with ES.

```
$GLOBALS['smwgElasticsearchEndpoints'] = [
	[ 'host' => '192.168.1.126', 'port' => 9200, 'scheme' => 'http' ],
	'localhost:9200'
];
```
Please consult the [reference material][es:conf:hosts] for details about the correct notation form.

### Configuration

The `$smwgElasticsearchConfig` is a compound setting that covers:

- `index` points to files that contain index and field mappings
- `connection` defines connection details for ES endpoints
- `replication` hold replication details
- `settings` can be used to modify ES specific settings
- `query` contains a list of settings directed towards query optimization and execution in connection with Semantic MediaWiki

```
$GLOBALS['smwgElasticsearchConfig'] = [
	'index'       => ...,
	'connection'  => ...,
	'replication' => ...,
	'settings'    => ...,
	'query'       => ...
];
```

A detailed list of settings and their explanations are available in the `DefaultSettings.php`. Please make sure that after changing any setting, to run `php rebuildElasticIndex.php --update-settings`.

#### Shards and replicas

The default shards and replica configuration is specified with:

- The `data` index has two primary shards and two replicas
- The `lookup` index has one primary shard and no replica with the documentation noting that "... consider using an index with a single shard ... lookup terms filter will prefer to execute the get request on a local node if possible ..."

If it is required to change the numbers of [shards][es:shards] and replicas then use the `$smwgElasticsearchConfig` setting.

```
$GLOBALS['smwgElasticsearchConfig'] = [
	'settings' => [
		'data' => [
			'number_of_shards' => 3,
			'number_of_replicas' => 3
		]
	]
] + $GLOBALS['smwgElasticsearchConfig'];
```

ES comes with a precondition that any change to the `number_of_shards` requires to rebuild the entire index, so changes to that setting should be considered carefully.

Read-heavy wikis might want to add (without the need re-index the data) replica shards at the time ES performance is in decline but those replicas should be put on an extra hardware.

#### Text, languages, and analyzers

The `data` index uses the `smw-data-standard.json` to define settings and mappings that influence how ES analyzes and index documents including fields identified as text and strings. Those text fields use the [standard analyzer][es:standard:analyzer] and should work for most applications.

Yet, for certain languages the `icu` (or any other language specific configuration) might provide better results therefore it possible to assign a different definition file that allows custom settings such as language [analyzer][es:lang:analyzer] to help increase the matching precision.

`smw-data-icu.json` is provided as example on how to alter those settings. Please note that query results on text fields may differ compared to when one would use the standard analyzer and it should be evaluated what settings are the most favorable for a user environment.

For a non-latin language environments it is recommended to add the [analysis-icu plugin][es:icu:tokenizer] and select `smw-data-icu.json` as index definition (see also the [unicode normalization][es:unicode:normalization] guide) to provide better unicode normalization and [case folding][es:unicode:case:folding].

Please note that any change to the index or analyzer settings __requires__ to rebuild the entire index.

## Searching

As noted before, `#ask` queries are system agnostic therefore queries that worked with the `SQLStore` (or `SPARQLStore`) should work equally without having to modify the query syntax.

This is achieved by mapping SMW description objects to an equivalent ES DSL expression. The `ElasticStore` has set its query execution to a `compat.mode` which ensures that searches return the same results as the `SQLStore` even in cases where ES could provide optimized results especially on free text searches. The `compat.mode` does not restrict search results or the freedom that comes with ES, rather it allows us to guarantee (for the query answering process) that results returned from a `QueryEngine` (`SQLStore` and `ElasticStore`) are comparable and testable.

The equality (and non equality) searches rely on [structure searches][es:structured:search] to match documents that operate with a [filter context][es:filter:context].

[Relevancy][es:relevance] sorting is a topic for itself (is only provided by the `ElasticStore`) and to allow results to be sorted by a computed relevancy, the query needs to signal that a different context is required during the execution and will only be taken into account when the `elastic.score` sortkey (see `score.sortfield`) is used on a non-filtered context.

```
{{#ask: [[Has text::~*some text in a document*]]
 |sort=elastic.score
 |order=desc
}}
```

Sorting results by relevancy makes only sense for query constructs that use a non-filtered context (`~/!~`) otherwise scores for matching documents will be the same and not contribute to a meaningful score.

#### Property chains, paths, and subqueries

ES doesn't support [subqueries][es:subqueries] or [joins][es:joins] but in order to execute a path or chain of properties it is necessary to create a set of results that match a path condition (e.g. `Foo.bar.foobar`) with each element holding a restricted list of results from the previous path element to allow for a traversal process.

To match the behaviour of the `SQLStore` in terms of path queries, Semantic MediaWiki has to split the path and execute each part individually to provide a list of elements as input for the next iteration. To avoid issues with a possible large result set, SMW needs to "park" those results and `subquery.terms.lookup.index.write.threshold` (default is 100) defines as to when to make use of the ES [terms lookup][es:terms-lookup] feature by moving results into a separate `lookup` index.

## Monitoring

To make it easier for administrators to monitor the interface between Semantic MediaWiki and Elasticsearch, several service links are provided for a better and quicker access to that information.

The main access point is defined with `Special:SemanticMediaWiki/elastic`.

It should be noted that only users with the `smw-admin` right (which is required to access `Special:SemanticMediaWiki` page) can access the information and only when an ES cluster is available.

### Logging

The enable connector specific logging, please use `smw-elastic` in your LocalSettings.

```
$wgDebugLogGroups  = [
	'smw-elastic' => ".../logs/smw-elastic-{$wgDBname}.log",
];
```

## Technical notes

Classes and objects related to the Elasticsearch binding are placed under the `SMW\Elastic` namespace.

```
SMW\Elastic
┃	┠━ Connection    # Responsible for building a connection to ES
┃	┠━ Indexer       # Contains all necessary classes for updating the ES index
┃	┕━ QueryEngine   # Hosts the query builder and the `#ask` language interpreter classes
┠━ ElasticFactory
┕━ ElasticStore
```

### Overview

![image](https://user-images.githubusercontent.com/1245473/36046618-e32e7a78-0e1c-11e8-90bb-5bee5650789f.png)

### Mappings and serialization

It should remembered that besides specific available types in ES, text fields are generally divided into analyzed and not_analyzed fields.

Semantic MediaWiki is [mapping][es:mapping] its internal structure using [`dynamic_templates`][es:dynamic:templates] to define expected data types, their attributes, and possibly add extra index fields (see [multi-fields][es:multi-fields]) to make use of certain query constructs.

The naming convention follows a very pragmatic naming scheme, `P:<ID>.<type>Field` with each new field (aka property) being mapped dynamically to a corresponding field type.

- `P:<ID>` identifies the property with a number which is the same as the internal ID in the `SQLStore` (`smw_id`)
- `<type>Field` declares a typed field (e.g. `txtField` which is important in case the type changes from `wpg` to `txt` and vice versa) and holds the actual indexable data.
- Dates are indexed using the julian day number (JDN) to allow for historic dates being applicable

The `SemanticData` object is always serialized in full avoiding the interface to keep delta information and hereby allowing the [bulk][es:bulk] update to be faster and more resilient while avoiding a get and fetch approach (we tried this at first but update performance were greatly impacted) for documents during an update process.

To allow for exact as well as full-text searches on the same field (which represents a property value pair), most mapped fields will have at least two or three additional [multi-field][es:multi-fields] elements to store text as `not_analyzed` (or keyword) and as sortable entity.

### `text_all`

The `text_all` mapping (see [copy-to][es:copy-to]) is used to enable wide proximity searches (e.g. `[[~~Foo bar]]` or `[[in:foo bar]]`) which translates into "Find all entities that have `foo bar` in one of its assigned `_uri`, `_txt`, or `_wpg` properties!" with `text_all` being a compound field for all strings that are relevant to run a wide search when a specific property is unknown.

### SQLStore vs. ElasticStore

Why not combine the `SQLStore` and ES search where ES only handles the text search? The need to support ordering of results requires that the ordering happens over the entire set of conditions and it is not possible to split a search between two systems while retaining consistency for the offset (from where result starts and end) pointer.

Why not use ES as a replacement? Because ES is a search engine and not a storage backend therefore the data storage and management remains part of the `SQLStore`. The `SQLStore` is responsible for creating IDs, storing data objects, and provide answers to requests that doesn't involve the `QueryEngine`.

## Glossary

- `Document` is called in ES a content container to holds indexable content and is equivalent to an entity (subject) in Semantic MediaWiki
- `Index` holds all documents within a collection of types and contains inverted indices to search across everything within those documents at once
- `Node` is a running instance of Elasticsearch
- `Cluster` is a group of nodes


## FAQ

> Limit of total fields [3000] in index [...] has been exceeded

If the rebuilder or ES returns with a similar message then the preconfigured limit needs to be changed which is most likely caused by an excessive use of property declarations. The user should question such usage patterns and analyze why so many properties are used and whether or not some can
be merged or properties are in fact misused as fact statements.

The limit is set to prevent [mapping explosion][es:map:explosion] but can be readjusted using the [index.mapping.total_fields.limit][es:mapping] (maximum number of fields in an index) setting.

```
$GLOBALS['smwgElasticsearchConfig']['settings']['data'] = [
	'index.mapping.total_fields.limit' => 6000
];
```

After changing any setting, ensure to run `php rebuildElasticIndex.php --update-settings`.

> Your version of PHP / json-ext does not support the constant 'JSON_PRESERVE_ZERO_FRACTION', which is important for proper type mapping in Elasticsearch. Please upgrade your PHP or json-ext.

[elasticsearch-php#534](https://github.com/elastic/elasticsearch-php/issues/534) has some details about the issue. Please check the [version matrix][es:version:matrix] to see which version is compatible with your PHP environment.

> I use CirrusSearch, can I search SMW (or its data) via CirrusSearch?

No, because first of all SMW doesn't rely on CirrusSearch at all and even if a user has CirrusSearch installed bot extensions have different requirements and different indices and are not designed to share content with each other.

> Can I use `Special:Search` with SMW and CirrusSearch?

Yes, by adding `$wgSearchType = 'SMWSearch';` one can use the `#ask` syntax (e.g. `[[Has date::>1970]]`) and execute structured searchs while any free input gets redirected to CirrusSearch. The input is an either/or not a conjunctive one which means only one of the both can be used at once through the `Special:Search` interface.

## Recommendations

- Analysis ICU ( tokenizer and token filters from the Unicode ICU library), see `bin/elasticsearch-plugin install analysis-icu`
- A [curated list](https://github.com/dzharii/awesome-elasticsearch) of useful resources about elasticsearch including articles, videos, blogs, tips and tricks, use cases
- [Elasticsearch: The Definitive Guide](http://oreilly.com/catalog/errata.csp?isbn=9781449358549) by Clinton Gormley and Zachary Tonge should provide insights in how to run and use Elasticsearch
- [10 Elasticsearch metrics to watch][oreilly:es-metrics-to-watch] describes key metrics to keep Elasticsearch running smoothly

[es:conf]: https://www.elastic.co/guide/en/elasticsearch/reference/6.1/system-config.html
[es:conf:hosts]: https://www.elastic.co/guide/en/elasticsearch/client/php-api/6.0/_configuration.html#_extended_host_configuration
[es:php-api]: https://www.elastic.co/guide/en/elasticsearch/client/php-api/6.0/_installation_2.html
[es:joins]: https://github.com/elastic/elasticsearch/issues/6769
[es:subqueries]: https://discuss.elastic.co/t/question-about-subqueries/20767/2
[es:terms-lookup]: https://www.elastic.co/blog/terms-filter-lookup
[es:dsl]: https://www.elastic.co/guide/en/elasticsearch/reference/6.1/query-dsl.html
[es:mapping]: https://www.elastic.co/guide/en/elasticsearch/reference/6.1/mapping.html
[es:multi-fields]: https://www.elastic.co/guide/en/elasticsearch/reference/current/multi-fields.html
[es:map:explosion]: https://www.elastic.co/blog/found-crash-elasticsearch#mapping-explosion
[es:indexing:speed]: https://www.elastic.co/guide/en/elasticsearch/reference/current/tune-for-indexing-speed.html
[es:create:index]: https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-create-index.html
[es:dynamic:templates]: https://www.elastic.co/guide/en/elasticsearch/reference/6.1/dynamic-templates.html
[es:version:matrix]: https://www.elastic.co/guide/en/elasticsearch/client/php-api/6.0/_installation_2.html#_version_matrix
[es:hardware]: https://www.elastic.co/guide/en/elasticsearch/guide/2.x/hardware.html#_memory
[es:standard:analyzer]: https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-standard-analyzer.html
[es:lang:analyzer]: https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-lang-analyzer.html
[es:icu:tokenizer]: https://www.elastic.co/guide/en/elasticsearch/plugins/6.1/analysis-icu-tokenizer.html
[es:unicode:normalization]: https://www.elastic.co/guide/en/elasticsearch/guide/current/unicode-normalization.html
[es:unicode:case:folding]: https://www.elastic.co/guide/en/elasticsearch/guide/current/case-folding.html
[es:shards]: https://www.elastic.co/guide/en/elasticsearch/reference/current/_basic_concepts.html#getting-started-shards-and-replicas
[es:alias-zero]: https://www.elastic.co/guide/en/elasticsearch/guide/master/index-aliases.html
[es:bulk]: https://www.elastic.co/guide/en/elasticsearch/reference/6.2/docs-bulk.html
[es:structured:search]: https://www.elastic.co/guide/en/elasticsearch/guide/current/structured-search.html
[es:filter:context]: https://www.elastic.co/guide/en/elasticsearch/reference/6.2/query-filter-context.html
[es:relevance]: https://www.elastic.co/guide/en/elasticsearch/guide/master/relevance-intro.html
[es:copy-to]: https://www.elastic.co/guide/en/elasticsearch/reference/master/copy-to.html
[oreilly:es-metrics-to-watch]: https://www.oreilly.com/ideas/10-elasticsearch-metrics-to-watch
[stack:segments]: https://stackoverflow.com/questions/15426441/understanding-segments-in-elasticsearch
