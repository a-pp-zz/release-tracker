<?php
namespace AppZz\Http\RT\Vendors;
use AppZz\Http\RT\Tracker;
use AppZz\Http\TransmissionRPC;
use AppZz\Http\TransmissionRPC\Exception;
use AppZz\Helpers\Arr;
use AppZz\Helpers\HtmlDomParser;
use AppZz\Http\CurlClient;
use AppZz\Http\RT\DB;

class LostFilm extends Tracker {

    private $_cookiefile;
    private $_baseurl = 'https://www.lostfilmtv5.site/';
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36';
    const TOR_QT = '1080p';

	public function __construct ()
	{
		parent::__construct ();
        $this->_cookiefile = DB::config ('datadir') . '/cookie-lostfilm.txt';
        $this->_auth();
	}

    private function _auth ($force = false)
    {
        if ($force OR ! file_exists ($this->_cookiefile)) {

            $request_options = [
                'url'          => $this->_baseurl . 'ajaxik.users.php',
                'referer'      => $this->_baseurl . 'login',
                'params'       => [
                    'act'          => 'users',
                    'type'         => 'login',
                    'mail'         => DB::config ('credentials.lostfilm.username'),
                    'pass'         => DB::config ('credentials.lostfilm.password'),
                    'need_captcha' => '',
                    'captcha'      => '',
                    'rem'          => 1,
                ],
                'cookiefile' => $this->_cookiefile,
                'proxy'      => false,
                'ajax'       => true,
                'method'     => 'POST',
                'accept'     => 'json'
            ];

            $status = null;
            $response = $this->_request ($request_options, $status);

            return ($status === 200);
        }

        return true;
    }

    public function sync ()
    {
        $results = $this->_db->from('lostfilm')
            ->where('status', 1)
            ->fetchAll();

        foreach ($results as $result) {
            $data = $this->_get_content($result['slug']);
            $changed = false;
            $last_serie_id = $result['last_serie_id'];

            foreach ($data->series as $num=>$serie_id) {
                if ($num === 0) {
                    $changed = ($serie_id != $result['last_serie_id']);
                    $last_serie_id = $serie_id;
                }

                if ($changed) {
                    $tor_url = $this->_get_torrent_url ($serie_id);
                    if ($tor_url) {
                        $tvname = str_replace ('_', ' ', $result['slug']);
                        $this->_add_torrent ($tor_url, $tvname);
                    }
                }

                if (empty($result['full_sync'])) {
                    break;
                }
            }

            if ( ! empty ($data) AND $changed) {
                $update = [
                    'title'            => $data->title,
                    'description'      => $data->description,
                    'last_serie_title' => $data->last_title,
                    'last_serie_id'    => $last_serie_id,
                    'last_update'      => date ('Y-m-d H:i:s')
                ];

                $this->_update ($result['id'], $update);
            }
        }
    }

	private function _update ($id, array $data = [])
	{
		if (empty($data)) {
			return FALSE;
		}

		return $this->_db->update('lostfilm')
					->set($data)
					->where('id', $id)
					->limit(1)
					->execute();
	}

    private function _get_content ($slug)
    {
        $ret = new \stdClass;
        $status = null;

        $url = $this->_baseurl . 'series/'.$slug.'/seasons';

        $response = $this->_request (['url'=>$url, 'proxy'=>FALSE, 'json'=>FALSE, 'cookiefile'=>$this->_cookiefile], $status);

        if ($status === 200) {

            $html = HtmlDomParser::str_get_html ($response);
            $title = $html->find('.header .title-ru', 0);
            $status = $html->find('.title-block .status', 0);

            if ($title) {
                $ret->title = strip_tags ($title->plaintext);
            }

            if ($status) {
                $ret->description = strip_tags ($status->plaintext);
                $ret->description = preg_replace ('#\s{2,}#u', ' ', $ret->description);
            }

            $series = $html->find('.movie-parts-list tr td');

            if ($series) {
                $ret->series = [];
                $ser_id = $ser_code = null;

                foreach ($series as $serie) {
                    $td_class = $serie->getAttribute('class');

                    if ($td_class == 'alpha') {
                        $div = $serie->find('div', 0);

                        if ($div) {
                            $ser_id = $div->getAttribute('data-episode');
                            $ser_code = $div->getAttribute('data-code');
                        }
                    }
                    elseif ($td_class == 'beta') {
                        if ( ! empty ($ser_id) AND ! empty ($ser_code)) {
                            $ret->series[] = $ser_id;
                            $ser_id = $ser_code = null;

                            if (empty ($ret->last_title)) {
                                $ret->last_title = strip_tags($serie->plaintext);
                            }
                        }

                        continue;
                    }
                }
            }

            return $ret;
        }

        return FALSE;
    }

    private function _get_torrent_url ($id)
    {
        $url = $this->_baseurl . 'v_search.php?a=' . $id;
        $status = $status2 = null;
        $response = $this->_request (['url'=>$url, 'proxy'=>FALSE, 'cookiefile'=>$this->_cookiefile], $status);

        if ($status == 200) {
            $html = HtmlDomParser::str_get_html ($response);
            $location = $html->find('body a', 0);

            if ($location) {
                $response2 = $this->_request (['url'=>$location->href, 'proxy'=>FALSE, 'cookiefile'=>$this->_cookiefile], $status2);
                if ($status2 === 200) {
                    $html = HtmlDomParser::str_get_html ($response2);
                    $links = $html->find('.inner-box--link.main a');

                    if ($links) {
                        foreach ($links as $link) {
                            if (strpos($link->innertext, LostFilm::TOR_QT) !== false) {
                                return $link->href;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    private function _download_torrent ($url, $id)
    {
        $status = null;
        $response = $this->_request (['url'=>$url, 'proxy'=>FALSE, 'cookiefile'=>$this->_cookiefile], $status);
        $file = DB::config ('datadir') . '/' . $id . '.torrent';

        if ($status === 200) {
            file_put_contents ($file, $response);
            return true;
        }

        return false;
    }
}
