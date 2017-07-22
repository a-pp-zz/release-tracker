<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\RT\TrackerInterface;
use AppZz\Helpers\Arr;

class Rutracker extends Tracker implements TrackerInterface {

	public function __construct ($tracker_id)
	{
		parent::__construct ($tracker_id);
	}		
	
	public function get ($pattern = NULL)
	{
		$body = $this->_request ();
		
		if ( ! $body)
			return FALSE;

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
			
			if ($pattern AND ! preg_match ($pattern, $title)) {
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