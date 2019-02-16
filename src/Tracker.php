<?php
namespace AppZz\Http\RT;
use AppZz\Helpers\Arr;
use AppZz\Http\CurlClient;
use SimpleCrud\SimpleCrud;
use PDO;
use DateTimeZone;
use DateTime;
use PHPMailer\PHPMailer\PHPMailer;

class Tracker {

	protected $_db;
	protected $_tracker;
	protected $_posts;
	protected $_data;
	protected $_update;
	protected $_tz;

	public static $db = [];
	public static $tvshows_path = '/media/uploads/TV Shows';
	public static $proxy;
	public static $soap4me_days = 7;

	public static $transmission = [
		'host' => 'localhost',
		'port' => 9091,
	];

	public static $types = [
		'music'    => 'Музыка',
		'tvshows'  => 'Сериалы',
		'movies'   => 'Фильмы',
		'cartoons' => 'Мультфильмы',
	];

	public static $patterns = [
		'music'    => NULL,
		'tvshows'  => '#.*(720|1080)p?.*#iu',
		'movies'   => '#.*(720|1080)p?.*#iu',
		'cartoons' => '#.*(720|1080)p?.*#iu',
	];

	const TZ                  = 'Europe/Moscow';
	const ITUNES_API_ENDPOINT = 'https://itunes.apple.com/search/';

