<?php

return [

	'stsUrl'        => 'https://login.microsoftonline.com/extSTS.srf',
	'signInPageUrl' => '/_forms/default.aspx?wa=wsignin1.0',
	'api'           => '/_api',
	'curlOptions'   => [
		CURLOPT_SSL_VERIFYPEER  => false,
		CURLOPT_SSLVERSION      => 4,
		CURLOPT_SSL_CIPHER_LIST => 'ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES256-SHA:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES128-SHA:AES256-GCM-SHA384:AES128-GCM-SHA256:AES256-SHA256:AES256-SHA:AES128-SHA256:AES128-SHA:DES-CBC3-SHA',
		CURLOPT_RETURNTRANSFER  => true,
	],
	'url'           => 'https://archimatika.sharepoint.com/sites/pwa',
	'username'      => env('SHAREPOINT_USERNAME', ''),
	'password'      => env('SHAREPOINT_PASSWORD', ''),

];