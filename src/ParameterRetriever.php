<?php

namespace Incenteev\DynamicParametersBundle;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Inline;

class ParameterRetriever
{
    private $container;
    private $parameterMap;
    private $allParameters;

    public function __construct(ContainerInterface $container, array $parameterMap, $allParameters = array())
    {
        $this->container = $container;
        $this->parameterMap = $parameterMap;
        $this->allParameters = $allParameters;
    }

    /**
     * @param string $name
     *
     * @return array|string|bool|int|float|null
     */
    public function getParameter($name)
    {
        $nameLowerCased = strtolower($name);// see \Symfony\Component\DependencyInjection\ParameterBag\ParameterBag::set
        if (array_key_exists($nameLowerCased, $this->allParameters)) {
            return $this->allParameters[$nameLowerCased];
        }

        if (!isset($this->parameterMap[$name])) {
            return $this->container->getParameter($name);
        }

        $varName = $this->parameterMap[$name]['variable'];

        $var = getenv($varName);

        if (false === $var) {
            return $this->container->getParameter($name);
        }

        if ($this->parameterMap[$name]['yaml']) {
            return Inline::parse($var);
        }

        return $var;
    }
}
