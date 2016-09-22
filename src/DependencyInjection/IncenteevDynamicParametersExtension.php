<?php

namespace Incenteev\DynamicParametersBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class IncenteevDynamicParametersExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container)
    {
        $parameterBag = $container->getParameterBag();
        $exceptions = array();

        /** @var \Symfony\Component\DependencyInjection\Definition $definition */
        foreach ($container->getDefinitions() as $id => $definition) {
            $toResolve = array(
                $definition->getClass(),
                $definition->getFile(),
            );
            $toResolve = array_merge(
                $toResolve,
                $definition->getArguments(),
                $definition->getProperties()
            );
            foreach ($definition->getMethodCalls() as $arguments) {
                $toResolve = array_merge($toResolve, $arguments);
            }
            foreach ($toResolve as $param) {
                try {
                    $parameterBag->resolveValue($param);
                } catch (ParameterNotFoundException $e) {
                    $exceptions[] = $e;
                }
            }
        }

        foreach ($parameterBag->all() as $param) {
            try {
                $parameterBag->resolveValue($param);
            } catch (ParameterNotFoundException $e) {
                $exceptions[] = $e;
            }
        }

        $newDynamicParams = array();
        foreach ($exceptions as $e) {
            $param = $e->getKey();

            if (0 === strpos($param, 'env(')) {
                // if it's our dyamic param - replace with temporary placeholder
                $container->setParameter($param, '${' . $param . '}');
                $newDynamicParams[] = $param;
            } else {
                // if it's not our dynamic param - do nothing, maybe there are extensions which are doing something similar

            }
        }

        foreach ($newDynamicParams as $newDynamicParam) {
            $envVarName = strtoupper(substr($newDynamicParam, 4, -1));
            $container->prependExtensionConfig($this->getAlias(), array('parameters' => array($newDynamicParam => $envVarName)));
        }
    }

    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $parameters = $config['parameters'];

        if ($config['import_parameter_handler_map']) {
            $composerFile = $container->getParameterBag()->resolveValue($config['composer_file']);

            $container->addResource(new FileResource($composerFile));

            $parameters = array_replace($this->loadHandlerEnvMap($composerFile), $parameters);
        }

        $container->setParameter('incenteev_dynamic_parameters.parameters', $parameters);
    }

    private function loadHandlerEnvMap($composerFile)
    {
        $settings = json_decode(file_get_contents($composerFile), true);

        if (empty($settings['extra']['incenteev-parameters'])) {
            return array();
        }

        $handlerConfigs = $settings['extra']['incenteev-parameters'];

        // Normalize to the multiple-file syntax
        if (array_keys($handlerConfigs) !== range(0, count($handlerConfigs) - 1)) {
            $handlerConfigs = array($handlerConfigs);
        }

        $parameters = array();
        foreach ($handlerConfigs as $config) {
            if (!empty($config['env-map'])) {
                $envMap = array_map(function ($var) {
                    return array('variable' => $var, 'yaml' => true);
                }, $config['env-map']);

                $parameters = array_replace($parameters, $envMap);
            }
        }

        return $parameters;
    }
}
