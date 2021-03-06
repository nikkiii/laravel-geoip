<?php namespace Torann\GeoIP;

use GeoIp2\Database\Reader;
use GeoIp2\WebService\Client;

use GuzzleHttp\Client as GuzzleClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use GeoIp2\Exception\AddressNotFoundException;

use Illuminate\Config\Repository;
use Illuminate\Session\Store as SessionStore;

class GeoIP {

	/**
	 * The session store.
	 *
	 * @var \Illuminate\Session\Store
	 */
	protected $session;

	/**
	 * Illuminate config repository instance.
	 *
	 * @var \Illuminate\Config\Repository
	 */
	protected $config;

	/**
	 * Remote Machine IP address.
	 *
	 * @var float
	 */
	protected $remote_ip = null;

	/**
	 * Location data.
	 *
	 * @var array
	 */
	protected $location = null;

	/**
	 * Default Location data.
	 *
	 * @var array
	 */
	protected $default_location = [
		"ip" 			=> "127.0.0.0",
		"isoCode" 		=> "US",
		"country" 		=> "United States",
		"city" 			=> "New Haven",
		"state" 		=> "CT",
		"postal_code"   => "06510",
		"lat" 			=> 41.31,
		"lon" 			=> -72.92,
		"timezone" 		=> "America/New_York",
		"continent"		=> "NA",
		"default"       => true,
	];

	/**
	 * Create a new GeoIP instance.
	 *
	 * @param  \Illuminate\Config\Repository  $config
	 * @param  \Illuminate\Session\Store      $session
	 */
	public function __construct(Repository $config, SessionStore $session)
	{
		$this->config  = $config;
		$this->session = $session;

		// Set custom default location
		$this->default_location = array_merge(
			$this->default_location,
			$this->config->get('geoip.default_location', [])
		);

		// Set IP
		$this->remote_ip = $this->default_location['ip'] = $this->getClientIP();
	}

	/**
	 * Save location data in the session.
	 *
	 * @return void
	 */
	function saveLocation()
	{
		$this->session->set('geoip-location', $this->location);
	}

	/**
	 * Get location from IP.
	 *
	 * @param  string $ip Optional
	 * @return array
	 */
	public function getLocation($ip = null)
	{
		// Get location data
		$this->location = $this->find($ip);

		// Save user's location
		if ($ip === null) {
			$this->saveLocation();
		}

		return $this->location;
	}

	/**
	 * Find location from IP.
	 *
	 * @param  string $ip Optional
	 * @return array
	 * @throws \Exception
	 */
	private function find($ip = null)
	{
		// Check Session
		if ($ip === null && $position = $this->session->get('geoip-location')) {
			return $position;
		}

		// If IP not set, user remote IP
		if ($ip === null) {
			$ip = $this->remote_ip;
		}

		// Check if the ip is not local or empty
		if ($this->checkIp($ip)) {
			// Get service name
			$service = 'locate_'.$this->config->get('geoip.service');

			// Check for valid service
			if (! method_exists($this, $service)) {
				throw new \Exception("GeoIP Service not support or setup.");
			}

			return $this->$service($ip);
		}

		return $this->default_location;
	}

	private $maxmind;

	/**
	 * Maxmind Service.
	 *
	 * @param  string $ip
	 * @return array
	 */
	private function locate_maxmind($ip)
	{
		$settings = $this->config->get('geoip.maxmind');

		if (empty($this->maxmind)) {
			if ($settings['type'] === 'web_service') {
				$this->maxmind = new Client($settings['user_id'], $settings['license_key']);
			}
			else {
				$this->maxmind = new Reader($settings['database_path']);
			}
		}

		try {
			$record = $this->maxmind->city($ip);

			$location = [
				"ip"			=> $ip,
				"isoCode" 		=> $record->country->isoCode,
				"country" 		=> $record->country->name,
				"city" 			=> $record->city->name,
				"state" 		=> $record->mostSpecificSubdivision->isoCode,
				"postal_code"   => $record->postal->code,
				"lat" 			=> $record->location->latitude,
				"lon" 			=> $record->location->longitude,
				"timezone" 		=> $record->location->timeZone,
				"continent"		=> $record->continent->code,
				"default"       => false,
			];
		}
		catch (AddressNotFoundException $e)
		{
			$location = $this->default_location;

			$logFile = 'geoip';

			$log = new Logger($logFile);
			$log->pushHandler(new StreamHandler(storage_path("logs/{$logFile}.log"), Logger::ERROR));
			$log->addError($e);
		}

		unset($record);

		return $location;
	}

