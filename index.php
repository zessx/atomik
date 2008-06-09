<?php

	/**
	 * Atomik Framework
	 * A one script PHP Framework
	 * 	
	 
	 * CHANGES IN 1.5:
	 * 	- New event system. Allow to extend the framework using callback functions
	 * 	- Almost all built-in features become packages
	 * 	- New config_merge() function
	 * 
	 *
	 * @version 2.0
	 * @package Atomik
	 * @author 2008 (c) Maxime Bouroumeau-Fuseau
	 * @license http://www.opensource.org/licenses/mit-license.php
	 * @link http://pimpmycode.fr/atomik
	 *
	 *
	 */
	define('ATOMIK_VERSION', '2.0');
	
	/* -------------------------------------------------------------------------------------------
	 *  DEFAULT CONFIGURATION
	 * ------------------------------------------------------------------------------------------ */

	config_merge(array(

		/* Plugins */
	
		'plugins'				=> array(),
	
		/* Core configuration */
	
		'core_url_trigger' 				=> 'url',
		'core_default_action' 			=> 'index',
		'core_handles_error'			=> true,
		'core_display_errors'			=> true,
	
		'core_paths_root'				=> './',
		'core_paths_plugins'			=> './plugins/',
		'core_paths_actions' 			=> './actions/',
		'core_paths_templates'	 		=> './templates/',
		'core_paths_includes'			=> './includes/',
	
		'core_filenames_pre_dispatch' 	=> './pre_dispatch.php',
		'core_filenames_post_dispatch' 	=> './post_dispatch.php',
		'core_filenames_404' 			=> './404.php',
		'core_filenames_config' 		=> './config.php',
	
		'start_time' 					=> time() + microtime()
	));


	/* -------------------------------------------------------------------------------------------
	 *  CORE
	 * ------------------------------------------------------------------------------------------ */
	 
	/* loads external configuration */
	if (file_exists(config_get('core_filenames_config'))) {
		require(config_get('core_filenames_config'));
	}
	
	/* loads plugins */
	foreach (config_get('plugins') as $plugin) {
		load_plugin($plugin);
	}
	
	/* registers the error handler */
	if (config_get('core_handles_errors', true) === true) {
		set_error_handler('atomik_error_handler');
	}
	
	/* core is starting */
	events_fire('core_start');
	
	/* retreives the requested url and saves it into the configuration */
	if (!isset($_GET[config_get('core_url_trigger')]) || empty($_GET[config_get('core_url_trigger')])) {
		/* no trigger specified, using default page name */
		config_set('request', config_get('core_default_action'));
	} else {
		$url = ltrim($_GET[config_get('core_url_trigger')], '/');
		
		/* checking if no dot are in the page name to avoid any hack attempt and if no 
		 * underscore is use as first character in a segment */
		if (strpos($url, '..') !== false || substr($url, 0, 1) == '_' || strpos($url, '/_') !== false) {
			trigger404();
		}
		
		config_set('request', $url);
		unset($url);
	}
	
	/* all configuration has been set, ready to dispatch */
	events_fire('core_before_dispatch');
	
	/* global pre dispatch action */
	if (file_exists(config_get('core_filenames_pre_dispatch'))) {
		include(config_get('core_filenames_pre_dispatch'));
	}
	
	/* executes the action */
	if (atomik_execute_action(config_get('request'), true, true, false) === false) {
		trigger404();
	}
	
	/* dispatch done */
	events_fire('core_after_dispatch');
	
	/* global post dispatch action */
	if (file_exists(config_get('core_filenames_post_dispatch'))) {
		require(config_get('core_filenames_post_dispatch'));
	}
	
	/* end */
	atomik_end(true);
	
	
	/* -------------------------------------------------------------------------------------------
	 *  Core functions
	 * ------------------------------------------------------------------------------------------ */

	
	/**
	 * Executes an action
	 *
	 * @see atomik_render_template()
	 * @param string $action
	 * @param bool $render OPTIONAL (default true)
	 * @param bool $echo OPTIONAL (default false)
	 * @param bool $triggerError OPTIONAL (default true)
	 * @return array|string|bool
	 */
	function atomik_execute_action($action, $render = true, $echo = false, $triggerError = true)
	{
		/* action and template filenames and existence */
		$actionFilename = config_get('core_paths_actions') . $action . '.php';
		$actionExists = file_exists($actionFilename);
		$templateFilename = config_get('core_paths_templates') . $action . '.php';
		$templateExists = file_exists($templateFilename);
		
		/* checks if at least the action file or the template file is defined */
		if (!$actionExists && !$templateExistss) {
			if ($triggerError) {
				trigger_error('Action ' . $action . ' does not exists', E_USER_ERROR);
			}
			return false;
		}
	
		events_fire('core_before_action', array($action));
	
		/* executes the action */
		if ($actionExists) {
			$vars = atomik_execute_action_scope($actionFilename);
			/* retreives the render variable from the action scope */
			if (isset($vars['render'])) {
				$render = $vars['render'];
			}
		}
	
		events_fire('core_after_action', array($action));
		
		/* returns $vars if the template is not rendered or if the template
		 * file does not exists */
		if (!$render || !$templateExists) {
			return $vars;
		}
		
		/* renders the template associated to the action */
		return atomik_render_template($action, $vars, $echo, $triggerError);
	}
	
	/**
	 * Requires the actions file inside a clean scope and returns defined
	 * variables
	 *
	 * @param string $__action_filename
	 * @return array
	 */
	function atomik_execute_action_scope($__action_filename)
	{
		require($__action_filename);
		$vars = get_defined_vars();
		unset($vars['__action_filename']);
		return $vars;
	}
	
	/**
	 * Renders a template
	 *
	 * @param string $template
	 * @param array $vars OPTIONAL
	 * @param bool $echo OPTIONAL (default false)
	 * @param bool $triggerError OPTIONAL (default true)
	 * @return string|bool
	 */
	function atomik_render_template($template, $vars = array(), $echo = false, $triggerError = true)
	{
		/* template filename */
		$filename = config_get('core_paths_templates') . $template . '.php';
		
		/* checks if the file exists */
		if (!file_exists($filename)) {
			if ($triggerError) {
				trigger_error('Template ' . $filename . ' not found', E_USER_WARNING);
			}
			return false;
		}
		
		events_fire('core_before_template', array($template));
		
		/* render the template in its own scope */
		$output = atomik_render_template_scope($filename, $vars);
		
		events_fire('core_after_template', array(&$output));
		
		/* checks if it's needed to echo the output */
		if (!$echo) {
			return $output;
		}
		
		/* echo output */
		events_fire('core_before_output', array($template, &$output));
		echo $output;
		events_fire('core_after_output', array($template, $output));
	}
	
	/**
	 * Renders a template in its own scope
	 *
	 * @param string $filename
	 * @param array $vars OPTIONAL
	 * @return string
	 */
	function atomik_render_template_scope($__template_filename, $vars = array())
	{
		extract($vars);
		ob_start();
		include($__template_filename);
		return ob_get_clean();
	}

	/**
	 * Fires the core_end event and exits the application
	 *
	 * @param bool $success OPTIONAL
	 */
	function atomik_end($success = false)
	{
		events_fire('core_end', array($success));
		exit;
	}
	
	/**
	 * Hanldes errors
	 *
	 * @param int $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int $errline
	 * @param mixed $errcontext
	 */
	function atomik_error_handler($errno, $errstr, $errfile = '', $errline = 0, $errcontext = null)
	{
		if ($errno <= error_reporting()) {
			$args = func_get_args();
			events_fire('core_error', $args);
		
			echo '<h1>An error has occured!</h1>';
			if (config_get('core_display_errors', true)) {
				echo '<p>' . $errstr . '</p><p>Code:' . $errno . '<br/>File: ' . $errfile .
				     '<br/>Line: ' . $errline . '</p>';
			}
		
			core_end();
		}
	}
	
	
	/* -------------------------------------------------------------------------------------------
	 *  Plugins functions
	 * ------------------------------------------------------------------------------------------ */

	
	/**
	 * Load a plugin
	 *
	 * @param string $plugin
	 * @param array $args OPTIONAL
	 */
	function load_plugin($plugin, $args = array())
	{
		/* global variables to saves loaded plugins name */
		global $_PLUGINS;
		if ($_PLUGINS === null) {
			$_PLUGINS = array();
		}
		
		/* checks if the plugin is already loaded */
		if (in_array($plugin, $_PLUGINS)) {
			return;
		}
		
		events_fire('core_before_plugin', array(&$plugin));
		
		/* checks if plugin has been set to false from one of the event callbacks */
		if ($plugin === false) {
			return;
		}
		
		/* checks if the atomik_plugin_NAME function is defined */
		$pluginFunction = 'atomik_plugin_' . $plugin;
		if (!function_exists($pluginFunction)) {
			if (file_exists(config_get('core_paths_plugins') . $plugin . '.php')) {
				/* loads the plugin */
				require_once(config_get('core_paths_plugins') . $plugin . '.php');
			} else {
				/* plugin not found */
				trigger_error('Missing plugin: ' . $plugin, E_USER_WARNING);
				return;
			}
		}
		
		/* checks if the function atomik_plugin_NAME is defined. The use of this function
		 * is not mandatory in plugin file */
		if (function_exists($pluginFunction)) {
			call_user_func_array($pluginFunction, $args);
		}
		
		events_fire('core_after_plugin', array($plugin));
		
		/* stores the plugin name inside $_PLUGINS so we won't load it twice */
		$_PLUGINS[] = $plugin;
	}
	
	/**
	 * Checks if a package is already loaded
	 *
	 * @param string $package
	 * @return bool
	 */
	function plugin_loaded($plugin)
	{
		/* global variables to saves loaded packages name */
		global $_PLUGINS;
		return in_array($plugin, $_PLUGINS);
	}
	
	
	/* -------------------------------------------------------------------------------------------
	 *  Config functions
	 * ------------------------------------------------------------------------------------------ */

	
	/**
	 * Merges current configuration with the array
	 *
	 * @param array $array
	 */
	function config_merge($array)
	{
		global $_CONFIG;
		$_CONFIG = array_merge(is_array($_CONFIG) ? $_CONFIG : array(), $array);
	}
	
	/**
	 * Gets a config value
	 *
	 * @param string $key
	 * @param mixed $default OPTIONAL Default value if the key is not found
	 * @return mixed
	 */
	function config_get($key, $default = '')
	{
		global $_CONFIG;
		return array_key_exists($key, $_CONFIG) ? $_CONFIG[$key] : $default;
	}
	
	/**
	 * Gets a config value from the array accessed with $key
	 *
	 * @param string $key
	 * @param string $subKey
	 * @param mixed $default OPTIONAL Default value if the key is not found
	 * @return mixed
	 */
	function config_get_deep($key, $subKey, $default = '')
	{
		global $_CONFIG;
		
		/* checks if the config key exists */
		if (!array_key_exists($key, $_CONFIG)) {
			return $default;
		}
		/* checks if it's an array and that the sub key exists */
		if (!is_array($_CONFIG[$key]) || !array_key_exists($subKey, $_CONFIG[$key])) {
			return $default;
		}
		
		return $_CONFIG[$key][$subKey];
	}

	/**
	 * Sets a config key/value pair
	 * 
	 * @param string $key
	 * @param mixed $value
	 */
	function config_set($key, $value)
	{
		global $_CONFIG;
		$_CONFIG[$key] = $value;
	}

	/**
	 * Sets a key/value pair inside the config value defined by $key
	 * 
	 * @param string $key
	 * @param string $subKey
	 * @param mixed $value
	 */
	function config_set_deep($key, $subKey, $value)
	{
		global $_CONFIG;
		
		/* checks if $key exists, initialize it if not */
		if (!array_key_exists($key, $_CONFIG)) {
			$_CONFIG[$key] = array();
		}
		
		$_CONFIG[$key][$subKey] = $value;
	}
	
	/**
	 * Like config merge but do not overwite
	 *
	 * @param array $values
	 */
	function config_set_default($values)
	{
		global $_CONFIG;
		$_CONFIG = array_merge($values, $_CONFIG);
	}
	
	/**
	 * Checks if a config key is defined
	 *
	 * @param string $key
	 * @return bool
	 */
	function config_isset($key)
	{
		global $_CONFIG;
		return array_key_exists($key, $_CONFIG);
	}
	
	
	/* -------------------------------------------------------------------------------------------
	 *  Events functions
	 * ------------------------------------------------------------------------------------------ */

	
	/**
	 * Registers a callback to an event
	 *
	 * @param string $event
	 * @param callback $callback
	 */
	function events_register($event, $callback)
	{
		global $_EVENTS;
		
		if (!isset($_EVENTS[$event])) {
			/* creates the array to store callbacks */
			$_EVENTS[$event] = array();
		}
		
		$_EVENTS[$event][] = $callback;
	}
	
	/**
	 * Fires an event
	 * 
	 * @param string $event
	 * @param array $args OPTIONAL Arguments for callbacks
	 */
	function events_fire($event, $args = array())
	{
		global $_EVENTS;
		
		if (isset($_EVENTS[$event])) {
			foreach ($_EVENTS[$event] as $callback) {
				call_user_func_array($callback, $args);
			}
		}
	}
	
	
	/* -------------------------------------------------------------------------------------------
	 *  Helper functions
	 * ------------------------------------------------------------------------------------------ */
	
	
	/**
	 * Triggers a 404 error
	 */
	function trigger404()
	{
		events_fire('404');
		
		/* HTTP header */
		header('HTTP/1.0 404 Not Found');
		
		if (file_exists(config_get('core_filenames_404'))) {
			/* includes the 404 error file */
			include(config_get('core_filenames_404'));
		} else {
			echo '<h1>404 - File not found</h1>';
		}
		
		atomik_end();
	}

	/**
	 * Redirects to another url
	 *
	 * @param string $destination
	 */
	function redirect($destination)
	{
		header('Location: ' . $destination);
		core_end();
	}

	/*
	 * Includes a file from the includes folder
	 * Do not specify the extension
	 *
	 * @param string $include
	 */
	function needed($include)
	{
		require_once(config_get('core_paths_includes') . $include . '.php');
	}
	

	
