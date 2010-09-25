<?php
// this is the it
class the
{
	// configuration
	var $config = array(); // used to set all kinds of custom data
	var $base_file = "index.php"; // the file where the app is defined
	
	// try to set it all at runtime
	var $index_file = "index.php";
	var $uri_string = "";
	var $link_uri = ""; // the segments of the URI
	var $base_uri = ""; // the segments of the URI
	var $uri_segments = array(); // these are used to set parameters via URL in any called model
	
	var $theme = ""; // the folder where the templates are
	var $default = ""; // the default template to load
	var $uri_templates = array(); // associations between uri segments and template files
	
	// template data
	var $models = array();
	var $models_methods_print = array();
	var $models_methods_render = array();
	var $models_methods_data = array();
	
		
	// instances of loaded models
	var $objects = array();
	
	// database conections
	var $connections = array();
	
	// the connected database
	var $database = "";
	
	// assumes html but can be set
	var $tpl_file_extension = 'html';
	
	// replacements
	var $replace = array();
	// raw template
	var $template_data = "";
	// the result of all the work
	var $output = "";
	
	// singleton
	private static $instance;
	
	// servers where the app may run
	var $servers = array();
	
	//the install token triggers the model install routine
	var $install_token = 'install';
	
	
	// events!
	var $events = array();
	var $debug_events = false; // shows each dispatched event as a comment in the output
	
	function the()
	{
		// find base URI
		$data = explode($this->base_file,str_replace('//','/',dirname($_SERVER['PHP_SELF']).'/'));
		$this->base_uri = 'http' . ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 's' : '').'://'.$_SERVER['HTTP_HOST'].$data[0];
		
		$this->uri_string = $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
		
		$this->link_uri = $this->base_uri.$this->index_file;
		
		$parts = explode($this->base_file.'/', $this->uri_string);
		if(array_key_exists(1, $parts))
			if($parts[1] != "")
				$this->uri_segments = explode("/", $parts[1]);
		
