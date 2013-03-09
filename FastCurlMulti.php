<?php 

/**
 * FastCurlMulti (PHP object-oriented wrapper for {@link http://curl.haxx.se/ cURL})
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
 * @license   http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @version   1.6
 */

 
/**
 * @property mixed $_mh curl_multi handle.
 * @property array $_ch FastCurl objects locked to this container.
 * @property bool $_multiset If it's activated, you can set the same CURLOPT to all FastCurl objects making $fastmulti_handle->curlopt=value;
 * @property bool $_implicit_refresh If it's activated when a FastCurl object (locked to this container) property is set, FastCurl object will be refresh.
 */
class FastCurlMulti
{
	const VERSION='1.6';
	
	private $_mh=NULL;
	
	private $_ch=NULL;
	
	private $_multiset=NULL;
	
	private $_implicit_refresh=NULL;
	
	
	/**
	 * @param FastCurl $chandle (It can be a single FastCurl object or an array of FastCurl objects)
	 * @param bool $multiset If it's activated, you can set the same CURLOPT to all FastCurl objects making $fastmulti_handle->curlopt=value;
	 * @param bool $_implicit_refresh If it's activated when a FastCurl object (locked to this container) property is set, FastCurl object will be refresh.
	 * @return FastCurlMulti $this
	 */
	public function __construct($chandle=NULL, $multiset=FALSE, $implicit_refresh=TRUE)
	{
		$this->_mh=curl_multi_init();
		$this->_ch=array();
		
		if($chandle)
		{
			if(is_array($chandle))
			{	
				foreach($chandle as $ch)
					$this->add($ch);
			}
			else
				$this->add($chandle);
		}
		
		$this->_multiset=is_bool($multiset)?$multiset:FALSE;
		$this->_implicit_refresh=is_bool($implicit_refresh)?$implicit_refresh:TRUE;
		
		return $this;
	}
	
	
	/**
	 * @return bool Set ok
	 */
	public function set_multiset($value)
	{
		if(($ret=is_bool($value)))
			$this->_multiset=$value;
			
		return $ret;
	}
	
	
	/**
	 * @return bool Set ok
	 */
	public function set_implicit_refresh($value)
	{
		if(($ret=is_bool($value)))
			$this->_implicit_refresh=$value;
			
		return $ret;
	}
	
	
	/**
	 * @return bool
	 */
	public function is_multiset()
	{
		return $this->_multiset;
	}
	
	
	/**
	 * @return bool
	 */
	public function is_implicit_refresh()
	{
		return $this->_implicit_refresh;
	}
	
	
	/**
	 * Before closing curl_multi_handle, we remove any FastCurl locked object.
	 */
	public function __destruct()
	{
		foreach($this->_ch as $ch)
			$this->remove($ch);
		
		curl_multi_close($this->_mh);
	}
	
	
	/**
	 * @param FastCurl $ch
	 */
	public function add(FastCurl $ch)
	{
		$ch->lockMulti($this);
	}

	
	/**
	 * @param FastCurl $ch
	 */
	public function remove(FastCurl $ch)
	{
		$ch->unlockMulti($this);
	}
	
	
	/**
	 * @param FastCurl $ch
	 */
	public function refresh(FastCurl $ch)
	{
		if($ch->unlockMulti($this))
			$ch->lockMulti($this);
	}
	
	
	/**
	 * Executes all curl handles parallelly (fastcookies support).
	 *
	 * @param bool $verbose Prints '+' while looping for all requests (useful to avoid browser's timeout (PHP output buffer must be off)).
	 */
	public function exec($verbose=FALSE)
	{
		$ir_orig=$this->is_implicit_refresh();
		$this->set_implicit_refresh(FALSE);
		
		$fc_redir=array();
		
		foreach($this->_ch as $fc)
			if($fc->followlocation && (is_array($fc->get_fc_cookies()) || $fc->is_fast_followlocation()))
			{
				$fc_redir[]=array($fc, $fc->url, $fc->referer);
				$fc->followlocation=FALSE;
			}
		
		do
		{
			$active=NULL;
			$mrc=FALSE;
			$redir=FALSE;
			
			foreach($fc_redir as $fc)
				if(is_array($fc[0]->get_fc_cookies()))
					$fc[0]->send_fc_cookies_from_multi($this);
			do
			{
				if($mrc===FALSE || curl_multi_select($this->_mh)!=-1)
				{
					while(($mrc=curl_multi_exec($this->_mh, $active))==CURLM_CALL_MULTI_PERFORM);
					
					if($verbose)
						echo '+ ';
				}
				
			}while($active && $mrc==CURLM_OK);
			
			foreach($fc_redir as $fc)
			{
				if(($res=curl_multi_getcontent($fc[0]->get_ch_from_multi($this))))
				{	
					if(is_array($fc[0]->get_fc_cookies()))
						$fc[0]->receive_fc_cookies_from_multi($this, $res);

					if(strpos(trim($fc[0]->info('HTTP_CODE')), '3')===0)
					{
						if($verbose)
							echo '> ';
						
						if($fc[0]->post && $fc[0]->info('HTTP_CODE')!=307)
							$fc[0]->enable_post(FALSE);
						
						preg_match('/(?:(?:Location)|(Refresh))\: *?(?(1)\d+; *?url\=)(?P<redir>[^\r\n]+)/i', $res, $url);
						
						if(preg_match('/^(?!.*?\:\/\/)/', ($url['redir']=trim($url['redir']))))
							$url['redir']=preg_replace('/(?<!\:\/)\/[^\/]+$/','',$fc[0]->url).'/'.ltrim($url['redir'], '/');
							
						if($fc[0]->autoreferer)
							$fc[0]->referer=$fc[0]->url;
						
						$fc[0]->url=$url['redir'];
						
						$this->refresh($fc[0]);
						
						$redir=TRUE;
					}
				}
			}
			
		}while($redir);
		
		foreach($fc_redir as $fc)
		{	
			$fc[0]->set_last_url_from_multi($this, $fc[0]->url);
			$fc[0]->url=$fc[1];
			$fc[0]->referer=$fc[2];
			$fc[0]->followlocation=TRUE;
		}
		
		$this->set_implicit_refresh($ir_orig);
	}
	
	
	/**
	 * @param FastCurl $ch
	 */
	public function accept(FastCurl $ch)
	{
		$this->_ch[] = $ch;
		curl_multi_add_handle($this->_mh, $ch->get_ch_from_multi($this));
	}
	
	
	/**
	 * @param FastCurl $ch
	 */
	public function release(FastCurl $ch)
	{
		if(($key=array_search($ch, $this->_ch, TRUE))!==FALSE)
		{
			curl_multi_remove_handle($this->_mh, $ch->get_ch_from_multi($this));
			unset($this->_ch[$key]);
		}
	}
	
	
	/**
	 * Sets a CURLOPT for every FastCurl object in the container (if _multiset is ON)
	 *
	 * @param string $opt
	 * @param mixed $value
	 * @return bool CURLOPT set ok
	 */
	public function __set($opt, $value)
	{
		if(($ret=($this->is_multiset() && defined('CURLOPT_'.strtoupper($opt)))))
		{
			foreach($this->_ch as $ch)
				$ch->$opt=$value;
		}
		
		return $ret;
	}
}

/* ?> */
