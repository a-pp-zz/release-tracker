<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\RT\TrackerInterface;
use AppZz\Http\TransmissionRPC;
use AppZz\Helpers\Arr;

class Soap4me extends Tracker implements TrackerInterface {

	private $_days = 30;

	public function __construct ($tracker_id, $days = 30)
	{
		parent::__construct ($tracker_id);

		if ($days)
			$this->_days = $days;
	}

	public function get ($pattern = NULL)
	{
		$body = $this->_request ();

		if ( ! $body)
			return FALSE;

		$items = (array) Arr::path ($body, 'channel.item');
		$times = [];

		foreach ($items as $item) {

			$title = Arr::get($item, 'title');
			$link = Arr::get($item, 'link');
			$time = Arr::get($item, 'pubDate');
			$post_id = 0;

			if ($link) {
				$post_id = (int) pathinfo($link, PATHINFO_FILENAME);
			}

			if (in_array($post_id, $this->_posts)) {
				continue;
			}

			if (preg_match('#(.*)\s+?\/\s+?сезон\s+?(\d+)\s+?#iu', $title, $title_parts)) {

				$days = $this->_get_days ($title_parts[1], $title_parts[2]);

				if ( ! $days)
					continue;

			} else {
				continue;
			}

			$dt = new \DateTime (NULL, $this->_tz);
			$dt->modify('-' . $days . ' day');
			$start = $dt->getTimestamp();
			unset ($dt);

			$dt = new \DateTime ($time, $this->_tz);
			$time = $dt->getTimestamp();

			if ($time < $start)
				continue;

			$times[] = $time;

			$this->_data[] = [
				'tracker_id' => $this->_tracker->id,
				'post_id'    => $post_id,
				'time'       => $time,
				'title'      => $title,
				'url'        => $link,
				'notify'     => 0
			];
		}

		if ($times) {
			$this->_update = max ($times);
		}
	}

	public function add_torrents ()
	{
		if ($this->_data) {

			try {
				$tr = new TransmissionRPC (Tracker::$transmission);
				$tr->auth(Arr::get(Tracker::$transmission, 'username'), Arr::get(Tracker::$transmission, 'password'));

				foreach ($this->_data as $data) {
					$title_parts = explode ('/', $data['title']);
					if ($title_parts) {
						$title_parts = array_shift($title_parts);
						$add = $tr->add_file ($data['url'], trim(Tracker::$tvshows_path . DIRECTORY_SEPARATOR . $title_parts));
					}
				}
			} catch (\Exception $e) {

			}
		}
	}

	private function _get_days ($title, $season)
	{
		$result = $this->_db->soap4me
		    ->select()
		    ->one()
		    ->where('title = :title', [':title' => trim ($title)])
		    ->where('season = :season', [':season' => trim ($season)])
		    //->where('status = :status', [':status' => 1])
		    ->run();

		if ($result) {
			return $result->status == 0 ? FALSE : $result->days;
		} else {
			$id = $this->_db->soap4me
		    ->insert()
		    ->data(['title'=>$title, 'season'=>$season, 'days'=>$this->_days, 'status'=>1])
		    ->run();
		    if ($id) {
		    	return $this->_days;
		    }
		}

		return FALSE;
	}
}
