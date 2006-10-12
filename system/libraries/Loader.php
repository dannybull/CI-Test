<?php  if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Code Igniter
 *
 * An open source application development framework for PHP 4.3.2 or newer
 *
 * @package		CodeIgniter
 * @author		Rick Ellis
 * @copyright	Copyright (c) 2006, pMachine, Inc.
 * @license		http://www.codeignitor.com/user_guide/license.html 
 * @link		http://www.codeigniter.com
 * @since		Version 1.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * Loader Class
 * 
 * Loads views and files
 *
 * @package		CodeIgniter
 * @subpackage	Libraries
 * @author		Rick Ellis
 * @category	Loader
 * @link		http://www.codeigniter.com/user_guide/libraries/loader.html
 */
class CI_Loader {

	// All these are set automatically. Don't mess with them.
	var $_ci_ob_level;
	var $_ci_view_path		= '';
	var $_ci_is_php5		= FALSE;
	var $_ci_is_instance 	= FALSE; // Whether we should use $this or $CI =& get_instance()
	var $_ci_cached_vars	= array();
	var $_ci_models			= array();
	var $_ci_helpers		= array();
	var $_ci_plugins		= array();
	var $_ci_scripts		= array();
	var $_ci_varmap			= array('unit_test' => 'unit', 'user_agent' => 'agent');
	

