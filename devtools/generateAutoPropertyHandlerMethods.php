<?php

set_time_limit(0);
date_default_timezone_set('UTC');

require __DIR__.'/../vendor/autoload.php';

$mode = isset($argv[1]) ? $argv[1] : AutoMethods::MODE_APPEND;

$updater = new AutoMethods(__DIR__.'/../src/', $mode);
$processedFiles = $updater->run();
exit((int) ($mode !== AutoMethods::MODE_VALIDATE || !count($processedFiles)));

class AutoMethods
{
    const MODE_APPEND = 'append';
    const MODE_REWRITE = 'rewrite';
    const MODE_VALIDATE = 'validate';

    const PADDING = "XXXPADDINGXXX\n";

    /**
     * @var string
     */
    private $_dir;

    /**
     * @var string
     */
    private $_mode;

    /**
     * @return array
     */
    public function getAvailableModes()
    {
        return [
            self::MODE_APPEND,
            self::MODE_REWRITE,
            self::MODE_VALIDATE,
        ];
    }

    /**
     * Constructor.
     *
     * @param string $dir  Directory to process.
     * @param string $mode Updated mode
     */
    public function __construct(
        $dir,
        $mode)
    {
        $this->_dir = realpath($dir);
        if ($this->_dir === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid path.', $dir));
        }
        if (!in_array($mode, $this->getAvailableModes())) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid mode.', $mode));
        }
        $this->_mode = $mode;
    }

    /**
     * Convert file path to class name.
     *
     * @param string $filePath
     *
     * @return string
     */
    private function _extractClassName(
        $filePath)
    {
        return '\InstagramAPI'.str_replace('/', '\\', substr($filePath, strlen($this->_dir), -4));
    }

    /**
     * Extract property type from its PHPDoc.
     *
     * @param ReflectionProperty $property
     *
     * @return string
     */
    private function _getType(
        ReflectionProperty $property)
    {
        $phpDoc = $property->getDocComment();
        if ($phpDoc === false || !preg_match('#@var\s+([^\s]+)#i', $phpDoc, $matches)) {
            $type = 'mixed';
        } else {
            $type = $matches[1];
        }

        return $type;
    }

    /**
     * Converts underscores to camel cases.
     *
     * @param string $property
     *
     * @return string
     */
    private function _camelCase(
        $property)
    {
        // Trim any leading underscores and save their count, because it's a special case.
        $result = ltrim($property, '_');
        $leadingUnderscores = strlen($property) - strlen($result);
        if (strlen($result)) {
            // Convert all chars prefixed with underscore to upper case.
            $result = preg_replace_callback('#_([^_])#', function ($matches) {
                return strtoupper($matches[1]);
            }, $result);
            // Convert fist char to upper case.
            $result[0] = strtoupper($result[0]);
        }
        // Restore leading underscores (if any).
        if ($leadingUnderscores) {
            $result = str_pad($result, strlen($result) + $leadingUnderscores, '_', STR_PAD_LEFT);
        }

        return $result;
    }

    /**
     * Generate a list of methods signatures based on properties list.
     *
     * @param ReflectionClass $reflection
     *
     * @return array Map of method name => [source, type]
     */
    private function _generateMethodsSignatures(
        ReflectionClass $reflection)
    {
        $result = [];
        $properties = $reflection->getProperties();
        $parent = $reflection->getParentClass();
        foreach ($properties as $property) {
            if (strpos($property->getDocComment(), '@internal') !== false) {
                continue;
            }
            $propertyName = $property->getName();
            // Determine property type.
            $type = $this->_getType($property);
            // Skip properties available in parent class.
            if ($parent->hasProperty($propertyName)) {
                $parentType = $this->_getType($parent->getProperty($propertyName));
                if ($parentType === $type) {
                    continue;
                }
            }

            // Normalize property name.
            $normalizedName = $this->_camelCase($propertyName);
            $signature = [$propertyName, $type];
            // getPropertyName() method.
            $getter = 'get'.$normalizedName;
            $result[$getter] = $signature;

            // setPropertyName() method.
            $setter = 'set'.$normalizedName;
            $result[$setter] = $signature;

            // isPropertyName() method.
            $iser = 'is'.$normalizedName;
            $result[$iser] = $signature;
        }

        return $result;
    }

    /**
     * @param ReflectionClass $reflection
     *
     * @return ReflectionMethod[]
     */
    private function _getExistingMethods(
        ReflectionClass $reflection)
    {
        $result = [];
        foreach ($reflection->getMethods() as $method) {
            $result[$method->getName()] = $method;
        }

        return $result;
    }

    /**
     * @param ReflectionMethod $method
     * @param array            $lines
     *
     * @return array
     */
    private function _removeExistingMethod(
        ReflectionMethod $method,
        array $lines)
    {
        $result = $lines;
        $from = $method->getStartLine();
        $phpDoc = $method->getDocComment();
        if (!empty($phpDoc)) {
            $from -= substr_count($method->getDocComment(), "\n") + 1;
        }
        $to = $method->getEndLine();

        for ($i = $from - 1; $i < $to; ++$i) {
            $result[$i] = self::PADDING;
        }

        return $result;
    }

    /**
     * @param array $lines
     *
     * @return array
     */
    private function _filterEmptyLines(
        array $lines)
    {
        $result = array_filter($lines, function ($line) {
            return $line !== self::PADDING;
        });
        $prevLine = '';
        $result = array_filter($result, function ($line) use (&$prevLine) {
            $line = trim($line);
            if ($prevLine === '' && $line === '') {
                return false;
            } else {
                $prevLine = $line;

                return true;
            }
        });

        return $result;
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    private function _isSimple(
        $type)
    {
        return in_array($type, ['string', 'bool', 'int', 'float', 'double', 'array']);
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    private function _isArray(
        $type)
    {
        return substr($type, -2) === '[]' && !$this->_isMixed($type);
    }

    /**
     * @param $type
     *
     * @return bool
     */
    private function _isMixed(
        $type)
    {
        return $type === 'mixed' || strpos($type, '|') !== false;
    }

    /**
     * @param $source
     * @param $type
     *
     * @return array
     */
    private function _generateArrayGetter(
        $source,
        $type)
    {
        $result = [];
        $result[] = "            if (is_array(\$this->_jsonData->{$source})) {\n";
        $result[] = "                \$this->{$source} = [];\n";
        $result[] = "                foreach (\$this->_jsonData->{$source} as \$idx => \$value) {\n";
        if ($this->_isMixed($type) || $this->_isSimple($type)) {
            $result[] = "                    \$this->{$source}[\$idx] = \$value;\n";
        } elseif ($this->_isArray($type)) {
            throw new \RuntimeException('No support for inner arrays.');
        } else {
            $result[] = "                    \$this->{$source}[\$idx] = new {$type}(\$value);\n";
        }
        $result[] = "                }\n";
        $result[] = "            } else {\n";
        $result[] = "                \$this->{$source} = null;\n";
        $result[] = "            }\n";

        return $result;
    }

    /**
     * @param $methodName
     * @param $source
     * @param $type
     *
     * @return array
     */
    private function _generateGetter(
        $methodName,
        $source,
        $type)
    {
        $result = [];
        $result[] = "\n";
        $result[] = "    /**\n";
        $result[] = "     * @return {$type}\n";
        $result[] = "     */\n";
        $result[] = "    public function {$methodName}()\n";
        $result[] = "    {\n";
        $result[] = "        if (\$this->{$source} !== null) {\n";
        $result[] = "            return \$this->{$source};\n";
        $result[] = "        } elseif (isset(\$this->_jsonData->{$source})) {\n";
        if ($this->_isMixed($type) || $this->_isSimple($type)) {
            $result[] = "            \$this->{$source} = \$this->_jsonData->{$source};\n";
        } elseif ($this->_isArray($type)) {
            $result = array_merge($result, $this->_generateArrayGetter($source, substr($type, 0, -2)));
        } else {
            $result[] = "            \$this->{$source} = new {$type}(\$this->_jsonData->{$source});\n";
        }
        $result[] = "\n";
        $result[] = "            return \$this->{$source};\n";
        $result[] = "        } else {\n";
        $result[] = "            return null;\n";
        $result[] = "        }\n";
        $result[] = "    }\n";
        $reuslt[] = "\n";

        return $result;
    }

    /**
     * @param $methodName
     * @param $source
     * @param $type
     *
     * @return array
     */
    private function _generateSetter(
        $methodName,
        $source,
        $type)
    {
        if ($this->_isArray($type) || $type === 'array') {
            $valueType = 'array ';
        } elseif ($this->_isMixed($type) || $this->_isSimple($type)) {
            $valueType = '';
        } else {
            $valueType = $type.' ';
        }
        $result = [];
        $result[] = "\n";
        $result[] = "    /**\n";
        $result[] = "     * @param {$type} \$value\n";
        $result[] = "     *\n";
        $result[] = "     * @return static\n";
        $result[] = "     */\n";
        $result[] = "    public function {$methodName}(\n";
        $result[] = "        {$valueType}\$value)\n";
        $result[] = "    {\n";
        $result[] = "        \$this->{$source} = \$value;\n";
        $result[] = "        \$this->_jsonData->{$source} = \$value;\n";
        $result[] = "\n";
        $result[] = "        return \$this;\n";
        $result[] = "    }\n";
        $reuslt[] = "\n";

        return $result;
    }

    /**
     * @param $methodName
     * @param $source
     *
     * @return array
     */
    private function _generateIser(
        $methodName,
        $source)
    {
        $result = [];
        $result[] = "\n";
        $result[] = "    /**\n";
        $result[] = "     * @return bool\n";
        $result[] = "     */\n";
        $result[] = "    public function {$methodName}()\n";
        $result[] = "    {\n";
        $result[] = "        return isset(\$this->_jsonData->{$source}) && \$this->_jsonData->{$source};\n";
        $result[] = "    }\n";
        $reuslt[] = "\n";

        return $result;
    }

    /**
     * @param string $methodName
     * @param array  $signature
     * @param array  $lines
     *
     * @return array
     */
    private function _generateMethodCode(
        $methodName,
        array $signature,
        array $lines)
    {
        list($source, $type) = $signature;
        if (!substr_compare($methodName, 'get', 0, 3)) {
            $method = $this->_generateGetter($methodName, $source, $type);
        } elseif (!substr_compare($methodName, 'set', 0, 3)) {
            $method = $this->_generateSetter($methodName, $source, $type);
        } elseif (!substr_compare($methodName, 'is', 0, 2)) {
            $method = $this->_generateIser($methodName, $source);
        } else {
            $method = [];
        }

        return $method;
    }

    /**
     * @param string             $filePath
     * @param array              $newMethods
     * @param ReflectionMethod[] $existingMethods
     * @param int                $insertAt
     *
     * @return bool
     */
    private function _generateMethods(
        $filePath,
        array $newMethods,
        array $existingMethods,
        $insertAt)
    {
        $lines = file($filePath);

        foreach ($newMethods as $methodName => $signature) {
            if (isset($existingMethods[$methodName])) {
                $lines = $this->_removeExistingMethod($existingMethods[$methodName], $lines);
            }
            $method = $this->_generateMethodCode($methodName, $signature, $lines);
            array_splice($lines, $insertAt - 1, 0, $method);
            $insertAt += count($method);
        }

        $lines = $this->_filterEmptyLines($lines);
        file_put_contents($filePath, implode('', $lines));

        return true;
    }

    /**
     * Process single file.
     *
     * @param string          $filePath
     * @param ReflectionClass $reflection
     *
     * @return bool
     */
    private function _processFile(
        $filePath,
        ReflectionClass $reflection)
    {
        $allMethods = $this->_generateMethodsSignatures($reflection);
        $existingMethods = $this->_getExistingMethods($reflection);
        $diff = array_diff_key($allMethods, $existingMethods);
        switch ($this->_mode) {
            case self::MODE_APPEND:
                $result = count($diff)
                    ? $this->_generateMethods($filePath, $diff, $existingMethods, $reflection->getEndLine())
                    : true;
                break;
            case self::MODE_REWRITE:
                $result = count($allMethods)
                    ? $this->_generateMethods($filePath, $allMethods, $existingMethods, $reflection->getEndLine())
                    : true;
                break;
            case self::MODE_VALIDATE:
                $result = !count($diff);
                break;
            default:
                throw new RuntimeException(sprintf('Unknown mode "%s".', $this->_mode));
        }

        return $result;
    }

    /**
     * Process all *.php files in given path.
     *
     * @return array
     */
    public function run()
    {
        $directoryIterator = new RecursiveDirectoryIterator($this->_dir);
        $recursiveIterator = new RecursiveIteratorIterator($directoryIterator);
        $phpIterator = new RegexIterator($recursiveIterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

        $result = [];
        foreach ($phpIterator as $filePath => $dummy) {
            $reflection = new ReflectionClass($this->_extractClassName($filePath));
            if ($reflection->isSubclassOf(\InstagramAPI\AutoPropertyHandler::class)) {
                if ($this->_processFile($filePath, $reflection)) {
                    $result[] = $filePath;
                }
            }
        }

        return $result;
    }
}
