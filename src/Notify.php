<?php
namespace AppZz\Http\RT;
use AppZz\Helpers\Arr;
use PHPMailer\PHPMailer\PHPMailer;

class Notify extends DB {

	protected $_db;
    protected $_ids = [];
    protected $_data = [];

	public function __construct ()
	{
        if ( ! $this->_db) {
            $this->_db = DB::instance();
        }
	}

    public function get_data ()
    {
        $trackers = $this->_db->from('tracker')
            ->orderBy('id ASC')
            ->fetchAll();

        $sended = FALSE;

        foreach ($trackers as $tracker) {

            $data = $this->_db->from('data')
                ->leftJoin('itunes ON itunes.data_id = data.id')
                ->where('tracker_id', Arr::get($tracker, 'id', 0))
                ->where('data.notify', 0)
                //->where('data.id', 50)
                ->select('itunes.url AS itunes_url')
                ->orderBy('data.time ASC');

            if ($data->count()) {

                $row['title'] = Arr::get ($tracker, 'title');
                $row['vendor'] = Tracker::get_vendor (Arr::get($tracker, 'feed'));
                $row['items'] = [];

                foreach ($data as $item) {
                    $row['items'][] = ['id'=>Arr::get($item, 'id'), 'title'=>Arr::get($item, 'title'), 'url'=>Arr::get($item, 'url'), 'itunes_url'=>Arr::get($item, 'itunes_url')];
                    $this->_ids[] = Arr::get ($item, 'id');
                }

                $this->_data[] = $row;
            }
        }

        return $this->_data;
    }

    public function send ()
    {
        $message = '';

        foreach ($this->_data as $data) {
            $message .= sprintf ('<h2>%s // %s</h2>', Arr::get ($data, 'title'), Arr::get ($data, 'vendor'));

            foreach ($data['items'] as $d) {

                $message .= sprintf ('<p><a target="_blank" href="%s">%s</a>', $d['url'], $d['title']);

                if ( ! empty ($d['itunes_url'])) {
                    $message .= sprintf ('<br /><strong>iTunes:</strong> <a target="_blank" href="%s">%s</a>', $d['itunes_url'], $d['itunes_url']);
                }

                $message .= '</p>';
            }
        }

        if ( ! empty ($message)) {
            if ($this->_send_email($message)) {
                $this->_db->update('data')->set(['notify'=>1])->where('id', $this->_ids)->execute();
                return true;
            }
        }

        return false;
    }

    protected function _send_email ($message)
    {
        $mail = new PHPMailer;
        $mail->CharSet = 'UTF-8';

        $email_params = DB::config('email_params', []);

        $smtp = Arr::get($email_params, 'smtp');

        if ( ! empty ($smtp)) {
            $mail->isSMTP();
            $mail->Host = Arr::get($smtp, 'host');
            $mail->SMTPAuth = true;
            $mail->Username = Arr::get($smtp, 'username');
            $mail->Password = Arr::get($smtp, 'password');
            $mail->SMTPSecure = Arr::get($smtp, 'secure', 'tls');
            $mail->Port = Arr::get($smtp, 'port');
        }

        $mail->From = Arr::get($email_params, 'from');
        $mail->FromName = Arr::get($email_params, 'from_name');
        $mail->addAddress(Arr::get($email_params, 'to'));
        $mail->isHTML(true);

        $mail->Subject = 'New Releases @ ' . date ('d.m.Y H:i:s');
        $mail->Body    = $message;
        $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

        if ($mail->send()) {
            return TRUE;
        }

        return FALSE;
    }
}