	/**
	 * Constructor
	 *
	 * Sets the path to the view files and gets the initial output buffering level
	 *
	 * @access	public
	 */
	function CI_Loader()
	{	
		$this->_ci_is_php5 = (floor(phpversion()) >= 5) ? TRUE : FALSE;
		$this->_ci_view_path = APPPATH.'views/';
		$this->_ci_ob_level  = ob_get_level();
				
		log_message('debug', "Loader Class Initialized");
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Class Loader
	 *
	 * This function lets users load and instantiate classes.
	 * It is designed to be called from a user's app controllers.
	 *
	 * @access	public
	 * @param	string	the name of the class
	 * @param	mixed	the optional parameters
	 * @return	void
	 */	
	function library($class, $params = NULL)
	{		
		if ($class == '')
			return;
	
		$this->_ci_load_class($class, $params);
		$this->_ci_assign_to_models();
	}

	// --------------------------------------------------------------------
	
	/**
	 * Model Loader
	 *
	 * This function lets users load and instantiate models.
	 *
	 * @access	public
	 * @param	string	the name of the class
	 * @param	mixed	any initialization parameters
	 * @return	void
	 */	
	function model($model, $name = '', $db_conn = FALSE)
	{		
		if ($model == '')
			return;
	
		// Is the model in a sub-folder? If so, parse out the filename and path.
		if (strpos($model, '/') === FALSE)
		{
			$path = '';
		}
		else
		{
			$x = explode('/', $model);
			$model = end($x);			
			unset($x[count($x)-1]);
			$path = implode('/', $x).'/';
		}
	
		if ($name == '')
		{
			$name = $model;
		}
		
		if (in_array($name, $this->_ci_models, TRUE))
		{
			return;
		}
		
		$CI =& get_instance();
		if (isset($CI->$name))
		{
			show_error('The model name you are loading is the name of a resource that is already being used: '.$name);
		}
	
		$model = strtolower($model);
		
		if ( ! file_exists(APPPATH.'models/'.$path.$model.EXT))
		{
			show_error('Unable to locate the model you have specified: '.$model);
		}
				
		if ($db_conn !== FALSE)
		{
			if ($db_conn === TRUE)
				$db_conn = '';
		
			$CI->load->database($db_conn, FALSE, TRUE);
		}
	
		if ( ! class_exists('Model'))
		{
			require_once(BASEPATH.'libraries/Model'.EXT);
		}

		require_once(APPPATH.'models/'.$path.$model.EXT);

		$model = ucfirst($model);
				
		$CI->$name = new $model();
		$CI->$name->_assign_libraries();
		
		$this->_ci_models[] = $name;	
	}
		
	// --------------------------------------------------------------------
	
	/**
	 * Database Loader
	 *
	 * @access	public
	 * @param	string	the DB credentials
	 * @param	bool	whether to return the DB object
	 * @param	bool	whether to enable active record (this allows us to override the config setting)
	 * @return	object
	 */	
	function database($params = '', $return = FALSE, $active_record = FALSE)
	{
		// Do we even need to load the database class?
		if (class_exists('CI_DB') AND $return == FALSE AND $active_record == FALSE)
		{
			return FALSE;
		}	
	
		require_once(BASEPATH.'database/DB'.EXT);

		if ($return === TRUE)
		{
			return DB($params, $active_record);
		}

		$CI =& get_instance();
		$CI->db =& DB($params, $active_record);			
		$this->_ci_assign_to_models();
	}
	
	// --------------------------------------------------------------------

	/**
	 * Load the Utilities Class
	 *
	 * @access	public
	 * @return	string		 
	 */		
	function dbutil()
	{
		if ( ! class_exists('CI_DB'))
		{
			$this->database();
		}
		
		$CI =& get_instance();
	
		require_once(BASEPATH.'database/DB_utility'.EXT);
		require_once(BASEPATH.'database/drivers/'.$CI->db->dbdriver.'/'.$CI->db->dbdriver.'_utility'.EXT);
		$class = 'CI_DB_'.$CI->db->dbdriver.'_utility';

		$CI->dbutil = new $class();
		$CI->load->_ci_assign_to_models();
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Load View
	 *
	 * This function is used to load a "view" file.  It has three parameters:
	 *
	 * 1. The name of the "view" file to be included.
	 * 2. An associative array of data to be extracted for use in the view.
	 * 3. TRUE/FALSE - whether to return the data or load it.  In
	 * some cases it's advantageous to be able to retun data so that
	 * a developer can process it in some way.
	 *
	 * @access	public
	 * @param	string
	 * @param	array
	 * @param	bool
	 * @return	void
	 */
	function view($view, $vars = array(), $return = FALSE)
	{
		return $this->_ci_load(array('view' => $view, 'vars' => $this->_ci_object_to_array($vars), 'return' => $return));
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Load File
	 *
	 * This is a generic file loader
	 *
	 * @access	public
	 * @param	string
	 * @param	bool
	 * @return	string
	 */
	function file($path, $return = FALSE)
	{
		return $this->_ci_load(array('path' => $path, 'return' => $return));
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Set Variables
	 *
	 * Once variables are set they become availabe within
	 * the controller class and its "view" files.
	 *
	 * @access	public
	 * @param	array
	 * @return	void
	 */
	function vars($vars = array())
	{
		$vars = $this->_ci_object_to_array($vars);
	
		if (is_array($vars) AND count($vars) > 0)
		{
			foreach ($vars as $key => $val)
			{
				$this->_ci_cached_vars[$key] = $val;
			}
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Load Helper
	 *
	 * This function loads the specified helper file.
	 *
	 * @access	public
	 * @param	mixed
	 * @return	void
	 */
	function helper($helpers = array())
	{
		if ( ! is_array($helpers))
		{
			$helpers = array($helpers);
		}
	
		foreach ($helpers as $helper)
		{
			if (isset($this->_ci_helpers[$helper]))
			{
				continue;
			}
		
			$helper = strtolower(str_replace(EXT, '', str_replace('_helper', '', $helper)).'_helper');
		
			if (file_exists(APPPATH.'helpers/'.$helper.EXT))
			{
				include_once(APPPATH.'helpers/'.$helper.EXT);
			}
			else
			{		
				if (file_exists(BASEPATH.'helpers/'.$helper.EXT))
				{
					include_once(BASEPATH.'helpers/'.$helper.EXT);
				}
				else
				{
					show_error('Unable to load the requested file: helpers/'.$helper.EXT);
				}
			}

			$this->_ci_helpers[$helper] = TRUE;
		}
		
		log_message('debug', 'Helpers loaded: '.implode(', ', $helpers));
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Load Helpers
	 *
	 * This is simply an alias to the above function in case the
	 * user has written the plural form of this function.
	 *
	 * @access	public
	 * @param	array
	 * @return	void
	 */
	function helpers($helpers = array())
	{
		$this->helper($helpers);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Load Plugin
	 *
	 * This function loads the specified plugin.
	 *
	 * @access	public
	 * @param	array
	 * @return	void
	 */
	function plugin($plugins = array())
	{
		if ( ! is_array($plugins))
		{
			$plugins = array($plugins);
		}
	
		foreach ($plugins as $plugin)
		{
			if (isset($this->_ci_plugins[$plugin]))
			{
				continue;
			}
	
			$plugin = strtolower(str_replace(EXT, '', str_replace('_plugin.', '', $plugin)).'_pi');		
		
			if (file_exists(APPPATH.'plugins/'.$plugin.EXT))
			{
				include_once(APPPATH.'plugins/'.$plugin.EXT);	
			}
			else
			{
				if (file_exists(BASEPATH.'plugins/'.$plugin.EXT))
				{
					include_once(BASEPATH.'plugins/'.$plugin.EXT);	
				}
				else
				{
					show_error('Unable to load the requested file: plugins/'.$plugin.EXT);
				}
			}
			
			$this->_ci_plugins[$plugin] = TRUE;
		}
		
		log_message('debug', 'Plugins loaded: '.implode(', ', $plugins));
	}

	// --------------------------------------------------------------------
	
	/**
	 * Load Plugins
	 *
	 * This is simply an alias to the above function in case the
	 * user has written the plural form of this function.
	 *
	 * @access	public
	 * @param	array
	 * @return	void
	 */
	function plugins($plugins = array())
	{
		$this->plugin($plugins);
	}

	// --------------------------------------------------------------------
	
	/**
	 * Load Script
	 *
	 * This function loads the specified include file from the
	 * application/scripts/ folder.
	 *
	 * NOTE:  This feature has been deprecated but it will remain available
	 * for legacy users.
	 *
	 * @access	public
	 * @param	array
	 * @return	void
	 */
	function script($scripts = array())
	{
		if ( ! is_array($scripts))
		{
			$scripts = array($scripts);
		}
	
		foreach ($scripts as $script)
		{
			if (isset($this->_ci_scripts[$script]))
			{
				continue;
			}
	
			$script = strtolower(str_replace(EXT, '', $script));
		
			if ( ! file_exists(APPPATH.'scripts/'.$script.EXT))
			{
				show_error('Unable to load the requested script: scripts/'.$script.EXT);
			}
			
			include_once(APPPATH.'scripts/'.$script.EXT);
			
			$this->_ci_scripts[$script] = TRUE;
		}
		
		log_message('debug', 'Scripts loaded: '.implode(', ', $scripts));
	}
		
	// --------------------------------------------------------------------
	
	/**
	 * Loads a language file
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */
	function language($file = '', $lang = '', $return = FALSE)
	{
		$CI =& get_instance();
		return $CI->lang->load($file, $lang, $return);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Loads a config file
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */
	function config($file = '')
	{		
		$CI =& get_instance();
		$CI->config->load($file);
	}

	// --------------------------------------------------------------------
	
	/**
	 * Scaffolding Loader
	 *
	 * This initializing function works a bit different than the
	 * others. It doesn't load the class.  Instead, it simply
	 * sets a flag indicating that scaffolding is allowed to be
	 * used.  The actual scaffolding function below is
	 * called by the front controller based on whether the
	 * second segment of the URL matches the "secret" scaffolding
	 * word stored in the application/config/routes.php
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */	
	function scaffolding($table = '')
	{		
		if ($table === FALSE)
		{
			show_error('You must include the name of the table you would like access when you initialize scaffolding');
		}
		
		$CI =& get_instance();
		$CI->_ci_scaffolding = TRUE;
		$CI->_ci_scaff_table = $table;
	}

	// --------------------------------------------------------------------
		
	/**
	 * Loader
	 *
	 * This function is used to load views and files.
	 *
	 * @access	private
	 * @param	array
	 * @return	void
	 */
	function _ci_load($data)
	{
		// This allows anything loaded using $this->load (viwes, files, etc.)
		// to become accessible from within the Controller and Model functions.
		// Only needed when running PHP 5
		
		if ($this->_ci_is_instance())
		{
			$CI =& get_instance();
			foreach (get_object_vars($CI) as $key => $var)
			{
				if ( ! isset($this->$key))
				{
					$this->$key =& $CI->$key;
				}
			}
		}
		
		// Set the default data variables
		foreach (array('view', 'vars', 'path', 'return') as $val)
		{
			$$val = ( ! isset($data[$val])) ? FALSE : $data[$val];
		}
		
		/*
		 * Extract and cache variables
		 *
		 * You can either set variables using the dedicated $this->load_vars() 
		 * function or via the second parameter of this function. We'll merge 
		 * the two types and cache them so that views that are embedded within 
		 * other views can have access to these variables.
		 */	
		if (is_array($vars))
		{
			$this->_ci_cached_vars = array_merge($this->_ci_cached_vars, $vars);
		}
		extract($this->_ci_cached_vars);
				
		// Set the path to the requested file
		if ($path == '')
		{
			$ext = pathinfo($view, PATHINFO_EXTENSION);
			$file = ($ext == '') ? $view.EXT : $view;
			$path = $this->_ci_view_path.$file;
		}
		else
		{
			$x = explode('/', $path);
			$file = end($x);
		}

		/*
		 * Buffer the output
		 *
		 * We buffer the output for two reasons:
		 * 1. Speed. You get a significant speed boost.
		 * 2. So that the final rendered template can be 
		 * post-processed by the output class.  Why do we
		 * need post processing?  For one thing, in order to 
		 * show the elapsed page load time.  Unless we
		 * can intercept the content right before it's sent to
		 * the browser and then stop the timer it won't be acurate.
		 */
		if ( ! file_exists($path))
		{
			show_error('Unable to load the requested file: '.$file);
		}

		ob_start();
		
		include($path);
		log_message('debug', 'File loaded: '.$path);
		
		// Return the file data if requested to
		if ($return === TRUE)
		{		
			$buffer = ob_get_contents();
			ob_end_clean(); 
			
			return $buffer;
		}

		/*
		 * Flush the buffer... or buff the flusher?
		 *
		 * In order to permit views to be nested within
		 * other views, we need to flush the content back out whenever 
		 * we are beyond the first level of output buffering so that 
		 * it can be seen and included properly by the first included 
		 * template and any subsequent ones. Oy!
		 *
		 */	
		if (ob_get_level() > $this->_ci_ob_level + 1)
		{
			ob_end_flush();
		}
		else
		{
			// PHP 4 requires that we use a global
			global $OUT;
			$OUT->set_output(ob_get_contents());
			ob_end_clean();
		}
	}

	// --------------------------------------------------------------------

	/**
	 * Load class
	 *
	 * This function loads the requested class.
	 *
	 * @access	private
	 * @param 	string	the item that is being loaded
	 * @param	mixed	any additional parameters
	 * @return 	void
	 */
	function _ci_load_class($class, $params = NULL)
	{	
		// Prep the class name
		$class = strtolower(str_replace(EXT, '', $class));
		
		// Is this a class extension request?	
		if (substr($class, 0, 3) == 'my_')
		{
			$class = preg_replace("/my_(.+)/", "\\1", $class);

			// Load the requested library from the main system/libraries folder
			if (file_exists(BASEPATH.'libraries/'.ucfirst($class).EXT))
			{
				include_once(BASEPATH.'libraries/'.ucfirst($class).EXT);
			}
			
			// Now look for a matching library
			foreach (array(ucfirst($class), $class) as $filename)
			{
				if (file_exists(APPPATH.'libraries/'.$filename.EXT))
				{
					include_once(APPPATH.'libraries/'.$filename.EXT);	
				}
			}
			
			return $this->_ci_init_class($filename, 'MY_', $params);
		}

		// Lets search for the requested library file and load it.
		// For backward compatibility we'll test for filenames that are
		// both uppercase and lower.
		foreach (array(ucfirst($class), $class) as $filename)
		{
			for ($i = 1; $i < 3; $i++)
			{
				$path = ($i % 2) ? APPPATH : BASEPATH;
			
				if (file_exists($path.'libraries/'.$filename.EXT))
				{
					include_once($path.'libraries/'.$filename.EXT);
					return $this->_ci_init_class($filename, '', $params);
				}
			}
		}
		
		// If we got this far we were unable to find the requested class
		log_message('error', "Unable to load the requested class: ".$class);
		show_error("Unable to load the class: ".$class);
	}
	
	// --------------------------------------------------------------------

	/**
	 * Instantiates a class
	 *
	 * @access	private
	 * @param	string
	 * @param	string
	 * @return	null
	 */
	function _ci_init_class($class, $prefix = '', $config = NULL)
	{	
		// Is there an associated config file for this class?
		if ($config !== NULL)
		{
			$config = NULL;
			if (file_exists(APPPATH.'config/'.$class.EXT))
			{
				include_once(APPPATH.'config/'.$class.EXT);
			}
		}
		
		if ($prefix == '')
		{
			$name = (class_exists('CI_'.$class)) ? 'CI_'.$class : $class;
		}
		else
		{
			$name = $prefix.$class;
		}
		
		// Set the variable name we will assign the class to
		$class = strtolower($class);			
		$classvar = ( ! isset($this->_ci_varmap[$class])) ? $class : $this->_ci_varmap[$class];
				
		// Instantiate the class		
		$CI =& get_instance();
		if ($config !== NULL)
		{
			$CI->$classvar = new $name($config);
		}
		else
		{		
			$CI->$classvar = new $name;
		}	
	} 	
	
	// --------------------------------------------------------------------
	
	/**
	 * Autoloader
	 *
	 * The config/autoload.php file contains an array that permits sub-systems, 
	 * libraries, plugins, and helpers to be loaded automatically.
	 *
	 * @access	private
	 * @param	array
	 * @return	void
	 */
	function _ci_autoloader()
	{	
		include_once(APPPATH.'config/autoload'.EXT);
		
		if ( ! isset($autoload))
		{
			return FALSE;
		}
		
		// Load any custome config file
		if (count($autoload['config']) > 0)
		{			
			$CI =& get_instance();
			foreach ($autoload['config'] as $key => $val)
			{
				$CI->config->load($val);
			}
		}		

		// Load plugins, helpers, and scripts
		foreach (array('helper', 'plugin', 'script') as $type)
		{
			if (isset($autoload[$type]) AND count($autoload[$type]) > 0)
			{
				$this->$type($autoload[$type]);
			}		
		}
		
		// A little tweak to remain backward compatible
		// The $autoload['core'] item was deprecated
		if ( ! isset($autoload['libraries']))
		{
			$autoload['libraries'] = $autoload['core'];
		}
		
		// Load libraries
		if (isset($autoload['libraries']) AND count($autoload['libraries']) > 0)
		{
			// Load the database driver.  
			if (in_array('database', $autoload['libraries']))
			{
				$this->database();
				$autoload['libraries'] = array_diff($autoload['libraries'], array('database'));
			}

			// Load the model class.  
			if (in_array('model', $autoload['libraries']))
			{
				$this->model();
				$autoload['libraries'] = array_diff($autoload['libraries'], array('model'));
			}

			// Load scaffolding
			if (in_array('scaffolding', $autoload['libraries']))
			{
				$this->scaffolding();
				$autoload['libraries'] = array_diff($autoload['libraries'], array('scaffolding'));
			}
		
			// Load all other libraries
			foreach ($autoload['libraries'] as $item)
			{
				$this->library($item);
			}
		}		
	}
	
	// --------------------------------------------------------------------

	/**
	 * Assign to Models
	 *
	 * Makes sure that anything loaded by the loader class (libraries, plugins, etc.)
	 * will be available to modles, if any exist.
	 *
	 * @access	private
	 * @param	object
	 * @return	array
	 */
	function _ci_assign_to_models()
	{
		if (count($this->_ci_models) == 0)
		{
			return;
		}

		if ($this->_ci_is_instance())
		{
			$CI =& get_instance();
			foreach ($this->_ci_models as $model)
			{			
				$CI->$model->_assign_libraries();
			}
		}
		else
		{		
			foreach ($this->_ci_models as $model)
			{			
				$this->$model->_assign_libraries();
			}
		}
	}  	

	// --------------------------------------------------------------------

	/**
	 * Object to Array
	 *
	 * Takes an object as input and convers the class variables to array key/vals
	 *
	 * @access	private
	 * @param	object
	 * @return	array
	 */
	function _ci_object_to_array($object)
	{
		return (is_object($object)) ? get_object_vars($object) : $object;
	}

	// --------------------------------------------------------------------

	/**
	 * Determines whether we should use the CI instance or $this
	 *
	 * @access	private
	 * @return	bool
	 */
	function _ci_is_instance()
	{
		if ($this->_ci_is_php5 == TRUE)
		{
			return TRUE;
		}
	
		global $CI;
		return (is_object($CI)) ? TRUE : FALSE;
	}
	
}
?>