<?php
/*
 * This file is part of MODX Revolution.
 *
 * Copyright (c) MODX, LLC. All Rights Reserved.
 *
 * For complete copyright and license information, see the COPYRIGHT and LICENSE
 * files found in the top-level directory of this distribution.
 */

namespace MODX\Revolution;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use MODX\Revolution\Services\Container;
use MODX\Revolution\Error\modError;
use MODX\Revolution\Error\modErrorHandler;
use MODX\Revolution\Mail\modMail;
use MODX\Revolution\Processors\Processor;
use MODX\Revolution\Processors\ProcessorResponse;
use MODX\Revolution\Registry\modRegister;
use MODX\Revolution\Registry\modRegistry;
use MODX\Revolution\Smarty\modSmarty;
use MODX\Revolution\Validation\modValidator;
use PDO;
use PDOStatement;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use xPDO\Cache\xPDOFileCache;
use xPDO\xPDO;
use xPDO\xPDOException;
use xPDO\Cache\xPDOCacheManager;
use xPDO\Om\xPDOObject;

/**
 * This is the MODX gateway class.
 *
 * It can be used to interact with the MODX framework and serves as a front
 * controller for handling requests to the virtual resources managed by the MODX
 * Content Management Framework.
 *
 * @package modx
 */
class modX extends xPDO {
    /**
     * The parameter for when a session state is not able to be accessed
     * @const SESSION_STATE_UNAVAILABLE
     */
    const SESSION_STATE_UNAVAILABLE = -1;
    /**
     * The parameter for when a session has not yet been instantiated
     * @const SESSION_STATE_UNINITIALIZED
     */
    const SESSION_STATE_UNINITIALIZED = 0;
    /**
     * The parameter for when a session has been fully initialized
     * @const SESSION_STATE_INITIALIZED
     */
    const SESSION_STATE_INITIALIZED = 1;
    /**
     * The parameter marking when a session is being controlled by an external provider
     * @const SESSION_STATE_EXTERNAL
     */
    const SESSION_STATE_EXTERNAL = 2;
    /**
     * @var Container The DI services container.
     */
    public $services;
    /**
     * @var modContext The Context represents a unique section of the site which
     * this modX instance is controlling.
     */
    public $context= null;
    /**
     * @var array An array of secondary contexts loaded on demand.
     */
    public $contexts= [];
    /**
     * @var modRequest|modConnectorRequest|modManagerRequest Represents a web request and provides helper methods for
     * dealing with request parameters and other attributes of a request.
     */
    public $request= null;
    /**
     * @var modResponse|modConnectorResponse|modManagerResponse Represents a web response, providing helper methods for
     * managing response header attributes and the body containing the content of
     * the response.
     */
    public $response= null;
    /**
     * @var modParser The modParser registered for this modX instance,
     * responsible for content tag parsing, and loaded only on demand.
     */
    public $parser= null;
    /**
     * @var array A listing of site Resources and Context-specific meta data.
     */
    public $resourceListing= null;
    /**
     * @var array A hierarchy map of Resources.
     */
    public $resourceMap= null;
    /**
     * @var array A lookup listing of Resource alias values and associated
     * Resource Ids
     */
    public $aliasMap= null;
    /**
     * @var modSystemEvent The current event being handled by modX.
     */
    public $event= null;
    /**
     * @var array A map of elements registered to specific events.
     */
    public $eventMap= null;
    /**
     * @var array A map of already processed Elements.
     */
    public $elementCache= [];
    /**
     * @var array An array of key=> value pairs that can be used by any Resource
     * or Element.
     */
    public $placeholders= [];
    /**
     * @var modResource An instance of the current modResource controlling the
     * request.
     */
    public $resource= null;
    /**
     * @var string The preferred Culture key for the current request.
     */
    public $cultureKey= '';
    /**
     * @var modLexicon Represents a localized dictionary of common words and phrases.
     */
    public $lexicon= null;
    /**
     * @var modUser The current user object, if one is authenticated for the
     * current request and context.
     */
    public $user= null;
    /**
     * @var array Represents the modContentType instances that can be delivered
     * by this modX deployment.
     */
    public $contentTypes= null;
    /**
     * @var mixed The resource id or alias being requested.
     */
    public $resourceIdentifier= null;
    /**
     * @var string The method to use to locate the Resource, 'id' or 'alias'.
     */
    public $resourceMethod= null;
    /**
     * @var boolean Indicates if the resource was generated during this request.
     */
    public $resourceGenerated= false;
    /**
     * @var array Version information for this MODX deployment.
     */
    public $version= null;
    /**
     * @var string Unique site id for each MODX installation.
     */
    public $site_id;
    /**
     * @var string Unique uuid for each MODX installation.
     */
    public $uuid;
    /**
     * @var boolean Indicates if modX has been successfully initialized for a
     * modContext.
     */
    protected $_initialized= false;
    /**
     * @var array An array of javascript content to be inserted into the HEAD
     * of an HTML resource.
     */
    public $sjscripts= [];
    /**
     * @var array An array of javascript content to be inserted into the BODY
     * of an HTML resource.
     */
    public $jscripts= [];
    /**
     * @var array An array of already loaded javascript/css code
     */
    public $loadedjscripts= [];
    /**
     * @var string Stores the virtual path for a request to MODX if the
     * friendly_alias_paths option is enabled.
     */
    public $virtualDir;
    /**
     * @var modErrorHandler|object An error_handler for the modX instance.
     * @deprecated Use the service/DI container to access the `error_handler` service.
     */
    public $errorHandler= null;
    /**
     * @var modError An error response class for the request
     */
    public $error = null;
    /**
     * @var modManagerController A controller object that represents a page in the manager
     */
    public $controller = null;
    /**
     * @var modRegistry $registry
     */
    public $registry;
    /**
     * @var modMail $mail
     */
    public $mail;
    /**
     * @var modSmarty $smarty
     */
    public $smarty;
    /**
     * @var array $processors An array of loaded processors and their class name
     */
    public $processors = [];
    /**
     * @var array An array of regex patterns regulary cleansed from content.
     */
    public $sanitizePatterns = [
        'scripts'       => '@<script[^>]*?>.*?</script>@si',
        'entities'      => '@&#(\d+);@',
        'tags1'         => '@\[\[(?:(?!(\[\[|\]\])).)*\]\]@si',
        'tags2'         => '@(\[\[|\]\])@si',
    ];
    /**
     * @var integer An integer representing the session state of modX.
     */
    protected $_sessionState= modX::SESSION_STATE_UNINITIALIZED;
    /**
     * @var array A config array that stores the bootstrap settings.
     */
    protected $_config= null;
    /**
     * @var array A config array that stores the system-wide settings.
     */
    public $_systemConfig= [];
    /**
     * @var array A config array that stores the user settings.
     */
    public $_userConfig= [];
    /**
     * @var int The current log sequence
     */
    protected $_logSequence= 0;

    /**
     * @var array An array of plugins that have been cached for execution
     */
    public $pluginCache= [];
    /**
     * @var array The element source cache used for caching and preparing Element data
     */
    public $sourceCache= [
        modChunk::class => []
        ,modSnippet::class => []
        ,modTemplateVar::class => [],
    ];
    /** @var modCacheManager $cacheManager */
    public $cacheManager;

    /**
     * @deprecated
     * @var modSystemEvent $Event
     */
    public $Event= null;
    /**
     * @deprecated
     * @var string $documentOutput
     */
    public $documentOutput= null;

    /**
     * Keeps an in-memory representation of what deprecated functions have been logged
     * for this request, to avoid spamming the log too often. See the `deprecated` method.
     *
     * @var array
     */
    private $_deprecations = [];

    /**
     * Harden the environment against common security flaws.
     *
     * @static
     */
    public static function protect() {
        $targets= ['PHP_SELF', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'QUERY_STRING'];
        foreach ($targets as $target) {
            $_SERVER[$target] = isset ($_SERVER[$target]) ? htmlspecialchars($_SERVER[$target], ENT_QUOTES) : null;
        }
    }

    /**
     * Sanitize values of an array using regular expression patterns.
     *
     * @static
     * @param array $target The target array to sanitize.
     * @param array|string $patterns A regular expression pattern, or array of
     * regular expression patterns to apply to all values of the target.
     * @param integer $depth The maximum recursive depth to sanitize if the
     * target contains values that are arrays.
     * @param integer $nesting The maximum nesting level in which to dive
     * @return array The sanitized array.
     */
    public static function sanitize(array &$target, array $patterns= [], $depth= 99, $nesting= 10) {
        foreach ($target as $key => &$value) {
            if (is_array($value) && $depth > 0) {
                modX :: sanitize($value, $patterns, $depth-1);
            } elseif (is_string($value)) {
                if (!empty($patterns)) {
                    $iteration = 1;
                    $nesting = ((integer) $nesting ? (integer) $nesting : 10);
                    while ($iteration <= $nesting) {
                        $matched = false;
                        foreach ($patterns as $pattern) {
                            $patternIterator = 1;
                            $patternMatches = preg_match($pattern, $value);
                            if ($patternMatches > 0) {
                                $matched = true;
                                while ($patternMatches > 0 && $patternIterator <= $nesting) {
                                    $value= preg_replace($pattern, '', $value);
                                    $patternMatches = preg_match($pattern, $value);
                                }
                            }
                        }
                        if (!$matched) {
                            break;
                        }
                        $iteration++;
                    }
                }
                $target[$key]= $value;
            }
        }
        return $target;
    }

    /**
     * @param array|string $data The target data to sanitize.
     * @param array $replaceable
     * @return array|string The sanitized data
     */
    public static function replaceReserved($data, array $replaceable = ['[' => '&#91;', ']' => '&#93;', '`' => '&#96;'])
    {
        if (\is_array($data)) {
            $result = [];
            foreach ($data as $key => &$value) {
                $key = self::replaceReserved($key, $replaceable);
                $result[$key] = self::replaceReserved($value, $replaceable);
            }
        } elseif (\is_scalar($data)) {
            $result = \str_replace(\array_keys($replaceable), \array_values($replaceable), $data);
        } else {
            $result = '';
        }

        return $result;
    }

    /**
     * Sanitizes a string
     *
     * @param string $str The string to sanitize
     * @param array $chars An array of chars to remove
     * @param string $allowedTags A list of tags to allow.
     * @return string The sanitized string.
     */
    public function sanitizeString($str,$chars = ['/',"'",'"','(',')',';','>','<'],$allowedTags = '') {
        $str = str_replace($chars,'',strip_tags($str,$allowedTags));
        return preg_replace("/[^A-Za-z0-9_\-\.\/\\p{L}[\p{L} _.-]/u",'',$str);
    }

    /**
     * Turn an associative or numeric array into a valid query string.
     *
     * @static
     * @param array $parameters An associative or numeric-indexed array of parameters.
     * @param string $numPrefix A string prefix added to the numeric-indexed array keys.
     * Ignored if associative array is used.
     * @param string $argSeparator The string used to separate arguments in the resulting query string.
     * @return string A valid query string representing the parameters.
     */
    public static function toQueryString(array $parameters = [], $numPrefix = '', $argSeparator = '&') {
        return http_build_query($parameters, $numPrefix, $argSeparator);
    }

    /**
     * Create, retrieve, or update specific modX instances.
     *
     * @static
     * @param string|int|null $id An optional identifier for the instance. If not set
     * a uniqid will be generated and used as the key for the instance.
     * @param array|null $config An optional array of config data for the instance.
     * @param bool $forceNew If true a new instance will be created even if an instance
     * with the provided $id already exists in modX::$instances.
     * @return static An instance of modX.
     * @throws xPDOException
     */
    public static function getInstance($id = null, $config = null, $forceNew = false) {
        $class = static::class;
        if (is_null($id)) {
            if (!is_null($config) || $forceNew || empty(self::$instances)) {
                $id = uniqid($class);
            } else {
                $instances =& self::$instances;
                $id = key($instances);
            }
        }
        if ($forceNew || !array_key_exists($id, self::$instances) || !(self::$instances[$id] instanceof $class)) {
            self::$instances[$id] = new $class('', $config);
        } elseif (self::$instances[$id] instanceof $class && is_array($config)) {
            self::$instances[$id]->config = array_merge(self::$instances[$id]->config, $config);
        }
        if (!(self::$instances[$id] instanceof $class)) {
            throw new xPDOException("Error getting {$class} instance, id = {$id}");
        }
        return self::$instances[$id];
    }

