<?php
namespace Mapbender\SearchBundle\Controller;

use Eslider\Driver\HKVStorage;
use Eslider\Entity\HKV;
use FOM\UserBundle\Entity\User;
use Mapbender\CoreBundle\Component\SecurityContext;
use Mapbender\SearchBundle\Component\QueryManager;
use Mapbender\SearchBundle\Entity\Query;
use Mapbender\SearchBundle\Utils\HTTPStatusConstants;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Class QueryController
 *
 * @package Mapbender\SearchBundle\Element
 * @author  Mohamed Tahrioui <mohamed.tahrioui@wheregroup.com>
 * @Route("/query/")
 */
class QueryController extends MapbenderController
{

    /** @var SecurityContext */
    protected $securityContext;

    /** @var QueryManager */
    protected $queryManager;

    /** @var User */
    protected $user;

    /**
     *This method has desired services injected.
     *
     * @return string[]
     */
    protected function mappings()
    {
        return array("queryManager"    => static::MAPBENDER_QUERY_MANAGER,
                     "securityContext" => static::MAPBENDER_SECURITY_CONTEXT
        );
    }


    /**
     * @param       $message
     * @param int   $status
     * @param array $headers
     * @return Response
     */
    private function getErrorMessage($message, $status = HTTPStatusConstants::_BAD_REQUEST, $headers = array())
    {
        return new Response($message, $status, $headers);
    }

    /**
     * @param int   $id
     * @param int   $status
     * @param array $headers
     * @return Response
     */
    private function getEmptyMessage($id, $status = HTTPStatusConstants::_NOT_FOUND, $headers = array())
    {

        return new Response("Could not alter/find query for id " . $id, $status, $headers);
    }


    /**
     * @Route("{id}/get")
     * @param int $int
     * @Method("GET")
     * @return Response
     */
    public function get($id)
    {
        if ($this->securityContext->isUserAllowedToView($this->user)) {
            $styleMap = $this->queryManager->getById($id);
            if ($styleMap != null) {
                return $this->getSuccessMessage($styleMap);
            } else {
                return $this->getEmptyMessage($id);
            }
        }
        return $this->getErrorMessage("Get: Current user is not authorized to access style w ith id " . $id, HTTPStatusConstants::_UNAUTHORIZED);

    }

    /**
     * @Route("list")
     * @Method("GET")
     * @return Response
     */
    public function listStyles()
    {
        if ($this->securityContext->isUserAllowedToView($this->user)) {
            $queries = $this->queryManager->listQueries();
            if ($queries != null) {
                return $this->getSuccessMessage($queries);
            } else {
                return $this->getEmptyMessage("all");
            }
        }
        return $this->getErrorMessage("Get: Current user is not authorized to list styles", HTTPStatusConstants::_UNAUTHORIZED);

    }

    /**
     * Updates or creates new StyleMap
     * @Route("save")
     * @Method("POST")
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request)
    {

        if ($this->securityContext->isUserAllowedToEdit($this->user)) {
            $styles = $request->request->all();

            $styleMap = $this->queryManager->saveArray($styles);
            return $this->getSuccessMessage($styleMap);
        }
        return $this->getErrorMessage("Update : Current user is not authorized to access style with id " . $request->get("id"), HTTPStatusConstants::_UNAUTHORIZED);

    }


    /**
     * @param Query|HKV[]|HKV $query
     * @param int             $status
     * @param array           $headers
     * @return Response
     */
    private function getSuccessMessage($query, $status = HTTPStatusConstants::_OK, $headers = array())
    {
        return new Response(HKVStorage::encodeValue($query), $status, $headers);

    }


}