<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\TransmissionRPC;
use AppZz\Http\TransmissionRPC\Exception;
use AppZz\Helpers\Arr;
use AppZz\Helpers\HtmlDomParser;
use AppZz\Http\CurlClient;

class LEProd extends Tracker {

	public function __construct ()
	{
		parent::__construct ();
	}

    public function sync ()
    {
        $themes = $this->_db->from('leprod')
            ->where('status', 1)
            ->fetchAll();

        foreach ($themes as $theme) {
            $data = $this->_get_content($theme['url']);

            print_r ($data);
            exit;

            if ( ! empty ($data->url)) {
                if (strcmp($theme['hash'], $data->hash) !== 0) {

                    $update = [
                        'magnet'      => $data->url,
                        'title'       => $data->title,
                        'last_update' => date ('Y-m-d H:i:s'),
                        'hash'        => $data->hash
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

		return $this->_db->update('leprod')
					->set($data)
					->where('id', $id)
					->limit(1)
					->execute();
	}

    private function _get_content ($url)
    {
        $ret = new \stdClass;
        $status = NULL;
        //$response = $this->_request (['url'=>$url, 'proxy'=>FALSE, 'json'=>FALSE], $status);
        //file_put_contents ('/var/lib/sys-tools/release-tracker/response.html', $response);
        $response = file_get_contents ('/var/lib/sys-tools/release-tracker/response.html');
        $status = 200;

        if ($status === 200) {
            $html = HtmlDomParser::str_get_html ($response);
            $title = $html->find('title', 0);

            if ($title) {
                $ret->title = $title->plaintext;
            }

            print_r ($title);
            exit;                        

            $url = $html->find('.button-2 a', 0);

            print_r ($url);
            print_r ($title);
            exit;

            foreach ($urls as $u) {
                if ( !empty ($u->href) AND preg_match('#magnet\:#', $u->href)) {
                    $ret->url = $u->href;

                    if (preg_match('#.*btih\:(\w{40}).*#iu', $ret->url, $pr)) {
                        $ret->hash = strtolower($pr[1]);
                    }

                    break;
                }
            }

            return $ret;
        }

        return FALSE;
    }
}
