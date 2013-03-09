<?php

/**
 * FastCurl (PHP object-oriented wrapper for {@link http://curl.haxx.se/ cURL})
 *
 * TEST FILE
 *
 * Copyright (c) 2010 Antonio López Vivar
 * 
 * LICENSE:
 * 
 * This library is free software; you can redistribute it
 * and/or modify it under the terms of the GNU Lesser General
 * Public License as published by the Free Software Foundation;
 * either version 2.1 of the License, or (at your option) any
 * later version.
 * 
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @package   FastCurl
 * @author    Antonio López Vivar <tonikelope@gmail.com>
 * @copyright 2010 Antonio López Vivar
 * @license   http://www.opensource.org/licenses/lgpl-license.php LGPL
 */

require_once('FastCurl.php');

error_reporting(E_ALL ^ E_NOTICE | E_STRICT);

try{
	
	//Create two FastCurl objects
	$fc1 = new FastCurl(array('url' => 'http://www.yahoo.com'));
	$fc2 = new FastCurl(array('url' => 'http://www.wikipedia.org'));
	
	
	//Exec sequentially
	$res1 = $fc1->fetch();
	$res2 = $fc2->fetch();
	
	
	//Create a FastCurlMulti container
	$fcm=new FastCurlMulti();
	$fcm->add($fc1);
	$fcm->add($fc2);
	
	
	//Exec parallelly
	$fcm->exec();
	
	
	//Fetch the responses
	$res1 = $fc1->fetch();
	$res2 = $fc2->fetch();
	
	
	//Destroy container
	unset($fcm);
	
	
	//Basic login
	$fc1->url = 'http://foofoofoo.com/login.php';
	$fc1->referer = $fc1->url;
	$fc1->enable_post('name=myuser&password=mypass');
	$res1 = $fc1->fetch();
	
	
	//Advanced login
	$email = 'blablabla@foo.com';
	$pass = 'foofoofoo';
	$fc2->url = 'http://foofoofoo.com/';
	$form = $fc2->fetch('/\< *?form.*?action.*?"(?P<action>.*?login.*?)".*?charset_test.*?value.*?"(?P<charset_test>.*?)".*?locale.*?value.*?"(?P<locale>.*?)".*?charset_test.*?value.*?"(?P<charset_test2>.*?)".*?lsd.*?value.*?"(?P<lsd>.*?)"/is');
	$fc2->enable_post(implode('&', array('charset_test='.urlencode($form['charset_test']), 'locale='.urlencode($form['locale']), 'email='.urlencode($email), 'pass='.urlencode($pass), 'charset_test='.urlencode($form['charset_test2']), 'lsd='.urlencode($form['lsd']))), NULL, $form['action'], $fc2->url);
	$res2 = $fc2->fetch();
	
	
	//Make another GET request (POST was auto-disabled after $fc1->fetch())
	$fc1->url = 'http://www.yahoo.com';
	$res1 = $fc1->fetch();
	
    
    //Let's try FastCurl cookie engine
    $fc3 = new FastCurl(array('url' => 'http://google.com', '_fastcookies' => TRUE));
    
    //OPTIONAL
    $fc3->cookiejar='fastcurl_cookies.txt'; 
    
    //Connect
    $fc3->exec();
    
    //Delete a cookie (BEWARE -> (www.foo.com != foo.com))
    $fc3->delete_fc_cookie('google.com', 'NID');
	
	//Set a new cookie
	$fc3->set_fc_cookie('new_foo_cookie', 'foo');
	
	//Change cookie
	$fc3->set_fc_cookie('PREF', 1337);
	
	//Dump all cookies
    var_dump($fc3->get_fc_cookies());
	
	//Clean all cookies
	$fc3->clean_fc_cookies();
	
    //Connect
    $fc3->exec();

	//Dump all cookies
    var_dump($fc3->get_fc_cookies());
    
    //Create new FastCurl object with the same cookies from $fc3
    $fc4 = new FastCurl(array('url' => 'http://google.com', '_fastcookies' => $fc3->get_fc_cookies()));
	
	
	
	//Bye bye
	unset($fc1);
	unset($fc2);
	unset($fc3); //Cookies will be stored in 'fastcurl_cookies.txt'
	unset($fc4);
	

}catch (Exception $e){
	echo $e->getMessage();
}

?>