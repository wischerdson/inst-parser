<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use GuzzleHttp\Client;

date_default_timezone_set('Europe/Moscow');

class Config
{
	const YAML_FILE = __DIR__.'/config.yaml';

	private array $state;

	public function __construct()
	{
		$this->state = array_map(fn ($item) => trim($item), yaml_parse_file(self::YAML_FILE));
	}

	public function saveState()
	{
		yaml_emit_file(self::YAML_FILE, $this->state, YAML_UTF8_ENCODING);
	}

	public function getState(): array
	{
		return $this->state;
	}

	public function __get(string $prop)
	{
		return array_key_exists($prop, $this->state) ? $this->state[$prop] : null;
	}

	public function __set(string $prop, string $value)
	{
		$this->state[$prop] = $value;
	}
}


class InstagramClient
{
	private Client $client;

	private array $headers;

	public function __construct()
	{
		$this->client = new Client([]);
	}

	public function setHeaders(array $headers)
	{
		$this->headers = $headers;
	}

	public function setHeader(string $name, string $value)
	{
		$this->headers[$name] = $value;
	}

	public function setRawHeaders(string $rawHeaders)
	{
		$this->setHeaders($this->parseRawHeaders($rawHeaders));
	}

	public function sendForm(string $url, array $formData)
	{
		$response = $this->client->post($url, [
			'form_params' => $formData,
			'debug' => false,
			'version' => '2',
			'headers' => $this->headers,
			'allow_redirects' => true
		]);

		$json = json_decode((string) $response->getBody());

		if (!$json) {
			echoAndLog("Account has been banned!");
			die;
		}

		return $json;
	}

	public function get($url)
	{
		$response = $this->client->get($url, [
			'debug' => false,
			'version' => '2',
			'headers' => $this->headers,
			'allow_redirects' => true
		]);

		$json = json_decode((string) $response->getBody());

		if (!$json) {
			echoAndLog("Account has been banned!");
			die;
		}

		return $json;
	}

	public function parseRawHeaders(string $rawHeaders): array
	{
		$rows = explode("\n", $rawHeaders);
		$result = [];

		foreach ($rows as $row) {
			$exploded = explode(':', $row);

			$key = trim(array_shift($exploded));
			$value = trim(implode(':', $exploded));

			$result[$key] = $value;
		}

		return $result;
	}

	public function parseCookie(string $rawCookie): array
	{
		$pairs = explode('; ', $rawCookie);
		$result = [];

		foreach ($pairs as $pair) {
			$explodedPair = explode('=', $pair);
			$key = array_shift($explodedPair);
			$value = implode('=', $explodedPair);
			$result[$key] = $value;
		}

		return $result;
	}
}


class Instagram
{
	private string $rawHeaders = <<<'EOD'
		User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:100.0) Gecko/20100101 Firefox/100.0
		X-Instagram-AJAX: 72b68f0470c8
		X-IG-App-ID: 936619743392459
		X-ASBD-ID: 198387
		X-IG-WWW-Claim: hmac.AR0nQIiU7uayzFND4zT0l9MnbfCnWc7nX-sPhcMymoIiACZG
		Content-Type: application/x-www-form-urlencoded
		Origin: https://www.instagram.com
		Referer: https://www.instagram.com
	EOD;

	private InstagramClient $client;

	private Config $config;

	public function __construct(Config $config)
	{
		$this->config = $config;

		$this->client = new InstagramClient();
		$this->client->setRawHeaders($this->rawHeaders);
		$this->client->setHeader('Cookie', $config->cookie);
		$this->client->setHeader(
			'X-CSRFToken',
			$this->client->parseCookie($config->cookie)['csrftoken']
		);
	}

	public function nextTaggedPostsPage()
	{
		$tag = urlencode($this->config->hashtag);
		$response = $this->client->sendForm("https://i.instagram.com/api/v1/tags/$tag/sections/", [
			'include_persistent' => '0',
			'max_id' => $this->config->max_id,
			'page' => $this->config->page,
			'surface' => 'grid',
			'tab' => 'recent'
		]);

		$this->config->max_id = $response->next_max_id;
		$this->config->page = $response->next_page;
		$this->config->saveState();

		return $response;
	}

	public function getUserById(string $userPk)
	{
		return $this->client->get("https://i.instagram.com/api/v1/users/$userPk/info/")->user;
	}
}

$config = new Config();

$capsule = new Capsule();
$capsule->addConnection([
	'driver' => 'mysql',
	'host' => $config->db_host,
	'database' => $config->db_database,
	'username' => $config->db_username,
	'password' => $config->db_password,
	'charset' => 'utf8mb4',
	'collation' => 'utf8mb4_general_ci'
]);

$capsule->setAsGlobal();

$instagram = new Instagram($config);

echoAndLog("Start parsing #$config->hashtag");

while (true) {
	$page = $instagram->nextTaggedPostsPage();

	foreach ($page->sections as $section) {
		$medias = $section->layout_content->medias;

		foreach ($medias as $media) {
			$userPk = $media->media->user->pk;

			if (Capsule::table('users')->where('instagram_user_id', $userPk)->doesntExist()) {
				sleep(random_int(5, 20));

				if (Capsule::table('users')->where('instagram_user_id', $userPk)->doesntExist()) {
					echoAndLog("Fetching and saving user $userPk");

					$user = $instagram->getUserById($userPk);

					Capsule::table('users')->insert([
						'instagram_user_id' => $user->pk,
						'login' => $user->username,
						'name' => $user->full_name,
						'bio' => $user->biography,
						'contact_phone_number' => $user->contact_phone_number ?? null,
						'whatsapp_number' => $user->whatsapp_number ?? null,
						'public_phone_number' => $user->public_phone_number ?? null,
						'public_phone_country_code' => $user->public_phone_country_code ?? null,
						'public_email' => $user->public_email ?? null,
						'city_name' => $user->city_name ?? null,
						'category' => $user->category ?? null,
						'tag' => $config->hashtag
					]);
				}
			}
		}
	}

	if (!(bool) $page->more_available) {
		echoAndLog("That's it, there are no more posts.");
		die;
	}

	echoAndLog("Next page: $page->next_page");
	sleep(random_int(10, 30));
}


function echoAndLog(string $content)
{
	$date = date('H:i');
	$content = "$date: $content\n";
	echo $content;
	file_put_contents('./log.txt', $content, FILE_APPEND);
}
