<?php
/*
 * This file is part of the OpCart software.
 *
 * (c) 2016, OpticsPlanet, Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Incenteev\DynamicParametersBundle\Tests;

use Incenteev\DynamicParametersBundle\IncenteevDynamicParametersBundle;
use Symfony\Component\DependencyInjection\Compiler\ResolveParameterPlaceHoldersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DependencyInjectionContainerIntegrationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @param array $dynamicParameters
     * @param string $expectedUrl
     * @return void
     * @dataProvider examplesOfNestedDynamicParameters
     */
    public function testNestedDynamicParameters($dynamicParameters, $expectedUrl)
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('fancy_url', '%base_url%/some-fancy-url.html');
        $container->setParameter('base_url', '%scheme%://%host%');
        $container->setParameter('scheme', 'http');
        $container->setParameter('host', '%subdomain%.example.org');
        $container->setParameter('subdomain', 'test');

        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $dynamicParametersConfig = array();
        foreach ($dynamicParameters as $name => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($name);
            $dynamicParametersConfig[$name] = array('variable' => $envVariableName, 'yaml' => false);
        }
        $container->setParameter('incenteev_dynamic_parameters.parameters', $dynamicParametersConfig);

        $container->register('srv.foo', 'stdClass')
            ->setProperty('url', '%fancy_url%')
            ->setProperty('urlConcatenatedWithText', 'Visit %fancy_url%!');

        $container->compile();

        // note that env vars are set after container compilation
        foreach ($dynamicParameters as $name => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($name);
            putenv($envVariableName . '=' . $value);
        }

        $service = $container->get('srv.foo');

        $this->assertEquals($expectedUrl, $service->url);
        $this->assertEquals(sprintf('Visit %s!', $expectedUrl), $service->urlConcatenatedWithText);
    }

    public function examplesOfNestedDynamicParameters()
    {
        return array(
            'no dynamic parameters' => array(
                array(), 'http://test.example.org/some-fancy-url.html'
            ),
            '%scheme% is a dynamic parameter' => array(
                array('scheme' => 'dynamic-scheme'), 'dynamic-scheme://test.example.org/some-fancy-url.html'
            ),
            '%host% is a dynamic parameter' => array(
                array('host' => 'dynamic-host.org'), 'http://dynamic-host.org/some-fancy-url.html'
            ),
            '%subdomain% is a dynamic parameter' => array(
                array('subdomain' => 'dynamic-subdomain'), 'http://dynamic-subdomain.example.org/some-fancy-url.html'
            ),
            '%base_url% is a dynamic parameter' => array(
                array('base_url' => 'dynamic-base-url'), 'dynamic-base-url/some-fancy-url.html'
            ),
            '%fancy_url% is a dynamic parameter' => array(
                array('fancy_url' => 'dynamic-fancy-url'), 'dynamic-fancy-url'
            ),
            '%subdomain% and %sceme% are dynamic parameters' => array(
                array('subdomain' => 'dynamic-subdomain', 'scheme' => 'dynamic-scheme'),
                'dynamic-scheme://dynamic-subdomain.example.org/some-fancy-url.html'
            ),
        );
    }

    public function testNotReplacesParameterDefinitionWhenNestedParametersAreNotDynamic()
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('fancy_url', '%base_url%/some-fancy-url.html');
        $container->setParameter('base_url', '%scheme%://%host%');
        $container->setParameter('scheme', 'http');
        $container->setParameter('host', '%subdomain%.example.org');
        $container->setParameter('subdomain', 'test');

        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        // define dummy dynamic parameters in order to give ParameterReplacementPass a reason for hooking into container compilation
        $container->setParameter(
            'incenteev_dynamic_parameters.parameters',
            array('some_parameter' => array('variable' => 'SOME_ENV_VAR', 'yaml' => false))
        );

        $container->register('srv.foo', 'stdClass')
            ->setProperty('url', '%fancy_url%')
            ->setProperty('urlConcatenatedWithText', 'Visit %fancy_url%!');


        // remove ResolveParameterPlaceHoldersPass in order to have an access to the original definition after compilation
        $optimizationPasses = $container->getCompiler()->getPassConfig()->getOptimizationPasses();
        foreach ($optimizationPasses as $index => $pass) {
            if ($pass instanceof ResolveParameterPlaceHoldersPass) {
                unset($optimizationPasses[$index]);
            }
        }
        $container->getCompiler()->getPassConfig()->setOptimizationPasses($optimizationPasses);

        $container->compile();

        $def = $container->getDefinition('srv.foo');
        $props = $def->getProperties();

        $this->assertEquals('%fancy_url%', $props['url']);
        $this->assertEquals('Visit %fancy_url%!', $props['urlConcatenatedWithText']);
    }

    public function testNoRegressionInStaticArrayParameterInjection()
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('array_parameter', array('element1', 'element2'));
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        // define dummy dynamic parameters in order to give ParameterReplacementPass a reason for hooking into container compilation
        $container->setParameter(
            'incenteev_dynamic_parameters.parameters',
            array('some_parameter' => array('variable' => 'SOME_ENV_VAR', 'yaml' => false))
        );

        $container->register('srv.foo', 'stdClass')
            ->setProperty('array_property', '%array_parameter%');

        $container->compile();

        $service = $container->get('srv.foo');

        $this->assertEquals(array('element1', 'element2'), $service->array_property);
    }
}