		$cwd = explode(DIRECTORY_SEPARATOR, __FILE__);
		unset($cwd[count($cwd)-1]);
		$cwd = implode('/', $cwd);
		define('BASE', $cwd.'/');
		
	}
	
	function observe($event, $model, $method)
	{
		$this->events[$event][] = array($model, $method);
	}
	
	function dispatch($event)
	{
		if($this->debug_events == true)
			echo "<!-- event! ".$event." -->";
			
		if(!is_array($this->events))
			return false;
			
		if(array_key_exists($event, $this->events))
		{
			// so we can trigger multiple actions on the same event
			foreach ($this->events[$event] as $action) {
				$model = $action[0];
				$method = $action[1];
				if(array_key_exists($model, $this->objects))
				{
					$object = $this->objects[$model];
					$object->$method();
				}
				else
				{
					include BASE.'models/'.$model.'/class.php';
					$this->objects[$model] = new $model();
					$object = $this->objects[$model];
					$object->$method();
				}
			}
			
		}	
	}
	
	// adds an available database connection based on the current URI
	function connection($host, $dbhost, $database, $user, $password)
	{
		$this->connections[$host] = array($dbhost, $database, $user, $password);
	}
	
	// adds a server where the app may run
	function server($name, $type)
	{
		$this->servers[$name] = $type;
	}
	
	// associates an uri segment with a template
	function template($uri_segment, $file_name, $theme="")
	{
		if($theme == "")
		{
			$this->uri_templates[$uri_segment] = $file_name;
		}
		else
		{
			$this->uri_templates[$uri_segment] = array($theme,$file_name);
		}
	}
	
	// set data to be replaced in all templates
	function replace($what, $with, $where = ".*")
	{
		$this->replace[$where][] = array($what,$with);
	}
	
	function output()
	{
		$this->load();
		$this->_print();
		$this->_render();
		$this->_remove();
		return $this->output;
	}
	
	function run()
	{
		$this->dispatch('before_run');
		$this->load();
		$this->_print();
		$this->_render();
		$this->_remove();
		$this->dispatch('before_output');
		echo $this->output;
		$this->dispatch('after_output');
	}
	
	function _parse($file)
	{
		
		if(is_array($file))
		{
			$this->theme = $file[0];
			$file = $file[1];
		}
		$this->template_data = file_get_contents(BASE.'../static/'.$this->theme.'/'.$file.'.'.$this->tpl_file_extension);
		
		// replacing global data
		foreach ($this->replace as $where => $replacements) {
			if(preg_match("|".$where."|", $this->uri_string))
			{
				foreach ($replacements as $value) {
					$this->template_data = str_replace($value[0], $value[1], $this->template_data);
				}
			}
		}
		
		
		// todo:add check $res if there are no matches
		$res = preg_match_all('/<!-- ((print|render)\.(([a-z_,-]*)\.([a-z_,-]*))) -->/', $this->template_data, $methodstarts);
		
		// we need to load these models
		$this->models = array_unique($methodstarts[4]);
		
		// categorize each method call
		foreach ($methodstarts[2] as $k=>$v) {
			if($v == 'render')
				$this->models_methods_render[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
			if($v == 'print')
				$this->models_methods_print[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
		}
		
		// manage relative links
		$this->template_data = preg_replace("/href=(\"|')(.*?)\?su=(.*?)(\"|')/", 'href="'.$this->link_uri.'/$3"', $this->template_data);
		
		$base = "<base href='".$this->base_uri."static/".$this->theme."/' />";
		$this->output = str_replace('<head>', "<head>\n".$base, $this->template_data);
		
		
		
		$this->dispatch('template_parsed');
	}
	
		
	// print replaces a block of html with the result of the method
	function _print()
	{
		$this->dispatch('before_printing');
		foreach ($this->models_methods_print as $action) {
			$model = $action[0];
			$method = $action[1];
			$start = "<!-- print.$model.$method -->";
			$end = "<!-- /print.$model.$method -->";
			$pos1 = strpos($this->output, $start);
			$pos2 = strpos($this->output, $end) - $pos1 + strlen($end);
			
			if(!method_exists($model, $method))
			{
				$this->output = substr_replace($this->output, "missing_metod".$model."_".$method, $pos1, $pos2);
				continue;
			}	
			
			$object = $this->objects[$model];
			$data = $object->$method();
			$this->dispatch('executed_'.$model."_".$method);
			
			if($data == false)
				$this->output = $this->output;
			else
				$this->output = substr_replace($this->output, $data, $pos1, $pos2);
			
		}
		$this->dispatch('after_printing');
	}
	
	// render checks for a returned array, if found loops trough and, if not, replaces data with array keys
	function _render()
	{
		$this->dispatch('before_render');
		foreach ($this->models_methods_render as $action) {
			$model = $action[0];
			$method = $action[1];
			$start = "<!-- render.$model.$method -->";
			$end = "<!-- /render.$model.$method -->";
			$pos1 = strpos($this->output, $start);
			$pos2 = strpos($this->output, $end) - $pos1 + strlen($end);
			
			if(!method_exists($model, $method))
			{
				$this->output = substr_replace($this->output, "missing_metod".$model."_".$method, $pos1, $pos2);
				continue;
			}
			
			$object = $this->objects[$model];
			$data_arr = $object->$method();
			$this->dispatch('executed_'.$model."_".$method);
			
			
			// we need to march data points into this entry
			$render_template = substr($this->output, $pos1+strlen($start), $pos2 - 2*strlen($end));
			$res = preg_match_all('/<!-- print\.([a-z,_,-]*) -->/', $render_template, $datastarts);
			
			$rendered_data = "";
			
			if($data_arr == false)
				return;
			
			foreach($data_arr as $data)
			{
				$rendered_tpl = $render_template;
				foreach ($datastarts[0] as $key => $value) {					
					$start = $value;
					$end = str_replace("<!-- ", "<!-- /", $value);
					$rpos1 = strpos($rendered_tpl, $start);
					$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);
					if(!array_key_exists($datastarts[1][$key], $data))
						$rendered_tpl = substr_replace($rendered_tpl, "missing_".$datastarts[1][$key], $rpos1, $rpos2);
					else
						$rendered_tpl = substr_replace($rendered_tpl, $data[$datastarts[1][$key]], $rpos1, $rpos2);
				}
				$rendered_data .= "\n".$rendered_tpl;
			}	
			$this->output = substr_replace($this->output, $rendered_data, $pos1, $pos2);
		}
		$this->dispatch('after_render');
		
	}
	
	static function database()
	{
		$i = self::$instance;
		return $i->database;
	}
	
	// remove deletes the not needed content
	function _remove()
	{
		$res = preg_match_all('/<!-- remove -->/', $this->template_data, $removesStarts);
		foreach ($removesStarts[0] as $key => $value) {
			$start = $value;
			$end = str_replace("<!-- ", "<!-- /", $value);
			$rpos1 = strpos($this->output, $start);
			$rpos2 = strpos($this->output, $end) - $rpos1 + strlen($end);
			$this->output = substr_replace($this->output, "", $rpos1, $rpos2);
		}
	}
	
	
	function load()
	{
			
		foreach ($this->uri_templates as $key=>$assoc)
		{
			if($this->template_data != "")
				continue;
			if(preg_match("|".$key."|", $this->uri_string))
				$this->_parse($assoc);
		}
		
		if($this->template_data == "")
			$this->_parse($this->default);
		
		include BASE.'model.php';
		$this->database = db::connect();
		
		foreach ($this->models as $model)
		{
			if(!array_key_exists($model, $this->objects))
			{
				if(!file_exists(BASE.'models/'.$model.'/class.php'))
				{
					echo '<!-- missing_model_'.$model.' -->';
					continue;
				}
				include BASE.'models/'.$model.'/class.php';
				$object = new $model();
				$this->objects[$model] = $object;
			}
			if(file_exists(BASE.'/models/'.$model."/sql.php"))
			{
				include BASE.'/models/'.$model."/sql.php";
				$this->database->querries = array_merge($this->database->querries, $querries);
			}

		}
		
		if(preg_match("|".$this->install_token."|", $this->uri_string))
			$this->_install();
		
	}
	
	function _install()
	{
		
		if(array_key_exists(1, $this->uri_segments))
		{ 
			$this->database->install($this->uri_segments[1]);
		}
		else
		{
			foreach ($this->models as $model)
			{
				$this->database->install($model);
			}
		}
	}
	
	public static function app()
	{
	    if (!self::$instance)
	    {
	        self::$instance = new the();
	    }
		
		return self::$instance;
	}
	
	// these are mainly used to set custom data
	public function __set($name, $value) {
        $this->config[$name] = $value;
    }

	public function __get($name) {
        if (array_key_exists($name, $this->config)) {
            return $this->config[$name];
        }
        return null;
    }

	// these are used for forms management and to be able to hook xss filters
	function post($index_name)
	{
		$this->post_pointer = $index_name;
		$this->dispatch("read_post_data");
		return $_POST[$index_name];
	}
	
	function get($index_name)
	{
		$this->get_pointer = $index_name;
		$this->dispatch("read_get_data");
		return $_GET[$index_name];
	}
	
	function has_get_data()
	{
		if(count($_GET) > 0)
			return true;
		else
			return false;
	}
	
	function has_post_data()
	{
		if(count($_POST) > 0)
			return true;
		else
			return false;
	}
}


?>