    /**
     * Construct a new modX instance.
     *
     * @param string $configPath An absolute filesystem path to look for the config file.
     * @param array $options xPDO options that can be passed to the instance.
     * @param array $driverOptions PDO driver options that can be passed to the instance.
     */
    public function __construct($configPath= '', $options = null, $driverOptions = null) {
        try {
            $options = $this->loadConfig($configPath, $options, $driverOptions);
            parent :: __construct(
                null,
                null,
                null,
                $options,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT)
            );
            $this->setLogLevel($this->getOption('log_level', null, xPDO::LOG_LEVEL_ERROR));
            $this->setLogTarget($this->getOption('log_target', null, 'FILE'));
            $debug = $this->getOption('debug');
            if (!is_null($debug) && $debug !== '') {
                $this->setDebug($debug);
            }
            $this->setPackage('MODX\Revolution', MODX_CORE_PATH . 'src/', null, 'MODX\\');
            $this->addPackage('MODX\Revolution\Registry\Db', MODX_CORE_PATH . 'src/', null, 'MODX\\');
            $this->addPackage('MODX\Revolution\Sources', MODX_CORE_PATH . 'src/', null, 'MODX\\');
            $this->addPackage('MODX\Revolution\Transport', MODX_CORE_PATH . 'src/', null, 'MODX\\');
        } catch (xPDOException $xe) {
            $this->sendError('unavailable', ['error_message' => $xe->getMessage()]);
        } catch (Exception $e) {
            $this->sendError('unavailable', ['error_message' => $e->getMessage()]);
        }
    }

    /**
     * Load the modX configuration when creating an instance of modX.
     *
     * @param string $configPath    An absolute path location to search for the modX config file.
     * @param array  $data          Data provided to initialize the instance with, overriding config file entries.
     * @param array   $driverOptions Driver options for the primary connection.
     *
     * @return Container A DI container containing the config data ready for use by the modX::__construct() method.
     * @throws xPDOException
     */
    protected function loadConfig($configPath = '', $data = [], $driverOptions = array()) {
        if (!is_array($data)) $data = [];
        modX :: protect();
        if (!defined('MODX_CONFIG_KEY')) {
            define('MODX_CONFIG_KEY', 'config');
        }
        if (empty ($configPath)) {
            $configPath= MODX_CORE_PATH . 'config/';
        }
        global $database_dsn, $database_user, $database_password, $config_options, $driver_options, $table_prefix, $site_id, $uuid;
        if (file_exists($configPath . MODX_CONFIG_KEY . '.inc.php') && include ($configPath . MODX_CONFIG_KEY . '.inc.php')) {
            $cachePath= MODX_CORE_PATH . 'cache/';
            if (MODX_CONFIG_KEY !== 'config') $cachePath .= MODX_CONFIG_KEY . '/';
            if (!is_array($config_options)) $config_options = [];
            if (!is_array($driver_options)) $driver_options = [];
            if (!is_array($driverOptions)) $driverOptions = [];
            $driver_options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT) + $driverOptions + $driver_options;
            $data = array_merge(
                [
                    xPDO::OPT_CACHE_KEY => 'default',
                    xPDO::OPT_CACHE_HANDLER => xPDOFileCache::class,
                    xPDO::OPT_CACHE_PATH => $cachePath,
                    xPDO::OPT_TABLE_PREFIX => $table_prefix,
                    xPDO::OPT_HYDRATE_FIELDS => true,
                    xPDO::OPT_HYDRATE_RELATED_OBJECTS => true,
                    xPDO::OPT_HYDRATE_ADHOC_FIELDS => true,
                    xPDO::OPT_VALIDATOR_CLASS => modValidator::class,
                    xPDO::OPT_VALIDATE_ON_SAVE => true,
                    'cache_system_settings' => true,
                    'cache_system_settings_key' => 'system_settings',
                    'load_deprecated_global_class_aliases' => true,
                ],
                $config_options,
                $data
            );
            $primaryConnection = [
                'dsn' => $database_dsn,
                'username' => $database_user,
                'password' => $database_password,
                'options' => [
                    xPDO::OPT_CONN_MUTABLE => isset($data[xPDO::OPT_CONN_MUTABLE]) ? (boolean) $data[xPDO::OPT_CONN_MUTABLE] : true,
                ],
                'driverOptions' => $driver_options,
            ];
            if (!array_key_exists(xPDO::OPT_CONNECTIONS, $data) || !is_array($data[xPDO::OPT_CONNECTIONS])) {
                $data[xPDO::OPT_CONNECTIONS] = [];
            }
            array_unshift($data[xPDO::OPT_CONNECTIONS], $primaryConnection);
            if (!empty($site_id)) $this->site_id = $site_id;
            if (!empty($uuid)) $this->uuid = $uuid;
            if ((boolean)$data['load_deprecated_global_class_aliases']) {
                include_once MODX_CORE_PATH . 'include/deprecated.php';
            }

            $container = new Container();
            $container['config'] = $data;

            return $container;
        } else {
            throw new xPDOException("Could not load MODX config file.");
        }
    }

    /**
     * Initializes the modX engine.
     *
     * This includes preparing the session, pre-loading some common
     * classes and objects, the current site and context settings, extension
     * packages used to override session handling, error handling, or other
     * initialization classes
     *
     * @param string $contextKey Indicates the context to initialize.
     * @param array|null $options An array of options for the initialization.
     * @return bool True if initialized successfully, or already initialized.
     */
    public function initialize($contextKey= 'web', $options = null) {
        if (!$this->_initialized) {
            if (!$this->startTime) {
                $this->startTime= microtime(true);
            }

            $this->getCacheManager();
            $this->getConfig();
            if (!$this->getOption(xPDO::OPT_SETUP)) {
                $this->_initNamespaces();
                $this->_initContext($contextKey, false, $options);
                $this->_loadExtensionPackages($options);
            }
            $this->_initSession($options);
            $this->_initErrorHandler($options);
            $this->_initHttpClient();
            $this->_initCulture($options);

            $this->services->add('registry', new modRegistry($this));
            $this->registry = $this->services->get('registry');

            if (!$this->getOption(xPDO::OPT_SETUP)) {
                $this->invokeEvent(
                    'OnMODXInit',
                    [
                        'contextKey' => $contextKey,
                        'options' => $options,
                    ]
                );
            }

            if (is_array ($this->config)) {
                $c = $this->config;
                if ((bool)$this->getOption('filter_config_placeholders', null, true)) {
                    unset($c['password'], $c['username'], $c['mail_smtp_pass'], $c['mail_smtp_user'], $c['proxy_password'], $c['proxy_username'], $c['connections'], $c['connection_init'], $c['connection_mutable'], $c['dbname'], $c['database'], $c['table_prefix'], $c['driverOptions'], $c['dsn'], $c['session_name'], $c['cache_path'], $c['connectors_path'], $c['friendly_alias_translit_class_path'], $c['manager_path'], $c['processors_path']);
                }
                $this->setPlaceholders($c, '+');
            }

            $this->_initialized= true;
        }
        return $this->_initialized;
    }

    /**
     * Initialize namespaces.
     */
    protected function _initNamespaces() {
        $namespaces = $this->call(modNamespace::class, 'loadCache', [&$this]);
        $modx =& $this;
        foreach ($namespaces as $namespace) {
            if (is_readable($namespace['path'] . 'bootstrap.php')) {
                require $namespace['path'] . 'bootstrap.php';
            }
        }
    }

    /**
     * Loads any extension packages.
     *
     * @param array|null An optional array of options that can contain additional
     * extension packages which will be merged with those specified via config.
     */
    protected function _loadExtensionPackages($options = null) {
        $cache = $this->call(modExtensionPackage::class, 'loadCache', [&$this]);
        if (!empty($cache)) {
            foreach ($cache as $package) {
                $package['table_prefix'] = isset($package['table_prefix']) ? $package['table_prefix'] : null;
                $this->addPackage($package['namespace'],$package['path'],$package['table_prefix']);
                if (!empty($package['service_name']) && !empty($package['service_class'])) {
                    $this->getService($package['service_name'],$package['service_class'],$package['path']);
                }
            }
        }
        $this->_loadExtensionPackagesDeprecated($options);
    }

    /**
     * Load system-setting based extension packages. This is not recommended; use modExtensionPackage from 2.3 onward.
     * The System Setting will be automatically removed in 2.4/3.0 and no longer functional.
     *
     * @deprecated To be removed in 2.4/3.0
     * @param null $options
     */
    protected function _loadExtensionPackagesDeprecated($options = null) {
        $extPackages = $this->getOption('extension_packages');
        $extPackages = $this->fromJSON($extPackages);
        if (!is_array($extPackages)) $extPackages = [];
        if (is_array($options) && array_key_exists('extension_packages', $options)) {
            $optPackages = $this->fromJSON($options['extension_packages']);
            if (is_array($optPackages)) {
                $extPackages = array_merge($extPackages, $optPackages);
            }
        }

        if (!empty($extPackages)) {
            foreach ($extPackages as $extPackage) {
                if (!is_array($extPackage)) continue;

                foreach ($extPackage as $packageName => $package) {
                    if (!empty($package) && !empty($package['path'])) {
                        $package['tablePrefix'] = isset($package['tablePrefix']) ? $package['tablePrefix'] : null;
                        $package['path'] = str_replace(
                            [
                            '[[++core_path]]',
                            '[[++base_path]]',
                            '[[++assets_path]]',
                            '[[++manager_path]]',
                            ],
                            [
                            $this->config['core_path'],
                            $this->config['base_path'],
                            $this->config['assets_path'],
                            $this->config['manager_path'],
                            ], $package['path']);
                        $this->addPackage($packageName,$package['path'],$package['tablePrefix']);
                        if (!empty($package['serviceName']) && !empty($package['serviceClass'])) {
                            $packagePath = str_replace('//','/',$package['path'].$packageName.'/');
                            $this->getService($package['serviceName'],$package['serviceClass'],$packagePath);
                        }
                    }
                }
            }
        }
    }


    /**
     * Sets the debugging features of the modX instance.
     *
     * @param boolean|int $debug Boolean or bitwise integer describing the
     * debug state and/or PHP error reporting level.
     *
     * @return boolean|int The previous value.
     *
     * @info PHP errors are handle by modErrorHandler with at most LOG_LEVEL_INFO
     *       When called by modX $debug is a string (ie $this->getOption('debug'))
     *
     *          (bool)true , (string)true , (string)-1 -> LOG_LEVEL_DEBUG (MODX), E_ALL | E_STRICT (PHP)
     *          (bool)false, (string)false, (string) 0 -> LOG_LEVEL_ERROR (MODX), 0                (PHP)
     *          (int)nnn                               -> LOG_LEVEL_INFO  (MODX), nnn              (PHP)
     *          (string)E_XXX                          -> LOG_LEVEL_INFO  (MODX), E_XXX            (PHP)
     */
    public function setDebug($debug= true) {
        $oldValue= $this->getDebug();
        if (($debug === true) || ('true' === $debug) || ('-1' === $debug)) {
            error_reporting(-1);
            parent :: setDebug(true);
        }
        else {
            if (($debug === false) || ('false' === $debug) || ('0' === $debug)) {
                error_reporting(0);
                parent :: setDebug(false);
            }
            else {
                $debug = (is_int($debug) ? $debug : (defined($debug) ? intval(constant($debug)) : 0));
                if ($debug) {
                    error_reporting($debug);
                    parent :: setLogLevel(xPDO::LOG_LEVEL_INFO);
                }
            }
        }
        return $oldValue;
    }

    /**
     * Get an extended xPDOCacheManager instance responsible for MODX caching.
     *
     * @param string $class The class name of the cache manager to load
     * @param array $options An array of options to send to the cache manager instance
     * @return modCacheManager A modCacheManager instance registered for this modX instance.
     */
    public function getCacheManager($class= 'xPDO\\Cache\\xPDOCacheManager', $options = ['path' => XPDO_CORE_PATH, 'ignorePkg' => true]
    ) {
        if ($this->cacheManager === null) {
            $className = $this->getOption('modCacheManager.class', null, modCacheManager::class);
            if ($this->cacheManager = new $className($this)) {
                $this->_cacheEnabled = true;
            }
        }
        return $this->cacheManager;
    }

    /**
     * Gets the MODX parser.
     *
     * Returns an instance of modParser responsible for parsing tags in element
     * content, performing actions, returning content and/or sending other responses
     * in the process.
     *
     * @return modParser The modParser for this modX instance.
     */
    public function getParser() {
        if (!$this->services->has('parser')) {
            $parserClass = $this->getOption('modParser.class', null, modParser::class);
            $this->services->add('parser', new $parserClass($this));
        }
        if (!$this->parser instanceof modParser) {
            $this->parser = $this->services->get('parser');
        }

        return $this->parser;
    }

    /**
     * Gets all of the parent resource ids for a given resource.
     *
     * @param integer $id The resource id for the starting node.
     * @param integer $height How many levels max to search for parents (default 10).
     * @param array $options An array of filtering options, such as 'context' to specify the context to grab from
     * @return array An array of all the parent resource ids for the specified resource.
     */
    public function getParentIds($id= null, $height= 10,array $options = []) {
        $parentId= 0;
        $parents= [];
        if ($id && $height > 0) {

            $context = '';
            if (!empty($options['context'])) {
                $this->getContext($options['context']);
                $context = $options['context'];
            }
            $resourceMap = !empty($context) && !empty($this->contexts[$context]->resourceMap) ? $this->contexts[$context]->resourceMap : $this->resourceMap;

            foreach ($resourceMap as $parentId => $mapNode) {
                if (array_search($id, $mapNode) !== false) {
                    $parents[]= $parentId;
                    break;
                }
            }
            if ($parentId && !empty($parents)) {
                $height--;
                $parents= array_merge($parents, $this->getParentIds($parentId,$height,$options));
            }
        }
        return $parents;
    }

    /**
     * Gets all of the child resource ids for a given resource.
     *
     * @see getTree for hierarchical node results
     * @param integer $id The resource id for the starting node.
     * @param integer $depth How many levels max to search for children (default 10).
     * @param array $options An array of filtering options, such as 'context' to specify the context to grab from
     * @return array An array of all the child resource ids for the specified resource.
     */
    public function getChildIds($id= null, $depth= 10,array $options = []) {
        $children= [];
        if ($id !== null && intval($depth) >= 1) {
            $id= is_int($id) ? $id : intval($id);

            $context = '';
            if (!empty($options['context'])) {
                $this->getContext($options['context']);
                $context = $options['context'];
            }
            $resourceMap = !empty($context) && !empty($this->contexts[$context]->resourceMap) ? $this->contexts[$context]->resourceMap : $this->resourceMap;

            if (isset ($resourceMap["{$id}"])) {
                if ($children= $resourceMap["{$id}"]) {
                    foreach ($children as $child) {
                        if ($c = $this->getChildIds($child, $depth - 1, $options)) {
                            $children = array_merge($children, $c);
                        }
                    }
                }
            }
        }
        return $children;
    }

    /**
     * Get a site tree from a single or multiple modResource instances.
     *
     * @see getChildIds for linear results
     * @param int|array $id A single or multiple modResource ids to build the
     * tree from.
     * @param int $depth The maximum depth to build the tree (default 10).
     * @param array $options An array of filtering options, such as 'context' to specify the context to grab from
     * @return array An array containing the tree structure.
     */
    public function getTree($id= null, $depth= 10, array $options = []) {
        $tree= [];
        if (!empty($options['context'])) {
            $this->getContext($options['context']);
        }
        if ($id !== null) {
            if (is_array ($id)) {
                foreach ($id as $k => $v) {
                    $tree[$v] = $this->getTree($v, $depth - 1, $options);
                }
            } elseif ($branch= $this->getChildIds($id, 1, $options)) {
                foreach ($branch as $key => $child) {
                    if ($depth > 0 && $leaf = $this->getTree($child, $depth - 1, $options)) {
                        $tree[$child]= $leaf;
                    } else {
                        $tree[$child]= $child;
                    }
                }
            }
        }
        return $tree;
    }

    /**
     * Sets a placeholder value.
     *
     * @param string $key The unique string key which identifies the
     * placeholder.
     * @param mixed $value The value to set the placeholder to.
     */
    public function setPlaceholder($key, $value) {
        if (is_string($key)) {
            $this->placeholders["{$key}"]= $value;
        }
    }

    /**
     * Sets a collection of placeholders stored in an array or as object vars.
     *
     * An optional namespace parameter can be prepended to each placeholder key in the collection,
     * to isolate the collection of placeholders.
     *
     * Note that unlike toPlaceholders(), this function does not add separators between the
     * namespace and the placeholder key. Use toPlaceholders() when working with multi-dimensional
     * arrays or objects with variables other than scalars so each level gets delimited by a
     * separator.
     *
     * @param array|object $placeholders An array of values or object to set as placeholders.
     * @param string $namespace A namespace prefix to prepend to each placeholder key.
     */
    public function setPlaceholders($placeholders, $namespace= '') {
        $this->toPlaceholders($placeholders, $namespace, '');
    }

    /**
     * Sets placeholders from values stored in arrays and objects.
     *
     * Each recursive level adds to the prefix, building an access path using an optional separator.
     *
     * @param array|object $subject An array or object to process.
     * @param string $prefix An optional prefix to be prepended to the placeholder keys. Recursive
     * calls prepend the parent keys.
     * @param string $separator A separator to place in between the prefixes and keys. Default is a
     * dot or period: '.'.
     * @param boolean $restore Set to true if you want overwritten placeholder values returned.
     * @return array A multi-dimensional array containing up to two elements: 'keys' which always
     * contains an array of placeholder keys that were set, and optionally, if the restore parameter
     * is true, 'restore' containing an array of placeholder values that were overwritten by the method.
     */
    public function toPlaceholders($subject, $prefix= '', $separator= '.', $restore= false) {
        $keys = [];
        $restored = [];
        if (is_object($subject)) {
            if ($subject instanceof xPDOObject) {
                $subject= $subject->toArray();
            } else {
                $subject= get_object_vars($subject);
            }
        }
        if (is_array($subject)) {
            foreach ($subject as $key => $value) {
                $rv = $this->toPlaceholder($key, $value, $prefix, $separator, $restore);
                if (isset($rv['keys'])) {
                    foreach ($rv['keys'] as $rvKey) $keys[] = $rvKey;
                }
                if ($restore === true && isset($rv['restore'])) {
                    $restored = array_merge($restored, $rv['restore']);
                }
            }
        }
        $return = ['keys' => $keys];
        if ($restore === true) $return['restore'] = $restored;
        return $return;
    }

    /**
     * Recursively validates and sets placeholders appropriate to the value type passed.
     *
     * @param string $key The key identifying the value.
     * @param mixed $value The value to set.
     * @param string $prefix A string prefix to prepend to the key. Recursive calls prepend the
     * parent keys as well.
     * @param string $separator A separator placed in between the prefix and the key. Default is a
     * dot or period: '.'.
     * @param boolean $restore Set to true if you want overwritten placeholder values returned.
     * @return array A multi-dimensional array containing up to two elements: 'keys' which always
     * contains an array of placeholder keys that were set, and optionally, if the restore parameter
     * is true, 'restore' containing an array of placeholder values that were overwritten by the method.
     */
    public function toPlaceholder($key, $value, $prefix= '', $separator= '.', $restore= false) {
        $return = ['keys' => []];
        if ($restore === true) $return['restore'] = [];
        if (!empty($prefix) && !empty($separator)) {
            $prefix .= $separator;
        }
        if (is_array($value) || is_object($value)) {
            $return = $this->toPlaceholders($value, "{$prefix}{$key}", $separator, $restore);
        } elseif (is_scalar($value)) {
            $return['keys'][] = "{$prefix}{$key}";
            if ($restore === true && array_key_exists("{$prefix}{$key}", $this->placeholders)) {
                $return['restore']["{$prefix}{$key}"] = $this->getPlaceholder("{$prefix}{$key}");
            }
            $this->setPlaceholder("{$prefix}{$key}", $value);
        }
        return $return;
    }

    /**
     * Get a placeholder value by key.
     *
     * @param string $key The key of the placeholder to a return a value from.
     * @return mixed The value of the requested placeholder, or an empty string if not located.
     */
    public function getPlaceholder($key) {
        $placeholder= null;
        if (is_string($key) && array_key_exists($key, $this->placeholders)) {
            $placeholder= & $this->placeholders["{$key}"];
        }
        return $placeholder;
    }

    /**
     * Unset a placeholder value by key.
     *
     * @param string $key The key of the placeholder to unset.
     */
    public function unsetPlaceholder($key) {
        if (is_string($key) && array_key_exists($key, $this->placeholders)) {
            unset($this->placeholders[$key]);
        }
    }

    /**
     * Unset multiple placeholders, either by prefix or an array of keys.
     *
     * @param string|array $keys A string prefix or an array of keys indicating
     * the placeholders to unset.
     */
    public function unsetPlaceholders($keys) {
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key)) $this->unsetPlaceholder($key);
                if (is_array($key)) $this->unsetPlaceholders($key);
            }
        } elseif (is_string($keys)) {
            $placeholderKeys = array_keys($this->placeholders);
            foreach ($placeholderKeys as $key) {
                if (strpos($key, $keys) === 0) $this->unsetPlaceholder($key);
            }
        }
    }

    /**
     * Returns the full table name (with dynamic prefix) based on database settings.
     * Legacy - Useful when dealing with migrations or prefixed database tables without an xPDO model (which xPDO.getTableName requires.)
     *
     * @param string $table Name of MODX table, less table prefix.
     * @return string Full table name containing database and table prefix.
     */
    public function getFullTableName( $table = '' ) {
        return $this->getOption('dbname') .".". $this->getOption( xPDO::OPT_TABLE_PREFIX ) . $table;
    }

    /**
     * Generates a URL representing a specified resource.
     *
     * @param integer $id The id of a resource.
     * @param string $context Specifies a context to limit URL generation to.
     * @param string $args A query string to append to the generated URL.
     * @param mixed $scheme The scheme indicates in what format the URL is generated.<br>
     * <pre>
     *      -1 : (default value) URL is relative to site_url
     *       0 : see http
     *       1 : see https
     *    full : URL is absolute, prepended with site_url from config
     *     abs : URL is absolute, prepended with base_url from config
     *    http : URL is absolute, forced to http scheme
     *   https : URL is absolute, forced to https scheme
     * </pre>
     * @param array $options An array of options for generating the Resource URL.
     * @return string The URL for the resource.
     */
    public function makeUrl($id, $context= '', $args= '', $scheme= -1, array $options= []) {
        $url= '';
        if ($validid = intval($id)) {
            $id = $validid;
            if ($context == '' || $this->context->get('key') == $context) {
                $url= $this->context->makeUrl($id, $args, $scheme, $options);
            }
            if (empty($url) && ($context !== $this->context->get('key'))) {
                $ctx= null;
                if ($context == '') {
                    /** @var PDOStatement $stmt  */
                    if ($stmt = $this->prepare("SELECT context_key FROM " . $this->getTableName(modResource::class) . " WHERE id = :id")) {
                        $stmt->bindValue(':id', $id);
                        if ($contextKey = $this->getValue($stmt)) {
                            $ctx = $this->getContext($contextKey);
                        }
                    }
                } else {
                    $ctx = $this->getContext($context);
                }
                if ($ctx) {
                    $url= $ctx->makeUrl($id, $args, 'full', $options);
                }
            }

            if (!empty($url) && $this->getOption('xhtml_urls', $options, false)) {
                $url= preg_replace("/&(?!amp;)/","&amp;", $url);
            }
        } else {
            $this->log(modX::LOG_LEVEL_ERROR, '`' . $id . '` is not a valid integer and may not be passed to makeUrl()');
        }
        return $url;
    }

    /**
     * Filter a string for use as a URL path segment.
     *
     * @param string $string The string to filter into a valid path segment.
     * @param array $options Optional filter setting overrides.
     *
     * @return string|null A valid path segment string or null if an error occurs.
     */
    public function filterPathSegment($string, array $options = []) {
        return $this->call(modResource::class, 'filterPathSegment', [&$this, $string, $options]);
    }

    public function findResource($uri, $context = '') {
        $resourceId = false;
        if (empty($context) && isset($this->context)) $context = $this->context->get('key');
        if (!empty($context) && (!empty($uri) || $uri === '0')) {
            $useAliasMap = (boolean) $this->getOption('cache_alias_map', null, false);
            if ($useAliasMap) {
                if (isset($this->context) && $this->context->get('key') === $context && is_array($this->aliasMap) && array_key_exists($uri, $this->aliasMap)) {
                    $resourceId = (integer) $this->aliasMap[$uri];
                } elseif ($ctx = $this->getContext($context)) {
                    $useAliasMap = $ctx->getOption('cache_alias_map', false) && is_array($ctx->aliasMap) && array_key_exists($uri, $ctx->aliasMap);
                    if ($useAliasMap && array_key_exists($uri, $ctx->aliasMap)) {
                        $resourceId = (integer) $ctx->aliasMap[$uri];
                    }
                }
            }
            if (!$resourceId && !$useAliasMap) {
                $query = $this->newQuery(modResource::class, ['context_key' => $context, 'uri' => $uri, 'deleted' => false]
                );
                $query->select($this->getSelectColumns(modResource::class, '', '', ['id']));
                $stmt = $query->prepare();
                if ($stmt) {
                    $value = $this->getValue($stmt);
                    if ($value) {
                        $resourceId = $value;
                    }
                }
            }
        }
        return $resourceId;
    }

    /**
     * Send the user to a type-specific core error page and halt PHP execution.
     *
     * @param string $type The type of error to present.
     * @param array $options An array of options to provide for the error file.
     */
    public function sendError($type = '', $options = []) {
        if (!is_string($type) || empty($type)) $type = $this->getOption('error_type', $options, 'unavailable');
        while (ob_get_level() && @ob_end_clean()) {}
        if (!XPDO_CLI_MODE) {
            $errorPageTitle = $this->getOption('error_pagetitle', $options, 'Error 503: Service temporarily unavailable');
            $errorMessage = $this->getOption('error_message', $options, '<p>Site temporarily unavailable.</p>');
            $errorHeader = $this->getOption('error_header', $options, $_SERVER['SERVER_PROTOCOL'] . ' 503 Service Unavailable');
            if (file_exists(MODX_CORE_PATH . "error/{$type}.include.php")) {
                @include(MODX_CORE_PATH . "error/{$type}.include.php");
            }
            header($errorHeader);
            echo "<html><head><title>{$errorPageTitle}</title></head><body>{$errorMessage}</body></html>";
            @session_write_close();
        } else {
            echo ucfirst($type) . "\n";
            echo $this->getOption('error_message', $options, 'Service temporarily unavailable') . "\n";
        }
        exit();
    }

    /**
     * Sends a redirect to the specified URL using the specified options.
     *
     * Valid 'type' option values include:
     *    REDIRECT_REFRESH  Uses the header refresh method
     *    REDIRECT_META  Sends a a META HTTP-EQUIV="Refresh" tag to the output
     *    REDIRECT_HEADER  Uses the header location method
     *
     * REDIRECT_HEADER is the default.
     *
     * @param string $url The URL to redirect the client browser to.
     * @param array|boolean $options An array of options for the redirect OR
     * indicates if redirect attempts should be counted and limited to 3 (latter is deprecated
     * usage; use count_attempts in options array).
     * @param string $type The type of redirection to attempt (deprecated, use type in
     * options array).
     * @param string $responseCode The type of HTTP response code HEADER to send for the
     * redirect (deprecated, use responseCode in options array)
     */
    public function sendRedirect($url, $options= false, $type= '', $responseCode = '') {
        if (!$this->getResponse()) {
            $this->log(modX::LOG_LEVEL_FATAL, "Could not load response class.");
        }
        if (!is_array($options)) {
            $options = ['count_attempts' => (boolean) $options];
        }
        if ($type) {
            $this->deprecated('2.0.5', 'Use type in options array instead.', 'sendRedirect method parameter $type');
            $options['type'] = $type;
            $type = '';
        }
        if ($responseCode) {
            $this->deprecated('2.0.5', 'Use responseCode in options array instead.', 'sendRedirect method parameter $responseCode');
            $options['responseCode'] = $responseCode;
            $responseCode = '';
        }
        $this->response->sendRedirect($url, $options, $type, $responseCode);
    }

    /**
     * Forwards the request to another resource without changing the URL.
     *
     * @param integer $id The resource identifier.
     * @param string $options An array of options for the process.
     * @param boolean $sendErrorPage Whether we should skip the sendErrorPage if the resource does not exist.
     */
    public function sendForward($id, $options = null, $sendErrorPage = true) {
        if (!$this->getRequest()) {
            $this->log(modX::LOG_LEVEL_FATAL, "Could not load request class.");
        }
        $idInt = intval($id);
        if (is_string($options) && !empty($options)) {
            $options = ['response_code' => $options];
        } elseif (!is_array($options)) {
            $options = [];
        }
        $this->elementCache = [];
        if ($idInt > 0) {
            $merge = array_key_exists('merge', $options) && !empty($options['merge']);
            $currentResource = [];
            if ($merge) {
                $excludes = array_merge(
                    explode(',', $this->getOption('forward_merge_excludes', $options, 'type,published,class_key')),
                    [
                        'content'
                        ,'pub_date'
                        ,'unpub_date'
                        ,'richtext'
                        ,'_content'
                        ,'_processed',
                    ]
                );
                if (!empty($this->resource->_fields)) {
                    foreach ($this->resource->_fields as $fkey => $fval) {
                        if (!in_array($fkey, $excludes)) {
                            if (is_scalar($fval) && $fval !== '') {
                                $currentResource[$fkey] = $fval;
                            } elseif (is_array($fval) && count($fval) === 5 && $fval[1] !== '') {
                                $currentResource[$fkey] = $fval;
                            }
                        }
                    }
                }
            }
            $this->resource= $this->request->getResource('id', $idInt, ['forward' => true]);
            if ($this->resource) {
                if ($merge && !empty($currentResource)) {
                    $this->resource->_fields = array_merge($this->resource->_fields, $currentResource);
                    $this->elementCache = [];
                    unset($currentResource);
                }
                $this->resourceIdentifier= $this->resource->get('id');
                $this->resourceMethod= 'id';
                if (isset($options['response_code']) && !empty($options['response_code'])) {
                    header($options['response_code']);
                }
                $this->request->prepareResponse();
                exit();
            } elseif ($sendErrorPage) {
                $this->sendErrorPage();
            }
            $options= array_merge(
                [
                    'error_type' => '404'
                    ,'error_header' => $this->getOption('error_page_header', $options,$_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found')
                    ,'error_pagetitle' => $this->getOption('error_page_pagetitle', $options,'Error 404: Page not found')
                    ,'error_message' => $this->getOption('error_page_message', $options,'<h1>Page not found</h1><p>The page you requested was not found.</p>'),
                ],
                $options
            );
        }
        $this->sendError($id, $options);
    }

    /**
     * Send the user to a MODX virtual error page.
     *
     * @uses invokeEvent() The OnPageNotFound event is invoked before the error page is forwarded
     * to.
     * @param array $options An array of options to provide for the OnPageNotFound event and error
     * page.
     */
    public function sendErrorPage($options = null) {
        if (!is_array($options)) $options = [];
        $options= array_merge(
            [
                'response_code' => $this->getOption('error_page_header', $options, $_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found')
                ,'error_type' => '404'
                ,'error_header' => $this->getOption('error_page_header', $options, $_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found')
                ,'error_pagetitle' => $this->getOption('error_page_pagetitle', $options, 'Error 404: Page not found')
                ,'error_message' => $this->getOption('error_page_message', $options, '<h1>Page not found</h1><p>The page you requested was not found.</p>'),
            ],
            $options
        );
        $this->invokeEvent('OnPageNotFound', $options);
        $this->sendForward($this->getOption('error_page', $options, $this->getOption('site_start')), $options, false);
    }

    /**
     * Send the user to the MODX unauthorized page.
     *
     * @uses invokeEvent() The OnPageUnauthorized event is invoked before the unauthorized page is
     * forwarded to.
     * @param array $options An array of options to provide for the OnPageUnauthorized
     * event and unauthorized page.
     */
    public function sendUnauthorizedPage($options = null) {
        if (!is_array($options)) $options = [];
        $options= array_merge(
            [
                'response_code' => $this->getOption('unauthorized_page_header' ,$options ,$_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized')
                ,'error_type' => '401'
                ,'error_header' => $this->getOption('unauthorized_page_header', $options,$_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized')
                ,'error_pagetitle' => $this->getOption('unauthorized_page_pagetitle',$options, 'Error 401: Unauthorized')
                ,'error_message' => $this->getOption('unauthorized_page_message', $options,'<h1>Unauthorized</h1><p>You are not authorized to view the requested content.</p>'),
            ],
            $options
        );
        $this->invokeEvent('OnPageUnauthorized', $options);
        $this->sendForward($this->getOption('unauthorized_page', $options, $this->getOption('site_start')), $options);
    }

    /**
     * Get the current authenticated User and assign it to the modX instance.
     *
     * @param string $contextKey An optional context to get the user from.
     * @param boolean $forceLoadSettings If set to true, will load settings
     * regardless of whether the user has an authenticated context or not.
     * @return modUser The user object authenticated for the request.
     */
    public function getUser($contextKey= '',$forceLoadSettings = false) {
        if ($contextKey == '') {
            if ($this->context !== null) {
                $contextKey= $this->context->get('key');
            }
        }
        if ($this->user === null || !is_object($this->user)) {
            $this->user= $this->getAuthenticatedUser($contextKey);
            if ($contextKey !== 'mgr' && !$this->user) {
                $this->user= $this->getAuthenticatedUser('mgr');
            }
        }
        if ($this->user !== null && is_object($this->user)) {
            if ($this->user->hasSessionContext($contextKey) || $forceLoadSettings) {
                if (!$forceLoadSettings && isset ($_SESSION["modx.{$contextKey}.user.config"])) {
                    $this->_userConfig= $_SESSION["modx.{$contextKey}.user.config"];
                } else {
                    $this->_userConfig= [];
                    $settings= $this->user->getSettings();
                    if (is_array($settings) && !empty ($settings)) {
                        foreach ($settings as $k => $v) {
                            $matches= [];
                            if (preg_match_all('~\{(.*?)\}~', $v, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $match) {
                                    if (isset($this->_userConfig["{$match[1]}"])) {
                                        $matchValue= $this->_userConfig["{$match[1]}"];
                                    } elseif (isset($this->config["{$match[1]}"])) {
                                        $matchValue= $this->config["{$match[1]}"];
                                    } else {
                                        $matchValue= '';
                                    }
                                    $v= str_replace($match[0], $matchValue, $v);
                                }
                            }
                            $this->_userConfig[$k]= $v;
                        }
                    }
                    $_SESSION["modx.{$contextKey}.user.config"]= $this->_userConfig;
                }
                if (is_array($this->_userConfig) && !empty($this->_userConfig)) {
                    $this->config= array_merge($this->config, $this->_userConfig);
                }
            }
        } else {
            $this->user = $this->newObject(modUser::class);
            $this->user->fromArray(
                [
                'id' => 0,
                'username' => $this->getOption('default_username','','(anonymous)',true),
                ], '', true);
        }
        ksort($this->config);
        $this->toPlaceholders($this->user->get(['id','username']), 'modx.user');
        return $this->user;
    }

    /**
     * Gets the user authenticated in the specified context.
     *
     * @param string $contextKey Optional context key; uses current context by default.
     * @return modUser|null The user object that is authenticated in the specified context,
     * or null if no user is authenticated.
     */
    public function getAuthenticatedUser($contextKey= '') {
        $user= null;
        if ($contextKey == '') {
            if ($this->context !== null) {
                $contextKey= $this->context->get('key');
            }
        }
        if ($contextKey && isset ($_SESSION['modx.user.contextTokens'][$contextKey])) {
            /** @var modUser $user */
            $user= $this->getObject(modUser::class, intval($_SESSION['modx.user.contextTokens'][$contextKey]), true);
            if ($user) {
                $user->getSessionContexts();
            }
        }
        return $user;
    }

    /**
     * Checks to see if the user has a session in the specified context.
     *
     * @param string $sessionContext The context to test for a session key in.
     * @return boolean True if the user is valid in the context specified.
     */
    public function checkSession($sessionContext= 'web') {
        $hasSession = false;
        if ($this->user !== null) {
            $hasSession = $this->user->hasSessionContext($sessionContext);
        }
        return $hasSession;
    }

    /**
     * Gets the modX core version data.
     *
     * @return array The version data loaded from the config version file.
     */
    public function getVersionData() {
        if ($this->version === null) {
            $this->version= @ include_once MODX_CORE_PATH . "docs/version.inc.php";
        }
        return $this->version;
    }

    /**
     * Reload the config settings.
     *
     * @return array An associative array of configuration key/values
     */
    public function reloadConfig() {
        $this->getCacheManager();
        $this->cacheManager->refresh();

        if (!$this->_loadConfig()) {
            $this->log(modX::LOG_LEVEL_ERROR, 'Could not reload core MODX configuration!');
        }
        return $this->config;
    }

    /**
     * Get the configuration for the site.
     *
     * @return array An associate array of configuration key/values
     */
    public function getConfig() {
        if (!$this->_initialized || !is_array($this->config) || empty ($this->config)) {
            if (!isset ($this->config['base_url']))
                $this->config['base_url']= MODX_BASE_URL;
            if (!isset ($this->config['base_path']))
                $this->config['base_path']= MODX_BASE_PATH;
            if (!isset ($this->config['core_path']))
                $this->config['core_path']= MODX_CORE_PATH;
            if (!isset ($this->config['url_scheme']))
                $this->config['url_scheme']= MODX_URL_SCHEME;
            if (!isset ($this->config['http_host']))
                $this->config['http_host']= MODX_HTTP_HOST;
            if (!isset ($this->config['site_url']))
                $this->config['site_url']= MODX_SITE_URL;
            if (!isset ($this->config['manager_path']))
                $this->config['manager_path']= MODX_MANAGER_PATH;
            if (!isset ($this->config['manager_url']))
                $this->config['manager_url']= MODX_MANAGER_URL;
            if (!isset ($this->config['assets_path']))
                $this->config['assets_path']= MODX_ASSETS_PATH;
            if (!isset ($this->config['assets_url']))
                $this->config['assets_url']= MODX_ASSETS_URL;
            if (!isset ($this->config['connectors_path']))
                $this->config['connectors_path']= MODX_CONNECTORS_PATH;
            if (!isset ($this->config['connectors_url']))
                $this->config['connectors_url']= MODX_CONNECTORS_URL;
            if (!isset ($this->config['connector_url']))
                $this->config['connector_url']= MODX_CONNECTORS_URL . 'index.php';
            if (!isset ($this->config['processors_path']))
                $this->config['processors_path']= MODX_PROCESSORS_PATH;
            if (!isset ($this->config['request_param_id']))
                $this->config['request_param_id']= 'id';
            if (!isset ($this->config['request_param_alias']))
                $this->config['request_param_alias']= 'q';
            if (!isset ($this->config['https_port']))
                $this->config['https_port']= isset($GLOBALS['https_port']) ? $GLOBALS['https_port'] : 443;
            if (!isset ($this->config['error_handler_class']))
                $this->config['error_handler_class']= modErrorHandler::class;
            if (!isset ($this->config['server_port']))
                $this->config['server_port']= isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '';

            $this->_config= $this->config;
            if (!$this->_loadConfig()) {
                $this->log(modX::LOG_LEVEL_FATAL, "Could not load core MODX configuration!");
                return null;
            }
        }
        return $this->config;
    }

    /**
     * Initialize, cleanse, and process a request made to a modX site.
     *
     * @return mixed The result of the request handler.
     */
    public function handleRequest() {
        if ($this->getRequest()) {
            return $this->request->handleRequest();
        }
        return '';
    }

    /**
     * Attempt to load the request handler class, if not already loaded.
     *
     * @access public
     * @param string $class The class name of the response class to load. Defaults to
     * modRequest; is ignored if the Setting "modRequest.class" is set.
     * @param string $path The absolute path by which to load the response class from.
     * Defaults to the current MODX model path.
     * @return boolean Returns true if a valid request handler object was
     * loaded on this or any previous call to the function, false otherwise.
     */
    public function getRequest($class= modRequest::class, $path= '') {
        $className = $this->getOption('modRequest.class', $this->config, $class);
        if ($this->request === null || !($this->request instanceof $className)) {
            $this->request= new $className($this);
        }

        return is_object($this->request) && $this->request instanceof $className;
    }

    /**
     * Attempt to load the response handler class, if not already loaded.
     *
     * @access public
     * @param string $class The class name of the response class to load. Defaults to
     * modResponse; is ignored if the Setting "modResponse.class" is set.
     * @param string $path The absolute path by which to load the response class from.
     * Defaults to the current MODX model path.
     * @return boolean Returns true if a valid response handler object was
     * loaded on this or any previous call to the function, false otherwise.
     */
    public function getResponse($class= modResponse::class, $path= '') {
        $className = $this->getOption('modResponse.class', $this->config, $class);
        if ($this->response === null || !($this->response instanceof $className)) {
            $this->response = new $className($this);
        }

        return $this->response instanceof $className;
    }

    /**
     * Register CSS to be injected inside the HEAD tag of a resource.
     *
     * @param string $src The CSS to be injected before the closing HEAD tag in
     * an HTML response.
     * @param string $media all, aural, braille, embossed, handheld, print, projection, screen, tty, tv
     * @return void
     */
    public function regClientCSS($src, $media = null) {
        if (isset ($this->loadedjscripts[$src]) && $this->loadedjscripts[$src]) {
            return;
        }
        $this->loadedjscripts[$src]= true;
        if (strpos(strtolower($src), "<style") !== false || strpos(strtolower($src), "<link") !== false) {
            $this->sjscripts[count($this->sjscripts)]= $src;
        } else {
            if (!empty($media)) {
                $media = ' media="' . $media .'"';
            }
            $this->sjscripts[count($this->sjscripts)]= '<link rel="stylesheet" href="' . $src . '" type="text/css"' . $media . ' />';
        }
    }

    /**
     * Register JavaScript to be injected inside the HEAD tag of a resource.
     *
     * @param string $src The JavaScript to be injected before the closing HEAD
     * tag of an HTML response.
     * @param boolean $plaintext Optional param to treat the $src as plaintext
     * rather than assuming it is JavaScript.
     * @return void
     */
    public function regClientStartupScript($src, $plaintext= false) {
        if (!empty ($src) && !array_key_exists($src, $this->loadedjscripts)) {
            if (isset ($this->loadedjscripts[$src]))
                return;
            $this->loadedjscripts[$src]= true;
            if ($plaintext == true) {
                $this->sjscripts[count($this->sjscripts)]= $src;
            } elseif (strpos(strtolower($src), "<script") !== false) {
                $this->sjscripts[count($this->sjscripts)]= $src;
            } else {
                $this->sjscripts[count($this->sjscripts)]= '<script src="' . $src . '"></script>';
            }
        }
    }

    /**
     * Register JavaScript to be injected before the closing BODY tag.
     *
     * @param string $src The JavaScript to be injected before the closing BODY
     * tag in an HTML response.
     * @param boolean $plaintext Optional param to treat the $src as plaintext
     * rather than assuming it is JavaScript.
     * @return void
     */
    public function regClientScript($src, $plaintext= false) {
        if (isset ($this->loadedjscripts[$src]))
            return;
        $this->loadedjscripts[$src]= true;
        if ($plaintext == true) {
            $this->jscripts[count($this->jscripts)]= $src;
        } elseif (strpos(strtolower($src), "<script") !== false) {
            $this->jscripts[count($this->jscripts)]= $src;
        } else {
            $this->jscripts[count($this->jscripts)]= '<script src="' . $src . '"></script>';
        }
    }

    /**
     * Register HTML to be injected before the closing HEAD tag.
     *
     * @param string $html The HTML to be injected.
     */
    public function regClientStartupHTMLBlock($html) {
        $this->regClientStartupScript($html, true);
    }

    /**
     * Register HTML to be injected before the closing BODY tag.
     *
     * @param string $html The HTML to be injected.
     */
    public function regClientHTMLBlock($html) {
        $this->regClientScript($html, true);
    }

    /**
     * Returns all registered JavaScripts.
     *
     * @access public
     * @return string The parsed HTML of the client scripts.
     */
    public function getRegisteredClientScripts() {
        $string= '';
        if (is_array($this->jscripts)) {
            $string= implode("\n",$this->jscripts);
        }
        return $string;
    }

    /**
     * Returns all registered startup CSS, JavaScript, or HTML blocks.
     *
     * @access public
     * @return string The parsed HTML of the startup scripts.
     */
    public function getRegisteredClientStartupScripts() {
        $string= '';
        if (is_array ($this->sjscripts)) {
            $string= implode("\n", $this->sjscripts);
        }
        return $string;
    }

    /**
     * Invokes a specified Event with an optional array of parameters.
     *
     * @todo refactor this completely, yuck!!
     *
     * @access public
     * @param string $eventName Name of an event to invoke.
     * @param array $params Optional params provided to the elements registered with an event.
     * @return bool|array
     */
    public function invokeEvent($eventName, array $params= []) {
        if (!$eventName)
            return false;
        if ($this->eventMap === null && $this->context instanceof modContext)
            $this->_initEventMap($this->context->get('key'));
        if (!isset ($this->eventMap[$eventName])) {
            //$this->log(modX::LOG_LEVEL_DEBUG,'System event '.$eventName.' was executed but does not exist.');
            return false;
        }
        $results= [];
        if (count($this->eventMap[$eventName])) {
            $this->event= new modSystemEvent();
            foreach ($this->eventMap[$eventName] as $pluginId => $pluginPropset) {
                /** @var modPlugin $plugin */
                $plugin= null;
                $this->Event = clone $this->event;
                $this->event->resetEventObject();
                $this->event->name= $eventName;
                if (isset ($this->pluginCache[$pluginId])) {
                    $plugin= $this->newObject(modPlugin::class);
                    $plugin->fromArray($this->pluginCache[$pluginId], '', true, true);
                    $plugin->_processed = false;
                    if ($plugin->get('disabled')) {
                        $plugin= null;
                    }
                } else {
                    $plugin= $this->getObject(modPlugin::class, ['id' => intval($pluginId), 'disabled' => '0'], true);
                }
                if ($plugin && !$plugin->get('disabled')) {
                    $this->event->plugin =& $plugin;
                    $this->event->activated= true;
                    $this->event->activePlugin= $plugin->get('name');
                    $this->event->propertySet= (($pspos = strpos($pluginPropset, ':')) >= 1) ? substr($pluginPropset, $pspos + 1) : '';

                    /* merge in plugin properties */
                    $eventParams = array_merge($plugin->getProperties(),$params);

                    $msg= $plugin->process($eventParams);
                    $results[]= $this->event->_output;
                    if ($msg && is_string($msg)) {
                        $this->log(modX::LOG_LEVEL_ERROR, '[' . $this->event->name . ']' . $msg);
                    } elseif ($msg === false) {
                        $this->log(modX::LOG_LEVEL_ERROR, '[' . $this->event->name . '] Plugin ' . $plugin->name . ' failed!');
                    }
                    $this->event->plugin = null;
                    $this->event->activePlugin= '';
                    $this->event->propertySet= '';
                    if (!$this->event->isPropagatable()) {
                        break;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Loads and runs a specific processor.
     *
     * @param string $action The processor to run, eg: context/update, or the processor class name (\MODX\Revolution\Processors\Context\Update::class)
     * @param array $scriptProperties Optional. An array of parameters to pass to the processor.
     * @param array $options Optional. An array of options for running the processor, only supports "processors_path" to set a custom path
     *
     * @return ProcessorResponse|mixed The result of the processor. Typically a ProcessorResponse object, but may be different for specific processors.
     */
    public function runProcessor($action = '', $scriptProperties = [], $options = []) {
        $result = null;

        // Make sure the required services are loaded before initialising a processor
        if (!$this->lexicon) {
            if (!$this->services->has('lexicon')) {
                $this->services->add('lexicon', new modLexicon($this));
            }
            $this->lexicon = $this->services->get('lexicon');
        }
        if (!$this->error) {
            if (!$this->services->has('error')) {
                $this->services->add('error', new modError($this));
            }
            $this->error = $this->services->get('error');
        }

        // First check if the processor can be found directly as a class name provided to $action
        // We're limiting to subclasses of Processor to prevent instantiating arbitrary classes
        if (static::isProcessorClass($action)) {
            /** @var Processor $processor */
            $processor = $action::getInstance($this, $action, $scriptProperties);

            return $processor->run();
        }

        // Check if we're dealing with an action like "context/update", which we transform into the 3.0+ class name
        $legacyAction = 'MODX\\Revolution\\Processors\\' . implode('\\', array_map('ucfirst', explode('/', $action)));

        // Special case for TVs, for which the directory is named differently from how it was called in the past
        if (strpos($legacyAction, '\\Tv\\') !== false) {
            $legacyAction = str_replace('\\Tv\\', '\\TemplateVar\\', $legacyAction);
        }
        if (static::isProcessorClass($legacyAction)) {
            /** @var Processor $processor */
            $processor = $legacyAction::getInstance($this, $legacyAction, $scriptProperties);

            return $processor->run();
        }

        // If we don't have a processor yet, we're dealing with a custom processor. See if we have been provided a path to look in.
        $processorsPath = isset($options['processors_path']) && !empty($options['processors_path']) ? $options['processors_path'] : $this->config['processors_path'];

        // Prevent path traversal through the action
        $action = preg_replace('/[\.]{2,}/', '', htmlspecialchars($action));

        // Find a matching processor file
        $processorFile = $processorsPath . ltrim($action . '.class.php','/');

        if (!file_exists($processorFile)) {
            $this->log(modX::LOG_LEVEL_ERROR, "Unable to load processor for action \"{$action}\", it does not exist as an autoloadable class that extends \MODX\Revolution\Processors\Processor, and also not as a file in \"{$processorFile}\"");
            return new ProcessorResponse($this, [
                'success' => false,
                'message' => 'Requested processor not found',
            ]);
        }

        // Check if we already know the name for the class (meaning we've loaded it before in this request)
        if (array_key_exists($processorFile,$this->processors)) {
            $className = $this->processors[$processorFile];
        }
        // Load the file. The file is expected to return (as a string) the name of its class.
        else {
            $className = include_once $processorFile;
            // include_once returns 1 if there was no return value in the file, or true if it was already loaded before
            // In both cases we can guess the class name based on the provided action
            if ($className === 1 || $className === true) {
                $section = explode('/', $action);
                $pieces = [];
                foreach ($section as $k) {
                    $pieces[] = ucfirst(str_replace(['.', '_', '-'], '', $k));
                }
                // This is an older construct, introduced in MODX 2.2. It's better to be explicit and return the name of your class in the processor
                $className = 'mod' . implode('', $pieces) . 'Processor';
            }

            $this->processors[$processorFile] = $className;
        }
        if (static::isProcessorClass($className)) {
            /** @var Processor $processor */
            $processor = $className::getInstance($this, $className, $scriptProperties);
            $processor->setPath($processorFile);
            return $processor->run();
        }

        $this->log(modX::LOG_LEVEL_ERROR, "Unable to load processor for action \"{$action}\". Its file in \"{$processorFile}\" exists, but does not return the processor class name and the class name could not be automatically inferred from the action.");
        return new ProcessorResponse($this, [
            'success' => false,
            'message' => 'Requested processor is invalid.',
        ]);
    }

    /**
     * @param string $className
     * @return bool
     */
    private static function isProcessorClass(string $className): bool
    {
        return class_exists($className) && is_subclass_of($className, Processor::class, true);
    }

    /**
     * Returns the current user ID, for the current or specified context.
     *
     * @param string $context The key of a valid modContext so you can retrieve
     * the current user ID from a different context than the current.
     * @return integer The ID of the current user.
     */
    public function getLoginUserID($context= '') {
        $userId = 0;
        if (empty($context) && $this->context instanceof modContext && $this->user instanceof modUser) {
            if ($this->user->hasSessionContext($this->context->get('key'))) {
                $userId = $this->user->get('id');
            }

        } else {
            $user = $this->getAuthenticatedUser($context);
            if ($user instanceof modUser) {
                $userId = $user->get('id');
            }
        }
        return $userId;
    }

    /**
     * Returns the current user name, for the current or specified context.
     *
     * @param string $context The key of a valid modContext so you can retrieve
     * the username from a different context than the current.
     * @return string The username of the current user.
     */
    public function getLoginUserName($context= '') {
        $userName = '';
        if (empty($context) && $this->context instanceof modContext && $this->user instanceof modUser) {
            if ($this->user->hasSessionContext($this->context->get('key'))) {
                $userName = $this->user->get('username');
            }

        } else {
            $user = $this->getAuthenticatedUser($context);
            if ($user instanceof modUser) {
                $userName = $user->get('username');
            }
        }
        return $userName;
    }

    /**
     * Returns whether modX instance has been initialized or not.
     *
     * @access public
     * @return boolean
     */
    public function isInitialized() {
        return $this->_initialized;
    }

    /**
     * Legacy fatal error message.
     *
     * @deprecated
     * @param string $msg
     * @param string $query
     * @param bool $is_error
     * @param string $nr
     * @param string $file
     * @param string $source
     * @param string $text
     * @param string $line
     */
    public function messageQuit($msg='unspecified error', $query='', $is_error=true, $nr='', $file='', $source='', $text='', $line='') {
        $this->deprecated('2.2.0', 'Use modX::log with modX::LOG_LEVEL_FATAL instead.');
        $this->log(modX::LOG_LEVEL_FATAL, 'msg: ' . $msg . "\n" . 'query: ' . $query . "\n" . 'nr: ' . $nr . "\n" . 'file: ' . $file . "\n" . 'source: ' . $source . "\n" . 'text: ' . $text . "\n" . 'line: ' . $line . "\n");
    }

    /**
     * Process and return the output from a PHP snippet by name.
     *
     * @param string $snippetName The name of the snippet.
     * @param array $params An associative array of properties to pass to the
     * snippet.
     * @return string The processed output of the snippet.
     */
    public function runSnippet($snippetName, array $params= []) {
        $output= '';
        if ($this->getParser()) {
            $snippet= $this->parser->getElement(modSnippet::class, $snippetName);
            if ($snippet instanceof modSnippet) {
                $snippet->setCacheable(false);
                $output= $snippet->process($params);
            }
        }
        return $output;
    }

    /**
     * Process and return the output from a Chunk by name.
     *
     * @param string $chunkName The name of the chunk.
     * @param array $properties An associative array of properties to process
     * the Chunk with, treated as placeholders within the scope of the Element.
     * @return string The processed output of the Chunk.
     */
    public function getChunk($chunkName, array $properties= []) {
        $output= '';
        if ($this->getParser()) {
            $chunk= $this->parser->getElement(modChunk::class, $chunkName);
            if ($chunk instanceof modChunk) {
                $chunk->setCacheable(false);
                $output= $chunk->process($properties);
            }
        }
        return $output;
    }

    /**
     * Parse a chunk using an associative array of replacement variables.
     *
     * @param string $chunkName The name of the chunk.
     * @param array $chunkArr An array of properties to replace in the chunk.
     * @param string $prefix The placeholder prefix, defaults to [[+.
     * @param string $suffix The placeholder suffix, defaults to ]].
     * @return string The processed chunk with the placeholders replaced.
     * @deprecated Prefer using $modx->getChunk, to be removed in MODX 4.0.
     */
    public function parseChunk($chunkName, $chunkArr, $prefix='[[+', $suffix=']]') {
        $this->deprecated('3.0.0', 'Use $modx->getChunk instead');
        $chunk= $this->getChunk($chunkName);
        if (!empty($chunk) || $chunk === '0') {
            if(is_array($chunkArr)) {
                foreach ($chunkArr as $key => $value) {
                    $chunk= str_replace($prefix.$key.$suffix, $value, $chunk);
                }
            }
        }
        return $chunk;
    }

    /**
     * Strip unwanted HTML and PHP tags and supplied patterns from content.
     *
     * @see modX::$sanitizePatterns
     * @param string $html The string to strip
     * @param string $allowed An array of allowed HTML tags
     * @param array $patterns An array of patterns to sanitize with; otherwise will use modX::$sanitizePatterns
     * @param int $depth The depth in which the parser will strip given the patterns specified
     * @return boolean True if anything was stripped
     */
    public function stripTags($html, $allowed= '', $patterns= [], $depth= 10) {
        $stripped= strip_tags($html, $allowed);
        if (is_array($patterns)) {
            if (empty($patterns)) {
                $patterns = $this->sanitizePatterns;
            }
            foreach ($patterns as $pattern) {
                $depth = ((integer) $depth ? (integer) $depth : 10);
                $iteration = 1;
                while ($iteration <= $depth && preg_match($pattern, $stripped)) {
                    $stripped= preg_replace($pattern, '', $stripped);
                    $iteration++;
                }
            }
        }
        return $stripped;
    }

    /**
     * Returns true if user has the specified policy permission.
     *
     * @param string $pm Permission key to check.
     * @return boolean
     */
    public function hasPermission($pm) {
        $state = $this->context->checkPolicy($pm);
        return $state;
    }

    /**
     * Logs a manager action.
     *
     * @param string $action The action to pull from the lexicon module.
     * @param string $class_key The class key that the action is being performed on.
     * @param mixed $item The primary key id or array of keys to grab the object with.
     * @param int|null $userId
     * @return modManagerLog The newly created modManagerLog object.
     */
    public function logManagerAction($action, $class_key, $item, $userId = null) {
        if($userId === null) {
            if ($this->user instanceof modUser) {
                $userId = $this->user->get('id');
            }
        }

        /** @var modManagerLog $ml */
        $ml = $this->newObject(modManagerLog::class);
        $ml->set('user', (integer) $userId);
        $ml->set('occurred', date('Y-m-d H:i:s'));
        $ml->set('action', empty($action) ? 'unknown' : $action);
        $ml->set('classKey', empty($class_key) ? '' : $class_key);
        $ml->set('item', empty($item) ? 'unknown' : $item);

        if (!$ml->save()) {
            $this->log(modX::LOG_LEVEL_ERROR, $this->lexicon('manager_log_err_save'));
            return null;
        }
        return $ml;
    }

    /**
     * Remove an event from the eventMap so it will not be invoked.
     *
     * @param string $event
     * @param integer $pluginId Plugin identifier to remove from the eventMap for the specified event.
     * @return boolean false if the event parameter is not specified or is not
     * present in the eventMap.
     */
    public function removeEventListener($event, $pluginId = 0) {
        $removed = false;
        if (!empty($event) && isset($this->eventMap[$event])) {
            if (intval($pluginId)) {
                unset ($this->eventMap[$event][$pluginId]);
            } else {
                unset ($this->eventMap[$event]);
            }
            $removed = true;
        }
        return $removed;
    }

    /**
     * Remove all registered events for the current request.
     */
    public function removeAllEventListener() {
        unset ($this->eventMap);
        $this->eventMap= [];
    }

    /**
     * Add a plugin to the eventMap within the current execution cycle.
     *
     * @param string $event Name of the event.
     * @param integer $pluginId Plugin identifier to add to the event.
     * @param string $propertySetName The name of property set bound to the plugin
     * @return boolean true if the event is successfully added, otherwise false.
     */
    public function addEventListener($event, $pluginId, $propertySetName = '') {
        $added = false;
        $pluginId = intval($pluginId);
        if ($event && $pluginId) {
            if (!isset($this->eventMap[$event]) || empty ($this->eventMap[$event])) {
                $this->eventMap[$event]= [];
            }
            $this->eventMap[$event][$pluginId]= $pluginId . (!empty($propertySetName) ? ':' . $propertySetName : '');
            $added = true;
        }
        return $added;
    }

    /**
     * Switches the primary Context for the modX instance.
     *
     * Be aware that switching contexts does not allow custom session handling
     * classes to be loaded. The gateway defines the session handling that is
     * applied to a single request. To create a context with a custom session
     * handler you must create a unique context gateway that initializes that
     * context directly.
     *
     * @param string $contextKey The key of the context to switch to.
     * @param boolean $reload Set to true to force the context data to be regenerated
     * before being switched to.
     * @return boolean True if the switch was successful, otherwise false.
     */
    public function switchContext($contextKey, $reload = false) {
        $switched= false;
        if ($this->context->key != $contextKey) {
            $switched= $this->_initContext($contextKey, $reload);
            if ($switched) {
                if (is_array($this->config)) {
                    $this->setPlaceholders($this->config, '+');
                }
            }
        }
        return $switched;
    }

    /**
     * Retrieve a context by name without initializing it.
     *
     * Within a request, contexts retrieved using this function will cache the
     * context data into the modX::$contexts array to avoid loading the same
     * context multiple times.
     *
     * @access public
     * @param string $contextKey The context to retrieve.
     * @return modContext A modContext object retrieved from cache or
     * database.
     */
    public function getContext($contextKey) {
        if (!isset($this->contexts[$contextKey])) {
            $this->contexts[$contextKey]= $this->getObject(modContext::class, ['key' => $contextKey]);
            if ($this->contexts[$contextKey]) {
                $this->contexts[$contextKey]->prepare();
            }
        }
        return $this->contexts[$contextKey];
    }

    /**
     * Gets a map of events and registered plugins for the specified context.
     *
     * Service #s:
     * 1 - Parser Service Events
     * 2 - Manager Access Events
     * 3 - Web Access Service Events
     * 4 - Cache Service Events
     * 5 - Template Service Events
     * 6 - User Defined Events
     *
     * @param string $contextKey Context identifier.
     * @return array A map of events and registered plugins for each.
     */
    public function getEventMap($contextKey) {
        $eventElementMap= [];
        if ($contextKey) {
            switch ($contextKey) {
                case 'mgr':
                    /* dont load Web Access Service Events */
                    $service= "Event.service IN (1,2,4,5,6) AND";
                    break;
                default:
                    /* dont load Manager Access Events */
                    $service= "Event.service IN (1,3,4,5,6) AND";
            }
            $pluginEventTbl= $this->getTableName(modPluginEvent::class);
            $eventTbl= $this->getTableName(modEvent::class);
            $pluginTbl= $this->getTableName(modPlugin::class);
            $propsetTbl= $this->getTableName(modPropertySet::class);
            $sql= "
                SELECT
                    Event.name AS event,
                    PluginEvent.pluginid,
                    PropertySet.name AS propertyset
                FROM {$pluginEventTbl} PluginEvent
                    INNER JOIN {$pluginTbl} Plugin ON Plugin.id = PluginEvent.pluginid AND Plugin.disabled = 0
                    INNER JOIN {$eventTbl} Event ON {$service} Event.name = PluginEvent.event
                    LEFT JOIN {$propsetTbl} PropertySet ON PluginEvent.propertyset = PropertySet.id
                ORDER BY Event.name, PluginEvent.priority ASC
            ";
            $stmt= $this->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($ee = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $eventElementMap[$ee['event']][(string) $ee['pluginid']]= $ee['pluginid'] . (!empty($ee['propertyset']) ? ':' . $ee['propertyset'] : '');
                }
            }
        }
        return $eventElementMap;
    }

    /**
     * Checks for locking on a page.
     *
     * @param integer $id Id of the user checking for a lock.
     * @param string $action The action identifying what is locked.
     * @param string $type Message indicating the kind of lock being checked.
     * @return string|boolean If locked, will return a locked message
     */
    public function checkForLocks($id,$action,$type) {
        $msg= false;
        $id= intval($id);
        if (!$id) $id= $this->getLoginUserID();
        if ($au = $this->getObject(modActiveUser::class, ['action' => $action, 'internalKey:!=' => $id])) {
            $msg = $this->lexicon('lock_msg',
                                  [
                'name' => $au->get('username'),
                'object' => $type,
                                  ]
            );
        }
        return $msg;
    }

    /**
     * Grabs a processed lexicon string.
     *
     * @access public
     * @param string $key
     * @param array $params
     * @param string $language
     * @return null|string The translated string, or null if none is set
     */
    public function lexicon($key,$params = [],$language = '') {
        $language = !empty($language) ? $language : $this->getOption('cultureKey',null,'en');
        if ($this->lexicon) {
            return $this->lexicon->process($key,$params,$language);
        } else {
            $this->log(modX::LOG_LEVEL_ERROR,'Culture not initialized; cannot use lexicon.');
        }
        return null;
    }

    /**
     * Returns the state of the SESSION being used by modX.
     *
     * The possible values for session state are:
     *
     * modX::SESSION_STATE_UNINITIALIZED
     * modX::SESSION_STATE_UNAVAILABLE
     * modX::SESSION_STATE_EXTERNAL
     * modX::SESSION_STATE_INITIALIZED
     *
     * @return integer Returns an integer representing the session state.
     */
    public function getSessionState() {
        if ($this->_sessionState !== modX::SESSION_STATE_INITIALIZED) {
            if (XPDO_CLI_MODE || headers_sent()) {
                $this->_sessionState = modX::SESSION_STATE_UNAVAILABLE;
            }
            elseif (isset($_SESSION)) {
                $this->_sessionState = modX::SESSION_STATE_EXTERNAL;
            }
        }
        return $this->_sessionState;
    }

    /**
     * Executed before parser processing of an element.
     */
    public function beforeProcessing() {}

    /**
     * Executed before the response is rendered.
     */
    public function beforeRender() {}

    /**
     * Executed before the handleRequest function.
     */
    public function beforeRequest() {
        unset($this->placeholders['+username'],$this->placeholders['+password'],$this->placeholders['+dbname'],$this->placeholders['+host']);
    }

    /**
     * Determines the current site_status.
     *
     * @return boolean True if the site is online or the user has a valid
     * user session in the 'mgr' context; false otherwise.
     */
    public function checkSiteStatus() {
        $status = false;
        if ($this->config['site_status'] == '1' || ($this->getSessionState() === modX::SESSION_STATE_INITIALIZED && $this->hasPermission('view_offline'))) {
            $status = true;
        }
        return $status;
    }

    /**
     * Add an extension package to MODX
     *
     * @param string $name
     * @param string $path
     * @param array $options
     * @return boolean
     */
    public function addExtensionPackage($name,$path,array $options = []) {
        $extPackages = $this->getOption('extension_packages');
        $extPackages = !empty($extPackages) ? $extPackages : [];
        $extPackages = is_array($extPackages) ? $extPackages : $this->fromJSON($extPackages);
        $extPackages[$name] = $options;
        $extPackages['path'] = $path;

        /** @var modSystemSetting $setting */
        $setting = $this->getObject(modSystemSetting::class,
                                    [
            'key' => 'extension_packages',
                                    ]
        );
        if (empty($setting)) {
            $setting = $this->newObject(modSystemSetting::class);
            $setting->set('key','extension_packages');
            $setting->set('namespace','core');
            $setting->set('xtype','textfield');
            $setting->set('area','system');
        }
        $value = $setting->get('value');
        $value = is_array($value) ? $value : $this->fromJSON($value);
        if (empty($value)) {
            $value = [];
            $value[$name] = $options;
            $value[$name]['path'] = $path;
            $value = '['.$this->toJSON($value).']';
        } else {
            $found = false;
            foreach ($value as $k => $v) {
                foreach ($v as $kk => $vv) {
                    if ($kk == $name) {
                        $found = true;
                    }
                }
            }
            if (!$found) {
                $extPack[$name] = $options;
                $extPack[$name]['path'] = $path;
                $value[] = $extPack;
            }
            $value = $this->toJSON($value);
        }
        $value = str_replace('\\','',$value);
        $setting->set('value',$value);
        return $setting->save();
    }

    /**
     * Remove an extension package from MODX
     *
     * @param string $name
     * @return boolean
     */
    public function removeExtensionPackage($name) {
        /** @var modSystemSetting $setting */
        $setting = $this->getObject(modSystemSetting::class,
                                    [
            'key' => 'extension_packages',
                                    ]
        );
        if (!$setting) {
            return false;
        }
        $value = $setting->get('value');
        $value = is_array($value) ? $value : $this->fromJSON($value);
        $found = false;
        foreach ($value as $idx => $extPack) {
            foreach ($extPack as $key => $opt) {
                if ($key == $name) {
                    unset($value[$idx]);
                    $found = true;
                }
            }
        }
        $removed = false;
        if ($found) {
            $value = $this->toJSON($value);
            $value = str_replace('\\','',$value);
            $setting->set('value',$value);
            $removed = $setting->save();
        }
        return $removed;
    }

    /**
     * Reload data for a specified Context, without switching to it.
     *
     * Note that the Context will be loaded even if it is not already.
     *
     * @param string $key The key of the Context to (re)load.
     * @return boolean True if the Context was (re)loaded successfully; false otherwise.
     */
    public function reloadContext($key = null) {
        $reloaded = false;
        if ($this->context instanceof modContext) {
            if (empty($key)) {
                $key = $this->context->get('key');
            }
            if ($key === $this->context->get('key')) {
                $reloaded = $this->_initContext($key, true);
                if ($reloaded && is_array($this->config)) {
                    $this->setPlaceholders($this->config, '+');
                }
            } else {
                if (!array_key_exists($key, $this->contexts) || !($this->contexts[$key] instanceof modContext)) {
                    $this->contexts[$key] = $this->newObject(modContext::class);
                    $this->contexts[$key]->_fields['key']= $key;
                }
                $reloaded = $this->contexts[$key]->prepare(true);
            }
        } elseif (!empty($key) && (!array_key_exists($key, $this->contexts) || !($this->contexts[$key] instanceof modContext))) {
            $this->contexts[$key] = $this->newObject(modContext::class);
            $this->contexts[$key]->_fields['key']= $key;
            $reloaded = $this->contexts[$key]->prepare(true);
        }
        return $reloaded;
    }

    /**
     * Start a PHP Session if one is not already available.
     *
     * @return bool Returns true if a session is successfully or already started, false otherwise.
     */
    public function startSession()
    {
        if ($this->_sessionState === modX::SESSION_STATE_UNINITIALIZED) {
            if (!session_start()) {
                $this->_sessionState = isset($_SESSION)
                    ? modX::SESSION_STATE_EXTERNAL
                    : modX::SESSION_STATE_UNAVAILABLE;
            } elseif (isset($_SESSION)) {
                $this->_sessionState = modX::SESSION_STATE_INITIALIZED;
            }
        }
        return isset($_SESSION);
    }

    /**
     * Marks the calling function as deprecated, sending a message into the error log.
     *
     * This automatically determines where the deprecated method was called from, and
     * includes that in the log message.
     *
     * @param string $since The version the function was marked as deprecated
     * @param string $recommendation A description or recommendation on what to replace a method with
     * @param string $deprecatedDef Can be used to override the definition (i.e. function name) for the log; useful if not a specific method but an entire entity is deprecated.
     */
    public function deprecated($since, $recommendation = '', $deprecatedDef = '')
    {
        if (!$this->getOption('log_deprecated', null, true)) {
            return;
        }

        // At the end of the request, save deprecations with their callers in one go
        register_shutdown_function(function () {
            foreach ($this->_deprecations as $deprecation) {
                $deprecation->save();
            }
        });

        // We use the trace to identify both the method that is deprecated, and the caller
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $deprecatedMethod = isset($trace[1]) ? $trace[1] : [];
        $caller = isset($trace[2]) ? $trace[2] : [];

        // Format the deprecated function definition with the class, if it has one
        if ($deprecatedDef === '') {
            $deprecatedDef = isset($deprecatedMethod['class'])
                ? $deprecatedMethod['class'] . '::' . $deprecatedMethod['function']
                : $deprecatedMethod['function'];
        }

        $deprecation = $this->_getDeprecatedMethod($since, $deprecatedDef, $recommendation);
        $deprecation->addCaller($caller['class'] ?? '', $caller['function'] ?? '', $deprecatedMethod['file'], $deprecatedMethod['line']);
    }

    /**
     *
     * Gets an (in-memory cached) modDeprecatedMethod instance.
     * @param $since
     * @param $callerDef
     * @param $recommendation
     * @return modDeprecatedMethod
     */
    private function _getDeprecatedMethod($since, $callerDef, $recommendation): modDeprecatedMethod
    {
        if (array_key_exists($callerDef, $this->_deprecations)) {
            return $this->_deprecations[$callerDef];
        }

        /** @var modDeprecatedMethod $deprecation */
        $deprecation = $this->getObject(modDeprecatedMethod::class, [
            'definition' => $callerDef,
        ]);
        if (!$deprecation) {
            $deprecation = $this->newObject(modDeprecatedMethod::class);
            $deprecation->set('definition', $callerDef);
        }
        // Exact messages may change from time-to-time, keep updated
        $deprecation->set('recommendation', $recommendation);
        $deprecation->set('since', $since);
        // We don't save it here - we do that at the end of the request

        $this->_deprecations[$callerDef] = $deprecation;
        return $deprecation;
    }


    /**
     * Load a class by fully qualified name.
     *
     * As of MODX 3.0, this is no longer necessary and deprecated. Instead of using loadClass, use the PSR-4 class
     * references that are available through the autoloader.
     *
     * @param string $fqn
     * @param string $path
     * @param bool $ignorePkg
     * @param bool $transient
     * @return bool|string
     * @deprecated Since 3.0
     */
    public function loadClass($fqn, $path = '', $ignorePkg = false, $transient = false)
    {
        if (strpos($fqn, '\\') !== false && class_exists($fqn)) {
            return $fqn;
        }

        if (empty($path)) {
            $class = $fqn;
            if (strpos($fqn, '.') !== false) {
                $tmp = explode('.', $fqn);
                $class = array_pop($tmp);
                if ($tmp[0] === 'modx') {
                    array_shift($tmp);
                }
                if (count($tmp) > 0) {
                    $class = implode('\\', array_map('ucfirst', $tmp)) . '\\' . $class;
                }
            }
            $class = 'MODX\\Revolution\\' . $class;
            if (class_exists($class)) {
                if (!class_exists($fqn)) {
                    class_alias($class, $fqn);
                }

                $this->deprecated('3.0', "Replace references to class {$fqn} with {$class} to take advantage of PSR-4 autoloading.", $fqn);

                return $class;
            }
        }

        return parent::loadClass($fqn, $path, $ignorePkg, $transient);
    }

    /**
     * Loads a specified Context.
     *
     * Merges any context settings with the modX::$config, and performs any
     * other context specific initialization tasks.
     *
     * @access protected
     * @param string $contextKey A context identifier.
     * @param boolean $regenerate If true, force regeneration of the context even if already initialized.
     * @param array $options Array of options to override context settings.
     * @return boolean True if the context was properly initialized.
     */
    protected function _initContext($contextKey, $regenerate = false, $options = null) {
        $initialized= false;
        $oldContext = is_object($this->context) ? $this->context->get('key') : '';
        if (isset($this->contexts[$contextKey]) && $this->contexts[$contextKey] instanceof modContext) {
            $this->context= & $this->contexts[$contextKey];
        } else {
            $this->context= $this->newObject(modContext::class);
            $this->context->_fields['key']= $contextKey;
            if (!$this->context->validate()) {
                $this->log(modX::LOG_LEVEL_ERROR, 'No valid context specified: ' . $contextKey);
                $this->context = null;
            }
        }
        if ($this->context) {
            if (!$this->context->prepare((boolean) $regenerate, is_array($options) ? $options : [])) {
                $this->context= null;
                $this->log(modX::LOG_LEVEL_ERROR, 'Could not prepare context: ' . $contextKey);
            } else {
                //This fixes error with multiple contexts
                $this->contexts[$contextKey]=$this->context;

                if ($this->context->checkPolicy('load')) {
                    $this->aliasMap= & $this->context->aliasMap;
                    $this->resourceMap= & $this->context->resourceMap;
                    $this->eventMap= & $this->context->eventMap;
                    $this->pluginCache= & $this->context->pluginCache;
                    $this->config= array_merge($this->_systemConfig, $this->context->config);
                    $iniTZ = ini_get('date.timezone');
                    $cfgTZ = $this->getOption('date_timezone', $options, '');
                    if (!empty($cfgTZ)) {
                        if (empty($iniTZ) || $iniTZ !== $cfgTZ) {
                            date_default_timezone_set($cfgTZ);
                        }
                    } elseif (empty($iniTZ)) {
                        date_default_timezone_set('UTC');
                    }
                    if ($this->_initialized) {
                        $this->user = null;
                        $this->getUser();
                    }
                    $initialized = true;
                } elseif (isset($this->contexts[$oldContext])) {
                    $this->context =& $this->contexts[$oldContext];
                } else {
                    $this->log(modX::LOG_LEVEL_ERROR, 'Could not load context: ' . $contextKey);
                }
            }
        }
        if ($initialized) {
            $this->setLogLevel($this->getOption('log_level', $options, xPDO::LOG_LEVEL_ERROR));

            $logTarget = $this->getOption('log_target', $options, 'FILE', true);
            if ($logTarget === 'FILE') {
                $options = [];
                $filename = $this->getOption('error_log_filename', $options, '');
                if (!empty($filename)) $options['filename'] = $filename;
                $filepath = $this->getOption('error_log_filepath', $options, '');
                if (!empty($filepath)) $options['filepath'] = rtrim($filepath, '/') . '/';
                $this->setLogTarget(
                    [
                    'target' => 'FILE',
                    'options' => $options,
                    ]
                );
            } else {
                $this->setLogTarget($logTarget);
            }

            $debug = $this->getOption('debug');
            if (!is_null($debug) && $debug !== '') {
                $this->setDebug($debug);
            }

            $this->invokeEvent(
                'OnContextInit',
                [
                    'contextKey' => $contextKey,
                    'options' => $options,
                ]
            );
        }
        return $initialized;
    }

    /**
     * Initializes the culture settings.
     *
     * @param array|null $options Options for the culture initialization process.
     */
    protected function _initCulture($options = null) {
        $cultureKey = $this->getOption('cultureKey', $options, 'en');
        if (!empty($_SESSION['cultureKey'])) $cultureKey = $_SESSION['cultureKey'];
        if (!empty($_REQUEST['cultureKey'])) $cultureKey = $_REQUEST['cultureKey'];
        $this->cultureKey = $cultureKey;
        $this->setOption('cultureKey', $cultureKey);

        if ($this->getOption('setlocale', $options, true)) {
            $locale = setlocale(LC_ALL, '0');
            $targetLocale = $this->getOption('locale', null, $locale, true);
            $result = setlocale(LC_ALL, $targetLocale);

            if ($result === false) {
                $this->log(modX::LOG_LEVEL_ERROR, 'Could not set the locale. Please check if the locale ' . $this->getOption('locale', null, $locale) . ' exists on your system');
            }
        }

        $this->services->add('lexicon', new modLexicon($this));
        $this->lexicon = $this->services->get('lexicon');

        $this->invokeEvent('OnInitCulture');
    }

    /**
     * Loads the error handler for this instance.
     *
     * @param array|null $options An array of options for the errorHandler.
     */
    protected function _initErrorHandler($options = null) {
        if (!$this->services->has('errorHandler') && $this->errorHandler !== null && is_object($this->errorHandler)) {
            $this->services->add('errorHandler', $this->errorHandler);
        }

        if (!$this->services->has('errorHandler')) {
            if ($ehClass = $this->getOption('error_handler_class', $options, modErrorHandler::class, true)) {
                $ehPath = $this->getOption('error_handler_path', $options, '', true);
                if ($ehClass = $this->loadClass($ehClass, $ehPath, false, true)) {
                    $errorHandler = new $ehClass($this);
                }
            }
            if (!$ehClass || !$errorHandler) {
                $errorHandler = new modErrorHandler($this);
            }
            $this->services->add('errorHandler', $errorHandler);
        }

        try {
            $services = $this->services;
            $this->errorHandler = $services->get('errorHandler');
            $setErrorHandler = function($errno, $errstr, $errfile = null, $errline = null, $errcontext = null) use ($services) {
                return $services->get('errorHandler')->handleError($errno, $errstr, $errfile, $errline, $errcontext);
            };

            $result = set_error_handler($setErrorHandler, $this->getOption('error_handler_types', $options, error_reporting(), true));
            if ($result === false) {
                $this->log(modX::LOG_LEVEL_ERROR, 'Could not set error handler. Make sure your class has a function called handleError(). Result: ' . print_r($result, true));
            }
        } catch (\Exception $exception) {
            $this->log(modX::LOG_LEVEL_ERROR, 'Error handler not found: ' . $exception->getMessage());
        }
    }

    protected function _initHttpClient()
    {
        if (!$this->services->has(ClientInterface::class)) {
            // Http Client is created as a factory, so that repeat calls get a fresh client. This is done to make sure
            // mutable clients (perhaps they allow setting options after instantiation) do not cause side-effects elsewhere
            $this->services->add(ClientInterface::class, $this->services->factory(function() {
                $opts = $this->buildHttpClientOptions();
                return new Client($opts);
            }));
        }
        if (!$this->services->has(ServerRequestFactoryInterface::class)) {
            $this->services->add(ServerRequestFactoryInterface::class, function() {
                return new HttpFactory();
            });
        }
        if (!$this->services->has(RequestFactoryInterface::class)) {
            $this->services->add(RequestFactoryInterface::class, function() {
                return new HttpFactory();
            });
        }
        if (!$this->services->has(StreamFactoryInterface::class)) {
            $this->services->add(StreamFactoryInterface::class, function() {
                return new HttpFactory();
            });
        }
    }

    /**
     * Build options for the HTTP client based on configuration settings.
     *
     * @return array The HTTP client options.
     */
    private function buildHttpClientOptions() {
        $opts = [];
        $proxyHost = $this->getOption('proxy_host', null, '');
        if (!empty($proxyHost)) {
            $proxy_str = $proxyHost;
            $proxyPort = $this->getOption('proxy_port', null, '');
            if (!empty($proxyPort)) {
                $proxy_str .= ':' . $proxyPort;
            }
            $proxyUsername = $this->getOption('proxy_username', null, '');
            if (!empty($proxyUsername)) {
                $proxyPassword = $this->getOption('proxy_password', null, '');
                $proxy_str = $proxyUsername . ':' . $proxyPassword . '@' . $proxy_str;
            }
            $opts['proxy'] = $proxy_str;
        }
        return $opts;
    }

    /**
     * Populates the map of events and registered plugins for each.
     *
     * @param string $contextKey Context identifier.
     */
    protected function _initEventMap($contextKey) {
        if ($this->eventMap === null) {
            $this->eventMap= $this->getEventMap($contextKey);
        }
    }

    /**
     * Loads the session handler and starts the session.
     *
     * @param array|null $options Options to override Settings explicitly.
     */
    protected function _initSession($options = null) {
        $contextKey= $this->context instanceof modContext ? $this->context->get('key') : null;
        if ($this->getOption('session_enabled', $options, true) || isset($_GET['preview'])) {
            if (!in_array($this->getSessionState(), [modX::SESSION_STATE_INITIALIZED, modX::SESSION_STATE_EXTERNAL, modX::SESSION_STATE_UNAVAILABLE], true)) {
                $sh = false;
                if ($sessionHandlerClass = $this->getOption('session_handler_class', $options)) {
                    if ($shClass = $this->loadClass($sessionHandlerClass, '', false, true)) {
                        if ($sh = new $shClass($this)) {
                            session_set_save_handler(
                                [& $sh, 'open'],
                                [& $sh, 'close'],
                                [& $sh, 'read'],
                                [& $sh, 'write'],
                                [& $sh, 'destroy'],
                                [& $sh, 'gc']
                            );
                        }
                    }
                }
                if (
                    (is_string($sessionHandlerClass) && !$sh instanceof $sessionHandlerClass) ||
                    !is_string($sessionHandlerClass)
                ) {
                    $sessionSavePath = $this->getOption('session_save_path', $options);
                    if ($sessionSavePath && is_writable($sessionSavePath)) {
                        session_save_path($sessionSavePath);
                    }
                }
                $cookieDomain = $this->getOption('session_cookie_domain', $options, '');
                $cookiePath = $this->getOption('session_cookie_path', $options, MODX_BASE_URL);
                if (empty($cookiePath)) $cookiePath = $this->getOption('base_url', $options, MODX_BASE_URL);
                $cookieSecure = (bool) $this->getOption('session_cookie_secure', $options, false);
                $cookieHttpOnly = (bool) $this->getOption('session_cookie_httponly', $options, true);
                $cookieSamesite = $this->getOption('session_cookie_samesite', $options, '');
                $cookieLifetime = (int) $this->getOption('session_cookie_lifetime', $options, 0);
                $gcMaxlifetime = (int) $this->getOption('session_gc_maxlifetime', $options, $cookieLifetime);
                if ($gcMaxlifetime > 0) {
                    ini_set('session.gc_maxlifetime', $gcMaxlifetime);
                }
                $site_sessionname = $this->getOption('session_name', $options, '');
                if (!empty($site_sessionname)) session_name($site_sessionname);

                $cookie_params = [
                    'lifetime' => $cookieLifetime,
                    'path' => $cookiePath,
                    'domain' => $cookieDomain,
                    'secure' => $cookieSecure,
                    'httponly' => $cookieHttpOnly
                ];
                if ($cookieSamesite !== '') {
                    $cookie_params['samesite'] = $cookieSamesite;
                }
                session_set_cookie_params($cookie_params);

                if ($this->getOption('anonymous_sessions', $options, true) || isset($_COOKIE[session_name()])) {
                    if (!$this->startSession()) {
                        $this->log(modX::LOG_LEVEL_ERROR, 'Unable to initialize a session', '', __METHOD__, __FILE__, __LINE__);
                        $this->getUser($contextKey);
                        return;
                    }
                    $this->getUser($contextKey);
                    $cookieExpiration = 0;
                    if (isset ($_SESSION['modx.' . $contextKey . '.session.cookie.lifetime'])) {
                        $sessionCookieLifetime = (integer)$_SESSION['modx.' . $contextKey . '.session.cookie.lifetime'];
                        if ($sessionCookieLifetime !== $cookieLifetime) {
                            if ($sessionCookieLifetime) {
                                $cookieExpiration = time() + $sessionCookieLifetime;
                            }
                            $cookie_settings = [
                                'expires' => $cookieExpiration,
                                'path' => $cookiePath,
                                'domain' => $cookieDomain,
                                'secure' => $cookieSecure,
                                'httponly' => $cookieHttpOnly
                            ];
                            if ($cookieSamesite !== '') {
                                $cookie_settings['samesite'] = $cookieSamesite;
                            }
                            setcookie(session_name(), session_id(), $cookie_settings);
                        }
                    }
                } else {
                    $this->getUser($contextKey);
                }
            } else {
                $this->getUser($contextKey);
            }
        } else {
            $this->getUser($contextKey);
        }
    }

    /**
     * Loads the modX system configuration settings.
     *
     * @access protected
     * @return boolean True if successful.
     */
    protected function _loadConfig() {
        $this->config = $this->_config;

        $this->getCacheManager();
        $config = $this->cacheManager->get('config', [
            xPDO::OPT_CACHE_KEY => $this->getOption('cache_system_settings_key', null, 'system_settings'),
            xPDO::OPT_CACHE_HANDLER => $this->getOption('cache_system_settings_handler', null, $this->getOption(xPDO::OPT_CACHE_HANDLER)),
            xPDO::OPT_CACHE_FORMAT => (integer) $this->getOption('cache_system_settings_format', null, $this->getOption(xPDO::OPT_CACHE_FORMAT, null, xPDOCacheManager::CACHE_PHP)),
        ]
        );
        if (empty($config)) {
            $config = $this->cacheManager->generateConfig();
        }
        if (empty($config)) {
            $config = [];
            if (!$settings = $this->getCollection(modSystemSetting::class)) {
                return false;
            }
            /** @var modSystemSetting $setting */
            foreach ($settings as $setting) {
                $config[$setting->get('key')]= $setting->get('value');
            }
        }
        $this->config = array_merge($this->config, $config);
        $this->_systemConfig = $this->config;
        return true;
    }

    /**
     * Provides modX the ability to use modRegister instances as log targets.
     *
     * {@inheritdoc}
     */
    protected function _log($level, $msg, $target= '', $def= '', $file= '', $line= '') {
        if (empty($target)) {
            $target = $this->logTarget;
        }
        $targetOptions = [];
        $targetObj = $target;
        if (is_array($target)) {
            if (isset($target['options'])) $targetOptions = $target['options'];
            $targetObj = isset($target['target']) ? $target['target'] : 'ECHO';
        }
        if (is_object($targetObj) && $targetObj instanceof modRegister) {
            if ($level === modX::LOG_LEVEL_FATAL) {
                if (empty ($file)) $file= (isset ($_SERVER['PHP_SELF'])) ? $_SERVER['PHP_SELF'] : (isset ($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '');
                $this->_logInRegister($targetObj, $level, $msg, $def, $file, $line);
                $this->sendError('fatal');
            }
            if ($this->_debug === true || $level <= $this->logLevel) {
                if (empty ($file)) $file= (isset ($_SERVER['PHP_SELF'])) ? $_SERVER['PHP_SELF'] : (isset ($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '');
                $this->_logInRegister($targetObj, $level, $msg, $def, $file, $line);
            }
        } else {
            if ($level === modX::LOG_LEVEL_FATAL) {
                while (ob_get_level() && @ob_end_clean()) {}
                if ($targetObj == 'FILE' && $cacheManager= $this->getCacheManager()) {
                    $filename = isset($targetOptions['filename']) ? $targetOptions['filename'] : 'error.log';
                    $filepath = isset($targetOptions['filepath']) ? $targetOptions['filepath'] : $this->getCachePath() . xPDOCacheManager::LOG_DIR;
                    $cacheManager->writeFile($filepath . $filename, '[' . date('Y-m-d H:i:s') . '] (' . $this->_getLogLevel($level) . $def . $file . $line . ') ' . $msg . "\n" . ($this->getDebug() === true ? '<pre>' . "\n" . print_r(debug_backtrace(), true) . "\n" . '</pre>' : ''), 'a');
                }
                $this->sendError('fatal');
            }
            parent :: _log($level, $msg, $target, $def, $file, $line);
        }
    }

    /**
     * Provides custom logging functionality for modRegister targets.
     *
     * @access protected
     * @param modRegister $register The modRegister instance to send to
     * @param int $level The level of error or message that occurred
     * @param string $msg The message to send to the register
     * @param string $def The type of error that occurred
     * @param string $file The filename of the file that the message occurs for
     * @param string $line The line number of the file that the message occurs for
     */
    protected function _logInRegister($register, $level, $msg, $def, $file, $line) {
        $timestamp = date('Y-m-d H:i:s');
        $messageKey = (string) time();
        $messageKey .= '-' . sprintf("%06d", $this->_logSequence);
        $message = [
            'timestamp' => $timestamp,
            'level' => $this->_getLogLevel($level),
            'msg' => $msg,
            'def' => $def,
            'file' => $file,
            'line' => $line,
        ];
        $options = [];
        if ($level === xPDO::LOG_LEVEL_FATAL) {
            $options['kill'] = true;
        }
        $register->send('', [$messageKey => $message], $options);
        $this->_logSequence++;
    }

    /**
     * Executed after the response is sent and execution is completed.
     *
     * @access protected
     */
    public function _postProcess() {
        if ($this->resourceGenerated && $this->getOption('cache_resource', null, true)) {
            if (is_object($this->resource) && $this->resource instanceof modResource && $this->resource->get('id') && $this->resource->get('cacheable')) {
                $this->resource->_contextKey = $this->context->get('key');
                $this->invokeEvent('OnBeforeSaveWebPageCache');
                $this->cacheManager->generateResource($this->resource);
            }
        }
        $this->invokeEvent('OnWebPageComplete');
    }
}
