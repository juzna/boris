<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\DI
 */



/**
 * Basic container builder.
 *
 * @author     David Grudl
 * @property-read NDIServiceDefinition[] $definitions
 * @property-read array $dependencies
 * @package Nette\DI
 */
class NDIContainerBuilder extends NObject
{
	const THIS_SERVICE = 'self',
		THIS_CONTAINER = 'container';

	/** @var array  %param% will be expanded */
	public $parameters = array();

	/** @var NDIServiceDefinition[] */
	private $definitions = array();

	/** @var array for auto-wiring */
	private $classes;

	/** @var array of file names */
	private $dependencies = array();

	/** @var NPhpClassType[] */
	private $generatedClasses = array();



	/**
	 * Adds new service definition. The expressions %param% and @service will be expanded.
	 * @param  string
	 * @return NDIServiceDefinition
	 */
	public function addDefinition($name)
	{
		if (!is_string($name) || !$name) { // builder is not ready for falsy names such as '0'
			throw new InvalidArgumentException("Service name must be a non-empty string, " . gettype($name) . " given.");

		} elseif (isset($this->definitions[$name])) {
			throw new InvalidStateException("Service '$name' has already been added.");
		}
		return $this->definitions[$name] = new NDIServiceDefinition;
	}



	/**
	 * Removes the specified service definition.
	 * @param  string
	 * @return void
	 */
	public function removeDefinition($name)
	{
		unset($this->definitions[$name]);
	}



	/**
	 * Gets the service definition.
	 * @param  string
	 * @return NDIServiceDefinition
	 */
	public function getDefinition($name)
	{
		if (!isset($this->definitions[$name])) {
			throw new NMissingServiceException("Service '$name' not found.");
		}
		return $this->definitions[$name];
	}



	/**
	 * Gets all service definitions.
	 * @return array
	 */
	public function getDefinitions()
	{
		return $this->definitions;
	}



	/**
	 * Does the service definition exist?
	 * @param  string
	 * @return bool
	 */
	public function hasDefinition($name)
	{
		return isset($this->definitions[$name]);
	}



	/********************* class resolving ****************d*g**/



	/**
	 * Resolves service name by type.
	 * @param  string  class or interface
	 * @return string  service name or NULL
	 * @throws NServiceCreationException
	 */
	public function getByType($class)
	{
		$lower = ltrim(strtolower($class), '\\');
		if (!isset($this->classes[$lower])) {
			return;

		} elseif (count($this->classes[$lower]) === 1) {
			return $this->classes[$lower][0];

		} else {
			throw new NServiceCreationException("Multiple services of type $class found: " . implode(', ', $this->classes[$lower]));
		}
	}



	/**
	 * Gets the service objects of the specified tag.
	 * @param  string
	 * @return array of [service name => tag attributes]
	 */
	public function findByTag($tag)
	{
		$found = array();
		foreach ($this->definitions as $name => $def) {
			if (isset($def->tags[$tag]) && $def->shared) {
				$found[$name] = $def->tags[$tag];
			}
		}
		return $found;
	}



	/**
	 * Creates a list of arguments using autowiring.
	 * @return array
	 */
	public function autowireArguments($class, $method, array $arguments)
	{
		$rc = NClassReflection::from($class);
		if (!$rc->hasMethod($method)) {
			if (!NArrays::isList($arguments)) {
				throw new NServiceCreationException("Unable to pass specified arguments to $class::$method().");
			}
			return $arguments;
		}

		$rm = $rc->getMethod($method);
		if (!$rm->isPublic()) {
			throw new NServiceCreationException("$rm is not callable.");
		}
		$this->addDependency($rm->getFileName());
		return NDIHelpers::autowireArguments($rm, $arguments, $this);
	}



