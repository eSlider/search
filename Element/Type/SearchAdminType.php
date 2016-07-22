<?php

namespace Mapbender\SearchBundle\Element\Type;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Mapbender\CoreBundle\Entity\Application;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 *
 */
class SearchAdminType extends AbstractType
{

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'search';
    }

    /**
     * @inheritdoc
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'application' => null,
            'element'     => null,
        ));
    }

    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /** @var Application $application */
        /** @var DataManagerAdminType $element */
        /** @var FormBuilder $builder */
        /** @var \AppKernel $kernel */
        /** @var Registry $doctrine */
        global $kernel;
        $container             = $kernel->getContainer();
        $dataStores            = $container->hasParameter("dataStores") ? array_keys($container->getParameter("dataStores")) : array();
        $dataStoreSelectValues = array_combine(array_values($dataStores), array_values($dataStores));

        $builder
            ->add('source', 'choice', array(
                    'choices'     => $dataStoreSelectValues,
                    'required'    => true,
                    'empty_value' => null
                )
            )
            ->add('sqlFieldName', 'text', array('required' => true))
            ->add('orderByFieldName', 'text', array('required' => true))
            ->add('titleFieldName', 'text', array('required' => true))
            ->add('connectionFieldName', 'text', array('required' => true))

            ->add('allowPrint', 'checkbox', array('required' => false))
            ->add('allowExport', 'checkbox', array('required' => false))
    }
}