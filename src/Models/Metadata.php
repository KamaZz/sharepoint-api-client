<?php

namespace kamazz\SharepointApiClient\Models;

use Illuminate\Database\Eloquent\Model;

class Metadata extends Model {

	protected $primaryKey   = 'key';
	public    $incrementing = false;

	protected $fillable = [
		'key', 'value',
	];
}
