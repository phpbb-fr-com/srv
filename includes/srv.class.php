<?php
/**
*
* @package Support Request Validation
* @author Zoddo for phpBB-fr.com MOD Team
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
*
*/

namespace SRV;

define('VERSION', '1.0.0-dev');

class Validator
{
	const ALL				= 31;
	const COOKIES			= 1;	// Also retrieves the active style & language
	const DOC_VERSION		= 2;
	const STYLE_VERSION		= 4;	// Active style (run cookies test if unknown)
	const STYLES_VERSION	= 8;	// Default styles (prosilver & subsilver2)
	const COPYRIGHT			= 16;	// Also check translation copyright (run cookies test if active language is unknown)
	
	protected $url;
	protected $active_style;
	protected $active_lang;
	protected $result = array();
	
	public $context;
	
	public function __construct($url)
	{
		// What? You dare give me something that is not a URL??
		if (!preg_match('#^https?://[a-z0-9-]+\.[a-z0-9.-]+(/[a-z0-9._/-]*)?$#i', $url))
		{
			trigger_error('The format of the URL is invalid', E_USER_ERROR);
		}
		
		// I did not ask for the path of a script but the path of the forum !
		if (preg_match('#(index|forum|board|portal)\.(php[0-9]*|html?)$#i', $url, $matches))
		{
			trigger_error('Please enter the URL without ' . $matches[0], E_USER_ERROR);
		}
		$this->url = $url . ((substr($url, strlen($url) - 1) != '/') ? '/' : '');
		
		// With a user-agent, this is better, right?
		$user_agent  = 'PHP/' . PHP_VERSION . ' (SRV/' . VERSION;
		$user_agent .= (defined('PHPBB_VERSION')) ? ('; PHPBB/' . PHPBB_VERSION) : '';
		$user_agent .= (!empty($_SERVER['HTTP_HOST'])) ? ('; +http://' . $_SERVER['HTTP_HOST'] . '/)') : ')';
		$context = array(
			'http'	=> array (
				'user_agent'		=> $user_agent,
				'follow_location'	=> 0, // 0 = unactivated ; 1 = activated
				'timeout'			=> 2
		));
		$this->context = stream_context_create($context);
		
		// We create our array?
		$this->reset_result_array(self::ALL);
	}
	
	public function __get($name)
	{
		return $this->{$name};
	}
	
	final public function run($type, $url = false)
	{		
		// Cookies test
		if ($type & self::COOKIES)
		{
			if (!$url)
			{
				$url = $this->url;
			}
			
			// Reset the result array
			$this->reset_result_array(self::COOKIES);
			
			// Get the board index (headers & contents)
			extract(file_get_contents($url, false, $this->context));
			
			// What !? There has an error?
			if ($http_response === false || $http_response_header['status'] == 404)
			{
				$this->result[self::COOKIES] = array_merge($this->result[self::COOKIES], array(
					'error'			=> true,
					'error_http'	=> (!empty($http_response_header['status'])) ? $http_response_header['status'] : false,
					'error_message'	=> (!empty($http_error)) ? $http_error : false
				));
			}
			// What?? I am told to go somewhere else?
			else if (!empty($http_response_header['location']))
			{
				if (!is_array($http_response_header['location']))
				{
					if (preg_match('#^(https?://[a-z0-9-]+\.[a-z0-9.-]+)#i', $http_response_header['location'], $matches))
					{
						$this->url = preg_replace('#^https?://[a-z0-9-]+\.[a-z0-9.-]+(/[a-z0-9._/-]*)?$#i',
									$matches[1] . '$1', $this->url);
					}
					$this->run(self::COOKIES, $http_response_header['location']);
				}
				else
				{
					if (preg_match('#^(https?://[a-z0-9-]+\.[a-z0-9.-]+)#i', $http_response_header['location'][0], $matches))
					{
						$this->url = preg_replace('#^https?://[a-z0-9-]+\.[a-z0-9.-]+(/[a-z0-9._/-]*)?$#i',
									$matches[1] . '$1', $this->url);
					}
					$this->run(self::COOKIES, $http_response_header['location'][0]);
				}
			}
			else
			{
				if (!is_array($http_response_header['set-cookie']))
				{
					if (!$this->result[self::COOKIES]['ok'] = $this->cookie_check($http_response_header['set-cookie']))
					{
						$this->result[self::COOKIES]['errors']['name'] = $cookie_name;
						foreach ($cookie_errors as $value)
						{
							$this->result[self::COOKIES]['errors'][$value] = true;
						}
					}
				}
				else
				{
					foreach ($http_response_header['set-cookie'] as $cookie)
					{
						if (!$this->result[self::COOKIES]['ok'] = $this->cookie_check($cookie))
						{
							$this->result[self::COOKIES]['errors']['name'] = $cookie_name;
							foreach ($cookie_errors as $value)
							{
								$this->result[self::COOKIES]['errors'][$value] = true;
							}
							
							break;
						}
					}
				}
				
				$this->result[self::COOKIES]['header_cookie'] = $http_response_header['set-cookie'];
				$this->get_active_style($http_response);
				$this->get_active_lang($http_response);
			}
		}
	}
	
	protected function reset_result_array($type)
	{
		if ($type & self::COOKIES)
		{
			$this->result[self::COOKIES] = array(
				'header_cookie'	=> null,
				'ok'			=> false,
				'errors'		=> array(
					'name'			=> null,
					'value'			=> false,
					'expires' 		=> false,
					'path'			=> false,
					'domain'		=> false,
					'secure'		=> false
				),
				
				'error'			=> false,
				'error_http'	=> false,
				'error_message'	=> false
			);
		}
	}
	
