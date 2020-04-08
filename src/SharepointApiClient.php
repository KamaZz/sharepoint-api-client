<?php

namespace kamazz\SharepointApiClient;

use Carbon\Carbon;
use kamazz\SharepointApiClient\Models\Metadata;

class SharepointApiClient {

	private static $stsUrl = '', $signInPageUrl = '', $api = '', $curlOptions = '';
	public                                                       $site, $formDigest;

	protected $url = '';
	protected $username, $password, $scheme, $server;
	private   $FedAuth, $rtFa;

	public function __construct()
	{
		if ( ! function_exists('curl_init'))
		{
			throw new \Exception('CURL module not available! This client requires CURL. See http://php.net/manual/en/book.curl.php');
		}

		self::$stsUrl        = config('sharepoint.stsUrl');
		self::$signInPageUrl = config('sharepoint.signInPageUrl');
		self::$api           = config('sharepoint.api');
		self::$curlOptions   = config('sharepoint.curlOptions');
		$this->url           = config('sharepoint.url');

		$parsed         = parse_url($this->url);
		$this->scheme   = $parsed['scheme'];
		$this->server   = $parsed['host'];
		$this->site     = $parsed['path'];
		$this->username = config('sharepoint.username');
		$this->password = config('sharepoint.password');
	}

	/**
	 * SPO sign-in
	 * @throws \Exception
	 */
	public function signIn()
	{
		$timeAgo   = Carbon::now()->subHours(4)->toDateTimeString();
		$formDigest = Metadata::where('key', 'SHAREPOINT_FORMDIGEST')->where('updated_at', '>', $timeAgo)->first();
		$FedAuth    = Metadata::where('key', 'SHAREPOINT_FEDAUTH')->where('updated_at', '>', $timeAgo)->first();
		// rtFa не меняеться почему-то
		$rtFa       = Metadata::where('key', 'SHAREPOINT_RTFA')->first();

		$this->formDigest = $formDigest ? $formDigest->value : false;
		$this->FedAuth    = $FedAuth ? $FedAuth->value : false;
		$this->rtFa       = $rtFa ? $rtFa->value : false;

		if (empty($this->formDigest) || empty($this->FedAuth) || empty($this->rtFa))
		{
			$token  = $this->requestToken($this->username, $this->password);
			$header = $this->submitToken($token);
			$this->saveAuthCookies($header);
			$contextInfo = $this->requestContextInfo();
			$this->saveFormDigest($contextInfo);

			Metadata::updateOrCreate(['key' => 'SHAREPOINT_FORMDIGEST'], ['value' => $this->formDigest]);
			Metadata::updateOrCreate(['key' => 'SHAREPOINT_FEDAUTH'], ['value' => $this->FedAuth]);
			Metadata::updateOrCreate(['key' => 'SHAREPOINT_RTFA'], ['value' => $this->rtFa]);
		}
	}

	/**
	 * Request the token
	 *
	 * @param string $username
	 * @param string $password
	 *
	 * @return string
	 * @throws \Exception
	 */
	private function requestToken($username, $password)
	{

		$samlRequest = $this->buildSamlRequest($username, $password, $this->url);

		$ch = curl_init();
		curl_setopt_array($ch, static::$curlOptions);
		curl_setopt($ch, CURLOPT_URL, self::$stsUrl);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $samlRequest);
		$result = curl_exec($ch);
		if ($result === false)
		{
			throw new \Exception(curl_error($ch));
		}
		curl_close($ch);

