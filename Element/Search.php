<?php

namespace Mapbender\SearchBundle\Element;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Eslider\Driver\HKVStorage;
use FOM\CoreBundle\Component\ExportResponse;
use Mapbender\DataSourceBundle\Component\DataStore;
use Mapbender\DataSourceBundle\Component\DataStoreService;
use Mapbender\DataSourceBundle\Component\FeatureType;
use Mapbender\DataSourceBundle\Component\FeatureTypeService;
use Mapbender\DataSourceBundle\Element\BaseElement;
use Mapbender\DataSourceBundle\Entity\Feature;
use Mapbender\DigitizerBundle\Component\Uploader;
use Mapbender\SearchBundle\Component\QueryManager;
use Mapbender\SearchBundle\Component\StyleManager;
use Mapbender\SearchBundle\Component\StyleMapManager;
use Mapbender\SearchBundle\Entity\QuerySchema;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Zumba\Util\JsonSerializer;

/**
 * Class Search

 * @package Mapbender\SearchBundle\Element
 * @author  Andriy Oblivantsev <eslider@gmail.com>
 */
class Search extends BaseElement
{
    /** @var string Element title */
    protected static $title = 'Search';

    /** @var string Element description */
    protected static $description = 'Object search element';

    /** @var FeatureType Current feature type */
    protected $featureType;

    /** @inheritdoc */
    static public function listAssets()
    {
        return array(
            'js'    =>
                array(
                    '../../vendor/blueimp/jquery-file-upload/js/jquery.fileupload.js',
                    '../../vendor/blueimp/jquery-file-upload/js/jquery.iframe-transport.js',
                    '/components/jquery-context-menu/jquery-context-menu-built.js',
                    '/components/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js',
                    'OpenLayerHelper.js',
                    'feature-style-editor.js',
                    'style-map-manager.js',
                    'query-result-title-bar-view.js',
                    'query-result-view.js',
                    'query-manager.js',
                    'mapbender.element.search.js'
                ),
            'css'   => array('sass/element/search.scss'),
            'trans' => array('MapbenderSearchBundle:Element:search.json.twig'));
    }

    /** @inheritdoc */
    public static function getDefaultConfiguration()
    {
        return array(
            'target'       => null,
            'featureTypes' => array(),
            'debug'        => false,
            'title'        => 'Search',
            'css'          => array(),
            'jsSrc'        => array(),
            'schemes'      => array()
        );
    }

    /**
     * Get the element configuration form type.
     *
     * Override this method to provide a custom configuration form instead of
     * the default YAML form.
     *
     * @return string Administration type class name
     */
    public static function getType()
    {
        return null;
    }

    /**
     * @return null
     */
    public static function getFormTemplate()
    {
        return null;
    }

    /**
     * Prepare form items for each scheme definition
     * Optional: get featureType by name from global context.
     *
     * @inheritdoc
     * @throws InvalidArgumentException
     */
    public function getConfiguration()
    {
        $configuration            = parent::getConfiguration();
        $configuration['debug']   = isset($configuration['debug']) ? $configuration['debug'] : false;
        $configuration['fileUri'] = $this->container->getParameter('mapbender.uploads_dir') . "/" . FeatureType::UPLOAD_DIR_NAME;

        if (isset($configuration['schemes']) && is_array($configuration['schemes'])) {
            foreach ($configuration['schemes'] as $key => &$scheme) {
                if (is_string($scheme['featureType'])) {
                    $featureTypes          = $this->container->getParameter('featureTypes');
                    $scheme['featureType'] = $featureTypes[ $scheme['featureType'] ];
                }
                if (isset($scheme['formItems'])) {
                    $scheme['formItems'] = $this->prepareItems($scheme['formItems']);
                }
            }
        }
        return $configuration;
    }

    /**
     * Prepare request feautre data by the form definition
     *
     * @param $feature
     * @param $formItems
     * @return array
     */
    protected function prepareQueriedFeatureData($feature, $formItems)
    {
        foreach ($formItems as $key => $formItem) {
            if (isset($formItem['children'])) {
                $feature = array_merge($feature, $this->prepareQueriedFeatureData($feature, $formItem['children']));
            } elseif (isset($formItem['type']) && isset($formItem['name'])) {
                switch ($formItem['type']) {
                    case 'select':
                        if (isset($formItem['multiple'])) {
                            $separator = isset($formItem['separator']) ? $formItem['separator'] : ',';
                            if (is_array($feature["properties"][ $formItem['name'] ])) {
                                $feature["properties"][ $formItem['name'] ] = implode($separator, $feature["properties"][ $formItem['name'] ]);
                            }
                        }
                        break;
                }
            }
        }
        return $feature;
    }

