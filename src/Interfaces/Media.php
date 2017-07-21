<?php
namespace AppZz\Http\RT\Interfaces;

interface Media {

	public function get_music ($pattern);

	public function get_tvshows ($pattern);

	public function get_movies ($pattern);

}