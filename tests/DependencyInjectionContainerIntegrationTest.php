<?php
namespace Incenteev\DynamicParametersBundle\Tests;

use Incenteev\DynamicParametersBundle\IncenteevDynamicParametersBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\Compiler\ResolveParameterPlaceHoldersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DependencyInjectionContainerIntegrationTest extends \PHPUnit_Framework_TestCase
{
    private $setEnvVars = array();

    protected function tearDown()
    {
        parent::tearDown();

        foreach ($this->setEnvVars as $envVarName) {
            putenv($envVarName);
        }
        $this->setEnvVars = array();
    }

    /**
     * @param string $valueDefinition e.g. "%foo% - %bar%"
     * @param string $expectedResolvedValue e.g. "foo-value - bar-value"
     * @dataProvider examplesOfConcatenatedDynamicParameters
     */
    public function testReplaceConcatenatedParameters($valueDefinition, $expectedResolvedValue)
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('foo', '%env(SYMFONY_FOO)%');
        $container->setParameter('bar', '[bar-static]');// braces make concatenated parameters more readable, see assertions

        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->register('srv.foo', 'stdClass')
            ->setProperty('test', $valueDefinition);

        $container->compile();

        // note that env var is set after container compilation
        $this->setEnvVar('SYMFONY_FOO', '[foo-dynamic]');

        $service = $container->get('srv.foo');

        $this->assertEquals($expectedResolvedValue, $service->test);
    }

    public function examplesOfConcatenatedDynamicParameters()
    {
        return array(
            'dynamic parameter concatenated with static parameter' => array(
                '%foo%%bar%', '[foo-dynamic][bar-static]'
            ),
            'dynamic parameter concatenated with text' => array(
                '%foo%_some-text', '[foo-dynamic]_some-text'
            ),
            'text concatenated with dynamic parameter' => array(
                'some-text_%foo%', 'some-text_[foo-dynamic]'
            ),
            'text concatenated with multiple parameters and texts and there is text at the end' => array(
                'some-text_%foo%_other-text_%bar%_text-at-the-end', 'some-text_[foo-dynamic]_other-text_[bar-static]_text-at-the-end'
            ),
            'text concatenated with multiple parameters and texts and there is parameter at the end' => array(
                'some-text_%foo%_other-text_%bar%', 'some-text_[foo-dynamic]_other-text_[bar-static]'
            ),
            'text with escaped percent concatenated with dynamic parameter' => array(
                'Save %foo%%%!%bar%', 'Save [foo-dynamic]%![bar-static]'
            ),
        );
    }

    public function testNotReplaceConcatenatedNonDynamicParameters()
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('foo', 'foo-static-value');
        $container->setParameter('bar', 'bar-static-value');
        $container->setParameter('baz', '%env(SYMFONY_BAZ)%');

        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->register('srv.foo', 'stdClass')
            ->setProperty('test', '%foo%%bar%');

        $container->compile();

        $def = $container->getDefinition('srv.foo');
        $props = $def->getProperties();

        $this->assertNotInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $props['test']);
    }

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
            $container->setParameter($name, '%env(' . $envVariableName . ')%');
        }

        $container->register('srv.foo', 'stdClass')
            ->setProperty('url', '%fancy_url%')
            ->setProperty('urlConcatenatedWithText', 'Visit %fancy_url%!');

        $container->compile();

        // note that env vars are set after container compilation
        foreach ($dynamicParameters as $name => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($name);
            $this->setEnvVar($envVariableName, $value);
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
        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');

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
        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');

        $container->register('srv.foo', 'stdClass')
            ->setProperty('array_property', '%array_parameter%');

        $container->compile();

        $service = $container->get('srv.foo');

        $this->assertEquals(array('element1', 'element2'), $service->array_property);
    }

    public function testDynamicParameterCanBeUsedInArrayParameter()
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('array_parameter', array('%env(SOME_ENV_VAR)%'));
        $container->setParameter('nested_array_parameter', '%array_parameter%');
        $container->setParameter('deep_array_parameter', array('foo' => array('bar' => array('baz' => '%env(SOME_ENV_VAR)%'))));
        $container->setParameter('deep_nested_array_parameter', '%deep_array_parameter%');
        $container->setParameter('array_parameter_with_deep_nested_array_parameter', array('arr' => '%deep_nested_array_parameter%'));
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->register('srv.foo', 'stdClass')
            ->setProperty('array_property', array('%env(SOME_ENV_VAR)%'))
            ->setProperty('array_parameter_property', '%array_parameter%')
            ->setProperty('nested_array_parameter_property', '%nested_array_parameter%')
            ->setProperty('deep_array_property', array('foo' => array('bar' => array('baz' => '%env(SOME_ENV_VAR)%'))))
            ->setProperty('deep_array_parameter_property', '%deep_array_parameter%')
            ->setProperty('deep_nested_array_parameter_property', '%deep_nested_array_parameter%')
            ->setProperty('too_long_to_name', '%array_parameter_with_deep_nested_array_parameter%');

        $container->compile();

        $this->setEnvVar('SOME_ENV_VAR', 'dynamic-value');

        $service = $container->get('srv.foo');

        $this->assertEquals(array('dynamic-value'), $service->array_property);
        $this->assertEquals(array('dynamic-value'), $service->array_parameter_property);
        $this->assertEquals(array('dynamic-value'), $service->nested_array_parameter_property);
        $this->assertEquals(array('foo' => array('bar' => array('baz' => 'dynamic-value'))), $service->deep_array_property);
        $this->assertEquals(array('foo' => array('bar' => array('baz' => 'dynamic-value'))), $service->deep_array_parameter_property);
        $this->assertEquals(array('foo' => array('bar' => array('baz' => 'dynamic-value'))), $service->deep_nested_array_parameter_property);
        $this->assertEquals(array('arr' => array('foo' => array('bar' => array('baz' => 'dynamic-value')))), $service->too_long_to_name);
    }

    public function testDynamicParameterCanBeUsedInContainerExtensionConfig()
    {
        $fakeExtension = new FakeExtension();
        $fakeExtension->configTreeBuilder = new TreeBuilder();
        $fakeExtension->configTreeBuilder->root('fake_extension')
            ->children()
                ->scalarNode('some_configurable_option')
                ->end()
            ->end();
        $fakeExtension->loadCallback = function ($mergedConfig, ContainerBuilder $container) {
            $container->register('fake_extension.some_service', 'stdClass')
                ->setProperty('someProperty', $mergedConfig['some_configurable_option']);

            $container->setParameter('parameter_defined_in_extension', $mergedConfig['some_configurable_option']);
            $container->register('fake_extension.some_other_service', 'stdClass')
                ->setProperty('someProperty', '%parameter_defined_in_extension%');
        };

        $container = new ContainerBuilder();
        $container->registerExtension($fakeExtension);
        $container->prependExtensionConfig('fake_extension', array('some_configurable_option' => '%some_parameter%'));

        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->setEnvVar('SOME_ENV_VAR', 'dynamic_value');

        $this->assertSame('dynamic_value', $container->get('fake_extension.some_service')->someProperty);
        $this->assertSame('dynamic_value', $container->get('fake_extension.some_other_service')->someProperty);
    }

    public function testDefaultValueOfDynamicParameterCanBeUsedInContainerExtensionConfig()
    {
        $fakeExtension = new FakeExtension();
        $fakeExtension->configTreeBuilder = new TreeBuilder();
        $fakeExtension->configTreeBuilder->root('fake_extension')
            ->children()
                ->scalarNode('some_configurable_option')
                ->end()
            ->end();
        $fakeExtension->loadCallback = function ($mergedConfig, ContainerBuilder $container) {
            $container->register('fake_extension.some_service', 'stdClass')
                ->setProperty('someProperty', $mergedConfig['some_configurable_option']);
        };

        $container = new ContainerBuilder();
        $container->registerExtension($fakeExtension);
        $container->prependExtensionConfig('fake_extension', array('some_configurable_option' => '%some_parameter%'));

        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');
        $container->setParameter('default_env(SOME_ENV_VAR)', 'default_static_value');

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->assertSame('default_static_value', $container->get('fake_extension.some_service')->someProperty);
    }

    /**
     * @param string $valueDefinition e.g. "%foo% - %bar%"
     * @param string $expectedResolvedValue e.g. "foo-value - bar-value"
     * @dataProvider examplesOfConcatenatedDynamicParameters
     */
    public function testConcatenatedDynamicParameterCanBeUsedInContainerExtensionConfig($valueDefinition, $expectedResolvedValue)
    {
        $fakeExtension = new FakeExtension();
        $fakeExtension->configTreeBuilder = new TreeBuilder();
        $fakeExtension->configTreeBuilder->root('fake_extension')
            ->children()
                ->scalarNode('some_configurable_option')
                ->end()
            ->end();
        $fakeExtension->loadCallback = function ($mergedConfig, ContainerBuilder $container) {
            $container->register('fake_extension.some_service', 'stdClass')
                ->setProperty('someProperty', $mergedConfig['some_configurable_option']);
        };

        $container = new ContainerBuilder();
        $container->registerExtension($fakeExtension);
        $container->prependExtensionConfig('fake_extension', array('some_configurable_option' => $valueDefinition));

        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');
        $container->setParameter('foo', '%env(SOME_ENV_VAR)%');
        $container->setParameter('bar', '[bar-static]');// braces make concatenated parameters more readable, see assertions

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->setEnvVar('SOME_ENV_VAR', '[foo-dynamic]');

        $this->assertSame($expectedResolvedValue, $container->get('fake_extension.some_service')->someProperty);
    }

    /**
     * @param array $dynamicParameters
     * @param string $expectedUrl
     * @return void
     * @dataProvider examplesOfNestedDynamicParameters
     */
    public function testNestedDynamicParameterCanBeUsedInContainerExtensionConfig($dynamicParameters, $expectedUrl)
    {
        $fakeExtension = new FakeExtension();
        $fakeExtension->configTreeBuilder = new TreeBuilder();
        $fakeExtension->configTreeBuilder->root('fake_extension')
            ->children()
                ->scalarNode('some_configurable_option')->end()
                ->scalarNode('some_other_configurable_option')->end()
            ->end();
        $fakeExtension->loadCallback = function ($mergedConfig, ContainerBuilder $container) {
            $container->register('fake_extension.some_service', 'stdClass')
                ->setProperty('url', $mergedConfig['some_configurable_option'])
                ->setProperty('urlConcatenatedWithText', $mergedConfig['some_other_configurable_option']);
        };

        $container = new ContainerBuilder();
        $container->registerExtension($fakeExtension);
        $container->prependExtensionConfig(
            'fake_extension',
            array(
                'some_configurable_option' => '%fancy_url%',
                'some_other_configurable_option' => 'Visit %fancy_url%!'
            )
        );

        $container->setParameter('fancy_url', '%base_url%/some-fancy-url.html');
        $container->setParameter('base_url', '%scheme%://%host%');
        $container->setParameter('scheme', 'http');
        $container->setParameter('host', '%subdomain%.example.org');
        $container->setParameter('subdomain', 'test');

        foreach ($dynamicParameters as $parameterName => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($parameterName);
            $container->setParameter($parameterName, '%env(' . $envVariableName . ')%');
        }

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        foreach ($dynamicParameters as $parameterName => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($parameterName);
            $this->setEnvVar($envVariableName, $value);
        }

        $service = $container->get('fake_extension.some_service');

        $this->assertEquals($expectedUrl, $service->url);
        $this->assertEquals(sprintf('Visit %s!', $expectedUrl), $service->urlConcatenatedWithText);
    }

    public function testExceptionIsThrownIfUnsetDynamicParameterWithoutDefaultValueIsUsedInContainerExtensionConfig()
    {
        $fakeExtension = new FakeExtension();
        $fakeExtension->configTreeBuilder = new TreeBuilder();
        $fakeExtension->configTreeBuilder->root('fake_extension')
            ->children()
                ->scalarNode('some_configurable_option')
                ->end()
            ->end();
        $fakeExtension->loadCallback = function ($mergedConfig, ContainerBuilder $container) {
            $container->register('fake_extension.some_service', 'stdClass')
                ->setProperty('someProperty', $mergedConfig['some_configurable_option']);
        };

        $container = new ContainerBuilder();
        $container->registerExtension($fakeExtension);
        $container->prependExtensionConfig('fake_extension', array('some_configurable_option' => '%some_parameter%'));

        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->setExpectedException('Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException');

        $container->get('fake_extension.some_service');
    }

    /**
     * @param string $valueDefinition e.g. "%foo% - %bar%"
     * @param string $expectedResolvedValue e.g. "foo-value - bar-value"
     * @dataProvider examplesOfConcatenatedDynamicParameters
     */
    public function testConcatenatedParametersFromRetriever($valueDefinition, $expectedResolvedValue)
    {
        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();

        $container = new ContainerBuilder();
        $container->setParameter('foo', '%env(SYMFONY_FOO)%');
        $container->setParameter('bar', '[bar-static]');// braces make concatenated parameters more readable, see assertions
        $container->setParameter('concatenated_parameter', $valueDefinition);

        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        // note that env var is set after container compilation
        $this->setEnvVar('SYMFONY_FOO', '[foo-dynamic]');

        $retriever = $container->get('incenteev_dynamic_parameters.retriever');

        $this->assertEquals($expectedResolvedValue, $retriever->getParameter('concatenated_parameter'));
        $this->assertEquals('[foo-dynamic]', $retriever->getParameter('foo'));
        $this->assertEquals('[bar-static]', $retriever->getParameter('bar'));
        $this->assertEquals('[foo-dynamic]', $retriever->getParameter('env(SYMFONY_FOO)'));
    }

    /**
     * @param array $dynamicParameters
     * @param string $expectedUrl
     * @return void
     * @dataProvider examplesOfNestedDynamicParameters
     */
    public function testNestedDynamicParametersFromRetriever($dynamicParameters, $expectedUrl)
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

        foreach ($dynamicParameters as $name => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($name);
            $container->setParameter($name, '%env(' . $envVariableName . ')%');
        }

        $container->setParameter('url', '%fancy_url%');
        $container->setParameter('url_concatenated_with_text', 'Visit %fancy_url%!');

        $container->compile();

        // note that env vars are set after container compilation
        foreach ($dynamicParameters as $name => $value) {
            $envVariableName = 'SYMFONY_' . strtoupper($name);
            $this->setEnvVar($envVariableName, $value);
        }

        $retriever = $container->get('incenteev_dynamic_parameters.retriever');

        $this->assertEquals($expectedUrl, $retriever->getParameter('url'));
        $this->assertEquals(sprintf('Visit %s!', $expectedUrl), $retriever->getParameter('url_concatenated_with_text'));
    }

    public function testExceptionIsThrownIfUnsetDynamicParameterWithoutDefaultValueIsRetrievedFromRetriever()
    {
        $container = new ContainerBuilder();
        $container->setParameter('some_parameter', '%env(SOME_ENV_VAR)%');

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->setExpectedException('Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException');

        $container->get('incenteev_dynamic_parameters.retriever')->getParameter('some_parameter');
    }

    public function testExceptionIsThrownIfUnknownParameterIsRetrievedFromRetriever()
    {
        $container = new ContainerBuilder();

        $dynamicParametersBundle = new IncenteevDynamicParametersBundle();
        $container->registerExtension($dynamicParametersBundle->getContainerExtension());
        $dynamicParametersBundle->build($container);

        $container->compile();

        $this->setExpectedException('Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException');

        $container->get('incenteev_dynamic_parameters.retriever')->getParameter('unknown_parameter');
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private function setEnvVar($name, $value)
    {
        putenv($name . '=' . $value);
        $this->setEnvVars[] = $name;
    }
}

class FakeExtension extends ConfigurableExtension
{
    public $alias = 'fake_extension';

    public $configTreeBuilder = null;

    public $loadCallback = null;

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container)
    {
        if ($this->loadCallback) {
            call_user_func($this->loadCallback, $mergedConfig, $container);
        }
    }

    public function getAlias()
    {
        return $this->alias;
    }

    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        if ($this->configTreeBuilder) {
            $config = new FakeExtensionConfiguration();
            $config->configTreeBuilder = $this->configTreeBuilder;

            return $config;
        }

        return null;
    }
}

class FakeExtensionConfiguration implements ConfigurationInterface
{
    public $configTreeBuilder;

    public function getConfigTreeBuilder()
    {
        return $this->configTreeBuilder;
    }
}