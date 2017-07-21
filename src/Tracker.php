<?php
namespace AppZz\Http\RT;
use AppZz\Helpers\Arr;
use AppZz\Http\CurlClient;
use SimpleCrud\SimpleCrud;
use PDO;
use DateTimeZone;
use DateTime;
use PHPMailer;

class Tracker {
	
	protected $_db;
	protected $_tracker;
	protected $_posts;
	protected $_data;
	protected $_update;
	protected $_vendor;
	protected $_tz;

	public static $db_path = './data/rt.sqlite';
	public static $proxy;
	public static $types = [
		'music'   => 'Музыка',
		'tvshows' => 'Сериалы',
		'movies'  => 'Фильмы',
	];

	const TZ = 'Europe/Moscow';

	public function __construct ()
	{
		if ( ! $this->_db) {
			$dsn = 'sqlite:' . Tracker::$db_path;
			$pdo = new PDO($dsn);
			$this->_db = new SimpleCrud($pdo);	
			$this->_tz = new DateTimeZone (Tracker::TZ);
		}	
	}

	public function notification (array $params = [])
	{
		$trackers = $this->_db->trackers
		    ->select()
		    ->orderBy('type ASC')
		    ->run();  

		$message = '';
		$type = NULL;
		$ids = [];

		foreach ($trackers as $tracker) {
			$this->_tracker = $tracker;
			$data = $this->_get_data();
			unset ($this->_tracker);    

			if ($data->count()) {

				$vendor = $this->_get_vendor($tracker->feed);
				
				if ($type != $tracker->type) {
					$type = $tracker->type;
					$message .= sprintf ('<h2>%s</h2>', Arr::get(Tracker::$types, $tracker->type, $tracker->type));
				}				

				$message .= sprintf ('<h3>%s // %s</h3>', $tracker->title, $vendor);

				foreach ($data as $d) {
					$message .= sprintf ('<p><a target="_blank" href="%s">%s</a></p>', $d->url, $d->title);
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
				$mail->SMTPSecure = 'tls';
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
				/*
				$this->_db->data->update()
							->data(['notify'=>1])
							->by('id', $ids)
							->run();				
				*/							
			}					
		}					

		return $message;  		      		    
	}

	public function register (array $data = [])
	{		
		$exists = $this->_get_tracker (Arr::get($data, 'feed'));

		if ($exists) {
			return FALSE;
		}

		return $this->_db->trackers
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
			$this->_db->trackers->update()
						->data(['update'=>$this->_update])
						->where('id = :id', [':id' => $this->_tracker->id])
						->limit(1)
						->run();	
		}

		if ($this->_data) {
			foreach ($this->_data as $data) {
				$this->_db->data
				    ->insert()
				    ->data($data)
				    ->run(); 				
			}
		}
	}

	protected function _request ()
	{
		$request = CurlClient::get($this->_tracker->feed)
					->agent('chrome');					
		
		if ( ! empty (Tracker::$proxy)) {
			$request->proxy(Tracker::$proxy);
		} else {
			$request->accept('gzip');
		}					
		
		$response = $request->send();		

		if ($response === 200) {
			return $request->get_body();
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
		$result = $this->_db->trackers
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

	protected function _get_data ($notify = -1, $field = NULL)
	{
		$result = $this->_db->data
		    ->select();

		$result->where('tracker_id = :id', [':id' => $this->_tracker->id]);

		if ($notify >= 0) {
			$result->where('notify = :n', [':n'=>$notify]);
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