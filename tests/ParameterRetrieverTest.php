<?php

namespace Incenteev\DynamicParametersBundle\Tests;

use Incenteev\DynamicParametersBundle\ParameterRetriever;

class ParameterRetrieverTest extends \Incenteev\DynamicParametersBundle\Tests\TestCase
{
    public function testMissingEnvVar()
    {
        $container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerInterface')
            ->getMockForAbstractClass();

        $container->expects($this->once())
            ->method('getParameter')
            ->with('foo')
            ->willReturn('bar');

        putenv('MISSING');

        $retriever = new ParameterRetriever($container, array('foo' => $this->buildParam('MISSING')));

        $this->assertSame('bar', $retriever->getParameter('foo'));
    }

    public function testUnknownParameter()
    {
        $container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerInterface')
            ->getMockForAbstractClass();

        $container->expects($this->once())
            ->method('getParameter')
            ->with('foo')
            ->willReturn('bar');

        $retriever = new ParameterRetriever($container, array('baz' => $this->buildParam('BAZ')));

        $this->assertSame('bar', $retriever->getParameter('foo'));
    }

    public function testEnvParameter()
    {
        $container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerInterface')
            ->getMockForAbstractClass();

        $container->expects($this->never())
            ->method('getParameter');

        $retriever = new ParameterRetriever($container, array('foo' => $this->buildParam('INCENTEEV_TEST_FOO')));

        putenv('INCENTEEV_TEST_FOO=bar');

        $this->assertSame('bar', $retriever->getParameter('foo'));
    }

    public function testYamlParameter()
    {
        $container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerInterface')
            ->getMockForAbstractClass();

        $container->expects($this->never())
            ->method('getParameter');

        $retriever = new ParameterRetriever($container, array('foo' => $this->buildParam('INCENTEEV_TEST_BAR', true)));

        putenv('INCENTEEV_TEST_BAR=true');

        $this->assertSame(true, $retriever->getParameter('foo'));
    }

    private function buildParam($envVar, $isYaml = false)
    {
        return array('variable' => $envVar, 'yaml' => $isYaml);
    }
}
