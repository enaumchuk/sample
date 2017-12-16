<?php
/**
* Sample RESTFul API Project
*
* @link      https://github.com/enaumchuk/sample for the canonical source repository
* @copyright Copyright (c) 2017 Edward Naumchuk <ed.naumchuk@secure12.net>
* @author    Edward Naumchuk <ed.naumchuk@secure12.net>
*/
namespace APP\Controllers\Api;

abstract class Controller
{
	protected $ci;
	protected $response;
	protected $request;
	protected $args;

	protected $models;

	protected $cacheId = "sampleapi";
	protected $cachedBody;
	protected $cacheApcEnabled = false;
	protected $cacheTTL = 3600;
	protected $cacheIdentity = null;

	public function __construct(&$ci)
	{
		$this->ci =& $ci;

		if (extension_loaded('apc') && ini_get('apc.enabled') && (APP_ENV == 'production')) {
			$this->cacheApcEnabled = true;
		}
	}

	public function __invoke($request, $response, $args)
	{
		$this->request = $request;
		$this->response = $response;
		$this->args = $args;
		//to access items in the container... $this->ci->get('');
	}

	public function __call($function, $args)
	{
		$data = array(
		 'status' => false,
		 'message' => get_called_class()."::{$function} - method is not defined",
		 'result' => array());
		return $this->response->withJson($data, 501);
	}

	/**
	* generic development endpoint
	*/
	public function development()
	{
		$data = array(
		 'status' => true,
		 'message' => get_called_class()."::development endpoint, ".$this->request->getMethod()." method",
		 'result' => array());
		return $this->response->withJson($data);
	}

	/**
	* check if response was cached
	*
	* @param mixed $identity
	*/
	public function checkCache($identity)
	{
		if ($this->cacheApcEnabled) {
			$this->cacheIdentity = $this->cacheId."_".$this->args['controller']."_".$this->args['action']."_".$this->request->getMethod()."_".md5($identity);
			$flag_success = false;
			$this->cachedBody = apc_fetch($this->cacheIdentity, $flag_success);
			return $flag_success;
		}
	}

	/**
	* return cached response
	*/
	public function cachedResponse()
	{
		$this->response = $this->response->withHeader('Content-Type', 'application/json;charset=utf-8');
		$this->response = $this->response->withHeader('Data-Source', 'Cache');
		$this->response->getBody()->write($this->cachedBody);
		return $this->response;
	}

	/**
	* process response
	*
	* @param mixed $data
	* @param mixed $status
	* @param mixed $encodingOptions
	*/
	public function processResponse($data, $status = null, $encodingOptions = 0)
	{
		$this->cachedBody = json_encode($data, $encodingOptions);

		// Ensure that the json encoding passed successfully
		if ($this->cachedBody === false) {
			throw new \RuntimeException(json_last_error_msg(), json_last_error());
		}

		if ($this->cacheApcEnabled && !empty($this->cacheIdentity)) {
			apc_store($this->cacheIdentity, $this->cachedBody, $this->cacheTTL);
		}

		$this->response = $this->response->withHeader('Content-Type', 'application/json;charset=utf-8');
		$this->response = $this->response->withHeader('Data-Source', 'Dynamic');
		$this->response = $this->response->withHeader('Access-Control-Allow-Origin', '*');
		$this->response->getBody()->write($this->cachedBody);
		if (!is_null($status)) {
			$this->response = $this->response->withStatus($status);
		}
		return $this->response;
	}

	protected function cacheRead($name)
	{
		if ($this->cacheApcEnabled) {
			return apc_fetch($this->cacheId."_{$name}");
		}
		return false;
	}

	protected function cacheWrite($name, $data, $ttl = null)
	{
		if ($this->cacheApcEnabled) {
			return apc_store($this->cacheId."_{$name}", $data, (isset($ttl) ? $ttl : $this->cacheTTL));
		}
		return false;
	}