    /**
     * Remove given fields
     *
     * @param $data
     * @param $fields
     * @return mixed
     */
    protected function filterFields($data, $fields)
    {
        foreach ($fields as $deniedFieldName) {
            if (isset($data[ $deniedFieldName ])) {
                unset($data[ $deniedFieldName ]);
            }
        }
        return $data;
    }

    /**
     * @inheritdoc
     */
    public function httpAction($action)
    {
        $words = array_filter(explode('/', $action));
        if (!$words) {
            throw new BadRequestHttpException("Invalid action " . var_export($action, true));
        }
        /** @todo: defeat redundant json encode in client, then we can do this ourselves */
        $request = $this->getRequestData();
        switch (strtolower($words[0])) {
            case 'style':
                $saveDataKey = 'style';
                $saveFieldFilter = array('userId', 'styleMaps', 'pointerEvents');
                $repository = $this->getStyleManager();
                break;
            case 'stylemap':
                $saveDataKey = 'styleMap';
                $saveFieldFilter = array('userId');
                $repository = $this->getStyleMapManager();
                break;
            case 'query':
            case 'queries':
                $saveDataKey = 'query';
                $saveFieldFilter = array('userId','where');
                $repository = $this->getQueryManager();
                break;
            default:
                if (isset($request['schema'])) {
                    $this->setSchema($request);
                }
                return parent::httpAction($action);
        }
        if ($repository) switch (strtolower($words[1])) {
            case 'list':
                return $this->zumbaResponse(array(
                    'list' => array_reverse($repository->getAll(), true)
                ));
            case 'remove':
                return new JsonResponse(array(
                    'result' => $repository->remove($request['id']),
                ));
            case 'save':
                $filtered = $this->filterFields($request[$saveDataKey], $saveFieldFilter);
                $entity = $repository->create($filtered);
                $entity->setUserId($this->getUserId());
                $repository->save($entity);
                // @todo: fix this inconsistency
                $responseDataKey = ($saveDataKey == 'query') ? 'entity' : $saveDataKey;
                return $this->zumbaResponse(array(
                    $responseDataKey => $entity,
                ));
            default:
                break;
        }
        throw new BadRequestHttpException("Invalid action " . var_export($action, true));
    }

    private function zumbaResponse($data)
    {
        $serializer = new JsonSerializer();
        $responseBody = $serializer->serialize($data);
        return new Response($responseBody, 200, array('Content-Type' => 'application/json'));
    }

    /**
     * Set feature type
     *
     * @param $featureType
     */
    private function setFeatureType(FeatureType $featureType)
    {
        $this->featureType = $featureType;
    }

    /**
     * Set schema (FeatureType)
     *
     * @param $request
     */
    protected function setSchema($request)
    {
        $configuration = $this->getConfiguration();
        $schemas       = $configuration['schemes'];
        $schema        = $schemas[ $request['schema'] ];
        if (is_array($schema['featureType'])) {
            $this->setFeatureType(new FeatureType($this->container, $schema['featureType']));
        } else {
            throw new Exception('FeatureType settings not correct');
        }
    }

    /**
     * Get feature type
     *
     * @return FeatureType
     */
    public function getFeatureType()
    {
        return $this->featureType;
    }

    /**
     * Export results
     *
     * @param $request
     * @return mixed
     */
    public function exportAction($request)
    {
        $ids             = isset($request['ids']) && is_array($request['ids']) ? $request['ids'] : array();
        $queryManager    = $this->getQueryManager(true);
        $query           = $queryManager->getById($request['queryId']);
        $schema          = $this->getSchemaById($query->getSchemaId());
        $featureTypeName = $schema->getFeatureType();
        $featureType     = $this->getFeatureTypeService()->get($featureTypeName);
        $config          = $featureType->getConfiguration('export');
        $connection      = $featureType->getConnection();
        $maxResults      = isset($config["maxResults"]) ? $config["maxResults"] : 10000; // TODO: Set max results in export
        $fileName        = $query->getName() . " " . date('Y:m:d H:i:s');

        if (!count($ids)) {
            $fields  = $featureType->getFields();
            $sql     = $queryManager->buildSql($query, false, $fields);
            $results = $connection->fetchAll($sql . " LIMIT " . $maxResults);
            $rows    = $featureType->export($results);
        } else {
            $rows = $featureType->getByIds($ids, false);
            $rows = $featureType->export($rows);
        }

        return new ExportResponse($rows, $fileName, $request["type"]);
    }

