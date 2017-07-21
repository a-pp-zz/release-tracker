<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\RT\Interfaces\Media;
use AppZz\Helpers\Arr;

class Rutracker extends Tracker implements Media {

	protected $_vendor = 'Rutracker';

	public function __construct ($tracker_id)
	{
		parent::__construct ();
		$this->_tracker = $this->_get_tracker($tracker_id);	
		$this->_posts = (array) $this->_get_data(-1, 'post_id');
	}	

	public function get_tvshows ($pattern = NULL)
	{
		return $this->_get ($pattern);
	}

	public function get_movies ($pattern = NULL)
	{
		return $this->_get ($pattern);
	}	
	
	public function get_music ($pattern = NULL)
	{
		return $this->_get ($pattern);
	}	

	private function _get ($pattern = NULL)
	{
		$body = $this->_request ();
		
		if ( ! $body)
			return FALSE;

		if ( ! $pattern)
			$pattern = '#720p|1080p#iu';

		$header = Arr::get ($body, 'title');
		$update = Arr::get ($body, 'updated');
		$items = Arr::get ($body, 'entry');

		if ($update) {
			$dt = new \DateTime ($update, $this->_tz);
			$this->_update = $dt->getTimestamp();

			if ($this->_tracker->update == $this->_update)
				return FALSE;
		}

		foreach ($items as $item) {
			
			$title = Arr::get($item, 'title');
			$link = Arr::path($item, 'link.@attributes.href');
			
			if ( ! preg_match ($pattern, $title)) {
				continue;			
			}

			$query_args = parse_url($link, PHP_URL_QUERY);
			parse_str($query_args, $query_array);
			$post_id = Arr::get($query_array, 't', 0);
			
			if (in_array($post_id, $this->_posts)) {
				continue;		
			}

			$this->_data[] = [
				'tracker_id' => $this->_tracker->id,
				'post_id'    => $post_id,
				'time'       => time(),
				'title'      => $title,
				'url'        => $link,
				'notify'     => 0
			];
		}
	}
}