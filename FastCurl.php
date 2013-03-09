<?php

/**
 * FastCurl (PHP object-oriented wrapper for {@link http://curl.haxx.se/ cURL})
 *
 * Copyright (c) 2010 Antonio López Vivar
 * 
 * LICENSE:
 * 
 * This library is free software: you can redistribute it and/or modify 
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see {@link http://www.gnu.org/licenses}.
 *
 * 
 * @category  Library
 * @package   FastCurl
 * @author    Antonio López Vivar <tonikelope@gmail.com>
 * @copyright 2010 Antonio López Vivar
 * @license   http://opensource.org/licenses/lgpl-3.0.html LGPL
 * @version   3.4
 */
	
require_once('FastCurlMulti.php');


/**
 * @property mixed|null $_ch cURL handle
 * @property array|null $_fastcurlopt Assoc array with CURLOPTs that have been set in cURL handle.
 * @property bool|null $_multilock Indicates if the object is associated to a FastCurlMulti container.
 * @property string|null $_response Keeps the response of last curl_exec() operation.
 * @property int|null $_exec_mode self::FCURL_EXEC_BODY: body, self::FCURL_EXEC_HEADERS: headers, self::FCURL_EXEC_HEADERSBODY: headers + body
 * @property bool|null $_anonymous Indicates if we use transparent anonymous queries.
 * @property bool|null $_response_lock Indicates if $_response is write protected.
 * @property array|null $_fastcookies FastCookies store.
 * @property bool|null $_track_header_out Indicates if CURLINFO_HEADER_OUT is ON (More info -> {@link http://php.net/manual/en/function.curl-getinfo.php})
 * @property bool|null $_fast_followlocation If safe_mode or open_basedir are enabled CURLOPT_FOLLOWLOCATION cannot be activated. In this case, FastCurl will do its own redirection.
 * @property bool|null $_public_suffix Public suffix tree (More info -> {@link http://publicsuffix.org})
 */
class FastCurl implements customHeaders, anonymizable
{
	const VERSION='3.4';
	
	const FCURL_EXEC_BODY=1;
	
	const FCURL_EXEC_HEADERS=2;
	
	const FCURL_EXEC_HEADERSBODY=3;
	
	const FCURL_CONNECTTIMEOUT=10;
	
	const PUBLIC_SUFFIX_FILE='effective_tld_names.dat'; 
	
	const PUBLIC_SUFFIX_URL='http://mxr.mozilla.org/mozilla-central/source/netwerk/dns/effective_tld_names.dat?raw=1'; 
	
	private $_ch=NULL;

	private $_fastcurlopt=NULL;
	
	private $_multilock=NULL;
	
	private $_response=NULL;
	
	private $_exec_mode=NULL;
	
	private $_anonymous=NULL;
	
	private $_response_lock=NULL;
	
	private $_fastcookies=NULL;
	
	private $_track_header_out=NULL;
	
	private $_fast_followlocation=NULL;
	
	private $_last_url=NULL;
	
	private static $_public_suffix = NULL;

	
	/**
	 * (All keys in arrays are optional and case/order insensitive).
	 *
	 * @param Array $params CURLOPTS and _fastcurlopt, _multilock, _response, _exec_mode, _anonymous, _response_lock, _fastcookies, _track_header_out
	 */
	public function __construct(Array $params=NULL)
	{
		if(!extension_loaded('curl')) 
			throw new ErrorException('cURL library is not loaded. Please recompile PHP with the cURL library.');
			
		$this->reset($params);
	}
	
	
	/**
	 * Use this function to FULL RESET a FastCurl object. (All keys in arrays are optional and case/order insensitive).
	 *
	 * @param Array $params CURLOPTS and _fastcurlopt, _multilock, _response, _exec_mode, _anonymous, _response_lock, _fastcookies, _track_header_out
	 */
	public function reset(Array $params=NULL)
	{
		$this->_fastcurlopt=array();
		
		if($params)
			$params=array_change_key_case($params);
		
		if($this->_ch)
		{
			if($this->is_multilock())
				$this->unlockMulti($this->_multilock);
				
			if(is_array($this->_fastcookies) && isset($this->cookiejar))
				$this->fc_cookies2disk();
			
			curl_close($this->_ch);
		}
		
		$this->_ch=curl_init();
		curl_setopt($this->_ch, CURLOPT_HEADER, TRUE);
		
		if($params['_multilock'] && is_a($params['_multilock'], 'FastCurlMulti'))
			$this->lockMulti($params['_multilock']);
		else
			$this->_multilock=NULL;
		
		$this->set_exec_mode($params['_exec_mode']);
		$this->set_anonymous((bool)$params['_anonymous']);
		$this->set_track_header_out((bool)$params['_track_header_out']);
		
		$this->set_response($params['_response']);
		$this->set_response_lock((bool)$params['_response_lock']);
		
		if($params['_fastcookies'] || is_array($params['_fastcookies']))
		{
			//FastCurl cookies (they can be enabled just at constructor time).
			date_default_timezone_set('UTC');
			
			//Optional: you can pass a _fastcookies array from other FastCurl object or a cookiefile
			if(is_array($params['_fastcookies']))
				$this->_fastcookies=$params['_fastcookies'];
			else
			{	
				$this->_fastcookies=array();
				
				if(is_string($params['_fastcookies']))
					$this->load_fc_cookies($params['_fastcookies']);
			}
				
			//Optional (default ON): USE PUBLIC SUFFIX LIST FOR COOKIES -> http://publicsuffix.org
			if($params['_fastcookies_ps']!==FALSE && !$this->load_public_suffix_file(is_bool($params['_fastcookies_ps']['url'])?$params['_fastcookies_ps']['url']:FALSE))
				trigger_error("PUBLIC SUFFIX LIST COULD NOT BE LOADED!", E_USER_WARNING);
		}
		else
		{
			//CURL cookies (DEFAULT)
			$this->_fastcookies=NULL;
			$this->cookiefile=uniqid();
		}
		
		$this->failonerror=TRUE;
		$this->useragent=implode('_', array('FastCurl', self::VERSION, FastCurlMulti::VERSION));
		$this->httpheader=$this->default_headers();
		$this->connecttimeout=self::FCURL_CONNECTTIMEOUT;
		$this->ssl_verifyhost=0;
		$this->ssl_verifypeer=FALSE;
		
		if($params)
		{
			if($params['_fastcurlopt'])
				$this->set_fastcurlopt($params['_fastcurlopt'], (bool)$this->_response, !empty($this->_fastcookies));
			
			foreach($params as $key => $value)
			{
				if($key[0]!='_')
					$this->$key=$value;
			}
		}
		
		return TRUE;
	}
	
	
	
