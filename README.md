# DynamicParametersBundle

Inspired by [incenteev/dynamic-parameters-bundle](https://github.com/Incenteev/DynamicParametersBundle) and [%env()%](http://symfony.com/blog/new-in-symfony-3-2-runtime-environment-variables) parameters in Symfony 3.2.

This bundle provides a way to read parameters from environment variables at runtime in Symfony 2.7 (and up) the same way as in Symfony 3.2 (some limitations apply).

[![Build Status](https://api.travis-ci.org/ecentria/DynamicParametersBundle.svg?branch=master)](https://travis-ci.org/ecentria/DynamicParametersBundle)

## Installation

Installation is a 2 step process:

1. Download Ecentria fork of IncenteevDynamicParametersBundle
2. Enable the bundle

### Step 1: Install bundle with composer

Run the following composer require command:

```bash
$ composer require ecentria/dynamic-parameters-bundle
```

### Step 2: Enable the bundle

Finally, enable the bundle in the kernel:

```php
// app/AppKernel.php

public function registerBundles()
{
    $bundles = array(
        // ...
        new Incenteev\DynamicParametersBundle\IncenteevDynamicParametersBundle(),
    );
}
```

## Usage

It's highly recommended to use %env()% parameters only as a value for regular parameter:
```yaml
# app/config/parameters.yml
parameters:
    database_host: %env(DATABASE_HOST)%
```
and then ``database_host`` can be used in following scenarios:
```yaml
parameters:
    # can be concatenated with strings/parameters
    dsn: mysql:host=%database_host%;dbname=testdb
    
    # can be used in array
    hosts:
        - localhost
        - %database_host%
    
# can be used in config
doctrine:
    dbal:
        connections:
            default:
                host: %database_host%

# can be used as service argument
services:
    foo:
        class: stdClass
        arguments:
            - %database_host%
```

Using ``%env(DATABASE_HOST)%`` directly (instead of ``%database_host%``) has several disadvantages:

1. Environment variable name ``DATABASE_HOST`` is duplicated whenever dynamic parameter is used
1. This use case is not covered with tests as much as recommended one (yet), something might not work as expected

### Retrieving parameters at runtime

The bundle takes care of service arguments, but changing the behavior of ``$container->getParameter()`` is not possible. However, it exposes a service to get parameters taking the environment variables into account.

```php
$this->get('incenteev_dynamic_parameters.retriever')->get('database_host');
```

## Limitations

- Getting a parameter from the container directly at runtime will not use the environment variable
- Uppercase names should be used for environment variables
