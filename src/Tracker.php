<?php
namespace AppZz\Http\RT;
use AppZz\Helpers\Arr;
use AppZz\Http\CurlClient;
use AppZz\Http\TransmissionRPC;
use DateTimeZone;

class Tracker extends DB {

	protected $_db;
	protected $_tracker;
	protected $_posts;
	protected $_data;
	protected $_update;
	protected $_tz;

	const TZ                  = 'Europe/Moscow';
	const ITUNES_API_ENDPOINT = 'https://itunes.apple.com/search/';

	public function __construct ($tracker_id = NULL)
	{
		if ( ! $this->_db) {
            $this->_db = DB::instance();
            $tz = DB::config ('timezone', Tracker::TZ);
			$this->_tz = new DateTimeZone ($tz);
		}

		if ($tracker_id AND ! $this->_tracker) {
			$this->_tracker = $this->_get_tracker ($tracker_id);
			$this->_posts = (array) $this->_get_data (-1, 'post_id');
		}
	}

	public function rutracker ($tracker_id)
	{
		return new Vendors\Rutracker ($tracker_id);
	}

	public function nnmclub ($tracker_id)
	{
		return new Vendors\Nnmclub ($tracker_id);
	}

	public function soap4me ($tracker_id)
	{
		return new Vendors\Soap4me ($tracker_id);
	}

	public function sync ()
	{
		$trackers = $this->_db->from('tracker')->fetchAll();

		foreach ($trackers as $tracker) {
			$vendor = Tracker::get_vendor ($tracker['feed']);
			$vendor = strtolower ($vendor);

			$vnd = $this->$vendor ($tracker['id']);
            $pattern = DB::config ('patterns.'.$tracker['type']);
			//$pattern = Arr::get(Tracker::$patterns, $tracker['type']);

			$vnd->get ($pattern);

			if ($vendor == 'soap4me') {
				//$vnd->add_torrents();
			}

			$vnd->save ();
		}
	}

	public function register_tracker (array $data = [])
	{
		$exists = $this->_get_tracker (Arr::get($data, 'feed'));

		if ($exists) {
			return FALSE;
		}

		return $this->_db
                ->insertInto ('tracker')
    		    ->values ([
                    'title' => Arr::get($data, 'title'),
                    'feed'  => Arr::get($data, 'feed'),
                    'type'  => Arr::get($data, 'type'),
    		    ])
                ->execute();
	}

    public function register_theme (array $data = [])
    {
        $exists = $this->_get_theme (Arr::get($data, 'url'));

        if ($exists) {
            return -1;
        }

        return (int) $this->_db
            ->insertInto ('theme')
            ->values ([
                'name' => Arr::get($data, 'name'),
                'url'  => Arr::get($data, 'url'),
                'status' => 1
            ])
            ->execute();
    }

