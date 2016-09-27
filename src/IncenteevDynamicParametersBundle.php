<?php

namespace Incenteev\DynamicParametersBundle;

use Incenteev\DynamicParametersBundle\DependencyInjection\Compiler\ParameterReplacementPass;
use Incenteev\DynamicParametersBundle\ExpressionLanguage\FunctionProvider;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class IncenteevDynamicParametersBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        // BC for dynamic parameters which are not in Symfony 3.2 format
        $container->addCompilerPass(new ParameterReplacementPass());

        // replace $(env(some_env_var)) placeholders at the very end of container compilation
        $container->addCompilerPass(new ParameterReplacementPass(), PassConfig::TYPE_AFTER_REMOVING);

        $container->addExpressionLanguageProvider(new FunctionProvider());
    }
}
