<?php


namespace kamazz\SharepointApiClient;

use Illuminate\Support\ServiceProvider;

class SharepointApiClientServiceProvider extends ServiceProvider {

	public function boot()
	{
		$this->publishes([__DIR__ . '/../config/' => config_path() . '/']);
	}

	public function register()
	{

	}
}