	/**
	 * Generates $dependencies, $classes and expands and normalize class names.
	 * @return array
	 */
	public function prepareClassList()
	{
		$this->classes = FALSE;

		// prepare generated factories
		foreach ($this->definitions as $name => $def) {
			if (!$def->implement) {
				continue;
			}

			if (!interface_exists($def->implement)) {
				throw new InvalidStateException("Interface $def->implement has not been found.");
			}
			$rc = NClassReflection::from($def->implement);
			$method = $rc->hasMethod('create') ? $rc->getMethod('create') : ($rc->hasMethod('get') ? $rc->getMethod('get') : NULL);
			if (count($rc->getMethods()) !== 1 || !$method || $method->isStatic()) {
				throw new InvalidStateException("Interface $def->implement must have just one non-static method create() or get().");
			}
			$def->implement = $rc->getName();

			if (!$def->class && empty($def->factory->entity)) {
				$returnType = $method->getAnnotation('return');
				if (!$returnType) {
					throw new InvalidStateException("Method $method has not @return annotation.");
				}
				if (!class_exists($returnType)) {
					if ($returnType[0] !== '\\') {
						$returnType = $rc->getNamespaceName() . '\\' . $returnType;
					}
					if (!class_exists($returnType)) {
						throw new InvalidStateException("Please use a fully qualified name of class in @return annotation at $method method. Class '$returnType' cannot be found.");
					}
				}
				$def->setClass($returnType);
			}

			if ($method->getName() === 'get') {
				if ($method->getParameters()) {
					throw new InvalidStateException("Method $method must have no arguments.");
				}
				if (empty($def->factory->entity)) {
					$def->setFactory('@\\' . ltrim($def->class, '\\'));
				} elseif (!$this->getServiceName($def->factory->entity)) {
					throw new InvalidStateException("Invalid factory in service '$name' definition.");
				}
			}

			if (!$def->parameters) {
				foreach ($method->getParameters() as $param) {
					$paramDef = ($param->isArray() ? 'array' : $param->getClassName()) . ' ' . $param->getName();
					if ($param->isOptional()) {
						$def->parameters[$paramDef] = $param->getDefaultValue();
					} else {
						$def->parameters[] = $paramDef;
					}
				}
			}
		}

		// complete class-factory pairs; expand classes
		foreach ($this->definitions as $name => $def) {
			if ($def->class) {
				$def->class = $this->expand($def->class);
				if (!$def->factory) {
					$def->factory = new NDIStatement($def->class);
				}
			} elseif (!$def->factory) {
				throw new NServiceCreationException("Class and factory are missing in service '$name' definition.");
			}
		}

		// check if services are instantiable
		foreach ($this->definitions as $name => $def) {
			$factory = $this->normalizeEntity($this->expand($def->factory->entity));
			if (is_string($factory) && preg_match('#^[\w\\\\]+\z#', $factory) && $factory !== self::THIS_SERVICE) {
				if (!class_exists($factory) || !NClassReflection::from($factory)->isInstantiable()) {
					throw new InvalidStateException("Class $factory used in service '$name' has not been found or is not instantiable.");
				}
			}
		}

		// complete classes
		foreach ($this->definitions as $name => $def) {
			$this->resolveClass($name);

			if (!$def->class) {
				continue;
			} elseif (!class_exists($def->class) && !interface_exists($def->class)) {
				throw new InvalidStateException("Class or interface $def->class used in service '$name' has not been found.");
			} else {
				$def->class = NClassReflection::from($def->class)->getName();
			}
		}

		//  build auto-wiring list
		$this->classes = array();
		foreach ($this->definitions as $name => $def) {
			$class = ($tmp=$def->implement) ? $tmp : $def->class;
			if ($def->autowired && $class) {
				foreach (class_parents($class) + class_implements($class) + array($class) as $parent) {
					$this->classes[strtolower($parent)][] = (string) $name;
				}
			}
		}

		foreach ($this->classes as $class => $foo) {
			$this->addDependency(NClassReflection::from($class)->getFileName());
		}
	}



	private function resolveClass($name, $recursive = array())
	{
		if (isset($recursive[$name])) {
			throw new InvalidArgumentException('Circular reference detected for services: ' . implode(', ', array_keys($recursive)) . '.');
		}
		$recursive[$name] = TRUE;

		$def = $this->definitions[$name];
		$factory = $this->normalizeEntity($this->expand($def->factory->entity));

		if ($def->class) {
			return $def->class;

		} elseif (is_array($factory)) { // method calling
			if ($service = $this->getServiceName($factory[0])) {
				if (NStrings::contains($service, '\\')) { // @Class
					throw new NServiceCreationException("Unable resolve class name for service '$name'.");
				}
				$factory[0] = $this->resolveClass($service, $recursive);
				if (!$factory[0]) {
					return;
				}
				if ($this->definitions[$service]->implement && $factory[1] === 'create') {
					return $def->class = $factory[0];
				}
			}
			$factory = new NCallback($factory);
			if (!$factory->isCallable()) {
				throw new InvalidStateException("Factory '$factory' is not callable.");
			}
			try {
				$reflection = $factory->toReflection();
				$def->class = preg_replace('#[|\s].*#', '', $reflection->getAnnotation('return'));
				if ($def->class && !class_exists($def->class) && $def->class[0] !== '\\' && $reflection instanceof ReflectionMethod) {
					}
			} catch (ReflectionException $e) {
			}

		} elseif ($service = $this->getServiceName($factory)) { // alias or factory
			if (!$def->implement) {
				$def->autowired = FALSE;
			}
			if (NStrings::contains($service, '\\')) { // @Class
				$service = ltrim($service, '\\');
				return $def->class = $service;
			}
			if ($this->definitions[$service]->implement) {
				$def->autowired = FALSE;
			}
			return $def->class = ($tmp=$this->definitions[$service]->implement) ? $tmp : $this->resolveClass($service, $recursive);

		} else {
			return $def->class = $factory; // class name
		}
	}



