<?php

namespace Algolia\AlgoliaSearch;

use Algolia\AlgoliaSearch\Config\SearchConfig;
use Algolia\AlgoliaSearch\Exceptions\MissingObjectId;
use Algolia\AlgoliaSearch\Response\IndexingObjectsResponse;
use Algolia\AlgoliaSearch\Response\IndexingResponse;
use Algolia\AlgoliaSearch\RequestOptions\RequestOptions;
use Algolia\AlgoliaSearch\Iterators\ObjectIterator;
use Algolia\AlgoliaSearch\Iterators\RuleIterator;
use Algolia\AlgoliaSearch\Iterators\SynonymIterator;
use Algolia\AlgoliaSearch\RetryStrategy\ApiWrapperInterface;
use Algolia\AlgoliaSearch\Support\AbstractIndexContent;
use Algolia\AlgoliaSearch\Support\Helpers;

class SearchIndex
{
    private $indexName;

    /**
     * @var ApiWrapperInterface
     */
    protected $api;

    /**
     * @var SearchConfig
     */
    protected $config;

    public function __construct($indexName, ApiWrapperInterface $apiWrapper, SearchConfig $config)
    {
        $this->indexName = $indexName;
        $this->api = $apiWrapper;
        $this->config = $config;
    }

    public function getIndexName()
    {
        return $this->indexName;
    }

    public function setIndexName($indexName)
    {
        $this->indexName = $indexName;

        return $this;
    }

    public function search($query, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['query'] = $query;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('query', $query);
        }