    /**
     * Export results
     *
     * @param $request
     * @return mixed
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function listSchemasAction($request)
    {
        $result                  = array();
        $featureTypeManager      = $this->getFeatureTypeService();
        $featureTypeDeclarations = $featureTypeManager->getFeatureTypeDeclarations();
        $schemas                 = $this->getSchemas();

        foreach ($schemas as $schemaId => $schema) {
            $featureTypeName = $schema->getFeatureType();
            $declaration     = $featureTypeDeclarations[ $featureTypeName ];
            $title           = isset($declaration['title']) ? $declaration['title'] : ucfirst($featureTypeName);
            $featureType     = $featureTypeManager->get($featureTypeName);
            $print           = $featureType->getConfiguration('print');
            $fields          = $schema->getFields();

            foreach ($fields as &$fieldDescription) {
                if (isset($fieldDescription["sql"])) {
                    /** @var Connection $dbalConnection */
                    $connectionName = isset($fieldDescription["connection"]) ? $fieldDescription["connection"] : "default";
                    $dbalConnection = $this->container->get("doctrine.dbal.{$connectionName}_connection");
                    $options        = array();

                    foreach ($dbalConnection->fetchAll($fieldDescription["sql"]) as $row) {
                        $options[ current($row) ] = current($row);
                    }

