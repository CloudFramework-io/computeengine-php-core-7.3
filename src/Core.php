<?php

/**
 * @author Héctor López <hlopez@cloudframework.io>
 * @version 2020
 */

if (!defined("_CLOUDFRAMEWORK_CORE_CLASSES_")) {
    define("_CLOUDFRAMEWORK_CORE_CLASSES_", TRUE);

    //region debug function
    /**
     * Echo in output a group of vars passed as args
     * @param mixed $args Element to print.
     */
    function __print($args)
    {
        $ret = "";
        if (key_exists('PWD', $_SERVER)) echo "\n";
        else echo "<pre>";
        for ($i = 0, $tr = count($args); $i < $tr; $i++) {
            if ($args[$i] === "exit")
                exit;
            if (key_exists('PWD', $_SERVER)) echo "\n[$i]: ";
            else echo "\n<li>[$i]: ";

            if (is_array($args[$i]))
                echo print_r($args[$i], TRUE);
            else if (is_object($args[$i]))
                echo var_dump($args[$i]);
            else if (is_bool($args[$i]))
                echo ($args[$i]) ? 'true' : 'false';
            else if (is_null($args[$i]))
                echo 'NULL';
            else
                echo $args[$i];
            if (key_exists('PWD', $_SERVER)) echo "\n";
            else echo "</li>";
        }
        if (key_exists('PWD', $_SERVER)) echo "\n";
        else echo "</pre>";
    }


    /**
     * Print a group of mixed vars passed as arguments
     */
    function _print()
    {
        __print(func_get_args());
    }

    function __fatal_handler() {
        global $core;
        $errfile = "unknown file";
        $errstr  = "shutdown";
        $errno   = E_CORE_ERROR;
        $errline = 0;

        $error = error_get_last();


        if( $error !== NULL) {
            $errno   = $error["type"];
            $errfile = $error["file"];
            $errline = $error["line"];
            $errstr  = $error["message"];

            $core->errors->add(["ErrorCode"=>$errno, "ErrorMessage"=>$errstr, "File"=>$errfile, "Line"=>$errline],'Fatal Error');
            _print( ["ErrorCode"=>$errno, "ErrorMessage"=>$errstr, "File"=>$errfile, "Line"=>$errline]);
        }
    }

    register_shutdown_function( "__fatal_handler" );



    /**
     * _print() with an exit
     */
    function _printe()
    {
        __print(array_merge(func_get_args(), array('exit')));
    }
    //endregion

    /**
     * Core Class to build cloudframework applications
     * @package Core
     */
    class Core
    {
        /** @var CorePerformance $__p Object to control de performance */
        public $__p;
        /** @var CoreSession $session Object to control de Session */
        public $session;
        /** @var CoreSystem $system Object to control system interaction */
        public $system;
        /** @var CoreLog $logs Object to control Logs */
        public $logs;
        /** @var CoreLog $errors Object to control Errors */
        public $errors;
        /** @var CoreIs $is Object to help with certain conditions */
        public $is;
        /** @var CoreConfig $config Object to control configuration */
        public $config;
        /** @var CoreRequest $request Object to control request calls */
        public $request;


        var $_version = 'v73.0507';

        /**
         * @var array $loadedClasses control the classes loaded
         * @link Core::loadClass()
         */
        private $loadedClasses = [];

        /**
         * Core constructor.
         * @param string $root_path
         */
        function __construct($root_path = '')
        {
            $this->__p = new CorePerformance();
            $this->session = new CoreSession();
            $this->system = new CoreSystem($root_path);
            $this->logs = new CoreLog();
            $this->errors = new CoreLog();
            $this->is = new CoreIs();
            $config = (is_file($_SERVER['DOCUMENT_ROOT'] . '/config.json'))?$_SERVER['DOCUMENT_ROOT'] . '/config.json':null;
            $this->config = new CoreConfig($this, $config);
            $this->request = new CoreRequest($this);

        }

        /**
         * Router
         */
        function dispatch()
        {

            // core.dispatch.headers: Evaluate to add headers in the response.
            if($headers = $this->config->get('core.dispatch.headers')) {
                if(is_array($headers)) foreach ($headers as $key=>$value) {
                    header("$key: $value");
                }
            }

            // API end points. By default $this->config->get('core_api_url') is '/'
            if ($this->isApiPath()) {

                if (!strlen($this->system->url['parts'][$this->system->url['parts_base_index']])) $this->errors->add('missing api end point');
                else {

                    $apifile = $this->system->url['parts'][$this->system->url['parts_base_index']];

                    // -----------------------
                    // Evaluating tests API cases
                    // path to file
                    if ($apifile[0] == '_' || $apifile == 'queue') {
                        $pathfile = __DIR__ . "/api/{$apifile}.php";
                        if (!file_exists($pathfile)) $pathfile = '';
                    } else {
                        // Every End-point inside the app has priority over the apiPaths
                        $pathfile = $this->system->app_path . "/api/{$apifile}.php";
                        if (!file_exists($pathfile)) {
                            $pathfile = '';
                            if (strlen($this->config->get('core.api.extra_path')))
                                $pathfile = $this->config->get('core.api.extra_path') . "/{$apifile}.php";

                        }
                    }

                    // IF NOT EXIST
                    include_once __DIR__ . '/class/RESTful.php';

                    try {
                        // Include the external file $pathfile
                        if (strlen($pathfile)) {
                            // init data storage client wrapper if filepath starts with gs://
                            @include_once $pathfile;
                            $this->__p->add('Loaded $pathfile', __METHOD__);

                        }

                        // By default the ClassName will be called API.. if the include set $api_class var, we will use that class name
                        if(!isset($api_class)) $api_class = 'API';

                        if (class_exists($api_class)) {
                            /** @var RESTful $api */
                            $api = new $api_class($this,$this->system->url['parts_base_url']);
                            if (array_key_exists(0,$api->params) && $api->params[0] == '__codes') {
                                $__codes = $api->codeLib;
                                foreach ($__codes as $key => $value) {
                                    $__codes[$key] = $api->codeLibError[$key] . ', ' . $value;
                                }
                                $api->addReturnData($__codes);
                            } else {
                                $api->main();
                            }
                            $api->send();

                        } else {
                            $api = new RESTful($this);
                            if(is_file($pathfile)) {
                                $api->setError("the code in '{$apifile}' does not include a {$api_class} class extended from RESTFul with method ->main(): ", 404);
                            } else {
                                $api->setError("the file for '{$apifile}' does not exist in api directory: ".$pathfile, 404);

                            }
                            $api->send();
                        }
                    } catch (Exception $e) {
                        $api = new RESTful($this);
                        if(is_file($pathfile)) {
                            $api->setError("the code in '{$apifile}' does not include a {$api_class} class extended from RESTFul with method ->main(): ", 404);
                        } else {
                            $api->setError("the file for '{$apifile}' does not exist in api directory: ".$pathfile, 404);
                        }
                        $this->errors->add(error_get_last());
                        $this->errors->add($e->getMessage());
                        $api->send();
                    }
                    $this->__p->add("API including RESTfull.php and {$apifile}.php: ", 'There are ERRORS');
                }
                return false;
            } // Take a LOOK in the menu
            elseif ($this->config->inMenuPath()) {

                // Common logic
                if (!empty($this->config->get('commonLogic'))) {
                    try {
                        include_once $this->system->app_path . '/logic/' . $this->config->get('commonLogic');
                        if (class_exists('CommonLogic')) {
                            $commonLogic = new CommonLogic($this);
                            $commonLogic->main();
                            $this->__p->add("Executed CommonLogic->main()", "/logic/{$this->config->get('commonLogic')}");

                        } else {
                            die($this->config->get('commonLogic').' does not include CommonLogic class');
                        }
                    } catch (Exception $e) {
                        $this->errors->add(error_get_last());
                        $this->errors->add($e->getMessage());
                        _print($this->errors->data);
                    }
                }

                // Specific logic
                if (!empty($this->config->get('logic'))) {
                    try {
                        include_once $this->system->app_path . '/logic/' . $this->config->get('logic');
                        if (class_exists('Logic')) {
                            $logic = new Logic($this);
                            $logic->main();
                            $this->__p->add("Executed Logic->main()", "/logic/{$this->config->get('logic')}");

                        } else {
                            $logic = new CoreLogic($this);
                            $logic->addError("api {$this->config->get('logic')} does not include a Logic class extended from CoreLogic with method ->main()", 404);
                        }

                    } catch (Exception $e) {
                        $this->errors->add(error_get_last());
                        $this->errors->add($e->getMessage());
                    }
                } else {
                    $logic = new CoreLogic($this);
                }
                // Templates
                if (!empty($this->config->get('template'))) {
                    $logic->render($this->config->get('template'));
                }
                // No template assigned.
                else {
                    // If there is no logic and no template, then ERROR
                    if(empty($this->config->get('logic'))) {
                        $this->errors->add('No logic neither template assigned');
                        _print($this->errors->data);
                    }
                }
            }
            // URL not found in the menu.
            else {
                $this->errors->add('URL has not exist in config-menu');
                _printe($this->errors->data);
            }
        }

        /**
         * Assign the path to Root of the app
         * @param $dir
         */
        function setAppPath($dir)
        {
            if (is_dir($this->system->root_path . $dir)) {
                $this->system->app_path = $this->system->root_path . $dir;
                $this->system->app_url = $dir;
            } else {
                $this->errors->add($this->system->root_path . $dir . " doesn't exist. ".$this->system->root_path . $dir);
            }
        }

        /**
         * Is the current route part of the API?
         * @return bool
         */
        private function isApiPath() {
            if(!$this->config->get('core.api.urls')) return false;
            $paths = $this->config->get('core.api.urls');
            if(!is_array($paths)) $paths = explode(',',$this->config->get('core.api.urls'));

            foreach ($paths as $path) {
                if(strpos($this->system->url['url'], $path) === 0) {
                    $path = preg_replace('/\/$/','',$path);
                    $this->system->url['parts_base_index'] = count(explode('/',$path))-1;
                    $this->system->url['parts_base_url'] = $path;
                    return true;
                }
            }
            return false;
        }

        /**
         * Return an object of the Class $class. If this object has been previously called class
         * @param $class
         * @param null $params
         * @return mixed|null
         */
        function loadClass($class, $params = null)
        {

            $hash = hash('md5', $class . json_encode($params));
            if (key_exists($hash, $this->loadedClasses)) return $this->loadedClasses[$hash];

            if (is_file(__DIR__ . "/class/{$class}.php"))
                include_once(__DIR__ . "/class/{$class}.php");
            elseif (is_file($this->system->app_path . "/class/" . $class . ".php"))
                include_once($this->system->app_path . "/class/" . $class . ".php");
            else {
                $this->errors->add("Class $class not found");
                return null;
            }
            $this->loadedClasses[$hash] = new $class($this, $params);
            return $this->loadedClasses[$hash];

        }

        /**
         * Init gc_datastorage_client and registerStreamWrapper
         */
        protected function initDataStorage() {

            if(is_object($this->gc_datastorage_client)) return;
            if(!$this->gc_project_id) {
                echo('Missing PROJECT_ID ENVIRONMENT VARIABLE TO REGISTER STREAM WRAPPER'."\n");
                if($this->is->terminal()) {
                    echo('export PROJECT_ID={YOUR-PROJECT-ID}'."\n");
                    exit;
                } else if($this->is->development()) {
                    echo('export PROJECT_ID={YOUR-PROJECT-ID}'."\n");
                    exit;
                }else  {
                    echo('add in app.yaml'."\nenv_variables:\n   PROJECT_ID: \"{YOUR-PROJECT-ID}\"");
                    exit;
                }
                $this->logs->add('Missing PROJECT_ID ENVIRONMENT VARIABLE TO REGISTER STREAM WRAPPER');
                $this->logs->add('export PROJECT_ID={YOUR-PROJECT-ID}');
                return;
            }
            $this->gc_datastorage_client = new StorageClient(['projectId' => $this->gc_project_id]);
            $this->gc_datastorage_client->registerStreamWrapper();
        }

        /**
         * json_encode function to fix the issue with json_encode started on Apr-2020
         * @param $data
         * @param null $options
         * @return string|null returns null if error in json_encode function
         */
        public function json_encode($data, $options=null) {
            if($options) return(json_encode($data, JSON_UNESCAPED_UNICODE | $options));
            else return(json_encode($data, JSON_UNESCAPED_UNICODE ));
        }
        /**
         * json_decode function avoid future problems
         * @param $data
         * @param boolean $ret_array
         * @return array|null returns null if error in json_decode function
         */
        public function json_decode($data, $ret_array=false) {
            return(json_decode($data, $ret_array ));
        }
    }

    /**
     * Class to manage CloudFramework configuration.
     * @package Core
     */
    class CoreConfig
    {
        private $core;
        private $_configPaths = [];
        var $data = [];
        var $menu = [];
        protected $lang = 'en';

        function __construct(Core &$core, $path)
        {
            $this->core = $core;
            $this->readConfigJSONFile($path);

            // Set lang for the system
            if (strlen($this->get('core.localization.default_lang'))) $this->setLang($this->get('core.localization.default_lang'));

            // core.localization.param_name allow to change the lang by URL
            if (strlen($this->get('core.localization.param_name'))) {
                $field = $this->get('core.localization.param_name');
                if (!empty($_GET[$field])) $this->core->session->set('_CloudFrameWorkLang_', $_GET[$field]);
                $lang = $this->core->session->get('_CloudFrameWorkLang_');
                if (strlen($lang))
                    if (!$this->setLang($lang)) {
                        $this->core->session->delete('_CloudFrameWorkLang_');
                    }
            }

        }

        /**
         * Return an array of files readed for config.
         * @return array
         */
        function getConfigLoaded()
        {
            $ret = [];
            foreach ($this->_configPaths as $path => $foo) {
                $ret[] = str_replace($this->core->system->root_path, '', $path);
            }
            return $ret;
        }

        /**
         * Get the current lang
         * @return string
         */
        function getLang()
        {
            return ($this->lang);
        }

        /**
         * Assign the language
         * @param $lang
         * @return bool
         */
        function setLang($lang)
        {
            $lang = preg_replace('/[^a-z]/', '', strtolower($lang));
            // Control Lang
            if (strlen($lang = trim($lang)) < 2) {
                $this->core->logs->add('Warning config->setLang. Trying to pass an incorrect Lang: ' . $lang);
                return false;
            }
            if (strlen($this->get('core.localization.allowed_langs'))
                && !preg_match('/(^|,)' . $lang . '(,|$)/', preg_replace('/[^A-z,]/', '', $this->get('core.localization.allowed_langs')))
            ) {
                $this->core->logs->add('Warning in config->setLang. ' . $lang . ' is not included in {{core.localization.allowed_langs}}');
                return false;
            }

            $this->lang = $lang;
            return true;
        }

        /**
         * Get a config var value. $var is empty return the array with all values.
         * @param string $var  Config variable
         * @return mixed|null
         */
        public function get($var='')
        {
            if(strlen($var))
                return (key_exists($var, $this->data)) ? $this->data[$var] : null;
            else return $this->data;
        }

        /**
         * Set a config var
         * @param $var string
         * @param $data mixed
         */
        public function set($var, $data)
        {
            $this->data[$var] = $data;
        }

        /**
         * Set a config vars bases in an Array {"key":"value"}
         * @param $data Array
         */
        public function bulkSet(Array $data)
        {
            foreach ($data as $key=>$item) {
                $this->data[$key] = $item;
            }
        }
        /**
         * Add a menu line
         * @param $var
         */
        public function pushMenu($var)
        {
            if (!key_exists('menupath', $this->data)) {
                $this->menu[] = $var;
                if (!isset($var['path'])) {
                    $this->core->logs->add('Missing path in menu line');
                    $this->core->logs->add($var);
                } else {
                    // Trying to match the URLs
                    if (strpos($var['path'], "{*}"))
                        $_found = strpos($this->core->system->url['url'], str_replace("{*}", '', $var['path'])) === 0;
                    else
                        $_found = $this->core->system->url['url'] == $var['path'];

                    if ($_found) {
                        $this->set('menupath', $var['path']);
                        foreach ($var as $key => $value) {
                            $value = $this->convertTags($value);
                            $this->set($key, $value);
                        }
                    }
                }
            }
        }

        /**
         * Determine if the current URL is part of the menupath
         * @return bool
         */
        public function inMenuPath()
        {
            return key_exists('menupath', $this->data);
        }

        /**
         * Try to read a JOSN file to process it as a corfig file
         * @param $path string
         * @return bool
         */
        public function readConfigJSONFile($path)
        {

            // Avoid recursive load JSON files
            if (isset($this->_configPaths[$path])) {
                $this->core->errors->add("Recursive config file: " . $path);
                return false;
            }
            $this->_configPaths[$path] = 1; // Control witch config paths are beeing loaded.
            try {
                $data = json_decode(@file_get_contents($path), true);

                if (!is_array($data)) {
                    $this->core->errors->add('error reading ' . $path);
                    if (json_last_error())
                        $this->core->errors->add("Wrong format of json: " . $path);
                    elseif (!empty(error_get_last()))
                        $this->core->errors->add(error_get_last());
                    return false;
                } else {
                    $this->processConfigData($data);
                    return true;
                }
            } catch (Exception $e) {
                $this->core->errors->add(error_get_last());
                $this->core->errors->add($e->getMessage());
                return false;
            }
        }

        /**
         * Process a config array
         * @param $data array
         */
        public function processConfigData(array $data)
        {
            // going through $data
            foreach ($data as $cond => $vars) {

                // Just a comment
                if ($cond == '--') continue;

                // Convert potentials Tags
                if (is_string($vars)) $vars = $this->convertTags($vars);
                $include = false;

                $tagcode = '';
                if (strpos($cond, ':') !== false) {
                    // Substitute tags for strings
                    $cond = $this->convertTags(trim($cond));
                    list($tagcode, $tagvalue) = explode(":", $cond, 2);
                    $tagcode = trim($tagcode);
                    $tagvalue = trim($tagvalue);

                    if ($this->isConditionalTag($tagcode))
                        $include = $this->getConditionalTagResult($tagcode, $tagvalue);
                    elseif ($this->isAssignationTag($tagcode)) {
                        $this->setAssignationTag($tagcode, $tagvalue, $vars);
                        continue;
                    } else {
                        $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                        continue;
                    }

                } else {
                    $include = true;
                    $vars = [$cond => $vars];

                }

                // Include config vars.
                if ($include) {
                    if (is_array($vars)) {
                        foreach ($vars as $key => $value) {
                            if ($key == '--') continue; // comment
                            // Recursive call to analyze subelements
                            if (strpos($key, ':')) {
                                $this->processConfigData([$key => $value]);
                            } else {
                                // Assign conf var values converting {} tags
                                $this->set($key, $this->convertTags($value));
                            }
                        }
                    }
                }
            }

        }

        /**
         * Evalue if the tag is a condition
         * @param $tag
         * @return bool
         */
        private function isConditionalTag($tag)
        {
            $tags = ["uservar", "authvar", "confvar", "sessionvar", "servervar", "auth", "noauth", "development", "production"
                , "indomain", "domain", "interminal", "url", "noturl", "inurl", "notinurl", "beginurl", "notbeginurl"
                , "inmenupath", "notinmenupath", "isversion", "false", "true"];
            return in_array(strtolower($tag), $tags);
        }

        /**
         * Evalue conditional tags on config file
         * @param $tagcode string
         * @param $tagvalue string
         * @return bool
         */
        private function getConditionalTagResult($tagcode, $tagvalue)
        {
            $evaluateTags = [];
            while(strpos($tagvalue,'|')) {
                list($tagvalue,$tags) = explode('|',$tagvalue,2);
                $evaluateTags[] = [trim($tagcode),trim($tagvalue)];
                list($tagcode,$tagvalue) = explode(':',$tags,2);
            }
            $evaluateTags[] = [trim($tagcode),trim($tagvalue)];
            $ret = false;
            // Conditionals tags
            // -----------------
            foreach ($evaluateTags as $evaluateTag) {
                $tagcode = $evaluateTag[0];
                $tagvalue = $evaluateTag[1];
                switch (trim(strtolower($tagcode))) {
                    case "uservar":
                    case "authvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($authvar, $authvalue) = explode("=", $tagvalue);
                            if ($this->core->user->isAuth() && $this->core->user->getVar($authvar) == $authvalue)
                                $ret = true;
                        }
                        break;
                    case "confvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($confvar, $confvalue) = explode('=', $tagvalue,2);
                            if (strlen($confvar) && $this->get($confvar) == $confvalue)
                                $ret = true;
                        } elseif (strpos($tagvalue, '!=') !== false) {
                            list($confvar, $confvalue) = explode('!=', $tagvalue,2);
                            if (strlen($confvar) && $this->get($confvar) != $confvalue)
                                $ret = true;
                        }
                        break;
                    case "sessionvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($sessionvar, $sessionvalue) = explode("=", $tagvalue);
                            if (strlen($sessionvar) && $this->core->session->get($sessionvar) == $sessionvalue)
                                $ret = true;
                        }elseif (strpos($tagvalue, '!=') !== false) {
                            list($sessionvar, $sessionvalue) = explode("!=", $tagvalue);
                            if (strlen($sessionvar) && $this->core->session->get($sessionvar) != $sessionvalue)
                                $ret = true;
                        }
                        break;
                    case "servervar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($servervar, $servervalue) = explode("=", $tagvalue);
                            if (strlen($servervar) && $_SERVER[$servervar] == $servervalue)
                                $ret = true;
                        }elseif (strpos($tagvalue, '!=') !== false) {
                            list($servervar, $servervalue) = explode("!=", $tagvalue);
                            if (strlen($servervar) && $_SERVER[$servervar] != $servervalue)
                                $ret = true;
                        }
                        break;
                    case "auth":
                    case "noauth":
                        if (trim(strtolower($tagcode)) == 'auth')
                            $ret = $this->core->user->isAuth();
                        else
                            $ret = !$this->core->user->isAuth();
                        break;
                    case "development":
                        $ret = $this->core->is->development();
                        break;
                    case "production":
                        $ret = $this->core->is->production();
                        break;
                    case "indomain":
                    case "domain":
                        $domains = explode(",", $tagvalue);
                        foreach ($domains as $ind => $inddomain) if (strlen(trim($inddomain))) {
                            if (trim(strtolower($tagcode)) == "domain") {
                                if (strtolower($_SERVER['HTTP_HOST']) == strtolower(trim($inddomain)))
                                    $ret = true;
                            } else {
                                if (isset($_SERVER['HTTP_HOST']) && stripos($_SERVER['HTTP_HOST'], trim($inddomain)) !== false)
                                    $ret = true;
                            }
                        }
                        break;
                    case "interminal":
                        $ret = $this->core->is->terminal();
                        break;
                    case "url":
                    case "noturl":
                        $urls = explode(",", $tagvalue);

                        // If noturl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "noturl") $ret = true;
                        foreach ($urls as $ind => $url) if (strlen(trim($url))) {
                            if (trim(strtolower($tagcode)) == "url") {
                                if (($this->core->system->url['url'] == trim($url)))
                                    $ret = true;
                            } else {
                                if (($this->core->system->url['url'] == trim($url)))
                                    $ret = false;
                            }
                        }
                        break;
                    case "inurl":
                    case "notinurl":
                        $urls = explode(",", $tagvalue);

                        // If notinurl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "notinurl") $ret = true;
                        foreach ($urls as $ind => $inurl) if (strlen(trim($inurl))) {
                            if (trim(strtolower($tagcode)) == "inurl") {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) !== false))
                                    $ret = true;
                            } else {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) !== false))
                                    $ret = false;
                            }
                        }
                        break;
                    case "beginurl":
                    case "notbeginurl":
                        $urls = explode(",", $tagvalue);
                        // If notinurl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "notbeginurl") $ret = true;
                        foreach ($urls as $ind => $inurl) if (strlen(trim($inurl))) {
                            if (trim(strtolower($tagcode)) == "beginurl") {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) === 0))
                                    $ret = true;
                            } else {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) === 0))
                                    $ret = false;
                            }
                        }
                        break;
                    case "inmenupath":
                        $ret = $this->inMenuPath();
                        break;
                    case "notinmenupath":
                        $ret = !$this->inMenuPath();
                        break;
                    case "isversion":
                        if (trim(strtolower($tagvalue)) == 'core')
                            $ret = true;
                        break;
                    case "false":
                    case "true":
                        $ret = trim(strtolower($tagcode))=='true';
                        break;

                    default:
                        $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                        break;
                }
                // If I have found a true, break foreach
                if($ret) break;
            }
            return $ret;
        }

        /**
         * Evalue if the tag is a condition
         * @param $tag
         * @return bool
         */
        private function isAssignationTag($tag)
        {
            $tags = ["webapp", "set", "include", "redirect", "menu","coreversion"];
            return in_array(strtolower($tag), $tags);
        }

        /**
         * Execure an assigantion based on the tagcode
         * @param $tagcode string
         * @param $tagvalue string
         * @return bool
         */
        private function setAssignationTag($tagcode, $tagvalue, $vars)
        {
            // Asignation tags
            // -----------------
            switch (trim(strtolower($tagcode))) {
                case "webapp":
                    $this->set("webapp", $vars);
                    $this->core->setAppPath($vars);
                    break;
                case "set":
                    $this->set($tagvalue, $vars);
                    break;
                case "include":
                    // Recursive Call
                    $this->readConfigJSONFile($vars);
                    break;
                case "redirect":
                    // Array of redirections
                    if (!$this->core->is->terminal()) {
                        if (is_array($vars)) {
                            foreach ($vars as $ind => $urls)
                                if (!is_array($urls)) {
                                    $this->core->errors->add('Wrong redirect format. It has to be an array of redirect elements: [{orig:dest},{..}..]');
                                } else {
                                    foreach ($urls as $urlOrig => $urlDest) {
                                        if ($urlOrig == '*' || !strlen($urlOrig))
                                            $this->core->system->urlRedirect($urlDest);
                                        else
                                            $this->core->system->urlRedirect($urlOrig, $urlDest);
                                    }
                                }

                        } else {
                            $this->core->system->urlRedirect($vars);
                        }
                    }
                    break;

                case "menu":
                    if (is_array($vars)) {
                        $vars = $this->convertTags($vars);
                        foreach ($vars as $key => $value) {
                            if (!empty($value['path']))
                                $this->pushMenu($value);
                            else {
                                $this->core->logs->add('wrong menu format. Missing path element');
                                $this->core->logs->add($value);
                            }

                        }
                    } else {
                        $this->core->errors->add("menu: tag does not contain an array");
                    }
                    break;
                case "coreversion":
                    if($this->core->_version!= $vars) {
                        die("config var 'CoreVersion' is '{$vars}' and the current cloudframework version is {$this->core->_version}. Please update the framework. composer.phar update");
                    }
                    break;
                default:
                    $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                    break;
            }
        }

        /**
         * Convert tags inside a string or object
         * @param $data mixed
         * @return mixed|string
         */
        public function convertTags($data)
        {
            $_array = is_array($data);

            // Convert into string if we received an array
            if ($_array) $data = json_encode($data);
            // Tags Conversions
            $data = str_replace('{{lang}}', $this->lang, $data);
            while (strpos($data, '{{confVar:') !== false) {
                list($foo, $var) = explode("{{confVar:", $data, 2);
                list($var, $foo) = explode("}}", $var, 2);
                $data = str_replace('{{confVar:' . $var . '}}', $this->get(trim($var)), $data);
            }
            // Convert into array if we received an array
            if ($_array) $data = json_decode($data, true);
            return $data;
        }

    }

    /**
     * Class to track performance
     * @package Core
     */
    class CorePerformance
    {
        var $data = [];

        function __construct()
        {
            // Performance Vars
            $this->data['initMicrotime'] = microtime(true);
            $this->data['lastMicrotime'] = $this->data['initMicrotime'];
            $this->data['initMemory'] = memory_get_usage() / (1024 * 1024);
            $this->data['lastMemory'] = $this->data['initMemory'];
            $this->data['lastIndex'] = 1;
            $this->data['info'][] = 'File: ' . str_replace($_SERVER['DOCUMENT_ROOT'], '', __FILE__);
            $this->data['info'][] = 'Init Memory Usage: ' . number_format(round($this->data['initMemory'], 4), 4) . 'Mb';

        }

        function add($title, $file = '', $type = 'all')
        {
            // Hidding full path (security)
            $file = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);


            if ($type == 'note') $line = "[$type";
            else $line = $this->data['lastIndex'] . ' [';

            if (strlen($file)) $file = " ($file)";

            $_mem = memory_get_usage() / (1024 * 1024) - $this->data['lastMemory'];
            if ($type == 'all' || $type == 'endnote' || $type == 'memory' || (isset($_GET['data']) && $_GET['data'] == $this->data['lastIndex'])) {
                $line .= number_format(round($_mem, 3), 3) . ' Mb';
                $this->data['lastMemory'] = memory_get_usage() / (1024 * 1024);
            }

            $_time = microtime(TRUE) - $this->data['lastMicrotime'];
            if ($type == 'all' || $type == 'endnote' || $type == 'time' || (isset($_GET['data']) && $_GET['data'] == $this->data['lastIndex'])) {
                $line .= (($line == '[') ? '' : ', ') . (round($_time, 3)) . ' secs';
                $this->data['lastMicrotime'] = microtime(TRUE);
            }
            $line .= '] ' . $title;
            $line = (($type != 'note') ? '[' . number_format(round(memory_get_usage() / (1024 * 1024), 3), 3) . ' Mb, '
                    . (round(microtime(TRUE) - $this->data['initMicrotime'], 3))
                    . ' secs] / ' : '') . $line . $file;
            if ($type == 'endnote') $line = "[$type] " . $line;
            $this->data['info'][] = $line;

            if ($title) {
                if (!isset($this->data['titles'][$title])) $this->data['titles'][$title] = ['mem' => '', 'time' => 0, 'lastIndex' => ''];
                $this->data['titles'][$title]['mem'] = $_mem;
                $this->data['titles'][$title]['time'] += $_time;
                $this->data['titles'][$title]['lastIndex'] = $this->data['lastIndex'];

            }

            if (isset($_GET['__p']) && $_GET['__p'] == $this->data['lastIndex']) {
                _printe($this->data);
                exit;
            }

            $this->data['lastIndex']++;

        }

        function getTotalTime($prec = 3)
        {
            return (round(microtime(TRUE) - $this->data['initMicrotime'], $prec));
        }

        function getTotalMemory($prec = 3)
        {
            return number_format(round(memory_get_usage() / (1024 * 1024), $prec), $prec);
        }

        function init($spacename, $key)
        {
            $this->data['init'][$spacename][$key]['mem'] = memory_get_usage();
            $this->data['init'][$spacename][$key]['time'] = microtime(TRUE);
            $this->data['init'][$spacename][$key]['ok'] = TRUE;
        }

        function end($spacename, $key, $ok = TRUE, $msg = FALSE)
        {

            // Verify indexes
            if(!isset($this->data['init'][$spacename][$key])) {
                $this->data['init'][$spacename][$key] = [];
            }

            $this->data['init'][$spacename][$key]['mem'] = round((memory_get_usage() - $this->data['init'][$spacename][$key]['mem']) / (1024 * 1024), 3) . ' Mb';
            $this->data['init'][$spacename][$key]['time'] = round(microtime(TRUE) - $this->data['init'][$spacename][$key]['time'], 3) . ' secs';
            $this->data['init'][$spacename][$key]['ok'] = $ok;
            if ($msg !== FALSE) $this->data['init'][$spacename][$key]['notes'] = $msg;
        }
    }

    /**
     * Class to manage session
     * @package Core
     */
    class CoreSession
    {
        var $start = false;
        var $id = '';

        function __construct()
        {
        }

        function init($id = '')
        {

            // If they pass a session id I will use it.
            if (!empty($id)) session_id($id);

            // Session start
            session_start();

            // Let's keep the session id
            $this->id = session_id();

            // Initiated.
            $this->start = true;
        }

        function get($var)
        {
            if (!$this->start) $this->init();
            if (key_exists('CloudSessionVar_' . $var, $_SESSION)) {
                try {
                    $ret = unserialize(gzuncompress($_SESSION['CloudSessionVar_' . $var]));
                } catch (Exception $e) {
                    return null;
                }
                return $ret;
            }
            return null;
        }

        function set($var, $value)
        {
            if (!$this->start) $this->init();
            $_SESSION['CloudSessionVar_' . $var] = gzcompress(serialize($value));
        }

        function delete($var)
        {
            if (!$this->start) $this->init();
            unset($_SESSION['CloudSessionVar_' . $var]);
        }
    }

    /**
     * Clas to interacto with with the System variables
     * @package Core
     */
    class CoreSystem
    {
        var $url, $app,$root_path, $app_path, $app_url;
        var $config = [];
        var $ip, $user_agent, $os, $lang, $format, $time_zone;
        var $geo;

        function __construct($root_path = '')
        {
            // region  $server_var from $_SERVER
            $server_var['HTTPS'] = (array_key_exists('HTTPS',$_SERVER))?$_SERVER['HTTPS']:null;
            $server_var['DOCUMENT_ROOT'] = (array_key_exists('DOCUMENT_ROOT',$_SERVER))?$_SERVER['DOCUMENT_ROOT']:null;
            $server_var['HTTP_HOST'] = (array_key_exists('HTTP_HOST',$_SERVER))?$_SERVER['HTTP_HOST']:null;
            $server_var['REQUEST_URI'] = (array_key_exists('REQUEST_URI',$_SERVER))?$_SERVER['REQUEST_URI']:null;
            $server_var['SCRIPT_NAME'] = (array_key_exists('SCRIPT_NAME',$_SERVER))?$_SERVER['SCRIPT_NAME']:null;
            $server_var['HTTP_USER_AGENT'] = (array_key_exists('HTTP_USER_AGENT',$_SERVER))?$_SERVER['HTTP_USER_AGENT']:null;
            $server_var['HTTP_ACCEPT_LANGUAGE'] = (array_key_exists('HTTP_ACCEPT_LANGUAGE',$_SERVER))?$_SERVER['HTTP_ACCEPT_LANGUAGE']:null;
            $server_var['HTTP_X_APPENGINE_COUNTRY'] = (array_key_exists('HTTP_X_APPENGINE_COUNTRY',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_COUNTRY']:null;
            $server_var['HTTP_X_APPENGINE_CITY'] = (array_key_exists('HTTP_X_APPENGINE_CITY',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_CITY']:null;
            $server_var['HTTP_X_APPENGINE_REGION'] = (array_key_exists('HTTP_X_APPENGINE_REGION',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_REGION']:null;
            $server_var['HTTP_X_APPENGINE_CITYLATLONG'] = (array_key_exists('HTTP_X_APPENGINE_CITYLATLONG',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_CITYLATLONG']:null;
            // endregion

            if (!strlen($root_path)) $root_path = (strlen($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : $_SERVER['PWD'];

            $this->url['https'] = $server_var['HTTPS'];
            $this->url['protocol'] = ($server_var['HTTPS'] == 'on') ? 'https' : 'http';
            $this->url['host'] = $server_var['HTTP_HOST'];
            $this->url['url_uri'] = $server_var['REQUEST_URI'];

            $this->url['url'] = $server_var['REQUEST_URI'];
            $this->url['params'] = '';
            if (strpos($server_var['REQUEST_URI'], '?') !== false)
                list($this->url['url'], $this->url['params']) = explode('?', $server_var['REQUEST_URI'], 2);

            $this->url['host_base_url'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'];
            $this->url['host_url'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'] . $this->url['url'];
            $this->url['host_url_uri'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'] . $server_var['REQUEST_URI'];
            $this->url['script_name'] = $server_var['SCRIPT_NAME'];
            $this->url['parts'] = explode('/', substr($this->url['url'], 1));
            $this->url['parts_base_index'] = 0;
            $this->url['parts_base_url'] = '/';

            // paths
            $this->root_path = $root_path;
            $this->app_path = $this->root_path;

            // Remote user:
            $this->ip = $this->getClientIP();
            $this->user_agent = $server_var['HTTP_USER_AGENT'];
            $this->os = $this->getOS();
            $this->lang = $server_var['HTTP_ACCEPT_LANGUAGE'];

            // About timeZone, Date & Number format
            if (isset($_SERVER['PWD']) && strlen($_SERVER['PWD'])) date_default_timezone_set('UTC'); // necessary for shell run
            $this->time_zone = array(date_default_timezone_get(), date('Y-m-d h:i:s'), date("P"), time());
            //date_default_timezone_set(($this->core->config->get('timeZone')) ? $this->core->config->get('timeZone') : 'Europe/Madrid');
            //$this->_timeZone = array(date_default_timezone_get(), date('Y-m-d h:i:s'), date("P"), time());
            $this->format['formatDate'] = "Y-m-d";
            $this->format['formatDateTime'] = "Y-m-d h:i:s";
            $this->format['formatDBDate'] = "Y-m-d";
            $this->format['formatDBDateTime'] = "Y-m-d h:i:s";
            $this->format['formatDecimalPoint'] = ",";
            $this->format['formatThousandSep'] = ".";

            // General conf
            // TODO default formats, currencies, timezones, etc..
            $this->config['setLanguageByPath'] = false;

            // GEO BASED ON GOOGLE APPENGINE VARS
            $this->geo['COUNTRY'] = $server_var['HTTP_X_APPENGINE_COUNTRY'];
            $this->geo['CITY'] = $server_var['HTTP_X_APPENGINE_CITY'];
            $this->geo['REGION'] = $server_var['HTTP_X_APPENGINE_REGION'];
            $this->geo['COORDINATES'] = $server_var['HTTP_X_APPENGINE_CITYLATLONG'];

        }

        function getClientIP() {

            $remote_address = (array_key_exists('REMOTE_ADDR',$_SERVER))?$_SERVER['REMOTE_ADDR']:'localhost';
            return  ($remote_address == '::1') ? 'localhost' : $remote_address;

            // Popular approaches we don't trust.
            // http://stackoverflow.com/questions/3003145/how-to-get-the-client-ip-address-in-php#comment50230065_3003233
            // http://stackoverflow.com/questions/15699101/get-the-client-ip-address-using-php
            /*
            if (getenv('HTTP_CLIENT_IP'))
                $ipaddress = getenv('HTTP_CLIENT_IP');
            else if (getenv('HTTP_X_FORWARDED_FOR'))
                $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
            else if (getenv('HTTP_X_FORWARDED'))
                $ipaddress = getenv('HTTP_X_FORWARDED');
            else if (getenv('HTTP_FORWARDED_FOR'))
                $ipaddress = getenv('HTTP_FORWARDED_FOR');
            else if (getenv('HTTP_FORWARDED'))
                $ipaddress = getenv('HTTP_FORWARDED');
            else if (getenv('REMOTE_ADDR'))
                $ipaddress = getenv('REMOTE_ADDR');
            else
                $ipaddress = 'UNKNOWN';
            return $ipaddress;
            */

        }

        public function getOS()
        {
            $os_platform = "Unknown OS Platform";
            $os_array = array(
                '/windows nt 6.2/i'     => 'Windows 8',
                '/windows nt 6.1/i'     => 'Windows 7',
                '/windows nt 6.0/i'     => 'Windows Vista',
                '/windows nt 5.2/i'     => 'Windows Server 2003/XP x64',
                '/windows nt 5.1/i'     => 'Windows XP',
                '/windows xp/i'         => 'Windows XP',
                '/windows nt 5.0/i'     => 'Windows 2000',
                '/windows me/i'         => 'Windows ME',
                '/win98/i'              => 'Windows 98',
                '/win95/i'              => 'Windows 95',
                '/win16/i'              => 'Windows 3.11',
                '/macintosh|mac os x/i' => 'Mac OS X',
                '/mac_powerpc/i'        => 'Mac OS 9',
                '/linux/i'              => 'Linux',
                '/ubuntu/i'             => 'Ubuntu',
                '/iphone/i'             => 'iPhone',
                '/ipod/i'               => 'iPod',
                '/ipad/i'               => 'iPad',
                '/android/i'            => 'Android',
                '/blackberry/i'         => 'BlackBerry',
                '/webos/i'              => 'Mobile'
            );
            foreach ($os_array as $regex => $value) {
                if (array_key_exists('HTTP_USER_AGENT',$_SERVER) && preg_match($regex, $_SERVER['HTTP_USER_AGENT'])) {
                    $os_platform = $value;
                }
            }
            return ($os_platform)?:$_SERVER['HTTP_USER_AGENT'];
        }

        /**
         * @param $url path for destination ($dest is empty) or for source ($dest if not empty)
         * @param string $dest Optional destination. If empty, destination will be $url
         */
        function urlRedirect($url, $dest = '')
        {
            if (!strlen($dest)) {
                if ($url != $this->url['url']) {
                    Header("Location: $url");
                    exit;
                }
            } else if ($url == $this->url['url'] && $url != $dest) {
                if (strlen($this->url['params'])) {
                    if (strpos($dest, '?') === false)
                        $dest .= "?" . $this->url['params'];
                    else
                        $dest .= "&" . $this->url['params'];
                }
                Header("Location: $dest");
                exit;
            }
        }

        function getRequestFingerPrint($extra = '')
        {
            // Return the fingerprint coming from a queue
            if (isset($_REQUEST['cloudframework_queued_fingerprint'])) {
                return (json_decode($_REQUEST['cloudframework_queued_fingerprint'], true));
            }

            $ret['user_agent'] = (isset($_SERVER['HTTP_USER_AGENT']))?$_SERVER['HTTP_USER_AGENT']:'unknown';
            $ret['host'] = (isset($_SERVER['HTTP_HOST']))?$_SERVER['HTTP_HOST']:null;
            $ret['software'] = $_SERVER['SERVER_SOFTWARE'];
            if ($extra == 'geodata') {
                $ret['geoData'] = $this->core->getGeoData();
                unset($ret['geoData']['source_ip']);
                unset($ret['geoData']['credit']);
            }
            $ret['hash'] = sha1(implode(",", $ret));
            $ret['ip'] = $this->ip;
            $ret['http_referer'] = (array_key_exists('HTTP_REFERER',$_SERVER))?$_SERVER['HTTP_REFERER']:'unknown';
            $ret['time'] = date('Ymdhis');
            $ret['uri'] = (isset($_SERVER['REQUEST_URI']))?$_SERVER['REQUEST_URI']:null;
            return ($ret);
        }

        function crypt($input, $rounds = 7)
        {
            $salt = "";
            $salt_chars = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));
            for ($i = 0; $i < 22; $i++) {
                $salt .= $salt_chars[array_rand($salt_chars)];
            }
            return crypt($input, sprintf('$2a$%02d$', $rounds) . $salt);
        }

        // Compare Password
        function checkPassword($passw, $compare)
        {
            return (crypt($passw, $compare) == $compare);
        }

    }

    /**
     * Class to manage Logs & Errors
     * @package Core
     */
    class CoreLog
    {
        var $lines = 0;
        var $data = [];
        var $syslog_type = LOG_DEBUG;

        /**
         * Reset the log and add an entry in the log.. if syslog_title is passed, also insert a LOG_DEBUG
         * @param $data
         * @param string $syslog_title
         */
        function set($data,$syslog_title=null, $syslog_type=null)
        {
            $this->lines = 0;
            $this->data = [];
            $this->add($data,$syslog_title, $syslog_type);
        }

        /**
         * Add an entry in the log.. if syslog_title is passed, also insert a LOG_DEBUG
         * @param $data
         * @param string $syslog_title
         */
        function add($data, $syslog_title=null, $syslog_type=null)
        {
            // Evaluate to write in syslog
            if(null !==  $syslog_title) {

                if(null==$syslog_type) $syslog_type = $this->syslog_type;
                else $syslog_type = intval($syslog_type);
                syslog($syslog_type, $syslog_title.': '. json_encode($data,JSON_FORCE_OBJECT));

                // Change the data sent to say that the info has been sent to syslog
                if(is_string($data))
                    $data = 'SYSLOG '.$syslog_title.': '.$data;
                else
                    $data = ['SYSLOG '.$syslog_title=>$data];
            }

            // Store in local var.
            $this->data[] = $data;
            $this->lines++;

        }

        /**
         * return the current data stored in the log
         * @return array
         */
        function get() { return $this->data; }

        /**
         * store all the data inside a syslog
         * @param $title
         */
        function sendToSysLog($title,$syslog_type=null) {

            switch ($syslog_type) {
                case "error":
                    $syslog_type = LOG_ERR;
                    break;
                case "warning":
                    $syslog_type = LOG_WARNING;
                    break;
                case "notice":
                    $syslog_type = LOG_NOTICE;
                    break;
                case "debug":
                    $syslog_type = LOG_DEBUG;
                    break;
                case "critical":
                    $syslog_type = LOG_CRIT;
                    break;
                case "alert":
                    $syslog_type = LOG_ALERT;
                    break;
                case "emergency":
                    $syslog_type = LOG_EMERG;
                    break;
                default:
                    $syslog_type = LOG_INFO;
                    break;
            }
            syslog($syslog_type, $title. json_encode($this->data,JSON_FORCE_OBJECT));
        }

        /**
         * Reset the log
         */
        function reset()
        {
            $this->lines = 0;
            $this->data = [];
        }

    }

    /**
     * Class to answer is? questions
     * @package Core
     */
    class CoreIs
    {
        function development()
        {
            return (array_key_exists('SERVER_SOFTWARE',$_SERVER) && stripos($_SERVER['SERVER_SOFTWARE'], 'Development') !== false || isset($_SERVER['PWD']));
        }

        function production()
        {
            return (array_key_exists('SERVER_SOFTWARE',$_SERVER) &&  stripos($_SERVER['SERVER_SOFTWARE'], 'Development') === false && !isset($_SERVER['PWD']));
        }

        function script()
        {
            return (isset($_SERVER['PWD']));
        }

        function dirReadble($dir)
        {
            if (strlen($dir)) return (is_dir($dir));
        }

        function terminal()
        {
            return isset($_SERVER['PWD']);
        }

        function dirWritable($dir)
        {
            if (strlen($dir)) {
                if (!$this->dirReadble($dir)) return false;
                try {
                    if (@mkdir($dir . '/__tmp__')) {
                        rmdir($dir . '/__tmp__');
                        return (true);
                    }
                } catch (Exception $e) {
                    return false;
                }
            }
        }

        function validEmail($email)
        {
            return (filter_var($email, FILTER_VALIDATE_EMAIL));
        }

        function validURL($url)
        {
            return (filter_var($url, FILTER_VALIDATE_URL));
        }
    }

    /**
     * Class to manage HTTP requests
     * @package Core
     */
    class CoreRequest
    {
        protected $core;
        protected $http;
        public $responseHeaders;
        public $error = false;
        public $errorMsg = [];
        public $options = null;
        private $curl = [];
        var $rawResult = '';
        var $automaticHeaders = true; // Add automatically the following headers if exist on config: X-CLOUDFRAMEWORK-SECURITY, X-SERVER-KEY, X-SERVER-KEY, X-DS-TOKEN,X-EXTRA-INFO

        function __construct(Core &$core)
        {
            $this->core = $core;
            if (!$this->core->config->get("CloudServiceUrl"))
                $this->core->config->set("CloudServiceUrl", 'https://api7.cloudframework.io');

        }

        /**
         * @param string $path Path to complete URL. if it does no start with http.. $path will be aggregated to: $this->core->config->get("CloudServiceUrl")
         * @return string
         */
        function getServiceUrl($path = '')
        {
            if (strpos($path, 'http') === 0) return $path;
            else {
                if (!$this->core->config->get("CloudServiceUrl"))
                    $this->core->config->set("CloudServiceUrl", 'https://api7.cloudframework.io');

                $this->http = $this->core->config->get("CloudServiceUrl");

                if (strlen($path) && $path[0] != '/')
                    $path = '/' . $path;
                return ($this->http . $path);
            }
        }

        /**
         * Call External Cloud Service Caching the result
         */
        function getCache($route, $data = null, $verb = 'GET', $extraheaders = null, $raw = false)
        {
            $_qHash = hash('md5', $route . json_encode($data) . $verb);
            $ret = $this->core->cache->get($_qHash);
            if (isset($_GET['refreshCache']) || $ret === false || $ret === null) {
                $ret = $this->get($route, $data, $extraheaders, $raw);
                // Only cache successful responses.
                if (is_array($this->responseHeaders) && isset($this->responseHeaders[0]) && strpos($this->responseHeaders[0], 'OK')) {
                    $this->core->cache->set($_qHash, $ret);
                }
            }
            return ($ret);
        }

        function getCurl($route, $data = null, $verb = 'GET', $extra_headers = null, $raw = false)
        {
            $this->core->__p->add('Request->getCurl: ', "$route " . (($data === null) ? '{no params}' : '{with params}'), 'note');
            $route = $this->getServiceUrl($route);
            $this->responseHeaders = null;
            $options['http']['header'] = ['Connection: close', 'Expect:', 'ACCEPT:']; // improve perfomance and avoid 100 HTTP Header


            // Automatic send header for X-CLOUDFRAMEWORK-SECURITY if it is defined in config
            if (strlen($this->core->config->get("CloudServiceId")) && strlen($this->core->config->get("CloudServiceSecret")))
                $options['http']['header'][] = 'X-CLOUDFRAMEWORK-SECURITY: ' . $this->generateCloudFrameWorkSecurityString($this->core->config->get("CloudServiceId"), microtime(true), $this->core->config->get("CloudServiceSecret"));

            // Extra Headers
            if ($extra_headers !== null && is_array($extra_headers)) {
                foreach ($extra_headers as $key => $value) {
                    $options['http']['header'][] .= $key . ': ' . $value;
                }
            }

            # Content-type for something different than get.
            if ($verb != 'GET') {
                if (stripos(json_encode($options['http']['header']), 'Content-type') === false) {
                    if ($raw) {
                        $options['http']['header'][] = 'Content-type: application/json';
                    } else {
                        $options['http']['header'][] = 'Content-type: application/x-www-form-urlencoded';
                    }
                }
            }
            // Build contents received in $data as an array
            if (is_array($data)) {
                if ($verb == 'GET') {
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            // This could be improved becuase the coding will produce 1738 format and 3986 format
                            $route .= http_build_query([$key => $value]) . '&';
                        } else {
                            $route .= $key . '=' . rawurlencode($value) . '&';
                        }
                    }
                } else {
                    if ($raw) {
                        if (stripos(json_encode($options['http']['header']), '/json') !== false) {
                            $build_data = json_encode($data);
                        } else
                            $build_data = $data;
                    } else {
                        $build_data = http_build_query($data);
                    }
                    $options['http']['content'] = $build_data;

                    // You have to calculate the Content-Length to run as script
                    // $options['http']['header'][] = sprintf('Content-Length: %d', strlen($build_data));
                }
            }

            $curl_options = [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,            // return headers
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => $options['http']['header'],
                CURLOPT_CUSTOMREQUEST => $verb

            ];
            // Appengine  workaround
            // $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
            // $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
            // Download https://pki.google.com/GIAG2.crt
            // openssl x509 -in GIAG2.crt -inform DER -out google.pem -outform PEM
            // $curl_options[CURLOPT_CAINFO] =__DIR__.'/google.pem';

            if (isset($options['http']['content'])) {
                $curl_options[CURLOPT_POSTFIELDS] = $options['http']['content'];
            }

            // Cache
            $ch = curl_init($route);
            curl_setopt_array($ch, $curl_options);
            $ret = curl_exec($ch);

            if (!curl_errno($ch)) {
                $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $this->responseHeaders = substr($ret, 0, $header_len);
                $ret = substr($ret, $header_len);
            } else {
                $this->addError(error_get_last());
                $this->addError([('Curl error ' . curl_errno($ch)) => curl_error($ch)]);
                $this->addError(['Curl url' => $route]);
                $ret = false;
            }
            curl_close($ch);

            $this->core->__p->add('Request->getCurl: ', '', 'endnote');
            return $ret;


        }


        function get_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->get($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        function post_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->post($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        function put_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->put($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        function delete_json_decode($route, $extra_headers = null)
        {
            $this->rawResult = $this->delete($route, $extra_headers);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        function get($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'GET', $extra_headers, $send_in_json);
        }

        function post($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'POST', $extra_headers, $send_in_json);
        }

        function put($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'PUT', $extra_headers, $send_in_json);
        }

        function patch($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'PATCH', $extra_headers, $send_in_json);
        }

        function delete($route, $extra_headers = null)
        {
            return $this->call($route, null, 'DELETE', $extra_headers);
        }

        function call($route, $data = null, $verb = 'GET', $extra_headers = null, $raw = false)
        {
            $route = $this->getServiceUrl($route);
            $this->responseHeaders = null;

            $this->core->logs->sendToSysLog("request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'));
            //syslog(LOG_INFO,"request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'));

            $this->core->__p->add("Request->{$verb}: ", "$route " . (($data === null) ? '{no params}' : '{with params}'), 'note');
            // Performance for connections
            $options = array('ssl' => array('verify_peer' => false));
            $options['http']['ignore_errors'] = '1';
            $options['http']['header'] = 'Connection: close' . "\r\n";

            if($this->automaticHeaders) {
                // Automatic send header for X-CLOUDFRAMEWORK-SECURITY if it is defined in config
                if (strlen($this->core->config->get("CloudServiceId")) && strlen($this->core->config->get("CloudServiceSecret")))
                    $options['http']['header'] .= 'X-CLOUDFRAMEWORK-SECURITY: ' . $this->generateCloudFrameWorkSecurityString($this->core->config->get("CloudServiceId"), microtime(true), $this->core->config->get("CloudServiceSecret")) . "\r\n";

                // Add Server Key if we have it.
                if (strlen($this->core->config->get("CloudServerKey")))
                    $options['http']['header'] .= 'X-SERVER-KEY: ' . $this->core->config->get("CloudServerKey") . "\r\n";

                // Add Server Key if we have it.
                if (strlen($this->core->config->get("X-DS-TOKEN")))
                    $options['http']['header'] .= 'X-DS-TOKEN: ' . $this->core->config->get("X-DS-TOKEN") . "\r\n";

                if (strlen($this->core->config->get("X-EXTRA-INFO")))
                    $options['http']['header'] .= 'X-EXTRA-INFO: ' . $this->core->config->get("X-EXTRA-INFO") . "\r\n";
            }
            // Extra Headers
            if ($extra_headers !== null && is_array($extra_headers)) {
                foreach ($extra_headers as $key => $value) {
                    $options['http']['header'] .= $key . ': ' . $value . "\r\n";
                }
            }

            // Method
            $options['http']['method'] = $verb;


            // Content-type
            if ($verb != 'GET')
                if (stripos($options['http']['header'], 'Content-type') === false) {
                    if ($raw) {
                        $options['http']['header'] .= 'Content-type: application/json' . "\r\n";
                    } else {
                        $options['http']['header'] .= 'Content-type: application/x-www-form-urlencoded' . "\r\n";
                    }
                }


            // Build contents received in $data as an array
            if (is_array($data)) {
                if ($verb == 'GET') {
                    if (strpos($route, '?') === false) $route .= '?';
                    else $route .= '&';
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            // This could be improved becuase the coding will produce 1738 format and 3986 format
                            $route .= http_build_query([$key => $value]) . '&';
                        } else {
                            $route .= $key . '=' . rawurlencode($value) . '&';
                        }
                    }
                } else {
                    if ($raw) {
                        if (stripos($options['http']['header'], 'application/json') !== false) {
                            $build_data = json_encode($data);
                        } else
                            $build_data = $data;
                    } else {
                        $build_data = http_build_query($data);
                    }
                    $options['http']['content'] = $build_data;

                    // You have to calculate the Content-Length to run as script
                    if($this->core->is->script())
                        $options['http']['header'] .= sprintf('Content-Length: %d', strlen($build_data)) . "\r\n";
                }
            }
            // Take data as a valid JSON
            elseif(is_string($data)) {
                if(is_array(json_decode($data,true))) $options['http']['content'] = $data;
            }


            // Save in the class the last options sent
            $this->options = ['route'=>$route,'options'=>$options];
            // Context creation
            $context = stream_context_create($options);

            try {
                $ret = @file_get_contents($route, false, $context);

                // Return response headers
                if(isset($http_response_header)) $this->responseHeaders = $http_response_header;
                else $this->responseHeaders = ['$http_response_header'=>'undefined'];

                // If we have an error
                if ($ret === false) {
                    $this->addError(['route_error'=>$route,'reponse_headers'=>$this->responseHeaders,'system_error'=>error_get_last()]);
                } else {
                    $code = $this->getLastResponseCode();
                    if ($code === null) {
                        $this->addError('Return header not found');
                        $this->addError($this->responseHeaders);
                        $this->addError($ret);
                    } else {
                        if ($code >= 400) {
                            $this->addError('Error code returned: ' . $code);
                            $this->addError($this->responseHeaders);
                            $this->addError($ret);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->addError(error_get_last());
                $this->addError($e->getMessage());
            }

            $this->core->logs->sendToSysLog("end request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'),(($this->error)?'debug':'info'));
            //syslog(($this->error)?LOG_DEBUG:LOG_INFO,"end request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'));

            $this->core->__p->add("Request->{$verb}: ", '', 'endnote');
            return ($ret);
        }

        function getLastResponseCode()
        {
            $code = null;
            if (isset($this->responseHeaders[0])) {
                list($foo, $code, $foo) = explode(' ', $this->responseHeaders[0], 3);
            }
            return $code;

        }

        // time, has to to be microtime().
        function generateCloudFrameWorkSecurityString($id, $time = '', $secret = '')
        {
            $ret = null;
            if (!strlen($secret)) {
                $secArr = $this->core->config->get('CLOUDFRAMEWORK-ID-' . $id);
                if (isset($secArr['secret'])) $secret = $secArr['secret'];
            }
            if (!strlen($secret)) {
                $this->core->logs->add('conf-var CLOUDFRAMEWORK-ID-' . $id . ' missing.');
            } else {
                if (!strlen($time)) $time = microtime(true);
                $date = new \DateTime(null, new \DateTimeZone('UTC'));
                $time += $date->getOffset();
                $ret = $id . '__UTC__' . $time;
                $ret .= '__' . hash_hmac('sha1', $ret, $secret);
            }
            return $ret;
        }
        /**
         * @param string $url
         * @param int $format
         * @desc Fetches all the headers
         * @return array
         */
        function getUrlHeaders($url)
        {
            if(!$this->core->is->validURL($url)) return($this->core->errors->add('invalid url: '.$url));
            if(!($headers = @get_headers($url))) {
                $this->core->errors->add(error_get_last()['message']);
            }
            return $headers;

            /*
            $url_info=parse_url($url);
            if (isset($url_info['scheme']) && $url_info['scheme'] == 'https') {
                $port = 443;
                $fp= @fsockopen('ssl://'.$url_info['host'], $port, $errno, $errstr, 30);
            } else {
                $port = isset($url_info['port']) ? $url_info['port'] : 80;
                $fp = @fsockopen($url_info['host'], $port, $errno, $errstr, 30);

            }
            if($fp)
            {
                $head = "HEAD ".@$url_info['path']."?".@$url_info['query']." HTTP/1.0\r\nHost: ".@$url_info['host']."\r\n\r\n";
                fputs($fp, $head);
                while(!feof($fp))
                {
                    if($header=trim(fgets($fp, 1024)))
                    {
                        if($format == 1)
                        {
                            $key = array_shift(explode(':',$header));
                            // the first element is the http header type, such as HTTP 200 OK,
                            // it doesn't have a separate name, so we have to check for it.
                            if($key == $header)
                            {
                                $headers[] = $header;
                            }
                            else
                            {
                                $headers[$key]=substr($header,strlen($key)+2);
                            }
                            unset($key);
                        }
                        else
                        {
                            $headers[] = $header;
                        }
                    }
                }
                fclose($fp);
                return $headers;
            }
            else
            {
                $this->core->errors->add(error_get_last());
                return false;
            }
            */
        }

        /**
         * @param $url
         * @param $header key name of the header to get.. If not passed return all the array
         * @return string|array
         */
        function getUrlHeader($url, $header=null) {
            $ret = 'error';
            $response = $this->getUrlHeaders($url);
            $headers = [];
            foreach ($response as $i=>$item) {
                if($i==0) $headers['response'] = $item;
                else {
                    list($key,$value) = explode(':',strtolower($item),2);
                    $headers[$key] = $value;
                }
            }
            if($header) return($headers[strtolower($header)]);
            else return $headers;
        }

        function addError($value)
        {
            $this->error = true;
            $this->core->errors->add($value);
            $this->errorMsg[] = $value;
        }
        public function getResponseHeader($key) {

            if(is_array($this->responseHeaders))
                foreach ($this->responseHeaders as $responseHeader)
                    if(strpos($responseHeader,$key)!==false) {
                        list($header_key,$content) = explode(':',$responseHeader,2);
                        $content = trim($content);
                        return $content;
                    }
            return null;
        }

        function sendLog($type, $cat, $subcat, $title, $text = '', $email = '', $app = '', $interactive = false)
        {

            if (!strlen($app)) $app = $this->core->system->url['host'];

            $this->core->logs->add(['sending cloud service logs:' => [$this->getServiceUrl('queue/cf_logs/' . $app), $type, $cat, $subcat, $title]]);
            if (!$this->core->config->get('CloudServiceLog') && !$this->core->config->get('LogPath')) return false;
            $app = str_replace(' ', '_', $app);
            $params['id'] = $this->core->config->get('CloudServiceId');
            $params['cat'] = $cat;
            $params['subcat'] = $subcat;
            $params['title'] = $title;
            if (!is_string($text)) $text = json_encode($text);
            $params['text'] = $text . ((strlen($text)) ? "\n\n" : '');
            if ($this->core->errors->lines) $params['text'] .= "Errors: " . json_encode($this->core->errors->data, JSON_PRETTY_PRINT) . "\n\n";
            if (count($this->core->logs->lines)) $params['text'] .= "Logs: " . json_encode($this->core->logs->data, JSON_PRETTY_PRINT);

            // IP gathered from queue
            if (isset($_REQUEST['cloudframework_queued_ip']))
                $params['ip'] = $_REQUEST['cloudframework_queued_ip'];
            else
                $params['ip'] = $this->core->system->ip;

            // IP gathered from queue
            if (isset($_REQUEST['cloudframework_queued_fingerprint']))
                $params['fingerprint'] = $_REQUEST['cloudframework_queued_fingerprint'];
            else
                $params['fingerprint'] = json_encode($this->core->system->getRequestFingerPrint(), JSON_PRETTY_PRINT);

            // Tell the service to send email of the report.
            if (strlen($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                $params['email'] = $email;
            if ($this->core->config->get('CloudServiceLog')) {
                $ret = $this->core->jsonDecode($this->get('queue/cf_logs/' . urlencode($app) . '/' . urlencode($type), $params, 'POST'), true);
                if (is_array($ret) && !$ret['success']) $this->addError($ret);
            } else {
                $ret = 'Sending to LogPath not yet implemented';
            }
            return $ret;
        }

        function sendCorsHeaders($methods = 'GET,POST,PUT', $origin = '')
        {

            // Rules for Cross-Domain AJAX
            // https://developer.mozilla.org/en-US/docs/Web/HTTP/Access_control_CORS
            // $origin =((strlen($_SERVER['HTTP_ORIGIN']))?preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']):'*')
            if (!strlen($origin)) $origin = ((strlen($_SERVER['HTTP_ORIGIN'])) ? preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']) : '*');
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Methods: $methods");
            header("Access-Control-Allow-Headers: Content-Type,Authorization,X-CloudFrameWork-AuthToken,X-CLOUDFRAMEWORK-SECURITY,X-DS-TOKEN,X-REST-TOKEN,X-EXTRA-INFO,X-WEB-KEY,X-SERVER-KEY,X-REST-USERNAME,X-REST-PASSWORD,X-APP-KEY");
            header("Access-Control-Allow-Credentials: true");
            header('Access-Control-Max-Age: 1000');

            // To avoid angular Cross-Reference
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
                header("HTTP/1.1 200 OK");
                exit();
            }


        }


        function getHeader($str)
        {
            $str = strtoupper($str);
            $str = str_replace('-', '_', $str);
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : '');
        }

        function getHeaders()
        {
            $ret = array();
            foreach ($_SERVER as $key => $value) if (strpos($key, 'HTTP_') === 0) {
                $ret[str_replace('HTTP_', '', $key)] = $value;
            }
            return ($ret);
        }


    }

}


// CloudSQL Class v10
if (!defined("_RESTfull_CLASS_")) {
    define("_RESTfull_CLASS_", TRUE);

    class RESTful
    {

        var $formParams = array();
        var $rawData = array();
        var $params = array();
        var $error = 0;
        var $code = null;
        var $codeLib = [];
        var $codeLibError = [];
        var $ok = 200;
        var $errorMsg = [];
        var $message = "";
        var $extra_headers = [];
        var $requestHeaders = array();
        var $method = '';
        var $contentTypeReturn = 'JSON';
        var $url = '';
        var $urlParams = '';
        var $returnData = null;
        var $auth = true;
        var $referer = null;

        var $service = '';
        var $serviceParam = '';
        var $org_id = '';
        var $rewrite = [];
        /** @var Core7|null */
        var $core = null;

        function __construct(Core &$core, $apiUrl = '/h/api')
        {
            $this->core = $core;
            // FORCE Ask to the browser the basic Authetication
            if (isset($_REQUEST['_forceBasicAuth'])) {
                if (($_REQUEST['_forceBasicAuth'] !== '0' && !$this->core->security->existBasicAuth())
                    || ($_REQUEST['_forceBasicAuth'] === '0' && $this->core->security->existBasicAuth())

                ) {
                    header('WWW-Authenticate: Basic realm="Test Authentication System"');
                    header('HTTP/1.0 401 Unauthorized');
                    echo "You must enter a valid login ID and password to access this resource\n";
                    exit;

                }
            }
            $this->core->__p->add("RESTFull: ", __FILE__, 'note');

            // $this->requestHeaders = apache_request_headers();
            $this->method = (isset($_SERVER['REQUEST_METHOD']) && strlen($_SERVER['REQUEST_METHOD'])) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($this->method == 'GET') {
                $this->formParams = &$_GET;
                if (isset($_GET['_raw_input_']) && strlen($_GET['_raw_input_'])) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, json_decode($_GET['_raw_input_'], true)) : json_decode($_GET['_raw_input_'], true);
            } else {
                if (count($_GET)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParam, $_GET) : $_GET;
                if (count($_POST)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $_POST) : $_POST;

                // Reading raw format is _raw_input is passed
                //POST
                $raw = null;
                if (isset($_POST['_raw_input_']) && strlen($_POST['_raw_input_'])) $raw = json_decode($_POST['_raw_input_'], true);
                if (is_array($raw)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $raw) : $raw;
                // GET
                $raw = null;
                if (isset($_GET['_raw_input_']) && strlen($_GET['_raw_input_'])) $raw = json_decode($_GET['_raw_input_'], true);
                if (is_array($raw)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $raw) : $raw;


                // raw data.
                $input = file_get_contents("php://input");

                if (strlen($input)) {
                    $this->formParams['_raw_input_'] = $input;

                    // Try to parse as a JSON
                    $input_array = json_decode($input, true);

                    if (!is_array($input_array) && strpos($input, "\n") === false && strpos($input, "=")) {
                        parse_str($input, $input_array);
                    }

                    if (is_array($input_array)) {
                        $this->formParams = array_merge($this->formParams, $input_array);
                        unset($input_array);

                    }
                    /*
                   if(strpos($this->requestHeaders['Content-Type'], 'json')) {
                   }
                     *
                     */
                }

                // Trimming fields
                foreach ($this->formParams as $i => $data) if (is_string($data)) $this->formParams[$i] = trim($data);
            }


            // URL splits
            $this->url = (isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : '';
            $this->urlParams = '';
            if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '?') !== false)
                list($this->url, $this->urlParams) = explode('?', $_SERVER['REQUEST_URI'], 2);

            // API URL Split. If $this->core->system->url['parts_base_url'] take it out
            $url = $this->url;
            if ($this->url) list($foo, $url) = explode($this->core->system->url['parts_base_url'] , $this->url, 2);
            $this->service = $url;
            $this->serviceParam = '';
            $this->params = [];


            if (strpos($url, '/') !== false) {
                list($this->service, $this->serviceParam) = explode('/', $url, 2);
                $this->service = strtolower($this->service);
                $this->params = explode('/', $this->serviceParam);
            }

            // Based on: http://www.restapitutorial.com/httpstatuscodes.html
            $this->addCodeLib('ok', 'OK', 200);
            $this->addCodeLib('inserted', 'Inserted succesfully', 201);
            $this->addCodeLib('no-content', 'No content', 204);
            $this->addCodeLib('form-params-error', 'Wrong form paramaters.', 400);
            $this->addCodeLib('params-error', 'Wrong parameters.', 400);
            $this->addCodeLib('security-error', 'You don\'t have right credentials.', 401);
            $this->addCodeLib('not-allowed', 'You are not allowed.', 403);
            $this->addCodeLib('not-found', 'Not Found', 404);
            $this->addCodeLib('method-error', 'Wrong method.', 405);
            $this->addCodeLib('conflict', 'There are conflicts.', 409);
            $this->addCodeLib('gone', 'The resource is not longer available.', 410);
            $this->addCodeLib('unsupported-media', 'Unsupported Media Type.', 415);
            $this->addCodeLib('system-error', 'There is a problem in the platform.', 503);
            $this->addCodeLib('datastore-error', 'There is a problem with the DataStore.', 503);
            $this->addCodeLib('db-error', 'There is a problem in the DataBase.', 503);
            if (method_exists($this, '__codes')) {
                $this->__codes();
            }

        }

        function sendCorsHeaders($methods = 'GET,POST,PUT', $origin = '')
        {

            // Rules for Cross-Domain AJAX
            // https://developer.mozilla.org/en-US/docs/Web/HTTP/Access_control_CORS
            // $origin =((strlen($_SERVER['HTTP_ORIGIN']))?preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']):'*')
            if (!strlen($origin)) $origin = ((array_key_exists('HTTP_ORIGIN', $_SERVER) && strlen($_SERVER['HTTP_ORIGIN'])) ? preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']) : '*');
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Methods: $methods");
            header("Access-Control-Allow-Headers: Content-Type,Authorization,X-CloudFrameWork-AuthToken,X-CLOUDFRAMEWORK-SECURITY,X-DS-TOKEN,X-REST-TOKEN,X-EXTRA-INFO,X-WEB-KEY,X-SERVER-KEY,X-REST-USERNAME,X-REST-PASSWORD,X-APP-KEY,Cache-Control,origin,x-requested-with");
            header("Access-Control-Allow-Credentials: true");
            header('Access-Control-Max-Age: 1000');

            // To avoid angular Cross-Reference
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
                header("HTTP/1.1 200 OK");
                exit();
            }


        }

        function setAuth($val, $msg = '')
        {
            if (!$val) {
                $this->setError($msg, 401);
            }
        }


        function checkMethod($methods, $msg = '')
        {
            if (strpos(strtoupper($methods), $this->method) === false) {
                if (!strlen($msg)) $msg = 'Method ' . $this->method . ' is not supported';
                $this->setErrorFromCodelib('method-error', $msg);
            }
            return ($this->error === 0);
        }

        function checkMandatoryFormParam($key, $msg = '', $values = [], $min_length = 1, $code = null)
        {
            if (isset($this->formParams[$key]) && is_string($this->formParams[$key]))
                $this->formParams[$key] = trim($this->formParams[$key]);

            if (!isset($this->formParams[$key])
                || (is_string($this->formParams[$key]) && strlen($this->formParams[$key]) < $min_length)
                || (is_array($this->formParams[$key]) && count($this->formParams[$key]) < $min_length)
                || (is_array($values) && count($values) && !in_array($this->formParams[$key], $values))
            ) {
                if (!strlen($msg))
                    $msg = "{{$key}}" . ((!isset($this->formParams[$key])) ? ' form-param missing ' : ' form-params\' length is less than: ' . $min_length);
                if (!$code) $code = 'form-params-error';
                $this->setError($msg, 400, $code, $msg);
            }
            return ($this->error === 0);

        }

        /**
         * Check if it is received mandatory params
         * @param array $keys with format: [key1,kye2,..] or [[key1,msg,[allowed,values],min_length],[]]
         * @return bool|void
         */
        function checkMandatoryFormParams($keys)
        {
            if (!$keys) return;

            if (!is_array($keys) && strlen($keys)) $keys = array($keys);
            foreach ($keys as $i => $item) if (!is_array($item)) $keys[$i] = array($item);

            foreach ($keys as $key) if (is_string($key[0])) {
                $fkey = $key[0];
                $fmsg = (isset($key[1])) ? $key[1] : '';
                $fvalues = (array_key_exists(2, $key) && is_array($key[2])) ? $key[2] : [];
                $fmin = (isset($key[3])) ? $key[3] : 1;
                $fcode = (isset($key[4])) ? $key[4] : null;
                $this->checkMandatoryFormParam($fkey, $fmsg, $fvalues, $fmin, $fcode);
            }
            return ($this->error === 0);
        }

        /**
         * Check the form paramters received based in a json model
         * @param array $model
         * @param string $codelibbase
         * @param null $data
         * @return bool
         */
        function validatePostData($model, $codelibbase = 'error-form-params', &$data = null, &$dictionaries = [])
        {

            if (null === $data) $data = &$this->formParams;
            if (!($ret = $this->checkFormParamsFromModel($model, true, $codelibbase, $data, $dictionaries))) return;

            if (is_array($model)) foreach ($model as $field => $props) {
                if (array_key_exists('validation', $props) && strpos($props['validation'], 'internal') !== false && array_key_exists($field, $data)) {
                    $this->setErrorFromCodelib($codelibbase . '-' . $field, $field . ' is internal and can not be rewritten');
                    return false;
                }
            }
            return $ret;
        }

        function validatePutData($model, $codelibbase = 'error-form-params', &$data = null, &$dictionaries = [])
        {
            if (null === $data) $data = &$this->formParams;
            if (!($ret = $this->checkFormParamsFromModel($model, false, $codelibbase, $data, $dictionaries))) return;

            if (is_array($model)) foreach ($model as $field => $props) {
                if (array_key_exists('validation', $props) && strpos($props['validation'], 'internal') !== false && array_key_exists($field, $data)) {
                    $this->setErrorFromCodelib($codelibbase . '-' . $field, $field . ' is internal and can not be rewritten');
                    return false;
                }
            }
            return $ret;
        }

        function checkFormParamsFromModel(&$model, $all = true, $codelibbase = '', &$data = null, &$dictionaries = [])
        {
            if (!is_array($model)) {
                $this->core->logs->add('Passed a non array model in checkFormParamsFromModel(array $model,...)');
                return false;
            }
            if ($this->error) return false;
            if (null === $data) $data = &$this->formParams;

            /* Control the params of the URL */
            $params = [];
            if (isset($model['_params'])) {
                $params = $model['_params'];
                unset($model['_params']);
            }

            //region validate there are not internal fields
            foreach ($data as $key => $datum) {
                if (in_array($key, $model) && isset($model[$key]['validation']) && stripos($model[$key]['validation'], 'internal') !== false)
                    return ($this->setErrorFromCodelib('params-error', $key . ': not allowed in form validation'));
            }
            //endregion

            //region validate there are not internal fields
            if (!$this->error) {
                /* @var $dv DataValidation */
                $dv = $this->core->loadClass('DataValidation');
                if (!$dv->validateModel($model, $data, $dictionaries, $all)) {
                    if ($dv->typeError == 'field') {
                        if (strlen($codelibbase))
                            $this->setErrorFromCodelib($codelibbase . '-' . $dv->field, $dv->errorMsg, 400, $codelibbase . '-' . $dv->field);
                        else
                            $this->setError($dv->field . ': ' . $dv->errorMsg, 400);
                    } else {
                        if (strlen($codelibbase))
                            $this->setError($this->getCodeLib($codelibbase) . '-' . $dv->field . ': ' . $dv->errorMsg, 503);
                        else
                            $this->setError($dv->field . ': ' . $dv->errorMsg, 503);
                    }
                    if (count($dv->errorFields))
                        $this->core->errors->add($dv->errorFields);
                }
            }
            //endregion

            if (!$this->error && count($params)) {
                if (!$dv->validateModel($params, $this->params, $dictionaries, $all)) {
                    if (strlen($codelibbase)) {
                        $this->setErrorFromCodelib($codelibbase . '-' . $dv->field, $dv->errorMsg);
                    } else
                        $this->setError($dv->field . ': ' . $dv->errorMsg);
                }
            }
            return !$this->error;
        }


        /**
         * Validate that a specific parameter exist: /{end-point}/parameter[0]/parameter[1]/parameter[2]/..
         * @param $pos
         * @param string $msg
         * @param array $validation
         * @param null $code
         * @return bool
         */
        function checkMandatoryParam($pos, $msg = '', $validation = [], $code = null)
        {
            if (!isset($this->params[$pos])) {
                $error = true;
            } else {
                $this->params[$pos] = trim($this->params[$pos]); // TRIM
                $error = strlen($this->params[$pos]) == 0;         // If empty error
            }

            // Validation by array values
            if (!$error && (is_array($validation) && count($validation) && !in_array($this->params[$pos], $validation))) $error = true;

            // Validation by string of validation
            if (!$error && is_string($validation) && strlen($validation)) {
                /* @var $dv DataValidation */
                $dv = $this->core->loadClass('DataValidation');
                $model = ["params[$pos]" => ['type' => 'string', 'validation' => $validation]];
                $data = ["params[$pos]" => $this->params[$pos]];
                if (!$dv->validateModel($model, $data)) {
                    $msg .= '. Validation error: ' . $dv->errorMsg . ' [' . $validation . ']';
                    $error = true;
                }
            }
            // Generate Error
            if ($error) {
                if (empty($code)) $code = 'params-error';
                $this->setErrorFromCodelib($code, ($msg == '') ? 'param ' . $pos . ' is mandatory' : $msg);
            }

            // Return
            return (!$this->error);
        }

        function setError($value, $key = 400, $code = null, $message = '')
        {
            $this->error = $key;
            $this->errorMsg[] = $value;
            $this->core->errors->add($value);
            $this->code = (null !== $code) ? $code : $key;
            $this->message = ($message) ? $message : $this->code;
        }

        function addHeader($key, $value)
        {
            $this->extra_headers[] = "$key: $value";
        }

        function setReturnFormat($method)
        {
            switch (strtoupper($method)) {
                case 'JSON':
                case 'TEXT':
                case 'HTML':
                    $this->contentTypeReturn = strtoupper($method);
                    break;
                default:
                    $this->contentTypeReturn = 'JSON';
                    break;
            }
        }

        /**
         * Returns the info of the header Authorization if it exists, otherwise null
         * @return null|string
         */
        function getHeaderAuthorization()
        {
            $str = 'AUTHORIZATION';
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : null);
        }

        function getRequestHeader($str)
        {
            $str = strtoupper($str);
            $str = str_replace('-', '_', $str);
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : '');
        }

        function getResponseHeaders()
        {
            $ret = array();
            foreach ($_SERVER as $key => $value) if (strpos($key, 'HTTP_') === 0) {
                $ret[str_replace('HTTP_', '', $key)] = $value;
            }
            return ($ret);
        }


        function sendHeaders()
        {
            if ($this->core->is->terminal()) return;

            $header = $this->getResponseHeader();
            if (strlen($header)) header($header);
            foreach ($this->extra_headers as $header) {
                header($header);
            }
            switch ($this->contentTypeReturn) {
                case 'JSON':
                    header("Content-Type: application/json");

                    break;
                case 'TEXT':
                    header("Content-Type: text/plain");

                    break;
                case 'HTML':
                    header("Content-Type: text/html");

                    break;
                default:
                    header("Content-Type: " . $this->contentTypeReturn);
                    break;
            }


        }

        function setReturnResponse($response)
        {
            $this->returnData = $response;
        }

        function updateReturnResponse($response)
        {
            if (is_array($response))
                foreach ($response as $key => $value) {
                    $this->returnData[$key] = $value;
                }
        }

        function rewriteReturnResponse($response)
        {
            $this->rewrite = $response;
        }

        function setReturnData($data)
        {
            $this->returnData['data'] = $data;
        }

        function addReturnData($value)
        {
            if (!isset($this->returnData['data'])) $this->setReturnData($value);
            else {
                if (!is_array($value)) $value = array($value);
                if (!is_array($this->returnData['data'])) $this->returnData['data'] = array($this->returnData['data']);
                $this->returnData['data'] = array_merge($this->returnData['data'], $value);
            }
        }

        /**
         * Add a code for JSON output
         * @param $code
         * @param $msg
         * @param int $error
         * @param null $model
         */
        public function addCodeLib($code, $msg, $error = 400, array $model = null)
        {
            $this->codeLib[$code] = $msg;
            $this->codeLibError[$code] = $error;
            if (is_array($model))
                foreach ($model as $key => $value) {

                    $this->codeLib[$code . '-' . $key] = $msg . ' {' . $key . '}';
                    $this->codeLibError[$code . '-' . $key] = $error;

                    // If instead to pass [type=>,validation=>] pass [type,validaton]
                    if (count($value) && isset($value[0])) {
                        $value['type'] = $value[0];
                        if (isset($value[1])) $value['validation'] = $value[1];
                    }
                    $this->codeLib[$code . '-' . $key] .= ' [' . $value['type'] . ']';
                    // Show the validation associated to the field
                    if (isset($value['validation']))
                        $this->codeLib[$code . '-' . $key] .= '(' . $value['validation'] . ')';

                    if ($value['type'] == 'model') {
                        $this->addCodeLib($code . '-' . $key, $msg . ' ' . $key . '.', $error, $value['fields']);
                    }
                }
        }


        function getCodeLib($code)
        {
            return (isset($this->codeLib[$code])) ? $this->codeLib[$code] : $code;
        }

        function getCodeLibError($code)
        {
            return (isset($this->codeLibError[$code])) ? $this->codeLibError[$code] : 400;
        }

        function setErrorFromCodelib($code, $extramsg = '')
        {
            if (is_array($extramsg)) $extramsg = json_encode($extramsg, JSON_PRETTY_PRINT);
            if (strlen($extramsg)) $extramsg = " [{$extramsg}]";

            // Delete from code any character :.* to delete potential comments
            $this->setError($this->getCodeLib($code) . $extramsg, $this->getCodeLibError($code), preg_replace('/:.*/', '', $code));
        }

        /**
         * Return the code applied with setError and defined in __codes
         * @return int|string
         */
        function getReturnCode()
        {
            // if there is no code and status == 200 then 'ok'
            if (null === $this->code && $this->getReturnStatus() == 200) $this->code = 'ok';

            // Return the code or the status number if code is null
            return (($this->code !== null) ? $this->code : $this->getReturnStatus());
        }

        /**
         * Assign a code
         * @param $code
         */
        function setReturnCode($code)
        {
            $this->code = $code;
        }

        function getReturnStatus()
        {
            return (($this->error) ? $this->error : $this->ok);
        }

        function setReturnStatus($status)
        {
            $this->ok = $status;
        }

        function getResponseHeader($code=null)
        {
            if(!$code) $code = $this->getReturnStatus();
            switch ($code) {
                case 201:
                    $ret = ("HTTP/1.0 201 Created");
                    break;
                case 204:
                    $ret = ("HTTP/1.0 204 No Content");
                    break;
                case 405:
                    $ret = ("HTTP/1.0 405 Method Not Allowed");
                    break;
                case 400:
                    $ret = ("HTTP/1.0 400 Bad Request");
                    break;
                case 401:
                    $ret = ("HTTP/1.0 401 Unauthorized");
                    break;
                case 404:
                    $ret = ("HTTP/1.0 404 Not Found");
                    break;
                case 503:
                    $ret = ("HTTP/1.0 503 Service Unavailable");
                    break;
                default:
                    if ($this->error) $ret = ("HTTP/1.0 " . $this->error);
                    else $ret = ("HTTP/1.0 200 OK");
                    break;
            }
            return ($ret);
        }

        function checkCloudFrameWorkSecurity($time = 0, $id = '')
        {
            $ret = false;
            $info = $this->core->security->checkCloudFrameWorkSecurity($time, $id); // Max. 10 min for the Security Token and return $this->getConf('CLOUDFRAMEWORK-ID-'.$id);
            if (false === $info) $this->setError($this->core->logs->get(), 401);
            else {
                $ret = true;
                $response['SECURITY-MODE'] = 'CloudFrameWork Security';
                $response['SECURITY-ID'] = $info['SECURITY-ID'];
                $response['SECURITY-EXPIRATION'] = ($info['SECURITY-EXPIRATION']) ? round($info['SECURITY-EXPIRATION']) . ' secs' : 'none';
                $this->setReturnResponse($response);
            }
            return $ret;
        }

        function existBasicAuth()
        {
            return ($this->core->security->existBasicAuth() && $this->core->security->existBasicAuthConfig());
        }

        /**
         * Check if an Authorization-Header Basic has been sent and match with any core.system.authorizations config var.
         * @param string $id
         * @return bool
         */
        function checkBasicAuthSecurity($id = '')
        {
            $ret = false;
            if (false === ($basic_info = $this->core->security->checkBasicAuthWithConfig())) {
                $this->setError($this->core->logs->get(), 401);
            } elseif (!isset($basic_info['id'])) {
                $this->setError('Missing "id" parameter in authorizations config file', 401);
            } elseif (strlen($id) > 0 && $id != $basic_info['id']) {
                $this->setError('This "id" parameter in authorizations is not allowed', 401);
            } else {
                $ret = true;
                $response['SECURITY-MODE'] = 'Basic Authorization: ' . $basic_info['_BasicAuthUser_'];
                $response['SECURITY-ID'] = $basic_info['id'];
                $this->setReturnResponse($response);
            }
            return $ret;
        }

        function getCloudFrameWorkSecurityInfo()
        {
            if (isset($this->returnData['SECURITY-ID'])) {
                return $this->core->config->get('CLOUDFRAMEWORK-ID-' . $this->returnData['SECURITY-ID']);
            } else return [];
        }


        /**
         * Echo the result
         * @param bool $pretty if true, returns the JSON string with JSON_PRETTY_PRINT
         * @param bool $return if true, instead to echo then return the output.
         * @param array $argv if we are running from a terminal it will receive the command line args.
         * @return mixed
         */
        function send($pretty = false, $return = false, $argv = [])
        {

            // Prepare the return data
            $ret = array();
            $ret['success'] = ($this->error) ? false : true;
            $ret['status'] = $this->getReturnStatus();
            $ret['code'] = $this->getReturnCode();
            if ($this->message) $ret['message'] = $this->message;

            if ($this->core->is->terminal())
                $ret['exec'] = '[' . $_SERVER['PWD'] . '] php ' . implode(' ', $argv);

            if (is_array($this->returnData)) $ret = array_merge($ret, $this->returnData);

            $this->sendHeaders();
            $this->core->__p->add("RESTFull: ", '', 'endnote');
            switch ($this->contentTypeReturn) {
                case 'JSON':
                    if (isset($this->formParams['__p'])) {
                        $this->core->__p->data['config loaded'] = $this->core->config->getConfigLoaded();
                        $ret['__p'] = $this->core->__p->data;
                    }

                    // Debug params
                    if (isset($this->formParams['__debug'])) {
                        if (!$this->core->is->terminal())
                            $ret['__debug']['url'] = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                        $ret['__debug']['method'] = $this->method;
                        $ret['__debug']['ip'] = $this->core->system->ip;
                        $ret['__debug']['header'] = $this->getResponseHeader();
                        $ret['__debug']['session'] = session_id();
                        $ret['__debug']['ip'] = $this->core->system->ip;
                        $ret['__debug']['user_agent'] = ($this->core->system->user_agent != null) ? $this->core->system->user_agent : $this->requestHeaders['User-Agent'];
                        $ret['__debug']['urlParams'] = $this->params;
                        $ret['__debug']['form-raw Params'] = $this->formParams;
                        $ret['__debug']['_totTime'] = $this->core->__p->getTotalTime(5) . ' secs';
                        $ret['__debug']['_totMemory'] = $this->core->__p->getTotalMemory(5) . ' Mb';
                    }

                    // If the API->main does not want to send $ret standard it can send its own data
                    if (count($this->rewrite)) $ret = $this->rewrite;

                    // JSON conversion
                    if ($pretty) $ret = json_encode($ret, JSON_PRETTY_PRINT);
                    else $ret = json_encode($ret);

                    break;
                default:
                    if ($this->core->errors->lines) $ret =& $this->core->errors->data;
                    else $ret = &$this->returnData['data'];
                    break;
            }

            // ending script
            if ($return) return $ret;
            else {
                if (is_string($ret)) die($ret);
                else die(json_encode($ret, JSON_PRETTY_PRINT));
            }
        }

        function getHeader($str)
        {
            $str = strtoupper($str);
            $str = str_replace('-', '_', $str);
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : '');
        }

        function getHeaders()
        {
            $ret = array();
            foreach ($_SERVER as $key => $value) if (strpos($key, 'HTTP_') === 0) {
                $ret[str_replace('HTTP_', '', $key)] = $value;
            }
            return ($ret);
        }

        function getHeadersToResend()
        {
            $ret = array();
            foreach ($_SERVER as $key => $value) if (strpos($key, 'HTTP_X_') === 0) {
                $ret[str_replace('_', '-', str_replace('HTTP_', '', $key))] = $value;
            }
            return ($ret);
        }

        /**
         * Return the value of  $this->formParams[$var]. Return null if $var does not exist
         * @param string $var
         * @retun null|mixed
         */
        public function getFormParamater($var)
        {
            if (!isset($this->formParams[$var])) return null;
            return $this->formParams[$var];
        }

        /**
         * Return the value of  $this->params[$var]. Return null if $var does not exist
         * @param integer $var
         * @retun null|mixed
         */
        public function getUrlPathParamater($var)
        {
            if (!isset($this->params[$var])) return null;
            return $this->params[$var];
        }

        /**
         * Execute a method if $method is defined.
         * @param string $method name of the method
         * @return bool
         */
        function useFunction($method)
        {
            if (method_exists($this, $method)) {
                $this->$method();
                return true;
            } else {
                return false;
            }
        }

    } // Class
}