        return $this->api->read('POST', api_path('/1/indexes/%s/query', $this->indexName), $requestOptions);
    }

    public function searchForFacetValue($facetName, $facetQuery, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['facetQuery'] = $facetQuery;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('facetQuery', $facetQuery);
        }

        return $this->api->read(
            'POST',
            api_path('/1/indexes/%s/facets/%s/query', $this->indexName, $facetName),
            $requestOptions
        );
    }

    public function moveTo($newIndexName, $requestOptions = array())
    {
        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/operation', $this->indexName),
            array(
                'operation' => 'move',
                'destination' => $newIndexName,
            ),
            $requestOptions
        );

        $this->setIndexName($newIndexName);

        return new IndexingResponse($response, $this);
    }

    public function copyTo($destIndexName, $requestOptions = array())
    {
        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/operation', $this->indexName),
            array(
                'operation' => 'copy',
                'destination' => $destIndexName,
            ),
            $requestOptions
        );

        return new IndexingResponse($response, $this);
    }

    public function copyIndexToApp($newAppId, $newApiKey, $requestOptions = array())
    {
        $allResponses = array();

        $newIndex = SearchClient::create($newAppId, $newApiKey)->initIndex($this->indexName);

        $settings = $this->getSettings();
        $allResponses[] = $newIndex->setSettings($settings);

        $objectsIterator = $this->browseObjects();
        $allResponses[] = $newIndex->saveObjects($objectsIterator);

        $synonymsIterator = $this->browseSynonyms();
        $allResponses[] = $newIndex->saveSynonyms($synonymsIterator);

        $rulesIterator = $this->browseRules();
        $allResponses[] = $newIndex->saveRules($rulesIterator);

        return $allResponses;
    }

    public function getSettings($requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['getVersion'] = 2;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addQueryParameter('getVersion', 2);
        }

        return $this->api->read(
            'GET',
            api_path('/1/indexes/%s/settings', $this->indexName),
            $requestOptions
        );
    }

    public function setSettings($settings, $requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        $response = $this->api->write(
            'PUT',
            api_path('/1/indexes/%s/settings', $this->indexName),
            $settings,
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function copySettingsTo($destIndexName, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['scope'] = array('settings');
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('scope', array('settings'));
        }

        return $this->copyTo($destIndexName, $requestOptions);
    }

    public function getObject($objectId, $requestOptions = array())
    {
        return $this->api->read(
            'GET',
            api_path('/1/indexes/%s/%s', $this->indexName, $objectId),
            $requestOptions
        );
    }

    public function getObjects($objectIds, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $attributesToRetrieve = '';
            if (isset($requestOptions['attributesToRetrieve'])) {
                $attributesToRetrieve = $requestOptions['attributesToRetrieve'];
                unset($requestOptions['attributesToRetrieve']);
            }

            $request = array();
            foreach ($objectIds as $id) {
                $req = array(
                    'indexName' => $this->indexName,
                    'objectID' => (string) $id,
                );

                if ($attributesToRetrieve) {
                    $req['attributesToRetrieve'] = $attributesToRetrieve;
                }

                $request[] = $req;
            }

            $requestOptions['requests'] = $request;
        }

        return $this->api->read(
            'POST',
            api_path('/1/indexes/*/objects'),
            $requestOptions
        );
    }

    public function saveObject($object, $requestOptions = array())
    {
        return $this->saveObjects(array($object), $requestOptions);
    }

    public function saveObjects($objects, $requestOptions = array())
    {
        if (isset($requestOptions['autoGenerateObjectIDIfNotExist'])
            && $requestOptions['autoGenerateObjectIDIfNotExist']) {
            unset($requestOptions['autoGenerateObjectIDIfNotExist']);

            return $this->addObjects($objects, $requestOptions);
        }

        if (isset($requestOptions['objectIDKey']) && $requestOptions['objectIDKey']) {
            $objects = Helpers::mapObjectIDs($requestOptions['objectIDKey'], $objects);
            unset($requestOptions['objectIDKey']);
        }

        try {
            return $this->splitIntoBatches('updateObject', $objects, $requestOptions);
        } catch (MissingObjectId $e) {
            $message = "\nAll objects must have an unique objectID (like a primary key) to be valid.\n\n";
            $message .= "If your batch has a unique identifier but isn't called objectID,\n";
            $message .= "you can map it automatically using `saveObjects(\$objects, ['objectIDKey' => 'primary'])`\n\n";
            $message .= "Algolia is also able to generate objectIDs automatically but *it's not recommended*.\n";
            $message .= "To do it, use `saveObjects(\$objects, ['autoGenerateObjectIDIfNotExist' => true])`\n\n";

            throw new MissingObjectId($message);
        }
    }

    protected function addObjects($objects, $requestOptions = array())
    {
        return $this->splitIntoBatches('addObject', $objects, $requestOptions);
    }

    public function partialUpdateObject($object, $requestOptions = array())
    {
        return $this->partialUpdateObjects(array($object), $requestOptions);
    }

    public function partialUpdateObjects($objects, $requestOptions = array())
    {
        $action = 'partialUpdateObjectNoCreate';

        if (isset($requestOptions['createIfNotExists']) && $requestOptions['createIfNotExists']) {
            $action = 'partialUpdateObject';
            unset($requestOptions['createIfNotExists']);
        }

        return $this->splitIntoBatches($action, $objects, $requestOptions);
    }

    public function replaceAllObjects($objects, $requestOptions = array())
    {
        $safe = isset($requestOptions['safe']) && $requestOptions['safe'];
        unset($requestOptions['safe']);

        $tmpName = $this->indexName.'_tmp_'.uniqid('php_', true);
        $tmpIndex = new self($tmpName, $this->api, $this->config);

        // Copy all index resources from production index
        $copyResponse = $this->copyTo($tmpIndex->getIndexName(), array(
            'scope' => array('settings', 'synonyms', 'rules'),
        ));

        if ($safe) {
            $copyResponse->wait();
        }

        // Send records (batched automatically)
        $batchResponse = $tmpIndex->saveObjects($objects, $requestOptions);

        if ($safe) {
            $batchResponse->wait();
        }

        // Move temporary index to production
        $moveResponse = $tmpIndex->moveTo($this->indexName);

        return array($copyResponse, $batchResponse, $moveResponse);
    }

    public function deleteObject($objectId, $requestOptions = array())
    {
        return $this->deleteObjects(array($objectId), $requestOptions);
    }

    public function deleteObjects($objectIds, $requestOptions = array())
    {
        $objects = array_map(function ($id) {
            return array('objectID' => $id);
        }, $objectIds);

        return $this->splitIntoBatches('deleteObject', $objects, $requestOptions);
    }

    public function deleteBy($filters, $requestOptions = array())
    {
        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/deleteByQuery', $this->indexName),
            $filters,
            $requestOptions
        );

        return new IndexingResponse($response, $this);
    }

    public function clearObjects($requestOptions = array())
    {
        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/clear', $this->indexName),
            array(),
            $requestOptions
        );

        return new IndexingResponse($response, $this);
    }

    public function batch($requests, $requestOptions = array())
    {
        $response = $this->rawBatch($requests, $requestOptions);

        return new IndexingResponse($response, $this);
    }

    protected function rawBatch($requests, $requestOptions = array())
    {
        return $this->api->write(
            'POST',
            api_path('/1/indexes/%s/batch', $this->indexName),
            array('requests' => $requests),
            $requestOptions
        );
    }

    protected function splitIntoBatches($action, $objects, $requestOptions = array())
    {
        $allResponses = array();
        $batch = array();
        $batchSize = $this->config->getBatchSize();
        $count = 0;

        foreach ($objects as $object) {
            $batch[] = $object;
            $count++;

            if ($count === $batchSize) {
                if ('addObject' !== $action) {
                    Helpers::ensureObjectID($batch, 'All objects must have an unique objectID (like a primary key) to be valid.');
                }
                $allResponses[] = $this->rawBatch(Helpers::buildBatch($batch, $action), $requestOptions);
                $batch = array();
                $count = 0;
            }
        }

        if ('addObject' !== $action) {
            Helpers::ensureObjectID($batch, 'All objects must have an unique objectID (like a primary key) to be valid.');
        }
        $allResponses[] = $this->rawBatch(Helpers::buildBatch($batch, $action), $requestOptions);

        return new IndexingObjectsResponse($allResponses, $this);
    }

    public function browseObjects($requestOptions = array())
    {
        return new ObjectIterator($this->indexName, $this->api, $requestOptions);
    }

    public function searchSynonyms($query, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['query'] = $query;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('query', $query);
        }

        return $this->api->read(
            'POST',
            api_path('/1/indexes/%s/synonyms/search', $this->indexName),
            $requestOptions
        );
    }

    public function getSynonym($objectId, $requestOptions = array())
    {
        return $this->api->read(
            'GET',
            api_path('/1/indexes/%s/synonyms/%s', $this->indexName, $objectId),
            $requestOptions
        );
    }

    public function saveSynonym($synonym, $requestOptions = array())
    {
        return $this->saveSynonyms(array($synonym), $requestOptions);
    }

    public function saveSynonyms($synonyms, $requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        if ($synonyms instanceof \Iterator) {
            $iteratedOver = array();
            foreach ($synonyms as $r) {
                $iteratedOver[] = $r;
            }
            $synonyms = $iteratedOver;
        }

        Helpers::ensureObjectID($synonyms, 'All synonyms must have an unique objectID to be valid');

        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/synonyms/batch', $this->indexName),
            $synonyms,
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function copySynonymsTo($destIndexName, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['scope'] = array('synonyms');
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('scope', array('synonyms'));
        }

        return $this->copyTo($destIndexName, $requestOptions);
    }

    public function replaceAllSynonyms($synonyms, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['replaceExistingSynonyms'] = true;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addQueryParameter('replaceExistingSynonyms', true);
        }

        return $this->saveSynonyms($synonyms, $requestOptions);
    }

    public function deleteSynonym($objectId, $requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        $response = $this->api->write(
            'DELETE',
            api_path('/1/indexes/%s/synonyms/%s', $this->indexName, $objectId),
            array(),
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function clearSynonyms($requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/synonyms/clear', $this->indexName),
            array(),
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function browseSynonyms($requestOptions = array())
    {
        return new SynonymIterator($this->indexName, $this->api, $requestOptions);
    }

    public function searchRules($query, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['query'] = $query;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('query', $query);
        }

        return $this->api->read(
            'POST',
            api_path('/1/indexes/%s/rules/search', $this->indexName),
            $requestOptions
        );
    }

    public function getRule($objectId, $requestOptions = array())
    {
        return $this->api->read(
            'GET',
            api_path('/1/indexes/%s/rules/%s', $this->indexName, $objectId),
            $requestOptions
        );
    }

    public function saveRule($rule, $requestOptions = array())
    {
        return $this->saveRules(array($rule), $requestOptions);
    }

    public function saveRules($rules, $requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        if ($rules instanceof \Iterator) {
            $iteratedOver = array();
            foreach ($rules as $r) {
                $iteratedOver[] = $r;
            }
            $rules = $iteratedOver;
        }

        Helpers::ensureObjectID($rules, 'All rules must have an unique objectID to be valid');

        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/rules/batch', $this->indexName),
            $rules,
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function copyRulesTo($destIndexName, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['scope'] = array('rules');
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('scope', array('rules'));
        }

        return $this->copyTo($destIndexName, $requestOptions);
    }

    public function replaceAllRules($rules, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['clearExistingRules'] = true;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addQueryParameter('clearExistingRules', true);
        }

        return $this->saveRules($rules, $requestOptions);
    }

    public function deleteRule($objectId, $requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        $response = $this->api->write(
            'DELETE',
            api_path('/1/indexes/%s/rules/%s', $this->indexName, $objectId),
            array(),
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function clearRules($requestOptions = array())
    {
        $default = array();
        if (is_bool($fwd = $this->config->getDefaultForwardToReplicas())) {
            $default['forwardToReplicas'] = $fwd;
        }

        $response = $this->api->write(
            'POST',
            api_path('/1/indexes/%s/rules/clear', $this->indexName),
            array(),
            $requestOptions,
            $default
        );

        return new IndexingResponse($response, $this);
    }

    public function browseRules($requestOptions = array())
    {
        return new RuleIterator($this->indexName, $this->api, $requestOptions);
    }

    public function getTask($taskId, $requestOptions = array())
    {
        if (!$taskId) {
            throw new \InvalidArgumentException('taskID cannot be empty');
        }

        return $this->api->read(
            'GET',
            api_path('/1/indexes/%s/task/%s', $this->indexName, $taskId),
            $requestOptions
        );
    }

    public function waitTask($taskId, $requestOptions = array())
    {
        $retry = 1;
        $time = $this->config->getWaitTaskTimeBeforeRetry();

        do {
            $res = $this->getTask($taskId, $requestOptions);

            if ('published' === $res['status']) {
                return;
            }

            $retry++;
            $factor = ceil($retry / 10);
            usleep($factor * $time); // 0.1 second
        } while (true);
    }

    public function custom($method, $path, $requestOptions = array(), $hosts = null)
    {
        return $this->api->send($method, $path, $requestOptions, $hosts);
    }

    public function reindex(AbstractIndexContent $indexContent, $requestOptions = array())
    {
        $safe = isset($requestOptions['safe']) && $requestOptions['safe'];
        unset($requestOptions['safe']);
        $allResponses = array();
        $replicas = false;

        $tmpName = $this->indexName.'_tmp_'.uniqid('php_', true);
        $tmpIndex = new self($tmpName, $this->api, $this->config);

        if (false === $settings = $indexContent->getSettings()) {
            $scope[] = 'settings';
        }
        if (false === $synonyms = $indexContent->getSynonyms()) {
            $scope[] = 'synonyms';
        }
        if (false === $rules = $indexContent->getRules()) {
            $scope[] = 'rules';
        }

        if (!empty($scope)) {
            $allResponses[] = $this->copyTo($tmpName, $scope);
        }

        if ($settings) {
            if (isset($settings['replicas'])) {
                $replicas = $settings['replicas'];
                unset($settings['replicas']);
            }
            $allResponses[] = $tmpIndex->setSettings($settings);
        }

        if ($synonyms) {
            $allResponses[] = $tmpIndex->saveSynonyms($synonyms);
        }

        if ($rules) {
            $allResponses[] = $tmpIndex->saveRules($rules);
        }

        $allResponses[] = $tmpIndex->saveObjects($indexContent->getObjects());

        if ($safe) {
            foreach ($allResponses as $response) {
                $response->wait();
            }
        }

        $moveResponse = $tmpIndex->moveTo($this->indexName);

        if ($safe) {
            $moveResponse->wait();
        }
        $allResponses[] = $moveResponse;

        if ($replicas) {
            $allResponses[] = $this->setSettings(array('replicas' => false));
        }

        return $allResponses;
    }

    public function delete($requestOptions = array())
    {
        $response = $this->api->write(
            'DELETE',
            api_path('/1/indexes/%s', $this->indexName),
            array(),
            $requestOptions
        );

        return new IndexingResponse($response, $this);
    }
}