	/**
	 * Adds a file to the list of dependencies.
	 * @return NDIContainerBuilder  provides a fluent interface
	 */
	public function addDependency($file)
	{
		$this->dependencies[$file] = TRUE;
		return $this;
	}



	/**
	 * Returns the list of dependent files.
	 * @return array
	 */
	public function getDependencies()
	{
		unset($this->dependencies[FALSE]);
		return array_keys($this->dependencies);
	}



	/********************* code generator ****************d*g**/



	/**
	 * Generates PHP classes. First class is the container.
	 * @return NPhpClassType[]
	 */
	public function generateClasses()
	{
		unset($this->definitions[self::THIS_CONTAINER]);
		$this->addDefinition(self::THIS_CONTAINER)->setClass('NDIContainer');

		$this->generatedClasses = array();
		$this->prepareClassList();

		$containerClass = $this->generatedClasses[] = new NPhpClassType('Container');
		$containerClass->addExtend('NDIContainer');
		$containerClass->addMethod('__construct')
			->addBody('parent::__construct(?);', array($this->expand($this->parameters)));

		$prop = $containerClass->addProperty('classes', array());
		foreach ($this->classes as $name => $foo) {
			try {
				$prop->value[$name] = $this->getByType($name);
			} catch (NServiceCreationException $e) {
				$prop->value[$name] = new NPhpLiteral('FALSE, //' . strstr($e->getMessage(), ':'));
			}
		}

		$definitions = $this->definitions;
		ksort($definitions);

		$meta = $containerClass->addProperty('meta', array());
		foreach ($definitions as $name => $def) {
			if ($def->shared) {
				foreach ($this->expand($def->tags) as $tag => $value) {
					$meta->value[$name][NDIContainer::TAGS][$tag] = $value;
				}
			}
		}

		foreach ($definitions as $name => $def) {
			try {
				$name = (string) $name;
				$methodName = NDIContainer::getMethodName($name, $def->shared);
				if (!NPhpHelpers::isIdentifier($methodName)) {
					throw new NServiceCreationException('Name contains invalid characters.');
				}
				$method = $containerClass->addMethod($methodName)
					->addDocument("@return " . (($tmp=$def->implement) ? $tmp : $def->class))
					->setVisibility($def->shared ? 'protected' : 'public')
					->setBody($name === self::THIS_CONTAINER ? 'return $this;' : $this->generateService($name))
					->setParameters($def->implement ? array() : $this->convertParameters($def->parameters));
			} catch (Exception $e) {
				throw new NServiceCreationException("Service '$name': " . $e->getMessage(), NULL, $e);
			}
		}

		return $this->generatedClasses;
	}