	/**
	 * Magic method for cloning FastCurl object.
	 */
	public function __clone()
	{
        $this->_ch=NULL;
        
        $this->reset(array('_fastcurlopt' => $this->_fastcurlopt, '_multilock' => $this->_multilock, '_response' => $this->_response, '_exec_mode' => $this->_exec_mode, '_anonymous' => $this->_anonymous, '_response_lock' => $this->_response_lock, '_fastcookies' => $this->_fastcookies, '_track_header_out' => $this->_track_header_out));
    }
	
	/**
	 * If you need to change any cookie you have to write them first to disk (curl does not manage cookies).
	 *
	 * @return bool
	 */
	public function curl_cookies2disk()
	{
		if(!is_array($this->_fastcookies) && $this->_ch && $this->cookiejar)
		{
			$res_lock=$this->is_response_lock();
			$this->set_response_lock(TRUE);
			
			if($this->is_multilock())
			{
				$mh=$this->_multilock;
				$this->unlockMulti($this->_multilock);
			}
			
			curl_close($this->_ch);
			
			$this->_ch=curl_init();
			
			if($mh)
				$this->lockMulti($mh);
	
			$this->set_fastcurlopt($this->get_fastcurlopt(), TRUE, FALSE);
			
			if($this->cookiefile!=$this->cookiejar)
				$this->cookiefile=$this->cookiejar;
				
			$this->set_response_lock($res_lock);
			
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * @return mixed $this->_ch
	 */
	public function get_ch_from_multi(FastCurlMulti $mh)
	{
		return ($this->is_multilock() && $this->_multilock===$mh)?$this->_ch:NULL;
	}
	
	
	/**
	 * REMEMBER: any CURLOPT (followlocation, autoreferer or returntransfer) that you DO NOT send, will be set to its default value!
	 * 
	 * @param string|array array('_exec_mode' => $this->_exec_mode, 'followlocation' => $this->followlocation, 'autoreferer' => $this->autoreferer, 'returntransfer' => $this->returntransfer)
	 * @param bool $keep_res TRUE to keep safe current $this->_response
	 * @return bool
	 */
	public function set_exec_mode($exec_mode=NULL, $keep_res=FALSE)
	{	
		if($keep_res)
		{
			$res_lock=$this->is_response_lock();
			$this->set_response_lock(TRUE);
		}
		
		$exec_mode=array_change_key_case(is_array($exec_mode)?$exec_mode:array('_exec_mode' => $exec_mode));
		
		if($exec_mode['_exec_mode']==self::FCURL_EXEC_BODY || $exec_mode['_exec_mode']==self::FCURL_EXEC_HEADERS || $exec_mode['_exec_mode']==self::FCURL_EXEC_HEADERSBODY)
			$this->_exec_mode=$exec_mode['_exec_mode'];
		else
			$this->_exec_mode=self::FCURL_EXEC_BODY;
		
		$this->followlocation=is_bool($exec_mode['followlocation'])?$exec_mode['followlocation']:TRUE;
		$this->autoreferer=is_bool($exec_mode['autoreferer'])?$exec_mode['autoreferer']:TRUE;
		$this->returntransfer=is_bool($exec_mode['returntransfer'])?$exec_mode['returntransfer']:TRUE;
		
		if($keep_res)
			$this->set_response_lock($res_lock);

		return TRUE;
	}
	
	
	/**
	 * @return array array('_exec_mode' => $this->_exec_mode, 'followlocation' => $this->followlocation, 'autoreferer' => $this->autoreferer, 'returntransfer' => $this->returntransfer)
	 */
	public function get_exec_mode()
	{
		return $this->_exec_mode?array('_exec_mode' => $this->_exec_mode, 'followlocation' => $this->followlocation, 'autoreferer' => $this->autoreferer, 'returntransfer' => $this->returntransfer):NULL;
	}
	
	/**
	 * Last effective url
	 * @return string 
	 */
	public function get_last_url()
	{
		return $this->_last_url;
	}
	
	/**
	 * 
	 */
	public function set_last_url_from_multi(FastCurlMulti $mh, $url)
	{
		if($this->is_multilock() && $this->_multilock===$mh)
		{
			$this->_last_url=$url;
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * @return bool 
	 */
	public function is_multilock()
	{
		return $this->_multilock?TRUE:FALSE;
	}
	
	
	/**
	 * @return bool 
	 */
	public function is_fast_followlocation()
	{
		return $this->_fast_followlocation;
	}
	
	
	/**
	 * Set the fastcurlopt array. (It merges current _fastcurlopt array with the new one passed in $curlopt, overwritting values with the same key).
	 *
	 * @param array $curlopt array('CURLOPT_NAME' => VALUE, etc...) (Remember header's array format is array('hname' => 'hvalue', etc...)) 
	 * @param bool $keep_res TRUE to keep safe current $this->_response
	 * @param bool $keep_cookiefile Set it TRUE if you do not want to "refresh" cookies by reading a cookiefile.
	 * @return int $tot Number of CURLOPTs MODIFIED.
	 */
	public function set_fastcurlopt(Array $curlopt, $keep_res=TRUE, $keep_cookiefile=TRUE)
	{		
		if(!count($curlopt))
			$this->_fastcurlopt=array();
		else
		{	
			if($keep_res)
			{
				$res_lock=$this->is_response_lock();
				$this->set_response_lock(TRUE);
			}
			
			if(!is_array($this->_fastcurlopt))
				$this->_fastcurlopt=array();
				
			if($keep_cookiefile)
				unset($curlopt['CURLOPT_COOKIEFILE']);
			
			foreach($curlopt as $opt => $value)
			{
				$opt=str_ireplace('CURLOPT_','',trim($opt));
				$this->$opt=$value;
			}
			
			if($keep_res)
				$this->set_response_lock($res_lock);
		}
	
		return TRUE;
	}
	
	
	/**
	 * @return array $this->_fastcurlopt
	 */
	public function get_fastcurlopt()
	{
		return $this->_fastcurlopt;
	}
	
	
	/**
	 * Parses and stores FastCurl cookies from a curl response
	 * 
	 * @param string $res_curl
	 *
	 * @return bool
	 */
	private function receive_fc_cookies($res_curl)
	{
		if(is_array($this->_fastcookies) && preg_match_all('/(?<=Set-Cookie\:).*?(?=\r\n)/i', preg_replace('/^(.*?(?:HTTP *?\/ *?.*? +?\d{3}.*?\r\n\r\n))+.*$/is', '\1', $res_curl), $cookies_raw)>0)
		{			
			foreach($cookies_raw[0] as $cookie_raw)
			{
				if(preg_match('/^(?P<name>[^=]+)\=(?P<value>.*?)(?:; (?P<rest>.+))?$/', trim($cookie_raw), $parse))
				{
					$name=trim($parse['name']);
					$value=trim($parse['value']);
						
					$c=array();
									
					if(isset($parse['rest']))
					{
						foreach(array('expires' => TRUE, 'max-age' => TRUE,'domain' => TRUE, 'path' => TRUE, 'secure' => FALSE, 'httponly' => FALSE) as $at_name => $val_required)
						{
							if(preg_match('/'.preg_quote($at_name, '/').($val_required?'\=(.*?)':'.*?').'(?=;|$)/i', trim($parse['rest']), $val))
								$c[$at_name]=$val_required?trim($val[1]):TRUE;
						}
					}
					
					if(!isset($c['domain']) || (!$this->is_public_suffix(($c['domain']=ltrim(strtolower($c['domain']), '.'))) && (preg_match('/^.*'.preg_quote($c['domain'], '/').'$/', $this->get_url_domain()))))
					{
						$path=isset($c['path'])?$c['path']:$this->get_url_path();	
						$expiry_time=(!isset($c['max-age']) && !isset($c['expires']))?-1:(isset($c['max-age'])?time()+$c['max-age']:strtotime($c['expires']));
						
						if(!isset($this->_fastcookies[$domain][$name][$path]) && ($expiry_time==-1 || $expiry_time > time()))
							$this->set_fc_cookie($name, $value, !isset($c['domain'])?array(NULL):$c['domain'], $path, $expiry_time, isset($c['secure']), isset($c['httponly']));
						else if($expiry_time==-1 || $expiry_time > time())
							$this->set_fc_cookie($name, $value, !isset($c['domain'])?array(NULL):$c['domain'], $path, $expiry_time, isset($c['secure']), isset($c['httponly']), $this->_fastcookies[$domain][$name][$path]['creation-time']);
						else
							$this->delete_fc_cookie($domain, $name, $path);
					}
				}
			}
		
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Parses and stores FastCurl cookies from a curl response (called from a FastCurlMulti)
	 * 
	 * @param FastCurlMulti $mh
	 * @param string $res
	 *
	 * @return bool
	 */
	public function receive_fc_cookies_from_multi(FastCurlMulti $mh, $res)
	{
		if($this->is_multilock() && $this->_multilock===$mh)
			return $this->receive_fc_cookies($res);
		else
			return FALSE;
	}
	
	
	/**
	 * Check if a domain is a public suffix (More info -> {@link http://publicsuffix.org})
	 * 
	 * @param string $domain
	 *
	 * @return int|bool
	 */
	private function is_public_suffix($domain)
	{
		if(is_array(self::$_public_suffix))
		{
			$current_tree_level=&self::$_public_suffix;
			$tot=count(($domain_levels=explode('.', trim($domain, '.'))));
			
			do
			{
				if(array_key_exists($domain_levels[$tot-1], $current_tree_level) || (array_key_exists('*', $current_tree_level) && !array_key_exists('!'.$domain_levels[$tot-1], $current_tree_level)))
				{
					$current_tree_level=&$current_tree_level[array_key_exists('*', $current_tree_level)?'*':$domain_levels[$tot-1]];
					$tot--;
				}
				else
					$current_tree_level=NULL;
				
			}while($current_tree_level && $tot>0);
		
			return (!$current_tree_level && $tot>0)?FALSE:TRUE;
		}
		else
			return 0;
	}
	
	
	/**
	 * Loads Public Suffix Tree (More info -> {@link http://publicsuffix.org})
	 * 
	 * @param bool $get_from_url Reads Public Suffix List from PUBLIC_SUFFIX_URL (instead PUBLIC_SUFFIX_FILE)
	 *
	 * @return int|bool
	 */
	private function load_public_suffix_file($get_from_url=FALSE)
	{
		if(!is_array(self::$_public_suffix))
		{
			if($get_from_url===TRUE || is_readable(($file_path=realpath(__DIR__.'/'.ltrim(self::PUBLIC_SUFFIX_FILE, '/')))))
			{
				$suffix=array();
				
				$f=fopen($get_from_url===TRUE?self::PUBLIC_SUFFIX_URL:$file_path, 'r');
				
				while(($l=fgets($f))!==FALSE)
				{
					if(($l=trim($l))!='' && strpos($l, '//')!==0)
					{
						$current=&$suffix;
						
						foreach(array_reverse(explode('.', $l)) as $level)
						{
							if(!isset($current[$level]))
								$current[$level]=array();
							
							$current=&$current[$level];
						}
					}
				}
				
				fclose($f);
				
				if($suffix)
				{
					self::$_public_suffix=$suffix;
					return TRUE;
				}
				else
					return FALSE;
			}
			else
				return FALSE;
		}
		else
			return TRUE;
	}
	
	
	/**
	 * Creates Cookie header and add it to the current request
	 *
	 * @return bool|string
	 */
	private function send_fc_cookies()
	{
		if(is_array($this->_fastcookies))
		{
			$cookies_domain=array();
			
			foreach($this->_fastcookies as $domain => $names)
			{
				if(preg_match('/^.*'.preg_quote($domain, '/').'$/i', $this->get_url_domain()))
				{
					$cookies_name=array();
					
					foreach($names as $name => $paths)
					{
						foreach($paths as $path => &$c)
							if(preg_match('/^(?:https?\:\/\/)?[^\/]+'.preg_quote(rtrim($path, '/'), '/').'/i', $this->url) && ($c['expiry-time']==-1 || $c['expiry-time'] > time()) && (!$c['secure'] || $this->is_https_url()) && (!$c['host-only'] || $domain==$this->get_url_domain()))
							{
								$c['last-access-time']=time();
								$cookies_name[$name]=array($path => $c['value']);
							}
							else if($c['expiry-time']!=-1 && $c['expiry-time'] <= time())
								$this->delete_fc_cookie($domain, $name, $path);
					}
					
					if($cookies_name)
						$cookies_domain[$domain]=$cookies_name;
				}
			}
			
			if($cookies_domain)
			{
				//First merge cookies from domain.com, foo.domain.com, loo.foo.domain.com, etc. (If two or more domains have a cookie with the same name, the one from the largest domain will prevail).
				uksort($cookies_domain, function($a, $b){return ($r=strlen($a)-strlen($b))?$r:strcasecmp($a,$b);});
				$cookies=array();
				
				foreach($cookies_domain as $cookie)
					$cookies=array_merge($cookies, $cookie);

				//Now prepare the Cookie http header. (If a cookie has several paths larger path values will be sent before).
				$cook_str=NULL;
				
				foreach($cookies as $name => $paths)
				{
					$cookies_path=array();
					
					foreach($paths as $path => $value)
						$cookies_path[$path]="$name=$value";
		
					krsort($cookies_path);
					
					$cook_str.=(!$cook_str?'':'; ').implode('; ', $cookies_path);
				}
			}
				
			if($cook_str)
				$this->add_header(array('cookie' => $cook_str));
			else
				$this->delete_header('cookie');
				
			return $cook_str;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Creates Cookie header and add it to the current request (called from a FastCurlMulti)
	 *
	 * @param FastCurlMulti $mh
	 * 
	 * @return bool|string
	 */
	public function send_fc_cookies_from_multi(FastCurlMulti $mh)
	{
		if($this->is_multilock() && $this->_multilock===$mh)
			return $this->send_fc_cookies();
		else
			return FALSE;
	}
	
	
	/**
	 * Writes FastCurl cookies to disk
	 * 
	 * @param string|null $cookiejar
	 *
	 * @return bool
	 */
	public function fc_cookies2disk($cookiejar=NULL)
	{
		if(is_array($this->_fastcookies) && ($this->cookiejar || $cookiejar))
		{
			$cookies=array("#FastCurl cookies (EDIT AT YOUR OWN RISK)\n");
			
			foreach($this->_fastcookies as $domain => $names)
			{
				foreach($names as $name => $paths)
				{
					foreach($paths as $path => $c)
						if($c['expiry-time']==-1 || $c['expiry-time'] > time())
							$cookies[]="{$domain}\t{$name}\t{$c['value']}\t{$path}\t{$c['expiry-time']}\t".($c['secure']?1:0)."\t".($c['httponly']?1:0)."\t".($c['host-only']?1:0)."\n";
				}
			}
			
			return file_put_contents($cookiejar?$cookiejar:$this->cookiejar, $cookies)===FALSE?FALSE:TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Loads FastCurl cookies from a file or a _fastcookies array
	 * 
	 * @param string|array $cookiefile
	 *
	 * @return bool
	 */
	public function load_fc_cookies($cookiefile)
	{		
		if(is_array($this->_fastcookies))
		{
			if(is_array($cookiefile))
			{
				foreach($cookiefile as $domain => $names)
				{
					foreach($names as $name => $paths)
					{
						foreach($paths as $path => $c)
						{
							if(isset($this->_fastcookies[$domain][$name][$path]))
							{
								if($c['creation-time'] > $this->_fastcookies[$domain][$name][$path]['creation-time'])
									$this->_fastcookies[$domain][$name][$path]=$c;
							}
							else
								$this->_fastcookies[$domain][$name][$path]=$c;
						}
					}
				}
			}
			else if(is_string($cookiefile) && is_readable($cookiefile) && ($cookies=file($cookiefile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)))
			{
				foreach($cookies as $cookie)
				{
					$cookie=trim($cookie);
					
					if($cookie[0]!='#')
					{
						list($domain, $name, $value, $path, $expiry_time, $secure, $httponly, $host_only)=explode("\t", $cookie);
						$this->set_fc_cookie($name, $value, (bool)$host_only?array($domain):$domain, $path, $expiry_time, (bool)$secure, (bool)$httponly);
					}
				}
			}

			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Sets a FastCurl cookie.
	 * 
	 * @param string $name
	 * @param string $value
	 * @param string|array $domain If host-only flag is TRUE use array($domain)
	 * @param string $path
	 * @param int $expire
	 * @param bool $secure
	 * @param bool $httponly
	 * @param int $creation_time
	 *
	 * @return bool
	 */
	public function set_fc_cookie($name, $value=NULL, $domain=NULL, $path='/', $expire=FALSE, $secure=FALSE, $httponly=FALSE, $creation_time=NULL)
	{
		if(!empty($name))
		{
			$host_only=TRUE;
			
			if(!$domain)
				$domain=$this->get_url_domain();
			else if(is_array($domain))
				$domain=$domain[0]?$domain[0]:$this->get_url_domain();
			else
				$host_only=FALSE;
			
			$this->_fastcookies[$domain][$name][($path=$path?$path:$this->get_url_path())]=array('value' => $value, 'secure' => $secure, 'httponly' => $httponly, 'creation-time' => ($t=is_numeric($creation_time)?$creation_time:time()), 'last-access-time' => $t, 'expiry-time' => (is_numeric($expire) && $expire > time())?$expire:-1, 'host-only' => $host_only);
						
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Gets a FastCurl cookie.
	 * 
	 * @param string $domain
	 * @param string $name
	 * @param string $path
	 *
	 * @return array|bool 
	 */
	public function get_fc_cookies($domain=NULL, $name=NULL, $path=NULL)
	{
		if(!func_num_args())
			return $this->_fastcookies;
		else if($domain || ($domain=$this->get_url_domain()))
		{
			if($name)
			{
				if($path)
					return($this->_fastcookies[$domain][$name][$path]);
				else
					return($this->_fastcookies[$domain][$name]);
			}
			else
				return($this->_fastcookies[$domain]);
							
			return TRUE;
		}
		else
			return FALSE;
	}

	
	/**
	 * Deletes a FastCurl cookie.
	 * 
	 * @param string $domain
	 * @param string $name
	 * @param string $path
	 *
	 * @return bool
	 */
	public function delete_fc_cookie($domain=NULL, $name=NULL, $path=NULL)
	{
		if($domain || ($domain=$this->get_url_domain()))
		{
			if($name)
			{
				if($path)
				{
					unset($this->_fastcookies[$domain][$name][$path]);
					
					if(!count($this->_fastcookies[$domain][$name]))
						unset($this->_fastcookies[$domain][$name]);
				}
				else
					unset($this->_fastcookies[$domain][$name]);
					
				if(!count($this->_fastcookies[$domain]))
					unset($this->_fastcookies[$domain]);
			}
			else
				unset($this->_fastcookies[$domain]);
							
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Deletes all FastCurl cookies.
	 * 
	 * @return TRUE.
	 */
	public function clean_fc_cookies()
	{
		$this->_fastcookies=array();
		return TRUE;
	}
	
	
	/**
	 * Returns the domain of the current URL (or referer).
	 * 
	 * @param bool $referer
	 * 
	 * @return string domain.
	 */
	public function get_url_domain($referer=FALSE)
	{
		return strtolower(preg_replace('/^(?:https?\:\/\/)?([^\/]+).*$/i', '\1', trim($referer?$this->referer:$this->url)));
	}
	
	
	/**
	 * Returns the "path" of the current URL (or referer)
	 * 
	 * @param bool $referer
	 * 
	 * @return string path.
	 */
	public function get_url_path($referer=FALSE)
	{
		return '/'.preg_replace('/^(?:https?\:\/\/)?[^\/]+(?:\/((?:[^\/?]+\/)*).*)?$/i', '\1', trim($referer?$this->referer:$this->url));
	}
	
	
	/**
	 * Returns if the current URL (or referer) is HTTPS
	 * 
	 * @param bool $referer
	 * 
	 * @return string path.
	 */
	public function is_https_url($referer=FALSE)
	{
		return preg_match('/^https\:\/\//i', trim($referer?$this->referer:$this->url));
	}
	
	
	/**
	 * Calls curl_exec() and returns the response.
	 *
	 * @param int|array $exec_mode (JUST FOR THIS REQUEST)
	 * @param $reset_post By default, after a exec() CURLOPT_POST is disabled (CURLOPT_POSTFIELDS are kept).
	 * 
	 * @return string|bool $this->response FRESH server's response.
	 */
	public function exec($exec_mode=NULL, $reset_post=TRUE)
	{
		if($this->url)
		{
			if($this->is_multilock())
			{
				$mh_orig=$this->_multilock;
				$this->unlockMulti($this->_multilock);
			}
			
			if($this->get_anonymous())
			{
				$visible_url=$this->url;
				$visible_ref=$this->referer;
				$this->url=$this->get_anonym_url($this->url);
				$this->referer=$this->get_anonym_url($this->referer);
			}
			
			if($exec_mode)
			{
				$exec_orig=$this->get_exec_mode();
				$this->set_exec_mode(is_array($exec_mode)?array_merge($exec_orig, $exec_mode):$exec_mode);
			}
			
			$this->send_fc_cookies();
				
			if($this->followlocation && (is_array($this->_fastcookies) || $this->is_fast_followlocation()))
			{
				$this->followlocation=FALSE;
				$url_orig=$this->url;
				$referer_orig=$this->referer;
				
				$this->receive_fc_cookies(($res=curl_exec($this->_ch)));
				
				while(strpos(trim($this->info('HTTP_CODE')), '3')===0)
				{
					if($this->post && $this->info('HTTP_CODE')!=307)
						$this->enable_post(FALSE);
					
					preg_match('/(?:(?:Location)|(Refresh))\: *?(?(1)\d+; *?url\=)(?P<redir>[^\r\n]+)/i', $res, $url);
					
					if(preg_match('/^(?!.*?\:\/\/)/', ($url['redir']=trim($url['redir']))))
						$url['redir']=preg_replace('/(?<!\:\/)\/[^\/]+$/','',$this->url).'/'.ltrim($url['redir'], '/');
						
					if($this->autoreferer)
						$this->referer=$this->url;
					
					$this->url=$url['redir'];
					$this->send_fc_cookies();
					$this->receive_fc_cookies(($res=curl_exec($this->_ch)));
				}
				
				$this->_last_url=$this->url;
				$this->url=$url_orig;
				$this->referer=$referer_orig;
				$this->followlocation=TRUE;		
			}
			else
			{
				$this->receive_fc_cookies(($res=curl_exec($this->_ch)));
				$this->_last_url=$this->info('EFFECTIVE_URL');
			}
			
			$res=$this->filter_res_curl($res);
				
			if($exec_mode)
				$this->set_exec_mode($exec_orig);
			
			if($this->get_anonymous())
			{
				$this->url=$visible_url;
				$this->referer=$visible_ref;
			}
			
			if($mh_orig)
				$this->lockMulti($mh_orig);
				
			if($reset_post && $this->post)
				$this->enable_post(FALSE);
				
			$this->set_response($res);
		}
	
		return $res;
	}
	
	
	/**
	 * Returns the LAST curl_exec() or curl_multi_exec() response (Every time a CURLOPT is set with $this->opt=value, the response is reset).
	 *
	 * @param string|array $regex REGEX pattern for looking in the response (array(N => $regex) will return sub-pattern \N).
	 * @param bool $regex_all preg_match or preg_match_all?
	 * @param bool $trim trim before preg_match?
	 * 
	 * @return string $this->response LAST server's response.
	 */
	public function fetch($regex=NULL, $regex_all=FALSE, $trim=TRUE)
	{
		if(!$this->_response && $this->is_multilock())
			$this->set_response(($fetch_res=$this->filter_res_curl(curl_multi_getcontent($this->_ch))));
		else if(!$this->_response && $regex)
			$fetch_res=$this->exec(array('returntransfer' => TRUE));
		else if(!$this->_response)
			$fetch_res=$this->exec();
		else
			$fetch_res=$this->_response;

		if($regex)
		{			
			$preg='preg_match'.($regex_all===TRUE?'_all':'');
			
			if(is_array($regex) && (list($k, $v) = each($regex)))
				$fetch_res_regex=$preg($v, $trim?trim($fetch_res):$fetch_res, $fetch_res_regex)?$fetch_res_regex[$k]:NULL;
			else if(!$preg($regex, $trim?trim($fetch_res):$fetch_res, $fetch_res_regex))
				$fetch_res_regex=NULL;
							
			if(!$this->returntransfer)
			{
				echo __METHOD__." $regex :\n";
				var_dump($fetch_res_regex);
			}
		}
		else if(!$this->returntransfer)
			echo htmlentities($fetch_res, ENT_QUOTES, 'UTF-8');
	
		return $regex?$fetch_res_regex:$fetch_res;
	}
	
	
	/**
	 * @return string curl_error 
	 */
	public function error()
	{
		return curl_error($this->_ch);
	}
	
	
	/**
	 * @return int curl_errno
	 */
	public function errno()
	{
		return curl_errno($this->_ch);
	}
	
	
	/**
	 * @param int $opt
	 * 
	 * @return mixed
	 */
	public function info($opt=NULL)
	{
		return defined(($opt='CURLINFO_'.str_replace('CURLINFO_','',strtoupper($opt))))?curl_getinfo($this->_ch, constant($opt)):curl_getinfo($this->_ch);
	}
	
	
	/**
	 * @param bool $value 
	 *   
	 * @return bool 
	 */
	public function set_track_header_out($value)
	{
		$this->_track_header_out=(bool)$value;
		return curl_setopt($this->_ch, CURLINFO_HEADER_OUT, (bool)$value);
	}
		
	
	/**
	 * @return bool 
	 */
	public function is_track_header_out()
	{
		return $this->_track_header_out;
	}
	
	
	/**
	 * Each CURLOPT is a FastCurl property (not defined), so this "magic" method is called by PHP engine every time we set a CURLOPT.
	 * First we search for a constant CURLOPT_$opt and if it exists we call curl_setopt() and we store the value at $this->_fastcurlopt array
	 * for retrieve with {@link __get()}
	 * @param string $opt
	 * @param mixed $value
	 * @return bool CURLOPT was set ok
	 */
	public function __set($opt, $value)
	{
		if(defined(($const = 'CURLOPT_'.strtoupper($opt))))
		{
			switch($const)
			{
				case 'CURLOPT_HTTPHEADER':
					
					if(is_array($value) && count($value)>0)
					{
						$headers=array();
						$curl_headers=array();
						
						foreach($value as $hname => $hvalue)
						{
							$hname=trim(strtolower($hname));
							$headers[$hname]=($hvalue=trim($hvalue));
							$hname[0]=strtoupper($hname[0]);
							$curl_headers[]=$hvalue?($hname.': '.$hvalue):($hname.':');
						}
						
						$value=$headers;
						
						curl_setopt($this->_ch, constant($const), array());
						$ret_curl=curl_setopt($this->_ch, constant($const), $curl_headers);
					}

					break;
					
				case 'CURLOPT_HEADER':
					trigger_error("{$const}: USE set_exec_mode()!", E_USER_WARNING);
					
					break;
				
				case 'CURLOPT_NOBODY':
					trigger_error("{$const}: USE set_exec_mode()!", E_USER_WARNING);
					
					break;
					
				case 'CURLOPT_FOLLOWLOCATION':
					
					if(!($ret_curl=curl_setopt($this->_ch, constant($const), (bool)$value)))
					{
						$this->_fast_followlocation=(bool)$value;
						$ret_curl=TRUE;
					}
					else
						$this->_fast_followlocation=NULL;
					
					break;
					
				case 'CURLOPT_COOKIEFILE':
					
					if(is_array($this->_fastcookies))
					{
						if(is_string($value))
						{
							$this->load_fc_cookies($value);
							$ret_curl=TRUE;
						}
						else
							$ret_curl=FALSE;
					}
					else
						$ret_curl=curl_setopt($this->_ch, constant($const), $value);
					
					break;
					
				case 'CURLOPT_COOKIEJAR':
					
					if(is_array($this->_fastcookies))
						$ret_curl=TRUE;
					else
						$ret_curl=curl_setopt($this->_ch, constant($const), $value);
					
					break;
					
				case 'CURLOPT_COOKIE':
					
					if(is_array($this->_fastcookies))
						trigger_error("{$const}: is locked with fastcookies enabled!", E_USER_WARNING);
					else
						$ret_curl=curl_setopt($this->_ch, constant($const), $value);
					
					break;
					
				case 'CURLOPT_POSTFIELDS':
				
					if(($ret_curl=curl_setopt($this->_ch, constant($const), $value)) && $value)
					{
						if($this->post===FALSE)
							curl_setopt($this->_ch, CURLOPT_POST, FALSE);
						else if(!$this->post)
							$this->post=TRUE;
					}

					break;
					
				default:
					
					$ret_curl=curl_setopt($this->_ch, constant($const), $value);
			}
			
			if($ret_curl)
			{
				$this->_fastcurlopt[$const]=$value;

				if($this->is_multilock() && $this->_multilock->is_implicit_refresh())
					$this->_multilock->refresh($this);
				
				//Reset _response after some CURLOPT is set
				$this->set_response(NULL);
			}
		}
		else
			trigger_error("{$const}: is not defined!", E_USER_WARNING);
		
		return $ret_curl;
	}
	
	
	/**
	 * @param string $opt
	 * @return mixed CURLOPT_$opt
	 */
	public function __get($opt)
	{
		return $this->_fastcurlopt['CURLOPT_'.strtoupper($opt)];
	}
	
	
	/**
	 * @param string $opt
	 * @return bool CURLOPT_$opt has been set.
	 */
	public function __isset($opt)
	{
		return isset($this->_fastcurlopt['CURLOPT_'.strtoupper($opt)]);
	}

	
	/**
	 * Used to "lock" the object to a FastCurlMulti container. (Remember: FastCurl N:1 FastCurlMulti)
	 *
	 * @param FastCurlMulti $mh
	 * @return bool FastCurl object locked ok.
	 */
	public function lockMulti(FastCurlMulti $mh)
	{
		if(!$this->is_multilock())
		{
			$this->_multilock=$mh;
			$mh->accept($this);
			$this->set_response(NULL);
			
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * Used to "unlock" the object to a FastCurlMulti container. (Remember: FastCurl N:1 FastCurlMulti)
	 *
	 * @param FastCurlMulti $mh
	 * @return bool FastCurl object unlocked ok.
	 */
	public function unlockMulti(FastCurlMulti $mh)
	{
		if($this->_multilock===$mh)
		{
			$this->fetch();
			$this->_multilock->release($this);
			$this->_multilock=NULL;
			
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * If http header already exists, it will be overwritten. array('hname' => 'hvalue')
	 *
	 * @param array $header
	 * @return bool TRUE 
	 */
	public function add_header(Array $header)
	{
		$this->httpheader=array_merge($this->_fastcurlopt['CURLOPT_HTTPHEADER'], array_filter(array_change_key_case($header)));
		
		return TRUE;
	}
	
	
	/**
	 * DELETES the http header.
	 * 
	 * @param string|array $hname
	 * @return bool TRUE
	 */
	public function delete_header($hname)
	{
		foreach(is_array($hname)?$hname:array($hname) as $h)
			$this->_fastcurlopt['CURLOPT_HTTPHEADER'][trim(strtolower($h))]=NULL;
		
		$this->httpheader=$this->_fastcurlopt['CURLOPT_HTTPHEADER'];
		
		return TRUE;
	}
	
	
	/**
	 * Restores the default value for that http header.
	 *
	 * @param string|array $hname
	 * @return bool Header reset ok.
	 */
	public function reset_header($hname)
	{
		foreach(is_array($hname)?$hname:array($hname) as $h)
			unset($this->_fastcurlopt['CURLOPT_HTTPHEADER'][trim(strtolower($h))]);
			
		$this->httpheader=$this->_fastcurlopt['CURLOPT_HTTPHEADER'];
	
		return TRUE;
	}
	
	
	/**
	 * Get header's value (previously set).
	 *
	 * @param string $hname
	 * @return string
	 */
	public function get_header($hname)
	{
		return $this->_fastcurlopt['CURLOPT_HTTPHEADER'][trim(strtolower($hname))];
	}
	
	
	/**
	 * Enable POST method.
	 *
	 * @param bool|string|array $postdata Content-Type header will be set by cURL depending on the type of this param. Urlencoded string -> application/x-www-form-urlencoded # Array -> multipart/form-data {@link http://www.php.net/manual/es/function.curl-setopt.php More info}
	 * @param string $ctype Custom content-type
	 * @param string $url Updates CURLOPT_URL
	 * @param string $referer Updates CURLOPT_REFERER
	 * @param bool $keep_res TRUE to keep safe current $this->_response
	 * @return bool $postdata format is correct
	 */
	public function enable_post($postdata, $ctype=NULL, $url=NULL, $referer=NULL, $keep_res=FALSE)
	{		
		if($keep_res)
		{
			$res_lock=$this->is_response_lock();
			$this->set_response_lock(TRUE);
		}
		
		if(is_bool($postdata))
		{			
			if(!($this->post=$postdata))
			{
				$this->reset_header('content-type');
				curl_setopt($this->_ch, CURLOPT_HTTPGET, TRUE);
			}
			else if($this->postfields)
				curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $this->postfields);
				
			$ret=TRUE;
		}
		else if(!empty($postdata))
		{
			if($ctype)
				$this->add_header(array('content-type' => trim($ctype)));
			else if($this->get_header('content-type'))
				$this->reset_header('content-type');
			
			$this->post=TRUE;
			$this->postfields=$ctype?$postdata:(is_array($postdata)?$postdata:trim($postdata, " \r\n&"));

			if($url)
				$this->url=$url;
		
			if($referer)
				$this->referer=$referer;
			
			$ret=TRUE;
		}
		else
			$ret=FALSE;
			
		if($keep_res)
			$this->set_response_lock($res_lock);
		
		return $ret;
	}
	
	
	/**
	 * @return array $version
	 */
	public function version()
 	{
 		$version = curl_version();
 		
 		$version['fastcurl_version'] = self::VERSION;
 		$version['fastcurlmulti_version'] = FastCurlMulti::VERSION;
 		
 		return $version;
 	}
	
	
	/**
	 * Change it to return your default headers.
	 * @return array $headers array('header1_name' => header1_value, 'header2_name' => header2_value, ...)
	 */
	public function default_headers()
	{	
		return NULL;
	}
	
	
	/**
	 * If the object is locked to a FastCurlMulti container we unlock it before closing curl handle.
	 */
	public function __destruct()
	{
		if($this->is_multilock())
			$this->unlockMulti($this->_multilock);
			
		if(is_array($this->_fastcookies) && isset($this->cookiejar))
			$this->fc_cookies2disk();
		
		curl_close($this->_ch);
	}
	
	
	/**
	 * @param string $url URL to hide.
	 */
	public function get_anonym_url($url)
	{
		$funcion=create_function('$service,$url', $this->_anonymous['code']);
		
		return ($url && !preg_match('/^(?:https?\:\/\/)?.*?'.preg_quote($this->_anonymous['service'],'/').'/i', $url))?$funcion($this->_anonymous['service'],$url):$url;
	}
	
	
	/**
	 * @return array $anonymous
	 */
	public function get_anonymous()
	{
		return $this->_anonymous;
	}
	
	
	/**
	 * @param array $value
	 */
	public function set_anonymous($value)
	{	
		if(is_bool($value))
			$this->_anonymous=$value?array('service' => 'blablabla.com', 'code' => ''):NULL;
		else if(is_array($value) && isset($value['service']) && isset($value['code']))
			$this->_anonymous=$value;
			
		return TRUE;
	}
	
	
	/**
	 * @param bool $value
	 */
	public function set_response_lock($value)
	{
		if(is_bool($value) && (!$value || $this->_response))
		{
			$this->_response_lock=$value;
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * @return bool $this->_response_lock
	 */
	public function is_response_lock()
	{
		return $this->_response_lock;
	}
	
	
	/**
	 * @param string $res
	 */
	private function set_response($res)
	{
		if(!$this->is_response_lock())
		{
			$this->_response=$res?$res:NULL;
			return TRUE;
		}
		else
			return FALSE;
	}
	
	
	/**
	 * @param string $res
	 * 
	 * @return string
	 */
	private function filter_res_curl($res)
	{
		switch($this->_exec_mode)
		{
			case self::FCURL_EXEC_BODY:
				$res=preg_replace('/^.*?(?:HTTP *?\/ *?\d+\.\d+ +?\d{3}.*?\r\n\r\n)+(.*)$/is', '\1', $res);
				break;
				
			case self::FCURL_EXEC_HEADERS:
				$res=trim(preg_replace('/^(.*?(?:HTTP *?\/ *?\d+\.\d+ +?\d{3}.*?\r\n\r\n)+).*$/is', '\1', $res));
				break;					
		}
		
		return $res;
	}
}


interface customHeaders
{
    public function default_headers();
}


interface anonymizable
{
	public function set_anonymous($value);
	public function get_anonym_url($url);
}

/* ?> */
