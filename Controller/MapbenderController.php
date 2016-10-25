<?php

namespace Mapbender\SearchBundle\Controller;

use Mapbender\ConfiguratorBundle\Controller\BaseController;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MapbenderController
 *
 * @package Mapbender\SearchBundle\Controller
 * @author  Mohamed Tahrioui <mohamed.tahrioui@wheregroup.com>
 */
class MapbenderController extends BaseController implements ContainerAwareInterface
{

    const MAPBENDER_FEATURE_SERVICE  = "features";
    const MAPBENDER_QUERY_MANAGER    = "mapbender.query.manager";
    const MAPBENDER_STYLE_MANAGER    = "mapbender.style.manager";
    const MAPBENDER_FEATURE_MANAGER  = "mapbender.featuretype.manager";
    const MAPBENDER_SECURITY_CONTEXT = "security.context";


    /**@var boolean */
    protected $containerSet = false;


    /**
     * Override this method to have desired services injected.
     *
     * @return string[]
     */
    protected function mappings()
    {
        return array();
    }

    /**
     * MapbenderController constructor.
     *
     * @param ContainerInterface|null $container
     */
    public function __construct(ContainerInterface $container = null)
    {
        if ($container) {
            $this->setContainer($container);
        }
    }

    /**
     * Sets the container.
     *
     * @param ContainerInterface|null $container A ContainerInterface instance or null
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container    = $container;
        $this->containerSet = true;
        if ($this->containerSet) {
            foreach ($this->mappings() as $key => $mapping) {
                $this->{$key} = $this->container->get($mapping);
            }

        }
    }
}