	public function __construct ($tracker_id = NULL)
	{
		if ( ! $this->_db) {
			$pdo = new PDO (Tracker::$db['dsn'], Tracker::$db['username'], Tracker::$db['password']);
			$this->_db = new SimpleCrud($pdo);
			$this->_tz = new DateTimeZone (Tracker::TZ);
		}

		if ($tracker_id AND ! $this->_tracker) {
			$this->_tracker = $this->_get_tracker($tracker_id);
			$this->_posts = (array) $this->_get_data(-1, 'post_id');
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
		return new Vendors\Soap4me ($tracker_id, Tracker::$soap4me_days);
	}

	public function sync ()
	{
		$trackers = $this->_db->tracker
		    ->select()
		    ->run();

		foreach ($trackers as $tracker) {
			$vendor = $this->_get_vendor($tracker->feed);
			$vendor = strtolower ($vendor);

			$vnd = $this->$vendor ($tracker->id);
			$pattern = Arr::get(Tracker::$patterns, $tracker->type);

			$vnd->get ($pattern);

			if ($vendor == 'soap4me') {
				$vnd->add_torrents();
			}

			$vnd->save ();
		}
	}

    public function notification_telegram ($options = [])
    {
        $trackers = $this->_db->tracker
            ->select()
            ->orderBy('type DESC')
            ->run();

        $max_items = Arr::get ($options, 'max_items', 10);

        foreach ($trackers as $tracker) {
            $this->_tracker = $tracker;
            $data = $this->_get_data(0, NULL, $max_items);
            unset ($this->_tracker);

            $total = $data->count();

            if ($total) {

                $vendor = $this->_get_vendor($tracker->feed);

                $message = sprintf ('<b>%s</b>', Arr::get(Tracker::$types, $tracker->type, $tracker->type)) . "\n\n";
                $message .= sprintf ('<em>%s // %s</em>', $tracker->title, $vendor) . "\n\n";
                $ids     = [];
                $sended  = FALSE;

                foreach ($data as $d) {

                    $message .= sprintf ('<a target="_blank" href="%s">%s</a>', $d->url, $d->title);

                    if ( !empty ($d->itunes) AND $d->itunes != -1) {
                        $message .= "\n" . sprintf ('iTunes: <a target="_blank" href="%s">%s</a>', $d->itunes, $d->itunes);
                    } else {
                        $message .= "\n\n";
                    }

                    $ids[] = $d->id;
                }

                $message .="\n";
            }

            $message = rtrim ($message, "\n");

            if ( ! empty ($message) AND ! empty ($options)) {
                $status = NULL;
                $url = sprintf ('https://api.telegram.org/bot%s/sendMessage', Arr::get ($options, 'token'));
                $response = $this->_request (['url'=>$url, 'params'=>['chat_id'=>Arr::get ($options, 'chat_id'), 'parse_mode'=>'HTML', 'text'=>$message], 'json'=>TRUE, 'proxy'=>TRUE], $status);

                if ($status !== 200) {
                    $error_msg = sprintf ('%d: %s', Arr::get ($response, 'error_code', 0), Arr::get ($response, 'description', 'Unknown error'));
                    $this->_error ($error_msg);
                } else {
                    $sended = TRUE;
                }
            }

            if ($ids AND $sended) {
                $this->_db->data->update()
                            ->data(['notify'=>1])
                            ->by('id', $ids)
                            ->run();
            }
        }

        if ($total > $max_items) {
            $this->notification_telegram ($options);
        }

        return TRUE;
    }

    public function notification_email (array $params = [])
    {
        $trackers = $this->_db->tracker
            ->select()
            ->orderBy('type DESC')
            ->run();

        $message = '';
        $type = NULL;
        $ids = [];

        $sended = FALSE;

        foreach ($trackers as $tracker) {
            $this->_tracker = $tracker;
            $data = $this->_get_data(0);
            unset ($this->_tracker);

            if ($data->count()) {

                $vendor = $this->_get_vendor($tracker->feed);

                if ($type != $tracker->type) {
                    $type = $tracker->type;
                    $message .= sprintf ('<h2>%s</h2>', Arr::get(Tracker::$types, $tracker->type, $tracker->type));
                }

                $message .= sprintf ('<h3>%s // %s</h3>', $tracker->title, $vendor);

                foreach ($data as $d) {

                    $message .= sprintf ('<p><a target="_blank" href="%s">%s</a>', $d->url, $d->title);

                    if ( !empty ($d->itunes_url) AND $d->itunes_url != -1) {
                        $message .= sprintf ('<a target="_blank" href="%s"><strong> - iTunes</strong></a>', $d->itunes_url);
                    }

                    $message .= '</p>';

                    $ids[] = $d->id;
                }
            }
        }

        if ( ! empty ($message)) {
            $mail = new PHPMailer;
            $mail->CharSet = 'UTF-8';

            $smtp = Arr::get($params, 'smtp');

            if ( ! empty ($smtp)) {
                $mail->isSMTP();
                $mail->Host = Arr::get($smtp, 'host');
                $mail->SMTPAuth = true;
                $mail->Username = Arr::get($smtp, 'username');
                $mail->Password = Arr::get($smtp, 'password');
                $mail->SMTPSecure = Arr::get($smtp, 'secure', 'tls');
                $mail->Port = Arr::get($smtp, 'port');
            }

            $mail->From = Arr::get($params, 'from');
            $mail->FromName = Arr::get($params, 'from_name');
            $mail->addAddress(Arr::get($params, 'to'));
            $mail->isHTML(true);

            $mail->Subject = 'New Releases @ ' . date ('d.m.Y H:i:s');
            $mail->Body    = $message;
            $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

            if ( ! $mail->send()) {
                $this->_error ($mail->ErrorInfo);
            } else {
            	$sended = TRUE;
            }
        }

        if ($ids AND $sended) {
            $this->_db->data->update()
                        ->data(['notify'=>1])
                        ->by('id', $ids)
                        ->run();
        }

        return $sended;
    }

	public function register (array $data = [])
	{
		$exists = $this->_get_tracker (Arr::get($data, 'feed'));

		if ($exists) {
			return FALSE;
		}

		return $this->_db->tracker
		    ->insert()
		    ->data([
		        'title' => Arr::get($data, 'title'),
		        'feed' => Arr::get($data, 'feed'),
		        'type' => Arr::get($data, 'type'),
		    ])
		->run();
	}

	public function save ()
	{
		if ($this->_update) {
			$this->_db->tracker->update()
						->data(['update'=>$this->_update])
						->where('id = :id', [':id' => $this->_tracker->id])
						->limit(1)
						->run();
		}

		if ($this->_data) {
			foreach ($this->_data as $data) {
				$data = array_map('trim', $data);

				if (isset($data['title'])) {
					$data['title'] = html_entity_decode ($data['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8');

					if ($this->_tracker AND $this->_tracker->type == 'music') {
						$data['itunes_url'] = $this->_get_itunes_url ($data['title']);
						usleep(500);
					}
				}

				$this->_db->data
				    ->insert()
				    ->data($data)
				    ->run();
			}
		}
	}

	public static function update_itunes_url ()
	{
		$rt = new Tracker;

		$result = $rt->_db->data
		    ->select()
		    ->leftJoin('tracker', 'tracker.id = data.tracker_id')
			->where('tracker.type = :type', [':type' => 'music'])
            ->where('data.post_id = :post_id', [':post_id' => 5662534])
			//->where('data.itunes_url IS NULL')
			->run();

		$total = $result->count();
		$num = 0;
		foreach ($result as $row) {
			$num++;
			echo "### {$num} / {$total} ###", PHP_EOL;
			$url = $rt->_get_itunes_url ($row->title);

			if ($url) {
				$row->itunes_url = $url;
				$row->save();
			}

			usleep (500);
		}

		/*

		$data = $this->_get_data(-1);
		$total = $data->count();
		$num = 0;
		foreach ($data as $row) {
			if ( !empty ($row->meta))
				continue;
			$num++;
			$meta = Tracker::get_music_metadata ($row->title);
			echo "### {$row->id}, {$num} // {$total} ";
			if ($meta) {
				$meta = serialize($meta);
				$this->_db->data->update()
							->data(['meta'=>$meta, 'notify'=>0])
							->where('id = :id', [':id' => $row->id])
							->limit(1)
							->run();
				echo "- OK";
			} else {
				echo "- FAIL";
			}

			echo " ###", PHP_EOL;
		}
		*/
	}

	protected function _get_itunes_url ($text)
	{
		$clean_pat = '#(\s+)?[\(\[\,]{1}(\s+)?$#iu';
		$descr_pat = '#\(.*(cd|disk|disc|remaster|demo).*\)#iu';
		//$genre_pat = '#(\((?<genre>[\w\s\.\,\-\/\:\!]+)\))\s?(?<title>.*)#iu';
		$genre_pat = '#(\((?<genre>[^\)\(]+)\)\)?)\s?(?<title>.*)#iu';
		$year_pat = '#(?<title>.*)(?<year>\d{4}).*#iu';
		$years_pat = '#(?<title>.*)(?<year>\d{4}.*\d{4})#iu';

		$artist = $album = $genre = $year = $url = NULL;

		$text = preg_replace ('#\[(Обновлено|WEB|CD|CDS)\]\s?#iu', '', $text);
		$text = html_entity_decode ($text, ENT_COMPAT | ENT_HTML401, 'UTF-8');

		$p1 = preg_match($years_pat, $text, $parts);

		if ( ! $p1)
			$p2 = preg_match($year_pat, $text, $parts);

		$parts = (array) $parts;

		$year = Arr::get ($parts, 'year');
		$title = Arr::get ($parts, 'title');

		$title = preg_replace ($clean_pat, '', $title);

		if (preg_match($genre_pat, $title, $parts)) {
			$genre = Arr::get ($parts, 'genre');
			$title = Arr::get ($parts, 'title');

			$title_parts = explode (' - ', $title);

			if ($title_parts) {
				$title_parts = array_map ('trim', $title_parts);
				$artist = Arr::get($title_parts, 0);
				$album = Arr::get($title_parts, 1);
			}
		}

		//print_r ($parts);

		$album  = preg_replace ($descr_pat, '', $album);
		$album  = preg_replace ($clean_pat, '', $album);

		$artist = trim ($artist);
		$album  = trim ($album);
		$genre  = trim ($genre);
		$year   = trim ($year);

		/*
		$meta = [
			'artist' => $artist,
			'album'  => $album,
			'genre'  => $genre,
			'year'   => $year,
		];
		*/

		if ($artist) {
			$urls = $this->_itunes_api_request ($artist, 'musicArtist');
			return !empty ($urls) ? $urls->artist_url : FALSE;
			//$meta['url'] = $urls->artist_url;
		}

		return FALSE;
	}

	protected function _request (array $options = [], &$status = NULL)
	{
        $url    = Arr::get ($options, 'url');
        $json   = Arr::get ($options, 'json');
        $proxy  = Arr::get ($options, 'proxy', TRUE);
        $params = Arr::get ($options, 'params', []);

        if (empty($url) AND ! empty ($this->_tracker) AND ! empty ($this->_tracker->feed)) {
            $url = $this->_tracker->feed;
        }

        if (empty($url)) {
            return FALSE;
        }

        $accept = $json ? 'json' : '*/*';

		$request = CurlClient::get($url, $params)
					->browser('chrome', 'mac')
					->accept($accept, 'gzip');

		if ($proxy AND ! empty (Tracker::$proxy) AND is_array (Tracker::$proxy)) {
			$proxy_host               = Arr::get (Tracker::$proxy, 'host');
			$proxy_params             = [];
			$proxy_params['port']     = Arr::get (Tracker::$proxy, 'port', 1080);
			$proxy_params['type']     = Arr::get (Tracker::$proxy, 'type', 'http');
			$proxy_params['username'] = Arr::get (Tracker::$proxy, 'username');
			$proxy_params['password'] = Arr::get (Tracker::$proxy, 'password');
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

			return FALSE;
		} else {
			$ret->artist_url = $ret->album_url = -1;
		}

		return FALSE;
	}

	protected function _get_vendor ($url)
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

	protected function _get_tracker ($value = 0)
	{
		$result = $this->_db->tracker
		    ->select()
		    ->one();

		if (is_numeric($value)) {
			$result->where('id = :id', [':id' => $value]);
		} else {
			$value = rtrim ($value, '/');
			$result->where('feed = :feed', [':feed' => $value]);
		}

		$result = $result->run();
		return $result;
	}

	protected function _get_data ($notify = -1, $field = NULL, $limit = 0)
	{
		$result = $this->_db->data
		    ->select();

		$result->where('tracker_id = :id', [':id' => $this->_tracker->id]);

		if ($notify >= 0) {
			$result->where('notify = :n', [':n'=>$notify]);
		}

        if ($limit) {
            $result->limit($limit);
        }

		$result = $result->orderBy('time ASC')->run();

		if ($field) {
			$ret = [];
			foreach ($result as $r) {
				$ret[] = $r->$field;
			}

			return $ret;
		}

		return $result;
	}

	protected function _error ($message, $code = 0)
	{
		throw new \Exception ($message, $code);
	}
}