	protected function get_active_style($source)
	{
		$url = preg_quote($this->url, '#');
		if (!preg_match('#<link href="' . $url . 'styles/(.+)/theme/(.+)\.css"#i', $source, $matches))
		{
			return false;
		}
		
		return $this->active_style = $matches[1];
	}
	
	protected function get_active_lang($source)
	{
		$url = preg_quote($this->url, '#');
		if (!preg_match('#<link href="' . $url . 'style\.+\?.*lang=([a-z]+).*"#i', $source, $matches))
		{
			return false;
		}
		
		return $this->active_lang = $matches[1];
	}
	
	final public function cookie_check($cookie)
	{
		global $cookie_errors, $cookie_name;
		
		handling_http_cookie($cookie);
		
		$cookie_errors = array();
		$cookie_name = $cookie['name'];
		
		if (!preg_match('#_(u|k|sid)$#i', $cookie['name'], $matches))
		{
			return true; // Not trigger an alert, this is not a native cookie of phpBB
		}
		
		if ($matches[1] == 'u' && !ctype_digit($cookie['value']))
		{
			$cookie_errors[] = 'value';
		}
		
		if (($matches[1] == 'k' || $matches[1] == 'sid') && !empty($cookie['value']) && !ctype_alnum($cookie['value']))
		{
			$cookie_errors[] = 'value';
		}
		
		if (!empty($cookie['expires']) && $cookie['expires'] <= (time() + (60 * 5)))
		{
			$cookie_errors[] = 'expires';
		}
		
		if (!empty($cookie['path']) && preg_match('#^(https?)://([a-z0-9-]+\.[a-z0-9.-]+)(/[a-z0-9._/-]*)$#i', $this->url, $matches))
		{
			if (substr($matches[3], 0, strlen($cookie['path'])) != $cookie['path'])
			{
				$cookie_errors[] = 'path';
			}
		}
		else
		{
			preg_match('#^(https?)://([a-z0-9-]+\.[a-z0-9.-]+)/#i', $this->url, $matches);
		}
		
		if (!empty($cookie['domain']) && substr($matches[2], strlen($cookie['domain'])/-1) != $cookie['domain'])
		{
			$cookie_errors[] = 'domain';
		}
		
		if (!empty($cookie['secure']) && $matches[1] != 'https')
		{
			$cookie_errors[] = 'secure';
		}
		
		return (empty($cookie_errors)) ? true : false;
	}
}

/*
 * @FIXME Sometimes $http_response_header is no longer global
 */
function file_get_contents($filename, $use_include_path = false, $context = null, $offset = -1, $maxlen = null)
{
	// global $http_response_header, $http_error;
	$http_response = $http_response_header = $http_error = null;
	
	set_error_handler(__NAMESPACE__ . '\exception_error_handler');
	
	try
	{
		$http_response = \file_get_contents($filename, $use_include_path, $context, $offset, $maxlen);
	}
	catch(\ErrorException $e)
	{
		$http_response = false;
		$http_error = $e->getMessage();
	}
	
	restore_error_handler();
	
	handling_http_headers($http_response_header);
	return compact('http_response', 'http_response_header', 'http_error');
}

function handling_http_headers(&$headers)
{
	if (!empty($headers) && is_array($headers))
	{
		$return = array('status' => false);
		
		foreach ($headers as $val)
		{
			$return[] = $val;
			
			if (preg_match('#^HTTP/1.[01] ([0-9]+) #i', $val, $matches))
			{
				$return['status']		= (int) $matches[1];
			}
			else if (preg_match('#^([a-z0-9-]+): (.+)$#i', $val, $matches))
			{
				$matches[1] = strtolower($matches[1]);
				
				// Oula ... There are more headers with the same name!
				if (isset($return[$matches[1]]))
				{
					// There is no existing array to list all the headers ... we create one?
					if (!is_array($return[$matches[1]]))
					{
						$return[$matches[1]] = array($return[$matches[1]]);
					}
					
					$return[$matches[1]][]	 = $matches[2];
				}
				else
				{
					$return[$matches[1]]	 = $matches[2];
				}
			}
		}
		
		return $headers = $return;
	}
	else
	{
		return false;
	}
}

function handling_http_cookie(&$cookie)
{
	$return = $matches = array();
	
	if (preg_match('#^([a-z0-9_-]+)=([^;]*)#i', $cookie, $matches))
	{
		$return['name']		= $matches[1];
		$return['value']	= $matches[2];
	}
	
	if (preg_match('#expires=([a-z]{3}, [0-9]{2}-[a-z]{3}-[0-9]{4} (?:[0-9]{2}:){2}[0-9]{2} GMT)#i', $cookie, $matches))
	{
		$return['expires']		= strtotime($matches[1]);
		$return['raw_expires']	= $matches[1];
	}
	
	if (preg_match('#path=([^;]+)#i', $cookie, $matches))
	{
		$return['path'] = $matches[1];
	}
	
	if (preg_match('#domain=([^;]+)#i', $cookie, $matches))
	{
		$return['domain'] = $matches[1];
	}
	
	$return['HttpOnly']	= (preg_match('#; HttpOnly#i', $cookie)) ? true : false;
	$return['secure']	= (preg_match('#; secure#i', $cookie)) ? true : false;
	
	return $cookie = $return;
}

function exception_error_handler($errno, $errstr, $errfile, $errline)
{
    throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
}

?>