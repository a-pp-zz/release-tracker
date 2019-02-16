<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\TransmissionRPC;
use AppZz\Helpers\Arr;
use Sunra\PhpSimple\HtmlDomParser;
use AppZz\Http\CurlClient;

class Theme extends Tracker {

	public function __construct ()
	{
		parent::__construct ();
	}

	public function sync ()
	{
		$themes = $this->_db->theme
		    ->select()
		    ->orderBy('id DESC')
		    ->run();

		foreach ($themes as $theme) {
			$magnet = $this->_get_magnet($theme->url);

			if ( ! empty ($magnet)) {
				if (strcmp($theme->magnet, $magnet) !== 0) {

					$update = [
						'magnet' =>$magnet,
						//'title'  =>$data->title,
						'update' =>time(),
						'notify' => 0
					];

					$this->_update ($theme->id, $update);
					$this->_add_torrent($magnet, $theme->name);
				}
			}
		}
	}

	private function _update ($id, array $data = [])
	{
		if (empty($data)) {
			return FALSE;
		}

		return $this->_db->theme->update()
					->data($data)
					->where('id = :id', [':id' => $id])
					->limit(1)
					->run();
	}

	private function _add_torrent ($url, $name)
	{
		try {
			$tr = new TransmissionRPC (Tracker::$transmission);
			$tr->auth(Arr::get(Tracker::$transmission, 'username'), Arr::get(Tracker::$transmission, 'password'));
			$tr->add_file ($url, trim(Tracker::$tvshows_path . DIRECTORY_SEPARATOR . $name));
		} catch (\Exception $e) {

		}
	}

	private function _get_magnet ($url)
	{
		$ret = new \stdClass;
		$request = CurlClient::get($url)
					->agent('chrome')
					->accept('*/*', 'gzip');

		if (Tracker::$proxy) {
			$proxy_host               = Arr::get (Tracker::$proxy, 'host');
			$proxy_params             = [];
			$proxy_params['port']     = Arr::get (Tracker::$proxy, 'port', 1080);
			$proxy_params['type']     = Arr::get (Tracker::$proxy, 'type', 'http');
			$proxy_params['username'] = Arr::get (Tracker::$proxy, 'username');
			$proxy_params['password'] = Arr::get (Tracker::$proxy, 'password');
			$request->proxy($proxy_host, $proxy_params);
		}

		$response = $request->send();

		if ($response === 200) {
			$body = $request->get_body();

			if (preg_match ('#magnet\:(?<magnet>[\w\?\=\:]+)#i', $body, $parts)) {
				return 'magnet:' . $parts['magnet'];
			}
		}

		return FALSE;
	}
}
