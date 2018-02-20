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
		    ->run();

		foreach ($themes as $theme) {
			$data = $this->_get_content($theme->url);

			if ( !empty ($data->url)) {
				if (strcmp($theme->magnet, $data->url) !== 0 OR strcmp($theme->title, $data->title) !== 0) {

					$update = [
						'magnet' =>$data->url,
						'title'  =>$data->title,
						'update' =>time(),
						'notify' => 0
					];

					$this->_update ($theme->id, $update);
					$this->_add_torrent($data->url, $theme->name);
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

	private function _get_content ($url)
	{
		$ret = new \stdClass;
		$request = CurlClient::get($url)
					->agent('chrome')
					->accept('*/*', 'gzip');

		if (Tracker::$proxy) {
			$request->proxy(Tracker::$proxy);
		}

		$response = $request->send();

		if ($response === 200) {
			$body = $request->get_body();
			$html = HtmlDomParser::str_get_html ($body);
			$title = $html->find('title', 0);

			if ($title) {
				$ret->title = $title->plaintext;
			}

			$urls = $html->find('a');

			foreach ($urls as $u) {
				if ( !empty ($u->href) AND preg_match('#magnet\:#', $u->href)) {
					$ret->url = $u->href;
					break;
				}
			}

			return $ret;
		}

		return FALSE;
	}
}