	protected function checkUserSession()
	{
		if (PHP_SAPI == 'cli') {
			return true;
		}
		// need to extract session id
		$session_id = $this->request->getParsedBodyParam('session_id', null);
		if (empty($session_id)) {
			return "Missed session identification";
		}
		if (!$this->getModel('Usersessions')->checkSession($session_id)) {
			// last  resort - check if it's coming form ladybug.com
			$http_referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null);
			if (!empty($http_referer)) {
				$referer_host = strtolower(parse_url($http_referer, PHP_URL_HOST));
				if (in_array($referer_host, ['ladybug.com', 'www.ladybug.com', 'admin.ladybug.com'])) {
					return true;
				}
			}
			return "Mismatched or expired session";
		}
		return true;
	}

	protected function getDateTime($date_time, $format = 'm/d/y h:i A')
	{
		$parsed_date = \DateTime::createFromFormat($format, $date_time);
		if ($parsed_date === false) {
			$errors_arr = \DateTime::getLastErrors();
			$errors = (isset($errors_arr['errors']) ? implode(", ", $errors_arr['errors']) : "Unknown error");
			throw new \ErrorException("DateTime conversion error: ".$errors.". Format: '".$format."'. Data: '".$date_time."'.");
		}
		return $parsed_date;
	}


	protected function cliSetup()
	{
		if (PHP_SAPI != 'cli') {
			return "CLI only";
		}
		// safety measure - set script's time limit
		set_time_limit(20);
		// set execution time limit
		ini_set('max_execution_time', '20');
		// increase memory limit
		ini_set('memory_limit', '256M');

		return true;
	}

	/**
	* get model by name
	*/
	protected function getModel($modelname = "")
	{
		if (empty($modelname)) {
			// set default model name by controller name
			$reflect = new \ReflectionClass($this);
			$modelname = $reflect->getShortName();
			$modelname = str_replace('Controller', '', $modelname);
		}
		if (isset($this->models[$modelname])) {
			return $this->models[$modelname];
		}
		$modelname = ucfirst(trim($modelname));
		if (!isset($this->models[$modelname])) {
			$class_name = '\\APP\\Models\\'.$modelname.'Model';
			if (!class_exists($class_name)) {
				throw new \Exception("Class {$class_name} does not exist!");
			}
			$this->models[$modelname] = new $class_name();
		}
		return $this->models[$modelname];
	}

	protected function failedResponse($message = "", $status_code = 200)
	{
		// add newrelic error
		if (extension_loaded('newrelic')) {
			newrelic_add_custom_parameter('ip', (array_key_exists('REMOTE_ADDR', $_SERVER) ? $_SERVER['REMOTE_ADDR'] : 'NA'));
			newrelic_add_custom_parameter('body', json_encode($this->request->getParsedBody()));
			newrelic_notice_error("API failed response: {$message}");
		}
		$data = array(
		  'status' => false,
		  'message' => $message,
		  'result' => array()
		  );
		return $this->response->withJson($data, $status_code);
	}

	/**
	 * Handles subprocessing of other processor methods with multi-threaded capability
	 */
	protected function subProcess($method, $sub_arguments, &$messages, $threads = 3, $quiet = true)
	{
		if (method_exists($this, $method."Post")) {
			if (($threads > 1) && !empty($sub_arguments)) {
				//set up pipe definitions
				$descriptorspec = array(
				 0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
				 1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
				 2 => array("pipe", "w"),  // stderr
				);

				//build out some process data containers
				foreach ($sub_arguments as $i => $args) {
						  $procs[$i] = array(
						   'resource' => null,
						   'pipes' => null,
						   'active' => false,
						   'start' => null
						  );
						  $output[$i] = '';
						  $err_output[$i] = '';
				}

				//set active processes to zero
				$active_procs = 0;

				// get controller name
				$reflect = new \ReflectionClass($this);
				$controllername = $reflect->getShortName();
				$controllername = str_replace('Controller', '', $controllername);

				//while there are processes
				while (count($procs)) {
							 //add new processes if queue has space
					foreach ($sub_arguments as $i => $args) {
						if ($active_procs < $threads) {
							$cmd = 'php cli.php /api/'.$controllername.'/'.$method.' "'.http_build_query($args).'" '.APP_ENV;
							$process = proc_open($cmd, $descriptorspec, $procs[$i]['pipes']);
							if ($process !== false) {
								// set streams into non-blocking mode
								stream_set_blocking($procs[$i]['pipes'][0], 0);
								stream_set_blocking($procs[$i]['pipes'][1], 0);
								stream_set_blocking($procs[$i]['pipes'][2], 0);

								$procs[$i]['resource'] = $process;
								$procs[$i]['active'] = true;
								$active_procs ++;
								//remove from args list so we don't reprocess
								unset($sub_arguments[$i]);
								$procs[$i]['start'] = microtime(true);
								if (!$quiet) {
									echo 'Starting process '.$i."\n";
								}
							} else {
								if (!$quiet) {
									echo 'error creating process for '.$i."\n";
								}
							}
						}
					}

							 //check all existing processes
					foreach ($procs as $i => $proc) {
						if ($proc['active']) {
							$status = proc_get_status($proc['resource']);

							//get the output
							$output[$i] .= stream_get_contents($proc['pipes'][1]);

							// get STDERR output
							$err_output[$i] .= stream_get_contents($proc['pipes'][2]);

							if (!$status['running']) {
								proc_close($proc['resource']);
								if (!$quiet) {
									// output thread's STDERR content
									if (!empty($err_output[$i])) {
										echo "Process ".$i." STDERR output:\n".$err_output[$i]."\n";
										// data clean-up
										$err_output[$i] = null;
									}
									echo 'Process '.$i.' completed in '.(microtime(true) - $proc['start']).
									 ' seconds.'."\n";
								}
								unset($procs[$i]);
								$active_procs--;
							}
						}
					}
							 // sleep for 200ms
							 usleep(200);
				}
				$messages = $output;
			} else {
				//regular foreach loop to process
				//foreach ($sub_arguments as $i => $args) {
				//	$messages[$i] = '';
				//	$this->$method($args, $messages[$i]);
				//}
			}
			return true;
		}
	}
}
