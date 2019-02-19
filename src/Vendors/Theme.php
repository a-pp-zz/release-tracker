<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\TransmissionRPC;
use AppZz\Http\TransmissionRPC\Exception;
use AppZz\Helpers\Arr;
use AppZz\Helpers\HtmlDomParser;
use AppZz\Http\CurlClient;

class Theme extends Tracker {

	public function __construct ()
	{
		parent::__construct ();
	}

    public function sync ()
    {
        $themes = $this->_db->from('theme')
            ->where('status', 1)
            ->fetchAll();

        foreach ($themes as $theme) {
            $data = $this->_get_content($theme['url']);

            if ( ! empty ($data->url)) {
                if (strcmp($theme['magnet'], $data->url) !== 0 OR strcmp($theme['title'], $data->title) !== 0) {

                    $update = [
                        'magnet' =>$data->url,
                        'title'  =>$data->title,
                        'last_update' =>date ('Y-m-d H:i:s'),
                    ];

                    $this->_update ($theme['id'], $update);
                    $this->_add_torrent($data->url, $theme['name']);
                }
            }
        }
    }

	private function _update ($id, array $data = [])
	{
		if (empty($data)) {
			return FALSE;
		}

		return $this->_db->update('theme')
					->set($data)
					->where('id', $id)
					->limit(1)
					->execute();
	}

    private function _get_content ($url)
    {
        $ret = new \stdClass;
        $status = NULL;
        $response = $this->_request (['url'=>$url, 'proxy'=>TRUE, 'json'=>FALSE], $status);

        if ($status === 200) {
            $html = HtmlDomParser::str_get_html ($response);
            $title = $html->find('title', 0);

            if ($title) {
                $ret->title = $title->plaintext;
                $encoding = mb_detect_encoding ($ret->title, ['utf-8', 'cp1251']);
                $ret->title = mb_convert_encoding ($ret->title, 'utf-8', $encoding);
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
