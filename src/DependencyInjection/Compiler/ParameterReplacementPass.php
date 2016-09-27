<?php

namespace Incenteev\DynamicParametersBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\ExpressionLanguage\Expression;

class ParameterReplacementPass implements CompilerPassInterface
{
    /**
     * @var \SplObjectStorage
     */
    private $visitedDefinitions;
    private $parameterExpressions = array();

    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter('incenteev_dynamic_parameters.parameters')) {
            return;
        }

        foreach ($container->getParameter('incenteev_dynamic_parameters.parameters') as $name => $paramConfig) {
            $function = $paramConfig['yaml'] ? 'dynamic_yaml_parameter' : 'dynamic_parameter';
            $parameterName = var_export($name, true);
            if (strpos($name, 'env(') === 0) {
                $parameterName = var_export('default_' . $name, true);
            }
            $this->parameterExpressions[$name] = sprintf('%s(%s, %s)', $function, $parameterName, var_export($paramConfig['variable'], true));
        }

        $this->visitedDefinitions = new \SplObjectStorage();

        foreach ($container->getDefinitions() as $definition) {
            $this->updateDefinitionArguments($definition, $container->getParameterBag());
        }

        // Release memory
        $this->visitedDefinitions = null;
        $this->parameterExpressions = array();
    }

    private function updateDefinitionArguments(Definition $definition, ParameterBagInterface $parameterBag)
    {
        if ($this->visitedDefinitions->contains($definition)) {
            return;
        }

        $this->visitedDefinitions->attach($definition);

        $definition->setProperties($this->updateArguments($definition->getProperties(), $parameterBag));
        $definition->setArguments($this->updateArguments($definition->getArguments(), $parameterBag));

        $methodsCalls = array();

        foreach ($definition->getMethodCalls() as $index => $call) {
            $methodsCalls[$index] = array($call[0], $this->updateArguments($call[1], $parameterBag));
        }

        $definition->setMethodCalls($methodsCalls);
    }

    /**
     * Replace dynamic parameters with expressions
     *
     * Implementation definitely is not the most efficient one in terms of CPU usage, but tests are good enough
     * to enable refactoring without braking functionality.
     *
     * See DependencyInjectionContainerIntegrationTest
     *
     * @param array $values
     * @param ParameterBagInterface $parameterBag
     * @return array
     */
    private function updateArguments(array $values, ParameterBagInterface $parameterBag)
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Definition) {
                $this->updateDefinitionArguments($value, $parameterBag);

                continue;
            }

            if ($value instanceof Parameter && isset($this->parameterExpressions[(string) $value])) {
                $values[$key] = new Expression($this->parameterExpressions[(string) $value]);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->updateArguments($value, $parameterBag);

                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $tmpValue = $this->resolveArrayOrNull($value, $parameterBag);
            if ($this->isArrayWithDynamicParametersInside($tmpValue, $parameterBag)) {
                $values[$key] = $this->updateArguments($tmpValue, $parameterBag);
                continue;
            }

            $value = $this->flattenParameterValueToClosestDynamicParameters($value, $parameterBag);

            foreach ($this->parameterExpressions as $parameterName => $expression) {
                $value = str_replace('${' . $parameterName . '}', '%' . $parameterName . '%', $value);
            }

            // Argument with parameters
            if (preg_match_all('/%%|%([^%\s]+)%/', $value, $match)) {
                $parameters = array_filter($match[1]);

                // Do not replace argument if there are no dynamic parameters inside
                if (!array_intersect($parameters, array_keys($this->parameterExpressions))) {
                    continue;
                }

                // Next two preg_replace_callback calls are transforming argument string like "%foo%-text-%bar%"
                // into expression like "dynamic_parameter('foo', 'SYMFONY_FOO')~'-text-'~static_parameter('bar')"
                // see use cases in ParameterReplacementPassTest::examplesOfConcatenatedDynamicParameters
                $tmpValue = preg_replace_callback(
                    '/(?P<text>.*?)(?:(?P<parameter>%(?:' . join('|', array_map('preg_quote', $parameters)) . ')%)|$)/',
                    function ($match) {
                        if (!$match['text']) {
                            return $match[0];
                        }

                        return str_replace($match['text'], '\'' . $match['text'] . '\'~', $match[0]);
                    },
                    $value
                );

                $parameterExpressions = $this->parameterExpressions;
                $tmpValue = preg_replace_callback(
                    '/%%|%([^%\s]+)%/',
                    function ($match) use ($parameterExpressions) {
                        // skip %%
                        if (!isset($match[1])) {
                            return $match[0];
                        }

                        $parameter = $match[1];

                        $expression = isset($parameterExpressions[$parameter]) ?
                            $parameterExpressions[$parameter] :
                            sprintf('static_parameter(%s)', var_export($parameter, true));

                        return str_replace('%' . $parameter . '%', $expression . '~', $match[0]);
                    },
                    $tmpValue
                );
                $tmpValue = substr($tmpValue, 0, -1);// remove trailing ~
                $tmpValue = str_replace('%%', '%', $tmpValue);// un-escape % in expression string
                $values[$key] = new Expression($tmpValue);
                continue;
            }
        }

        return $values;
    }

    /**
     * @param string $value
     * @return string mixed
     */
    public function flattenParameterValueToClosestDynamicParameters($value, ParameterBagInterface $parameterBag)
    {
        $parameterExpressions = $this->parameterExpressions;
        $that = $this;
        $value = preg_replace_callback(
            '/%%|%([^%\s]+)%/',
            function ($match) use ($parameterExpressions, $that, $parameterBag) {
                // skip %%
                if (!isset($match[1])) {
                    return $match[0];
                }

                $parameter = $match[1];

                if (!isset($parameterExpressions[$parameter])) {
                    // static parameter - let's drill down and see if there is nested dynamic parameter
                    $value = $parameterBag->get($parameter);
                    if (is_string($value) && (preg_match('/%%|%([^%\s]+)%/', $value) || $that->hasDynamicPlaceholders($value))) {
                        return $that->flattenParameterValueToClosestDynamicParameters($value, $parameterBag);
                    }
                }

                return $match[0];
            },
            $value
        );

        return $value;
    }

    private function resolveArrayOrNull($value, ParameterBagInterface $parameterBag)
    {
        if (is_string($value) && preg_match('/^%([^%\s]+)%$/', $value, $match)) {
            $tmpValue = $parameterBag->get($match[1]);

            if (is_array($tmpValue)) {
                return $tmpValue;
            }

            return $this->resolveArrayOrNull($tmpValue, $parameterBag);
        }

        return null;
    }

    public function isArrayWithDynamicParametersInside($value, ParameterBagInterface $parameterBag)
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $k => $v) {

            $v =  $this->resolveArrayOrNull($v, $parameterBag) ?: $v;

            if ($this->isArrayWithDynamicParametersInside($v, $parameterBag)) {
                return true;
            }

            if (is_array($v)) {
                continue;
            }

            if ($this->hasDynamicPlaceholders($this->flattenParameterValueToClosestDynamicParameters($v, $parameterBag))) {
                return true;
            }
        }

        return false;
    }

    public function hasDynamicPlaceholders($value)
    {
        foreach ($this->parameterExpressions as $parameterName => $expression) {
            if (strpos(strtolower($value), '${' . $parameterName . '}') !== false) {
                return true;
            }

            if (strpos(strtolower($value), '%' . $parameterName . '%') !== false) {
                return true;
            }
        }
        return false;
    }
}
