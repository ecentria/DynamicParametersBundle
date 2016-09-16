<?php

namespace Incenteev\DynamicParametersBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
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
            $this->parameterExpressions[$name] = sprintf('%s(%s, %s)', $function, var_export($name, true), var_export($paramConfig['variable'], true));
        }

        $this->visitedDefinitions = new \SplObjectStorage();

        foreach ($container->getDefinitions() as $definition) {
            $this->updateDefinitionArguments($definition);
        }

        // Release memory
        $this->visitedDefinitions = null;
        $this->parameterExpressions = array();
    }

    private function updateDefinitionArguments(Definition $definition)
    {
        if ($this->visitedDefinitions->contains($definition)) {
            return;
        }

        $this->visitedDefinitions->attach($definition);

        $definition->setProperties($this->updateArguments($definition->getProperties()));
        $definition->setArguments($this->updateArguments($definition->getArguments()));

        $methodsCalls = array();

        foreach ($definition->getMethodCalls() as $index => $call) {
            $methodsCalls[$index] = array($call[0], $this->updateArguments($call[1]));
        }

        $definition->setMethodCalls($methodsCalls);
    }

    private function updateArguments(array $values)
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Definition) {
                $this->updateDefinitionArguments($value);

                continue;
            }

            if ($value instanceof Parameter && isset($this->parameterExpressions[(string) $value])) {
                $values[$key] = new Expression($this->parameterExpressions[(string) $value]);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->updateArguments($value);

                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            // Parameter-only argument
            if (preg_match('/^%([^%\s]++)%$/', $value, $match)) {
                $parameter = strtolower($match[1]);

                if (isset($this->parameterExpressions[$parameter])) {
                    $values[$key] = new Expression($this->parameterExpressions[$parameter]);
                }

                continue;
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
}
