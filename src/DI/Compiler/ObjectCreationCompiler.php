<?php

namespace DI\Compiler;

use DI\Compiler;
use DI\Definition\Exception\InvalidDefinition;
use DI\Definition\ObjectDefinition;
use DI\Definition\ObjectDefinition\MethodInjection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class ObjectCreationCompiler
{
    /**
     * @var Compiler
     */
    private $compiler;

    public function __construct(Compiler $compiler)
    {
        $this->compiler = $compiler;
    }

    public function compile(ObjectDefinition $definition)
    {
        $this->assertClassIsNotAnonymous($definition);
        $this->assertClassIsInstantiable($definition);

        // Lazy?
        if ($definition->isLazy()) {
            return $this->compileLazyDefinition($definition);
        }

        $classReflection = new ReflectionClass($definition->getClassName());
        $constructorArguments = $this->resolveParameters($definition->getConstructorInjection(), $classReflection->getConstructor());
        $dumpedConstructorArguments = array_map(function ($value) {
            return $this->compiler->compileValue($value);
        }, $constructorArguments);

        $code = [];
        $code[] = sprintf(
            '$object = new %s(%s);',
            $definition->getClassName(),
            implode(', ', $dumpedConstructorArguments)
        );

        // Property injections
        foreach ($definition->getPropertyInjections() as $propertyInjection) {
            $value = $propertyInjection->getValue();
            $value = $this->compiler->compileValue($value);

            // TODO handle private properties
            $className = $propertyInjection->getClassName() ?: $definition->getClassName();
            $property = new ReflectionProperty($className, $propertyInjection->getPropertyName());
            if (! $property->isPublic()) {
                throw new \Exception('Unable to compile access to private properties');
            }

            $code[] = sprintf('$object->%s = %s;', $propertyInjection->getPropertyName(), $value);
        }

        // Method injections
        foreach ($definition->getMethodInjections() as $methodInjection) {
            $methodReflection = new \ReflectionMethod($definition->getClassName(), $methodInjection->getMethodName());
            $parameters = $this->resolveParameters($methodInjection, $methodReflection);

            $dumpedParameters = array_map(function ($value) {
                return $this->compiler->compileValue($value);
            }, $parameters);

            $code[] = sprintf(
                '$object->%s(%s);',
                $methodInjection->getMethodName(),
                implode(', ', $dumpedParameters)
            );
        }

        return implode("\n        ", $code);
    }

    public function resolveParameters(MethodInjection $definition = null, ReflectionMethod $method = null)
    {
        $args = [];

        if (! $method) {
            return $args;
        }

        $definitionParameters = $definition ? $definition->getParameters() : [];

        foreach ($method->getParameters() as $index => $parameter) {
            if (array_key_exists($index, $definitionParameters)) {
                // Look in the definition
                $value = &$definitionParameters[$index];
            } elseif ($parameter->isOptional()) {
                // If the parameter is optional and wasn't specified, we take its default value
                $args[] = $this->getParameterDefaultValue($parameter, $method);
                continue;
            } else {
                throw new InvalidDefinition(sprintf(
                    'Parameter $%s of %s has no value defined or guessable',
                    $parameter->getName(),
                    $this->getFunctionName($method)
                ));
            }

            $args[] = &$value;
        }

        return $args;
    }

    private function compileLazyDefinition(ObjectDefinition $definition) : string
    {
        $subDefinition = clone $definition;
        $subDefinition->setLazy(false);
        $subDefinition = $this->compiler->compileValue($subDefinition);

        return <<<PHP
        \$object = \$this->proxyFactory->createProxy(
            '{$definition->getClassName()}',
            function (&\$wrappedObject, \$proxy, \$method, \$params, &\$initializer) {
                \$wrappedObject = $subDefinition;
                \$initializer = null; // turning off further lazy initialization
                return true;
            }
        );
PHP;
    }

    /**
     * Returns the default value of a function parameter.
     *
     * @throws InvalidDefinition Can't get default values from PHP internal classes and functions
     * @return mixed
     */
    private function getParameterDefaultValue(ReflectionParameter $parameter, ReflectionMethod $function)
    {
        try {
            return $parameter->getDefaultValue();
        } catch (\ReflectionException $e) {
            throw new InvalidDefinition(sprintf(
                'The parameter "%s" of %s has no type defined or guessable. It has a default value, '
                . 'but the default value can\'t be read through Reflection because it is a PHP internal class.',
                $parameter->getName(),
                $this->getFunctionName($function)
            ));
        }
    }

    private function getFunctionName(ReflectionMethod $method) : string
    {
        return $method->getName() . '()';
    }

    private function assertClassIsNotAnonymous(ObjectDefinition $definition)
    {
        if (strpos($definition->getClassName(), '@') !== false) {
            throw new \Exception('Cannot compile anonymous classes');
        }
    }

    private function assertClassIsInstantiable(ObjectDefinition $definition)
    {
        if (! $definition->isInstantiable()) {
            // Check that the class exists
            if (! $definition->classExists()) {
                throw InvalidDefinition::create($definition, sprintf(
                    'Entry "%s" cannot be compiled: the class doesn\'t exist',
                    $definition->getName()
                ));
            }
            throw InvalidDefinition::create($definition, sprintf(
                'Entry "%s" cannot be compiled: the class is not instantiable',
                $definition->getName()
            ));
        }
    }
}