	public function save ()
	{
		if ($this->_update) {
			$this->_db->update('tracker')
						->set(['last_update'=>$this->_update])
						->where('id', $this->_tracker['id'])
						->limit(1)
						->execute();
		}

		if ($this->_data) {
			foreach ($this->_data as $data) {
				$data = array_map('trim', $data);
                $data['title'] = html_entity_decode ($data['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8');

				$data_id = $this->_db
				    ->insertInto('data')
				    ->values($data)
				    ->execute();

                if ($this->_tracker AND $this->_tracker['type'] == 'music' AND ! empty ($data['title']) AND ! empty ($data_id)) {
                    usleep (500);
                    $itunes_url = $this->_get_itunes_url ($data['title']);

                    if ( ! empty ($itunes_url)) {
                        $this->_db
                            ->insertInto('itunes')
                            ->values(['data_id'=>$data_id, 'url'=>$itunes_url])
                            ->execute();
                    }
                }
			}
		}
	}

    public static function get_vendor ($url)
    {
        $host = parse_url ($url, PHP_URL_HOST);

        if (strpos($host, 'rutracker') !== FALSE)
            return 'Rutracker';

        if (strpos($host, 'nnm-club') !== FALSE)
            return 'Nnmclub';

        if (strpos($host, 'nnmclub') !== FALSE)
            return 'Nnmclub';

        if (strpos($host, 'soap4') !== FALSE)
            return 'Soap4me';

        return 'unknown';
    }

	public static function update_itunes_url ()
	{
		$rt = new Tracker;

        $result = $rt->_db->from('data')
            ->leftJoin('itunes ON itunes.data_id = data.id')
            ->leftJoin('tracker ON data.tracker_id = tracker.id')
            ->select('itunes.url AS itunes_url')
            ->select('data.id AS data_id')
            ->where('tracker.type', 'music')
            //->where('data.id', 97)
            ->where('itunes.url IS NULL');

		$total = $result->count();

        if ($total === 0) {
            return false;
        }

        $result = $result->fetchAll();

		$num = 0;

        foreach ($result as $row) {
			$num++;
			echo "### {$num} / {$total} ###", PHP_EOL;
            echo $row['title'], PHP_EOL;
            $artist = NULL;
			$url = $rt->_get_itunes_url ($row['title'], $artist);
            echo sprintf ('Artist: %s', $artist), PHP_EOL;
            echo sprintf ('Url: %s', $url), PHP_EOL;

			if ($url) {
                $rt->_db->insertInto('itunes')->values(['data_id'=>$row['data_id'], 'url'=>$url])->execute();
			}

			usleep (500);
		}
	}

	protected function _get_itunes_url ($text, &$artist = NULL)
	{
		$clean_pat = '#(\s+)?[\(\[\,]{1}(\s+)?$#iu';
		$genre_pat = '#(\((?<genre>[^\)\(]+)\)\)?)\s?(?<title>.*)#iu';
		$year_pat = '#(?<title>.*)(?<year>\d{4}).*#iu';
		$years_pat = '#(?<title>.*)(?<year>\d{4}.*\d{4})#iu';

		$text = preg_replace ('#\[(Обновлено|WEB|CD|CDS)\]\s?#iu', '', $text);
		$text = html_entity_decode ($text, ENT_COMPAT | ENT_HTML401, 'UTF-8');

		$p1 = preg_match ($years_pat, $text, $parts);

		if ( ! $p1) {
			$p2 = preg_match ($year_pat, $text, $parts);
        }

        if ( ! $p1 AND ! $p2) {
            return FALSE;
        }

		$parts = (array) $parts;
		$title = Arr::get ($parts, 'title');

		$title = preg_replace ($clean_pat, '', $title);

		if (preg_match($genre_pat, $title, $parts)) {
			$title = Arr::get ($parts, 'title');

			$title_parts = explode (' - ', $title);

			if ($title_parts) {
				$title_parts = array_map ('trim', $title_parts);
				$artist = Arr::get($title_parts, 0);
			}
		}

		if ($artist) {
			$urls = $this->_itunes_api_request ($artist, 'musicArtist');
			return !empty ($urls) ? $urls->artist_url : FALSE;
		}

		return FALSE;
	}

	protected function _request (array $options = [], &$status = NULL)
	{
        $url    = Arr::get ($options, 'url');
        $json   = Arr::get ($options, 'json');
        $use_proxy  = Arr::get ($options, 'proxy', TRUE);
        $params = Arr::get ($options, 'params', []);

        if (empty($url) AND ! empty ($this->_tracker) AND ! empty ($this->_tracker['feed'])) {
            $url = $this->_tracker['feed'];
        }

        if (empty($url)) {
            return FALSE;
        }

        $accept = $json ? 'json' : '*/*';

		$request = CurlClient::get($url, $params)
					->browser('chrome', 'mac')
					->accept($accept, 'gzip');

        $proxy = DB::config ('proxy', []);

		if ($use_proxy AND ! empty ($proxy) AND is_array ($proxy)) {
			$proxy_host               = Arr::get ($proxy, 'host');
			$proxy_params             = [];
			$proxy_params['port']     = Arr::get ($proxy, 'port', 1080);
			$proxy_params['type']     = Arr::get ($proxy, 'type', 'http');
			$proxy_params['username'] = Arr::get ($proxy, 'username');
			$proxy_params['password'] = Arr::get ($proxy, 'password');
			$request->proxy($proxy_host, $proxy_params);
		}

		$response = $request->send();
        $status = $response->get_status();

        return $response->get_body();
	}

	protected function _itunes_api_request ($text, $entity = 'album')
	{
		$ret = new \stdClass;
		$ret->artist_url = $ret->album_url = NULL;

		$params = [
			'term'     => $text,
			'country'  => 'ru',
			'media'    => 'music',
			'explicit' => 'Yes',
			'entity'   => $entity,
			'limit'    => 1,
		];

        $status = NULL;
        $response = $this->_request (['url'=>Tracker::ITUNES_API_ENDPOINT, 'params'=>$params, 'json'=>TRUE, 'proxy'=>FALSE], $status);

		if ($status === 200) {
			$body = json_decode($response, TRUE);

			if (json_last_error() === JSON_ERROR_NONE) {

				$total = Arr::get ($body, 'resultCount', 0);
				$results = Arr::get ($body, 'results', 0);

				if ($total === 0) {
					return FALSE;
                }

				$results = array_shift($results);

				if ($entity == 'album') {
					$ret->artist_url = Arr::get($results, 'artistViewUrl');
					$ret->album_url = Arr::get($results, 'collectionViewUrl');
				} else {
					$ret->artist_url = Arr::get($results, 'artistLinkUrl');
				}

				return $ret;
			}
		}

		return FALSE;
	}

	protected function _get_tracker ($value = 0)
	{
        $result = $this->_db->from('tracker');

		if (is_numeric($value)) {
			$result->where('id', $value);
		} else {
			$value = rtrim ($value, '/');
			$result->where('feed', $value);
		}

        $result->limit (1);

		return $result->fetch();
	}

    protected function _get_theme ($value = 0)
    {
        $result = $this->_db->from('theme');

        if (is_numeric($value)) {
            $result->where('id', $value);
        } else {
            $value = rtrim ($value, '/');
            $result->where('url', $value);
        }

        $result->limit (1);

        return $result->fetch();
    }

	protected function _get_data ($notify = -1, $field = NULL, $limit = 0)
	{
		$result = $this->_db->from('data');
		$result->where('tracker_id', $this->_tracker['id']);

		if ($notify >= 0) {
			$result->where('notify', $notify);
		}

        if ($limit) {
            $result->limit ($limit);
        }

		$result = $result->orderBy('time ASC');

        if ($field) {
            return $result->fetchPairs('id', $field);
        } else {
            return $result->fetchAll();
        }
	}

    protected function _add_torrent ($url, $name)
    {
        $tvshows_path = DB::config ('tvshows_path');

        if ($tvshows_path) {
            $tvshows_path .= trim (DIRECTORY_SEPARATOR . $name);
        }

        $transmission = DB::config ('transmission');
        $tr = new TransmissionRPC ($transmission);
        $tr->auth(Arr::get($transmission, 'username'), Arr::get($transmission, 'password'));
        $tr->add_file ($url, $tvshows_path);
    }
}
