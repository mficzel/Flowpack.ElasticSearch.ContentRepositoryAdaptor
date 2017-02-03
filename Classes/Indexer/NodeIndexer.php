<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Mapping\NodeTypeMappingBuilder;
use Flowpack\ElasticSearch\Domain\Model\Document as ElasticSearchDocument;
use Flowpack\ElasticSearch\Domain\Model\Index;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\ContentRepository\Search\Indexer\BulkNodeIndexerInterface;

/**
 * Indexer for Content Repository Nodes. Triggered from the NodeIndexingManager.
 *
 * Internally, uses a bulk request.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer implements BulkNodeIndexerInterface
{
    /**
     * Optional postfix for the index, e.g. to have different indexes by timestamp.
     *
     * @var string
     */
    protected $indexNamePostfix = '';

    /**
     * @Flow\Inject
     * @var ElasticSearchClient
     */
    protected $searchClient;

    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilder
     */
    protected $nodeTypeMappingBuilder;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contextFactory;

    /**
     * The current ElasticSearch bulk request, in the format required by http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-bulk.html
     *
     * @var array
     */
    protected $currentBulkRequest = [];

    /**
     * @var boolean
     */
    protected $enableBulkProcessing = false;

    /**
     * Returns the index name to be used for indexing, with optional indexNamePostfix appended.
     *
     * @return string
     */
    public function getIndexName()
    {
        $indexName = $this->searchClient->getIndexName();
        if (strlen($this->indexNamePostfix) > 0) {
            $indexName .= '-' . $this->indexNamePostfix;
        }

        return $indexName;
    }

    /**
     * Set the postfix for the index name
     *
     * @param string $indexNamePostfix
     * @return void
     */
    public function setIndexNamePostfix($indexNamePostfix)
    {
        $this->indexNamePostfix = $indexNamePostfix;
    }

    /**
     * Return the currently active index to be used for indexing
     *
     * @return Index
     */
    public function getIndex()
    {
        $index = $this->searchClient->findIndex($this->getIndexName());
        $index->setSettingsKey($this->searchClient->getIndexName());

        return $index;
    }

    /**
     * index this node, and add it to the current bulk request.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspaceName In case this is triggered during publishing, a workspace name will be passed in
     * @return void
     * @throws \Neos\ContentRepository\Search\Exception\IndexingException
     */
    public function indexNode(NodeInterface $node, $targetWorkspaceName = null)
    {
        $indexer = function (NodeInterface $node, $targetWorkspaceName = null) {
            $contextPath = $node->getContextPath();

            if ($node->getNodeType()->getName() === 'unstructured') {
                return;
            }

            if ($this->settings['indexAllWorkspaces'] === false) {
                // we are only supposed to index the live workspace.
                // We need to check the workspace at two occasions; checking the
                // $targetWorkspaceName and the workspace name of the node's context as fallback
                if ($targetWorkspaceName !== null && $targetWorkspaceName !== 'live') {
                    return;
                }

                if ($targetWorkspaceName === null && $node->getContext()->getWorkspaceName() !== 'live') {
                    return;
                }
            }

            if ($targetWorkspaceName !== null) {
                $contextPath = str_replace($node->getContext()->getWorkspace()->getName(), $targetWorkspaceName, $contextPath);
            }

            $contextPathHash = sha1($contextPath);
            $nodeType = $node->getNodeType();

            $mappingType = $this->getIndex()->findType(NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($nodeType));

            if ($this->enableBulkProcessing === false) {
                $this->removeDuplicateDocuments($contextPath, $contextPathHash, $node);
            }

            if ($node->isRemoved()) {
                $mappingType->deleteDocumentById($contextPathHash);
                $this->updateFulltext($node, [], $targetWorkspaceName);
                $this->logger->log(sprintf('NodeIndexer: Removed node %s from index (node flagged as removed). ID: %s', $contextPath, $contextPathHash), LOG_DEBUG, null, 'ElasticSearch (CR)');

                return;
            }

            $fulltextIndexOfNode = [];
            $nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextIndexOfNode, function ($propertyName) use ($contextPathHash) {
                $this->logger->log(sprintf('NodeIndexer (%s) - Property "%s" not indexed because no configuration found.', $contextPathHash, $propertyName), LOG_DEBUG, null, 'ElasticSearch (CR)');
            });

            $document = new ElasticSearchDocument($mappingType,
                $nodePropertiesToBeStoredInIndex,
                $contextPathHash
            );

            $documentData = $document->getData();
            if ($targetWorkspaceName !== null) {
                $documentData['__workspace'] = $targetWorkspaceName;
            }

            $dimensionCombinations = $node->getContext()->getDimensions();
            if (is_array($dimensionCombinations)) {
                $documentData['__dimensionCombinations'] = $dimensionCombinations;
                $documentData['__dimensionCombinationHash'] = md5(json_encode($dimensionCombinations));
            }

            $this->appendToBulkRequest($node, $document, $documentData, $fulltextIndexOfNode, $targetWorkspaceName);

            $this->logger->log(sprintf('NodeIndexer: Added / updated node %s. ID: %s Context: %s', $contextPath, $contextPathHash, json_encode($node->getContext()->getProperties())), LOG_DEBUG, null, 'ElasticSearch (CR)');
        };

        $dimensionCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        $workspaceName = $targetWorkspaceName ?: 'live';
        $nodeIdentifier = $node->getIdentifier();

        if ($node->isRemoved()) {
            $indexer($node, $targetWorkspaceName);
        }

        if ($dimensionCombinations !== []) {
            foreach ($dimensionCombinations as $combination) {
                $context = $this->contextFactory->create([
                    'workspaceName' => $workspaceName,
                    'dimensions' => $combination
                ]);
                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node !== null) {
                    $indexer($node, $targetWorkspaceName);
                }
            }
        } else {
            $context = $this->contextFactory->create(['workspaceName' => $workspaceName]);
            $node = $context->getNodeByIdentifier($nodeIdentifier);
            if ($node !== null) {
                $indexer($node, $targetWorkspaceName);
            }
        }
    }

    /**
     * @param NodeInterface $node
     * @param ElasticSearchDocument $document
     * @param array $documentData
     * @param string $fulltextIndexOfNode
     * @param string $targetWorkspaceName
     */
    protected function appendToBulkRequest(NodeInterface $node, ElasticSearchDocument $document, array $documentData, $fulltextIndexOfNode, $targetWorkspaceName)
    {
        if ($this->isFulltextEnabled($node)) {
            if ($this->isFulltextRoot($node)) {
                // for fulltext root documents, we need to preserve the "__fulltext" field. That's why we use the
                // "update" API instead of the "index" API, with a custom script internally; as we
                // shall not delete the "__fulltext" part of the document if it has any.
                $this->currentBulkRequest[] = [
                    [
                        'update' => [
                            '_type' => $document->getType()->getName(),
                            '_id' => $document->getId(),
                            '_index' => $this->getIndexName(),
                            '_retry_on_conflict' => 3
                        ]
                    ],
                    // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-update.html
                    [
                        'script' => [
                            'inline' => '
                                    fulltext = (ctx._source.containsKey("__fulltext") ? ctx._source.__fulltext : new HashMap());
                                    fulltextParts = (ctx._source.containsKey("__fulltextParts") ? ctx._source.__fulltextParts : new HashMap());
                                    ctx._source = newData;
                                    ctx._source.__fulltext = fulltext;
                                    ctx._source.__fulltextParts = fulltextParts
                                ',
                            'params' => [
                                'newData' => $documentData
                            ]
                        ],
                        'upsert' => $documentData,
                        'lang' => 'groovy'
                    ]
                ];
            } else {
                // non-fulltext-root documents can be indexed as-they-are
                $this->currentBulkRequest[] = [
                    [
                        'index' => [
                            '_type' => $document->getType()->getName(),
                            '_id' => $document->getId()
                        ]
                    ],
                    $documentData
                ];
            }

            $this->updateFulltext($node, $fulltextIndexOfNode, $targetWorkspaceName);
        }
    }

    /**
     * Remove document with the same contextPathHash but different NodeType, required after NodeType change
     *
     * @param string $contextPath
     * @param string $contextPathHash
     * @param NodeInterface $node
     */
    protected function removeDuplicateDocuments($contextPath, $contextPathHash, NodeInterface $node)
    {
        $type = NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($node->getNodeType()->getName());
        $this->logger->log(sprintf('NodeIndexer: Check duplicate nodes for %s (%s). ContentContextHash: %s', $contextPath, $type, $contextPathHash), LOG_DEBUG, null, 'ElasticSearch (CR)');
        $result = $this->getIndex()->request('GET', '/_search?scroll=1m', [], json_encode([
            'sort' => ['_doc'],
            'query' => [
                'bool' => [
                    'must' => [
                        'ids' => [
                            'values' => [$contextPathHash]
                        ]
                    ],
                    'must_not' => [
                        'term' => [
                            '_type' => $type
                        ]
                    ]
                ]
            ]
        ]));
        $treatedContent = $result->getTreatedContent();
        $scrollId = $treatedContent['_scroll_id'];


        $mapHitToDeleteRequest = function ($hit) {
            return json_encode([
                'delete' => [
                    '_type' => $hit['_type'],
                    '_id' => $hit['_id']
                ]
            ]);
        };

        $bulkRequest = [];
        while (isset($treatedContent['hits']['hits']) && $treatedContent['hits']['hits'] !== []) {
            $hits = $treatedContent['hits']['hits'];
            $bulkRequest = array_merge($bulkRequest, array_map($mapHitToDeleteRequest, $hits));
            $result = $this->getIndex()->request('GET', '/_search/scroll?scroll=1m', [], $scrollId, false);
            $treatedContent = $result->getTreatedContent();
        }

        $this->logger->log(sprintf('NodeIndexer: Check duplicate nodes for %s (%s), found %d document(s). ContentContextHash: %s', $contextPath, $type, count($bulkRequest), $contextPathHash), LOG_DEBUG, null, 'ElasticSearch (CR)');
        if ($bulkRequest !== []) {
            $this->getIndex()->request('POST', '/_bulk', [], implode("\n", $bulkRequest) . "\n");
        }
        $this->searchClient->request('DELETE', '/_search/scroll', [], json_encode([
            'scroll_id' => [
                $scrollId
            ]
        ]));
    }

    /**
     *
     *
     * @param NodeInterface $node
     * @param array $fulltextIndexOfNode
     * @param string $targetWorkspaceName
     * @return void
     */
    protected function updateFulltext(NodeInterface $node, array $fulltextIndexOfNode, $targetWorkspaceName = null)
    {
        if (($targetWorkspaceName !== null && $targetWorkspaceName !== 'live') || ($node->getWorkspace()->getName() !== 'live' && !$node->isRemoved())) {
            return;
        }


        $closestFulltextNode = $node;
        while (!$this->isFulltextRoot($closestFulltextNode)) {
            $closestFulltextNode = $closestFulltextNode->getParent();
            if ($closestFulltextNode === null) {
                // root of hierarchy, no fulltext root found anymore, abort silently...
                $this->logger->log('No fulltext root found for ' . $node->getPath(), LOG_WARNING);

                return;
            }
        }

        $closestFulltextNodeContextPath = str_replace($closestFulltextNode->getContext()->getWorkspace()->getName(), 'live', $closestFulltextNode->getContextPath());
        $closestFulltextNodeContextPathHash = sha1($closestFulltextNodeContextPath);

        $upsertFulltextParts = [];
        if (!empty($fulltextIndexOfNode)) {
            $upsertFulltextParts[$node->getIdentifier()] = $fulltextIndexOfNode;
        }

        $this->currentBulkRequest[] = [
            [
                'update' => [
                    '_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($closestFulltextNode->getNodeType()->getName()),
                    '_id' => $closestFulltextNodeContextPathHash
                ]
            ],
            // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/docs-update.html
            [
                // first, update the __fulltextParts, then re-generate the __fulltext from all __fulltextParts
                'script' => [
                    'inline' => '
                        ctx._source.__fulltext = new HashMap();
                        if (!ctx._source.containsKey("__fulltextParts")) {
                            ctx._source.__fulltextParts = new HashMap();
                        }
                        if (fulltext.size() == 0) {
                            ctx._source.__fulltextParts.remove(identifier);
                        } else {
                            ctx._source.__fulltextParts.put(identifier, fulltext);
                        }

                        ctx._source.__fulltextParts.each { originNodeIdentifier, partContent -> partContent.each { bucketKey, content ->
                                if (ctx._source.__fulltext.containsKey(bucketKey)) {
                                    value = ctx._source.__fulltext[bucketKey] + " " + content.trim();
                                } else {
                                    value = content.trim();
                                }
                                ctx._source.__fulltext[bucketKey] = value;
                            }
                        }
                    ',
                    'params' => [
                        'identifier' => $node->getIdentifier(),
                        'fulltext' => $fulltextIndexOfNode
                    ]
                ],
                'upsert' => [
                    '__fulltext' => $fulltextIndexOfNode,
                    '__fulltextParts' => $upsertFulltextParts
                ],
                'lang' => 'groovy'
            ]
        ];
    }

    /**
     * Whether the node is configured as fulltext root.
     *
     * @param NodeInterface $node
     * @return boolean
     */
    protected function isFulltextRoot(NodeInterface $node)
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $elasticSearchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($elasticSearchSettingsForNode['fulltext']['isRoot']) && $elasticSearchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule node removal into the current bulk request.
     *
     * @param NodeInterface $node
     * @return void
     */
    public function removeNode(NodeInterface $node)
    {
        if ($this->settings['indexAllWorkspaces'] === false) {
            if ($node->getContext()->getWorkspaceName() !== 'live') {
                return;
            }
        }

        // TODO: handle deletion from the fulltext index as well
        $identifier = sha1($node->getContextPath());

        $this->currentBulkRequest[] = [
            [
                'delete' => [
                    '_type' => NodeTypeMappingBuilder::convertNodeTypeNameToMappingName($node->getNodeType()),
                    '_id' => $identifier
                ]
            ]
        ];

        $this->logger->log(sprintf('NodeIndexer: Removed node %s from index (node actually removed). Persistence ID: %s', $node->getContextPath(), $identifier), LOG_DEBUG, null, 'ElasticSearch (CR)');
    }

    /**
     * perform the current bulk request
     *
     * @return void
     */
    public function flush()
    {
        if (count($this->currentBulkRequest) === 0) {
            return;
        }

        $content = '';
        foreach ($this->currentBulkRequest as $bulkRequestTuple) {
            $tupleAsJson = '';
            foreach ($bulkRequestTuple as $bulkRequestItem) {
                $itemAsJson = json_encode($bulkRequestItem);
                if ($itemAsJson === false) {
                    $this->logger->log('Indexing Error: Bulk request item could not be encoded as JSON - ' . json_last_error_msg(), LOG_ERR, $bulkRequestItem);
                    continue 2;
                }
                $tupleAsJson .= $itemAsJson . chr(10);
            }
            $content .= $tupleAsJson;
        }

        if ($content !== '') {
            $responseAsLines = $this->getIndex()->request('POST', '/_bulk', [], $content)->getOriginalResponse()->getContent();
            foreach (explode("\n", $responseAsLines) as $responseLine) {
                $response = json_decode($responseLine);
                if (!is_object($response) || (isset($response->errors) && $response->errors !== false)) {
                    $this->logger->log('Indexing Error: ' . $responseLine, LOG_ERR);
                }
            }
        }

        $this->currentBulkRequest = [];
    }

    /**
     * Update the index alias
     *
     * @return void
     * @throws Exception
     * @throws \Flowpack\ElasticSearch\Transfer\Exception\ApiException
     * @throws \Exception
     */
    public function updateIndexAlias()
    {
        $aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name
        if ($this->getIndexName() === $aliasName) {
            throw new Exception('UpdateIndexAlias is only allowed to be called when $this->setIndexNamePostfix has been created.', 1383649061);
        }

        if (!$this->getIndex()->exists()) {
            throw new Exception('The target index for updateIndexAlias does not exist. This shall never happen.', 1383649125);
        }

        $aliasActions = [];
        try {
            $response = $this->searchClient->request('GET', '/_alias/' . $aliasName);
            if ($response->getStatusCode() !== 200) {
                throw new Exception('The alias "' . $aliasName . '" was not found with some unexpected error... (return code: ' . $response->getStatusCode() . ')', 1383650137);
            }

            $indexNames = array_keys($response->getTreatedContent());

            if ($indexNames === []) {
                // if there is an actual index with the name we want to use as alias, remove it now
                $response = $this->searchClient->request('HEAD', '/' . $aliasName);
                if ($response->getStatusCode() === 200) {
                    $response = $this->searchClient->request('DELETE', '/' . $aliasName);
                    if ($response->getStatusCode() !== 200) {
                        throw new Exception('The index "' . $aliasName . '" could not be removed to be replaced by an alias. (return code: ' . $response->getStatusCode() . ')', 1395419177);
                    }
                }
            } else {
                foreach ($indexNames as $indexName) {
                    $aliasActions[] = [
                        'remove' => [
                            'index' => $indexName,
                            'alias' => $aliasName
                        ]
                    ];
                }
            }
        } catch (\Flowpack\ElasticSearch\Transfer\Exception\ApiException $exception) {
            // in case of 404, do not throw an error...
            if ($exception->getResponse()->getStatusCode() !== 404) {
                throw $exception;
            }
        }

        $aliasActions[] = [
            'add' => [
                'index' => $this->getIndexName(),
                'alias' => $aliasName
            ]
        ];

        $this->searchClient->request('POST', '/_aliases', [], \json_encode(['actions' => $aliasActions]));
    }

    /**
     * Remove old indices which are not active anymore (remember, each bulk index creates a new index from scratch,
     * making the "old" index a stale one).
     *
     * @return array<string> a list of index names which were removed
     */
    public function removeOldIndices()
    {
        $aliasName = $this->searchClient->getIndexName(); // The alias name is the unprefixed index name

        $currentlyLiveIndices = array_keys($this->searchClient->request('GET', '/_alias/' . $aliasName)->getTreatedContent());

        $indexMappings = $this->searchClient->request('GET', '/_all')->getTreatedContent();
        $allIndices = array_keys($indexMappings);

        $indicesToBeRemoved = array_filter($allIndices, function ($indexName) use ($aliasName, $currentlyLiveIndices) {
            return strpos($indexName, $aliasName . '-') === 0 && array_search($indexName, $currentlyLiveIndices) === false;
        });

        if (count($indicesToBeRemoved) > 0) {
            $this->searchClient->request('DELETE', '/' . implode(',', $indicesToBeRemoved));
        }

        return $indicesToBeRemoved;
    }

    /**
     * Perform indexing without checking about duplication document
     *
     * This is used during bulk indexing to improve performance
     *
     * @param callable $callback
     * @throws \Exception
     */
    public function withBulkProcessing(callable $callback)
    {
        $bulkProcessing = $this->enableBulkProcessing;
        $this->enableBulkProcessing = true;
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $callback->__invoke();
        } catch (\Exception $exception) {
            $this->enableBulkProcessing = $bulkProcessing;
            throw $exception;
        }
        $this->enableBulkProcessing = $bulkProcessing;
    }
}