	private $guzzle;

	private $continents;

	/**
	 * IP-API.com Service.
	 *
	 * @param  string $ip
	 * @return array
	 */
	public function locate_ipapi($ip)
	{
		$settings = $this->config->get('geoip.ipapi');

		if (empty($this->guzzle)) {
			$base = [
				'base_uri' => 'http://ip-api.com/',
				'headers' => [
					'User-Agent' => 'Laravel-GeoIP'
				],
				'query' => [
					'fields' => 49663
				]
			];

			if ($settings['key']) {
				$base['base_uri'] = ($settings['secure'] ? 'https' : 'http') . '://pro.ip-api.com/';
				$base['query']['key'] = $settings['key'];
			}

			$this->guzzle = new GuzzleClient($base);
		}

		if (empty($this->continents)) {
			if (file_exists($settings['continent_path'])) {
				$this->continents = json_decode(file_get_contents($settings['continent_path']));
			}
		}

		try {
			$data = $this->guzzle->get('/json/' . $ip);

			$json = json_decode($data->getBody());

			if ($json->status !== 'success') {
				throw new \Exception('Request failed (' . $json->message . ')');
			}

			$location = [
				"ip"			=> $ip,
				"isoCode" 		=> $json->countryCode,
				"country" 		=> $json->country,
				"city" 			=> $json->city,
				"state" 		=> $json->region,
				"postal_code"   => $json->zip,
				"lat" 			=> $json->lat,
				"lon" 			=> $json->lon,
				"timezone" 		=> $json->timezone,
				"continent"		=> $this->continents ? object_get($this->continents, $json->countryCode, 'Unknown') : 'Unknown',
				"default"       => false,
			];
		}
		catch (\Exception $e)
		{
			$location = $this->default_location;

			$logFile = 'geoip';

			$log = new Logger($logFile);
			$log->pushHandler(new StreamHandler(storage_path("logs/{$logFile}.log"), Logger::ERROR));
			$log->addError($e);
		}

		return $location;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function getClientIP()
	{
		if (getenv('HTTP_CLIENT_IP')) {
			$ipaddress = getenv('HTTP_CLIENT_IP');
		}
		else if (getenv('HTTP_X_FORWARDED_FOR')) {
			$ipaddress = getenv('HTTP_X_FORWARDED_FOR');
		}
		else if (getenv('HTTP_X_FORWARDED')) {
			$ipaddress = getenv('HTTP_X_FORWARDED');
		}
		else if (getenv('HTTP_FORWARDED_FOR')) {
			$ipaddress = getenv('HTTP_FORWARDED_FOR');
		}
		else if (getenv('HTTP_FORWARDED')) {
			$ipaddress = getenv('HTTP_FORWARDED');
		}
		else if (getenv('REMOTE_ADDR')) {
			$ipaddress = getenv('REMOTE_ADDR');
		}
		else if (isset($_SERVER['REMOTE_ADDR'])) {
			$ipaddress = $_SERVER['REMOTE_ADDR'];
		}
		else {
			$ipaddress = '127.0.0.0';
		}

		return $ipaddress;
	}

	/**
	 * Checks if the ip is not local or empty.
	 *
	 * @return bool
	 */
	private function checkIp($ip)
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE)) {
			return false;
		}

		return true;
	}

}