		return $this->processToken($result);
	}

	/**
	 * Construct the XML to request the security token
	 *
	 * @param string $username
	 * @param string $password
	 * @param string $address
	 *
	 * @return string
	 */
	private function buildSamlRequest($username, $password, $address)
	{
		$samlRequestTemplate = file_get_contents(__DIR__ . '/Helpers/SAML.xml');
		$samlRequestTemplate = str_replace('{username}', $username, $samlRequestTemplate);
		$samlRequestTemplate = str_replace('{password}', $password, $samlRequestTemplate);
		$samlRequestTemplate = str_replace('{address}', $address, $samlRequestTemplate);

		return $samlRequestTemplate;
	}

	/**
	 * Verify and extract security token from the HTTP response
	 *
	 * @param mixed $body
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	private function processToken($body)
	{
		$xml = new \DOMDocument();
		$xml->loadXML($body);
		$xpath = new \DOMXPath($xml);
		if ($xpath->query("//S:Fault")->length > 0)
		{
			$nodeErr = $xpath->query("//S:Fault/S:Detail/psf:error/psf:internalerror/psf:text")->item(0);
			throw new \Exception($nodeErr->nodeValue);
		}
		$nodeToken = $xpath->query("//wsse:BinarySecurityToken")->item(0);

		return $nodeToken->nodeValue;
	}

	/**
	 * Get the FedAuth and rtFa cookies
	 *
	 * @param mixed $token
	 *
	 * @return string
	 * @throws \Exception
	 */
	private function submitToken($token)
	{

		$urlinfo = parse_url($this->url);
		$url     = $urlinfo['scheme'] . '://' . $urlinfo['host'] . self::$signInPageUrl;

		$ch = curl_init();
		curl_setopt_array($ch, static::$curlOptions);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $token);
		curl_setopt($ch, CURLOPT_HEADER, true);
		$result = curl_exec($ch);
		if ($result === false)
		{
			throw new \Exception(curl_error($ch));
		}
		$header = substr($result, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
		curl_close($ch);

		return $header;
	}

	/**
	 * Save the SPO auth cookies
	 *
	 * @param mixed $header
	 */
	private function saveAuthCookies($header)
	{
		$cookies       = $this->cookie_parse($header);
		$this->FedAuth = $cookies['FedAuth'];
		$this->rtFa    = $cookies['rtFa'];
	}

	/**
	 * Parse cookies
	 *
	 * @param mixed $header
	 *
	 * @return mixed
	 */
	private function cookie_parse($header)
	{
		$headerLines = explode("\r\n", $header);
		$cookies     = [];
		foreach ($headerLines as $line)
		{
			if (preg_match('/^Set-Cookie: /i', $line))
			{
				$line                 = preg_replace('/^Set-Cookie: /i', '', trim($line));
				$csplit               = explode(';', $line);
				$cinfo                = explode('=', $csplit[0], 2);
				$cookies[ $cinfo[0] ] = $cinfo[1];
			}
		}

		return $cookies;
	}

	/**
	 * Request the Context Info
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	private function requestContextInfo()
	{
		$options = [
			'url'    => $this->url . "/_api/contextinfo",
			'method' => 'POST',
		];

		$data = $this->request($options);

		return $data->d->GetContextWebInformation;
	}

	/**
	 * Request the SharePoint REST endpoint
	 *
	 * @param mixed $options
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	public function request($options)
	{
		$data    = array_key_exists('data', $options) ? json_encode($options['data']) : '';
		$headers = [
			'Accept: application/json; odata=verbose',
			'Content-type: application/json; odata=verbose',
			'Cookie: FedAuth=' . $this->FedAuth . '; rtFa=' . $this->rtFa,
			'Content-length:' . strlen($data),
		];
		// Include If-Match header if etag is specified
		if (array_key_exists('etag', $options))
		{
			$headers[] = 'If-Match: ' . $options['etag'];
		}
		// Include X-RequestDigest header if formdigest is specified
		if (array_key_exists('formdigest', $options))
		{
			$headers[] = 'X-RequestDigest: ' . $options['formdigest'];
		}
		// Include X-Http-Method header if xhttpmethod is specified
		if (array_key_exists('xhttpmethod', $options))
		{
			$headers[] = 'X-Http-Method: ' . $options['xhttpmethod'];
		}

		$ch = curl_init();
		curl_setopt_array($ch, static::$curlOptions);
		curl_setopt($ch, CURLOPT_URL, $options['url']);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if ($options['method'] != 'GET')
		{
			curl_setopt($ch, CURLOPT_POST, 1);
			if (array_key_exists('data', $options))
			{
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
		}

		$result = curl_exec($ch);
		if ($result === false)
		{
			throw new \Exception(curl_error($ch));
		}

		curl_close($ch);

		return json_decode($result);
	}

	/**
	 * Save the SPO Form Digest
	 *
	 * @param mixed $contextInfo
	 */
	private function saveFormDigest($contextInfo)
	{
		$this->formDigest = $contextInfo->FormDigestValue;
	}

	/**
	 * Request the SharePoint REST endpoint
	 *
	 * @param $path
	 * @param $verb
	 * @param null $data
	 * @param array $headers
	 *
	 * @return mixed
	 * @throws \Exception
	 *
	 */
	public function apiRequest($path, $verb, $data = null, $headers = [])
	{
		$ch                = curl_init();
		$headers['Accept'] = 'application/json;odata=verbose';
		$headers['Cookie'] = 'FedAuth=' . $this->FedAuth . '; rtFa=' . $this->rtFa;
		switch ($verb)
		{
			case 'POST':
				$headers['X-RequestDigest'] = $this->formDigest;
				curl_setopt($ch, CURLOPT_POST, 1);
				if ($data)
				{
					curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
					$headers['Content-length'] = strlen($data);
				} else
				{
					$headers['Content-length'] = 0;
				}
				break;
			case 'PUT':
				$headers['X-RequestDigest'] = $this->formDigest;
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
				$headers['X-Http-Method'] = 'PUT';
				if ($data)
				{
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
					$headers['Content-length'] = strlen($data);
				} else
				{
					$headers['Content-length'] = 0;
				}
				break;
			default:
				// nothing
				break;
		}
		$curl_header = [];
		foreach ($headers as $header => $value)
		{
			$curl_header[] = $header . ': ' . $value;
		}

		$url = $this->scheme . '://' . $this->server . $this->site . static::$api . $path;

		curl_setopt_array($ch, (array) static::$curlOptions);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);

		$result = curl_exec($ch);
		if ($result === false)
		{
			throw new \Exception(curl_error($ch));
		}
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		return ['url' => $url, 'headers' => $curl_header, 'status' => $status, 'body' => $result];
	}
}