	/**
	 * Generates body of service method.
	 * @return string
	 */
	private function generateService($name)
	{
		$def = $this->definitions[$name];
		$parameters = $this->parameters;
		foreach ($this->expand($def->parameters) as $k => $v) {
			$v = explode(' ', is_int($k) ? $v : $k);
			$parameters[end($v)] = new NPhpLiteral('$' . end($v));
		}

		$code = '$service = ' . $this->formatStatement(NDIHelpers::expand($def->factory, $parameters, TRUE)) . ";\n";

		$entity = $this->normalizeEntity($def->factory->entity);
		if ($def->class && $def->class !== $entity && !$this->getServiceName($entity)) {
			$code .= NPhpHelpers::formatArgs("if (!\$service instanceof $def->class) {\n"
				. "\tthrow new UnexpectedValueException(?);\n}\n",
				array("Unable to create service '$name', value returned by factory is not $def->class type.")
			);
		}

		$setups = (array) $def->setup;
		if ($def->inject && $def->class) {
			$injects = array();
			foreach (NDIHelpers::getInjectProperties(NClassReflection::from($def->class)) as $property => $type) {
				$injects[] = new NDIStatement('$' . $property, array('@\\' . ltrim($type, '\\')));
			}

			foreach (get_class_methods($def->class) as $method) {
				if (substr($method, 0, 6) === 'inject') {
					$injects[] = new NDIStatement($method);
				}
			}

			foreach ($injects as $inject) {
				foreach ($setups as $key => $setup) {
					if ($setup->entity === $inject->entity) {
						$inject = $setup;
						unset($setups[$key]);
					}
				}
				array_unshift($setups, $inject);
			}
		}

		foreach ($setups as $setup) {
			$setup = NDIHelpers::expand($setup, $parameters, TRUE);
			if (is_string($setup->entity) && strpbrk($setup->entity, ':@?') === FALSE) { // auto-prepend @self
				$setup->entity = array('@self', $setup->entity);
			}
			$code .= $this->formatStatement($setup, $name) . ";\n";
		}

		$code .= 'return $service;';

		if (!$def->implement) {
			return $code;
		}

		$factoryClass = $this->generatedClasses[] = new NPhpClassType;
		$factoryClass->setName(str_replace(array('\\', '.'), '_', "{$def->implement}Impl_{$name}"))
			->addImplement($def->implement)
			->setFinal(TRUE);

		$factoryClass->addProperty('container')
			->setVisibility('private');

		$factoryClass->addMethod('__construct')
			->addBody('$this->container = $container;')
			->addParameter('container')
				->setTypeHint('NDIContainer');

		$factoryClass->addMethod(NClassReflection::from($def->implement)->hasMethod('get') ? 'get' : 'create')
			->setParameters($this->convertParameters($def->parameters))
			->setBody(str_replace('$this', '$this->container', $code));

		return "return new {$factoryClass->name}(\$this);";
	}



	/**
	 * Converts parameters from ServiceDefinition to PhpGenerator.
	 * @return NPhpParameter[]
	 */
	private function convertParameters(array $parameters)
	{
		$res = array();
		foreach ($this->expand($parameters) as $k => $v) {
			$tmp = explode(' ', is_int($k) ? $v : $k);
			$param = $res[] = new NPhpParameter;
			$param->setName(end($tmp));
			if (!is_int($k)) {
				$param = $param->setOptional(TRUE)->setDefaultValue($v);
			}
			if (isset($tmp[1])) {
				$param->setTypeHint($tmp[0]);
			}
		}
		return $res;
	}



	/**
	 * Formats PHP code for class instantiating, function calling or property setting in PHP.
	 * @return string
	 * @internal
	 */
	public function formatStatement(NDIStatement $statement, $self = NULL)
	{
		$entity = $this->normalizeEntity($statement->entity);
		$arguments = $statement->arguments;

		if (is_string($entity) && NStrings::contains($entity, '?')) { // PHP literal
			return $this->formatPhp($entity, $arguments, $self);

		} elseif ($service = $this->getServiceName($entity)) { // factory calling or service retrieving
			if ($this->definitions[$service]->shared) {
				if ($arguments) {
					throw new NServiceCreationException("Unable to call service '$entity'.");
				}
				return $this->formatPhp('$this->getService(?)', array($service));
			}
			$params = array();
			foreach ($this->definitions[$service]->parameters as $k => $v) {
				$params[] = preg_replace('#\w+\z#', '\$$0', (is_int($k) ? $v : $k)) . (is_int($k) ? '' : ' = ' . NPhpHelpers::dump($v));
			}
			$rm = new NFunctionReflection(create_function(implode(', ', $params), ''));
			$arguments = NDIHelpers::autowireArguments($rm, $arguments, $this);
			return $this->formatPhp('$this->?(?*)', array(NDIContainer::getMethodName($service, FALSE), $arguments), $self);

		} elseif ($entity === 'not') { // operator
			return $this->formatPhp('!?', array($arguments[0]));

		} elseif (is_string($entity)) { // class name
			if ($constructor = NClassReflection::from($entity)->getConstructor()) {
				$this->addDependency($constructor->getFileName());
				$arguments = NDIHelpers::autowireArguments($constructor, $arguments, $this);
			} elseif ($arguments) {
				throw new NServiceCreationException("Unable to pass arguments, class $entity has no constructor.");
			}
			return $this->formatPhp("new $entity" . ($arguments ? '(?*)' : ''), array($arguments), $self);

		} elseif (!NArrays::isList($entity) || count($entity) !== 2) {
			throw new InvalidStateException("Expected class, method or property, " . NPhpHelpers::dump($entity) . " given.");

		} elseif ($entity[0] === '') { // globalFunc
			return $this->formatPhp("$entity[1](?*)", array($arguments), $self);

		} elseif (NStrings::contains($entity[1], '$')) { // property setter
			NValidators::assert($arguments, 'list:1', "setup arguments for '" . NCallback::create($entity) . "'");
			if ($this->getServiceName($entity[0], $self)) {
				return $this->formatPhp('?->? = ?', array($entity[0], substr($entity[1], 1), $arguments[0]), $self);
			} else {
				return $this->formatPhp($entity[0] . '::$? = ?', array(substr($entity[1], 1), $arguments[0]), $self);
			}

		} elseif ($service = $this->getServiceName($entity[0], $self)) { // service method
			$class = $this->definitions[$service]->implement;
			if (!$class || !method_exists($class, $entity[1])) {
				$class = $this->definitions[$service]->class;
			}
			if ($class) {
				$arguments = $this->autowireArguments($class, $entity[1], $arguments);
			}
			return $this->formatPhp('?->?(?*)', array($entity[0], $entity[1], $arguments), $self);

		} else { // static method
			$arguments = $this->autowireArguments($entity[0], $entity[1], $arguments);
			return $this->formatPhp("$entity[0]::$entity[1](?*)", array($arguments), $self);
		}
	}



