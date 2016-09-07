<?php

namespace Incenteev\DynamicParametersBundle\Tests\DependencyInjection\Compiler;

use Incenteev\DynamicParametersBundle\DependencyInjection\Compiler\ParameterReplacementPass;
use Incenteev\DynamicParametersBundle\ExpressionLanguage\FunctionProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Parameter;

class ParameterReplacementPassTest extends \PHPUnit_Framework_TestCase
{
    public function testReplaceParameters()
    {
        $container = new ContainerBuilder();

        $container->setParameter('foo', 'bar');
        $container->setParameter('bar', 'baz');
        $container->setParameter('incenteev_dynamic_parameters.parameters', array('foo' => array('variable' => 'SYMFONY_FOO', 'yaml' => false)));

        $container->register('srv.foo', 'stClass')
            ->setProperty('test', '%foo%')
            ->setProperty('test2', '%bar%');

        $container->register('srv.bar', 'ArrayObject')
            ->addMethodCall('append', array('%foo%'))
            ->addMethodCall('append', array(new Parameter('foo')));

        $pass = new ParameterReplacementPass();
        $pass->process($container);

        $def = $container->getDefinition('srv.foo');
        $props = $def->getProperties();

        $this->assertInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $props['test'], 'Parameters are replaced in properties');
        $this->assertEquals('dynamic_parameter(\'foo\', \'SYMFONY_FOO\')', (string) $props['test']);
        $this->assertEquals('%bar%', $props['test2'], 'Other parameters are not replaced');

        $def = $container->getDefinition('srv.bar');
        $calls = $def->getMethodCalls();

        $this->assertInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $calls[0][1][0], 'Parameters are replaced in arguments');
        $this->assertEquals('dynamic_parameter(\'foo\', \'SYMFONY_FOO\')', (string) $calls[0][1][0]);

        $this->assertInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $calls[1][1][0], 'Parameter instances are replaced in arguments');
        $this->assertEquals('dynamic_parameter(\'foo\', \'SYMFONY_FOO\')', (string) $calls[1][1][0]);
    }

    public function testReplaceYamlParameter()
    {
        $container = new ContainerBuilder();

        $container->setParameter('foo', 'bar');
        $container->setParameter('incenteev_dynamic_parameters.parameters', array('foo' => array('variable' => 'SYMFONY_FOO', 'yaml' => true)));

        $container->register('srv.foo', 'stClass')
            ->setProperty('test', '%foo%');

        $pass = new ParameterReplacementPass();
        $pass->process($container);

        $def = $container->getDefinition('srv.foo');
        $props = $def->getProperties();

        $this->assertInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $props['test'], 'Parameters are replaced in properties');
        $this->assertEquals('dynamic_yaml_parameter(\'foo\', \'SYMFONY_FOO\')', (string) $props['test']);
    }

    /**
     * @param string $valueDefinition e.g. "%foo% - %bar%"
     * @param string $expectedResolvedValue e.g. "foo-value - bar-value"
     * @dataProvider examplesOfConcatenatedDynamicParameters
     */
    public function testReplaceConcatenatedParameters($valueDefinition, $expectedResolvedValue)
    {
        $container = new ContainerBuilder();
        $container->addExpressionLanguageProvider(new FunctionProvider());
        $container->setParameter('foo', '[foo-static]');// braces make concatenated parameters more readable, see assertions
        $container->setParameter('bar', '[bar-static]');
        $container->setParameter('incenteev_dynamic_parameters.parameters', array('foo' => array('variable' => 'SYMFONY_FOO', 'yaml' => false)));

        putenv('SYMFONY_FOO=[foo-dynamic]');

        $container->register('srv.foo', 'stdClass')
            ->setProperty('test', $valueDefinition);

        $pass = new ParameterReplacementPass();
        $pass->process($container);

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
        $container = new ContainerBuilder();
        $container->addExpressionLanguageProvider(new FunctionProvider());
        $container->setParameter('foo', 'foo-static-value');
        $container->setParameter('bar', 'bar-static-value');
        $container->setParameter('incenteev_dynamic_parameters.parameters', array('baz' => array('variable' => 'SYMFONY_BAZ', 'yaml' => false)));
        $container->register('srv.foo', 'stdClass')
            ->setProperty('test', '%foo%%bar%');

        $pass = new ParameterReplacementPass();
        $pass->process($container);

        $def = $container->getDefinition('srv.foo');
        $props = $def->getProperties();

        $this->assertNotInstanceOf('Symfony\Component\ExpressionLanguage\Expression', $props['test']);
    }
}
