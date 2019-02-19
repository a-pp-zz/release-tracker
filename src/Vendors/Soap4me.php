<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\RT\DB;
use AppZz\Http\RT\TrackerInterface;
use AppZz\Helpers\Arr;

class Soap4me extends Tracker implements TrackerInterface {

	private $_days = 30;

	public function __construct ($tracker_id)
	{
		parent::__construct ($tracker_id);

		$this->_days = DB::config ('soap4me_days', $this->_days);
	}

	public function get ($pattern = NULL)
	{
		$status = NULL;
		$body = $this->_request ([], $status);

		if ( ! $body OR $status !== 200) {
			return FALSE;
		}

		$items = (array) Arr::path ($body, 'channel.item');
		$times = [];

		foreach ($items as $item) {

			$title = Arr::get($item, 'title');
			$link = Arr::get($item, 'link');
			$time = Arr::get($item, 'pubDate');
			$post_id = 0;

			if ($link) {
				$post_id = (int) pathinfo ($link, PATHINFO_FILENAME);
			}

			if (in_array($post_id, $this->_posts)) {
				continue;
			}

			if (preg_match('#(.*)\s+?\/\s+?сезон\s+?(\d+)\s+?#iu', $title, $title_parts)) {

				$days = $this->_get_days ($title_parts[1], $title_parts[2]);

				if ( ! $days) {
					continue;
				}

			} else {
				continue;
			}

			$dt = new \DateTime (NULL, $this->_tz);
			$dt->modify('-' . $days . ' day');
			$start = $dt->getTimestamp();
			unset ($dt);

			$dt = new \DateTime ($time, $this->_tz);
			$time = $dt->getTimestamp();

			if ($time < $start) {
				continue;
			}

			$times[] = $time;

			$this->_data[] = [
				'tracker_id' => $this->_tracker['id'],
				'post_id'    => $post_id,
				'time'       => date ('Y-m-d H:i:s', $time),
				'title'      => $title,
				'url'        => $link,
			];
		}

		if ($times) {
			$this->_update = date ('Y-m-d H:i:s', max ($times));
		}
	}

	public function add_torrents ()
	{
		if ($this->_data) {
			$transmission = DB::config ('transmission');
			$tr = new TransmissionRPC ($transmission);
			$tr->auth(Arr::get($transmission, 'username'), Arr::get($transmission, 'password'));

			foreach ($this->_data as $data) {
				$title_parts = explode ('/', $data['title']);
				if ($title_parts) {
					$title_parts = array_shift($title_parts);
					$this->_add_torrent ($data['url'], $title_parts);
				}
			}
		}
	}

	private function _get_days ($title, $season)
	{
		$result = $this->_db->from('soap4me')
						    ->where('title', trim ($title))
						    ->where('season', trim ($season))
						    //->where('status = :status', [':status' => 1])
						    ->fetch();

		if ($result) {
			return $result['status'] == 0 ? FALSE : $result['days'];
		} else {
			$id = $this->_db->insertInto('soap4me')
						    ->values(['title'=>$title, 'season'=>$season, 'days'=>$this->_days, 'status'=>1])
						    ->execute();

		    if ($id) {
		    	return $this->_days;
		    }
		}

		return FALSE;
	}
}