	/**
	 * Formats PHP statement.
	 * @return string
	 */
	public function formatPhp($statement, $args, $self = NULL)
	{
		$that = $this;
		array_walk_recursive($args, create_function('&$val', 'extract($GLOBALS[0]['.array_push($GLOBALS[0], array('self'=>$self,'that'=> $that)).'-1], EXTR_REFS);
			list($val) = $that->normalizeEntity(array($val));

			if ($val instanceof NDIStatement) {
				$val = new NPhpLiteral($that->formatStatement($val, $self));

			} elseif ($val === \'@\' . NDIContainerBuilder::THIS_CONTAINER) {
				$val = new NPhpLiteral(\'$this\');

			} elseif ($service = $that->getServiceName($val, $self)) {
				$val = $service === $self ? \'$service\' : $that->formatStatement(new NDIStatement($val));
				$val = new NPhpLiteral($val);

			} elseif (is_string($val) && preg_match(\'#^[\\w\\\\\\\\]*::[A-Z][A-Z0-9_]*\\z#\', $val, $m)) {
				$val = new NPhpLiteral(ltrim($val, \':\'));
			}
		'));
		return NPhpHelpers::formatArgs($statement, $args);
	}



	/**
	 * Expands %placeholders% in strings (recursive).
	 * @return mixed
	 */
	public function expand($value)
	{
		return NDIHelpers::expand($value, $this->parameters, TRUE);
	}



	/** @internal */
	public function normalizeEntity($entity)
	{
		if (is_string($entity) && NStrings::contains($entity, '::') && !NStrings::contains($entity, '?')) { // NClass::method -> [Class, method]
			$entity = explode('::', $entity);
		}

		if (is_array($entity) && $entity[0] instanceof NDIServiceDefinition) { // [ServiceDefinition, ...] -> [@serviceName, ...]
			$tmp = array_keys($this->definitions, $entity[0], TRUE);
			$entity[0] = "@$tmp[0]";

		} elseif ($entity instanceof NDIServiceDefinition) { // ServiceDefinition -> @serviceName
			$tmp = array_keys($this->definitions, $entity, TRUE);
			$entity = "@$tmp[0]";

		} elseif (is_array($entity) && $entity[0] === $this) { // [$this, ...] -> [@container, ...]
			$entity[0] = '@' . NDIContainerBuilder::THIS_CONTAINER;
		}
		return $entity; // Class, @service, [Class, member], [@service, member], [, globalFunc]
	}



	/**
	 * Converts @service or @Class -> service name and checks its existence.
	 * @return string  of FALSE, if argument is not service name
	 */
	public function getServiceName($arg, $self = NULL)
	{
		if (!is_string($arg) || !preg_match('#^@[\w\\\\.].*\z#', $arg)) {
			return FALSE;
		}
		$service = substr($arg, 1);
		if ($service === self::THIS_SERVICE) {
			$service = $self;
		}
		if (NStrings::contains($service, '\\')) {
			if ($this->classes === FALSE) { // may be disabled by prepareClassList
				return $service;
			}
			$res = $this->getByType($service);
			if (!$res) {
				throw new NServiceCreationException("Reference to missing service of type $service.");
			}
			return $res;
		}
		if (!isset($this->definitions[$service])) {
			throw new NServiceCreationException("Reference to missing service '$service'.");
		}
		return $service;
	}



	/** @deprecated */
	function generateClass()
	{
		throw new DeprecatedException(__METHOD__ . '() is deprecated; use generateClasses()[0] instead.');
	}

}