                    $fieldDescription["options"] = $options;
                    unset($fieldDescription["connection"]);
                    unset($fieldDescription["sql"]);
                }
            }

            $result[ $schemaId ] = array(
                'title'       => $title,
                'fields'      => $fields,
                'print'       => $print,
                'featureType' => $featureTypeName
            );
        }

        ksort($result);

        return array(
            'list' => $result // array_reverse($result, true)
        );
    }

    /**
     * Removes a Style Entity ID from StyleMap Entity
     *
     * @param $request
     * @return array
     * @throws \Symfony\Component\Config\Definition\Exception\Exception
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function removeStyleFromStyleMapAction($request)
    {
        $styleManager = $this->getStyleMapManager();
        $styleMapId   = $request['styleMapId'];
        $styleId      = $request['styleId'];
        return array(
            'result' => $styleManager->removeStyle($styleMapId, $styleId)
        );
    }


    /**
     * Add a Style Entity ID to StyleMap Entity
     *
     * @param $request
     * @return mixed
     */
    public function addStyleToStylemapAction($request)
    {
        $styleMapManager = $this->getStyleMapManager();
        $styleMapId      = isset($request["stylemapid"]) ? $request["stylemapid"] : "UNDEFINED";
        $styleId         = isset($request["styleid"]) ? $request["styleid"] : "UNDEFINED";
        $style           = $styleMapManager->addStyle($styleMapId, $styleId);

        return new JsonResponse(array(
            'stylemap' => HKVStorage::encodeValue($style)
        ));
    }

    /**
     * Select features
     *
     * @param $request
     * @return \Mapbender\DataSourceBundle\Entity\Feature[]
     */
    public function selectFeaturesAction($request)
    {
        return $this->getFeatureType()->search(
            array_merge(
                array(
                    'returnType' => 'FeatureCollection',
                    'maxResults' => 2500
                ),
                $request));
    }

    /**
     * List queries
     *
     * @param $request
     * @return \Mapbender\DataSourceBundle\Entity\Feature[]
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function checkQueryAction($request)
    {
        $queryManager = $this->getQueryManager(true);
        $query        = $queryManager->create($request['query']);
        $check        = null;

        try {
            $check = $queryManager->check($query, $request['intersectGeometry'], $request['srid']);
        } catch (DBALException $e) {
            $message = $e->getMessage();
            if (strpos($message, 'ERROR:')) {
                preg_match("/\\s+ERROR:\\s+(.+)/", $message, $found);
                $message = ucfirst($found[1]) . ".";
            }
            $check = array(
                'errorMessage' => $message,
            );
        }

        return $check;
    }


    /**
     * List queries
     *
     * @param $request
     * @return \Mapbender\DataSourceBundle\Entity\Feature[]
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function fetchQueryAction($request)
    {
        $queryManager  = $this->getQueryManager(true);
        $query = $queryManager->getById($request['query']['id']);
        $configuration = $this->getConfiguration();

        $queryManager->setSchemas($configuration["schemas"]);

        $schema = $queryManager->getSchemaById($query->getSchemaId());

        try {
            $maxResults            = $schema->getMaxResults();
            $request['maxResults'] = $maxResults;
            $results               = $queryManager->fetchQuery($query, $request);
            $count                 = count($results["features"]);


            if ($count == $maxResults) {
                $results["infoMessage"] = "Mehr als $maxResults Treffer gefunden, $maxResults Treffer angezeigt. \nGgf. an Kollegen mit FLIMAS-Desktop wenden.";
            }

            return $results;
        } catch (DBALException $e) {
            $message = $e->getMessage();
            if (strpos($message, 'ERROR:')) {
                preg_match("/\\s+ERROR:\\s+(.+)/", $message, $found);
                $message = ucfirst($found[1]) . ".";
            }
            $check = array(
                'errorMessage' => $message,
            );
        }

        return $check;
    }

    /**
     * Get data store
     *
     * @param $request
     * @return JsonResponse
     */
    public function getDatastoreAction($request)
    {
        $results = array();
        // TODO: get request ID and check
        if (!isset($request['id']) || !isset($request['dataItemId'])) {
            $results = array(
                array('errors' => array(
                    array('message' => "datastore/get: id or dataItemId not defined!")
                ))
            );
        }

        $id           = $request['id'];
        $dataItemId   = $request['dataItemId'];
        $dataStore    = $this->getDataStoreById($id);
        $dataItem     = $dataStore->get($dataItemId);
        $dataItemData = null;
        if ($dataItem) {
            $dataItemData = $dataItem->toArray();
            $results      = $dataItemData;
        }
        return new JsonResponse($results);
    }

    /**
     * Save data store
     *
     * @param $request
     * @return JsonResponse
     */
    public function saveDatastoreAction($request)
    {
        $id          = $request['id'];
        $dataItem    = $request['dataItem'];
        $dataStore   = $this->getDataStoreById($id);
        $uniqueIdKey = $dataStore->getDriver()->getUniqueId();
        if (empty($request['dataItem'][ $uniqueIdKey ])) {
            unset($request['dataItem'][ $uniqueIdKey ]);
        }
        return new JsonResponse($dataStore->save($dataItem));
    }

    /**
     * Remove data store
     *
     * @param $request
     * @return JsonResponse
     */
    public function removeDatastoreAction($request)
    {
        $id                = $request['id'];
        $dataStore         = $this->getDataStoreById($id);
        $uniqueIdKey       = $dataStore->getDriver()->getUniqueId();
        $dataItemId        = $request['dataItem'][ $uniqueIdKey ];
        $results["result"] = $dataStore->remove($dataItemId);
        return new JsonResponse(array(
            'id'         => $id,
            'removed'    => $dataStore->remove($dataItemId),
            'dataItemId' => $dataItemId
        ));
    }

    /**
     * Delete feature
     *
     * @param $request
     * @return bool|mixed|null
     */
    public function deleteAction($request)
    {
        return $this->getFeatureType()->remove($request['feature']);
    }

    /**
     * Upload file action
     *
     * @param $request
     * @return bool|mixed|null
     */
    public function uploadFileAction($request)
    {
        $requestService             = $this->container->get('request');
        $schemaName                 = isset($request['schema']) ? $request['schema'] : $requestService->get('schema');
        $featureType                = $this->getFeatureType();
        $fieldName                  = $requestService->get('field');
        $urlParameters              = array('schema' => $schemaName,
                                            'fid'    => $requestService->get('fid'),
                                            'field'  => $fieldName);
        $serverUrl                  = preg_replace('/\\?.+$/', "", $_SERVER["REQUEST_URI"]) . "?" . http_build_query($urlParameters);
        $uploadDir                  = $featureType->getFilePath($fieldName);
        $uploadUrl                  = $featureType->getFileUrl($fieldName) . "/";
        $urlParameters['uploadUrl'] = $uploadUrl;
        $uploadHandler              = new Uploader(array(
            'upload_dir'                   => $uploadDir . "/",
            'script_url'                   => $serverUrl,
            'upload_url'                   => $uploadUrl,
            'accept_file_types'            => '/\.(gif|jpe?g|png)$/i',
            'print_response'               => false,
            'access_control_allow_methods' => array(
                'OPTIONS',
                'HEAD',
                'GET',
                'POST',
                'PUT',
                'PATCH',
                //'DELETE'
            ),
        ));
        return new JsonResponse(array_merge($uploadHandler->get_response(), $urlParameters));
    }

    /**
     * Save feature
     *
     * @param $request
     * @return JsonResponse
     */
    public function saveAction($request)
    {
        $results             = array();
        $configuration       = $this->getConfiguration();
        $featureType         = $this->getFeatureType();
        $schemas             = $configuration['schemes'];
        $debugMode           = $configuration['debug'] || $this->container->get('kernel')->getEnvironment() == 'dev';
        $schemaName          = $request['schema'];
        $schemaConfiguration = null;

        if (!empty($schemaName)) {
            //throw new Exception('For initialization there is no name of the declared scheme');
            $schemaConfiguration = $schemas[ $schemaName ];
            if (is_array($schemaConfiguration['featureType'])) {
                $featureType = new FeatureType($this->container, $schemaConfiguration['featureType']);
                $this->setFeatureType($featureType);
            } else {
                throw new Exception('FeatureType settings not correct');
            }
        }

        // save once
        if (isset($request['feature'])) {
            $request['features'] = array($request['feature']);
        }

        $connection = $featureType->getDriver()->getConnection();

        try {
            // save collection
            if (isset($request['features']) && is_array($request['features'])) {
                foreach ($request['features'] as $feature) {
                    /**
                     * @var $feature Feature
                     */
                    $featureData = $this->prepareQueriedFeatureData($feature, $schemaConfiguration['formItems']);

                    foreach ($featureType->getFileInfo() as $fileConfig) {
                        if (!isset($fileConfig['field']) || !isset($featureData["properties"][ $fileConfig['field'] ])) {
                            continue;
                        }
                        $url                                               = $featureType->getFileUrl($fileConfig['field']);
                        $requestUrl                                        = $featureData["properties"][ $fileConfig['field'] ];
                        $newUrl                                            = str_replace($url . "/", "", $requestUrl);
                        $featureData["properties"][ $fileConfig['field'] ] = $newUrl;
                    }

                    $feature = $featureType->save($featureData);
                    $results = array_merge($featureType->search(array(
                        'srid'  => $feature->getSrid(),
                        'where' => $connection->quoteIdentifier($featureType->getUniqueId()) . '=' . $connection->quote($feature->getId()))));
                }
            }
            $results = $featureType->toFeatureCollection($results);
        } catch (DBALException $e) {
            $message = $debugMode ? $e->getMessage() : "Feature can't be saved. Maybe something is wrong configured or your database isn't available?\n" .
                "For more information have a look at the webserver log file. \n Error code: " . $e->getCode();
            $results = array('errors' => array(
                array('message' => $message, 'code' => $e->getCode())
            ));
        }

        return new JsonResponse($results);
    }

    /**
     * Get element schemas
     *
     * @return QuerySchema[]
     */
    protected function getSchemas()
    {
        $configuration = $this->getConfiguration();
        $schemas       = array();
        foreach ($configuration["schemas"] as $schemaDefinition) {
            $schemas[] = new QuerySchema($schemaDefinition);
        }
        return $schemas;
    }

    /**
     * Get element schema by ID
     *
     * @param $id
     * @return QuerySchema
     */
    protected function getSchemaById($id)
    {
        $schemas = $this->getSchemas();
        return $schemas[ $id ];
    }

    /**
     * @param bool $initSchemas
     * @return QueryManager
     */
    protected function getQueryManager($initSchemas = true)
    {
        /** @var QueryManager $queryManager */
        $queryManager = $this->container->get('mapbender.search.query.manager');
        if ($initSchemas) {
            /**
             * @todo: Don't mutate services after container initialization.
             *        This is a (backwards compatible) violation of expected service usage.
             *        If we need schemas, we should supply them in the calls to the service, not set them first with
             *        global side effects.
             */
            $queryManager->setSchemas($this->getSchemas());
        }
        return $queryManager;
    }

    /**
     * @return FeatureTypeService
     */
    protected function getFeatureTypeService()
    {
        $serviceId = $this->container->getParameter('mapbender.search.featuretype.service.id');
        /** @var FeatureTypeService $service */
        $service = $this->container->get($serviceId);
        return $service;
    }

    /**
     * @return StyleManager
     */
    protected function getStyleManager()
    {
        /** @var StyleManager $service */
        $service = $this->container->get('mapbender.search.style.manager');
        return $service;
    }

    /**
     * @return StyleMapManager
     */
    protected function getStyleMapManager()
    {
        /** @var StyleMapManager $service */
        $service = $this->container->get('mapbender.search.stylemap.manager');
        return $service;
    }

    /**
     * @param string $id
     * @return DataStore
     */
    protected function getDataStoreById($id)
    {
        /** @var DataStoreService $dataStoreService */
        $dataStoreService = $this->container->get('data.source');
        return $dataStoreService->get($id);
    }
}
