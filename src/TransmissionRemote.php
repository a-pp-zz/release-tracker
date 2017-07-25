<?php
namespace AppZz\Http\RT;

class TransmissionRemote {

	public static $bin = '/usr/local/bin/transmission-remote';
	public static $download_path = '/media/uploads/Downloads';

	private $_host = 'localhost';
	private $_port = 9091;
	private $_ssl = FALSE;

	private $_username;
	private $_password;
	private $_buffer;
	private $_temp;

	public function __construct ($host = 'localhost', $port = 9091, $ssl = FALSE)
	{
		if ($host)
			$this->_host = $host;
		
		if ($port)
			$this->_port = $port;
		
		$this->_ssl  = (bool) $ssl;
		$this->_temp = tempnam ('/tmp', 'tr_remote');
		$this->_temp_err = tempnam ('/tmp', 'tr_remote_err');
	}

	public function auth ($username, $password)
	{
		if ($username)
			$this->_username = $username;
		
		if ($password)
			$this->_password = $password;
		
		return $this;
	}

	public function status ()
	{
		$this->_command(['-si'=>FALSE]);
		return TRUE;
	}

	public function get_list ()
	{
		$fields = [
			1 => 'id',
			2 => 'done',
			3 => 'have',
			4 => 'eta',
			5 => 'up',
			6 => 'down',
			7 => 'ratio',
			8 => 'status',
			9 => 'name',
		];

		$this->_command(['--list'=>FALSE]);
		
		$ret = [];

		if ($this->_buffer) {
			$buffer = explode ("\n", $this->_buffer);
			array_splice ($buffer, 0, 1);
			array_splice ($buffer, -2, 1);

			foreach ($buffer as $line=>$buf) {
				$values = preg_split("/[\s]{2,}/", $buf);
				foreach ($values as $k=>$v) {
					if (isset($fields[$k])) {
						$ret[$line][$fields[$k]] = $v;
					}
				}
			}
		}

		return $ret;
	}

	public function add ($file, $download_path = FALSE)
	{
		$download_path = ! empty ($download_path) ? $download_path : TransmissionRemote::$download_path;
		$cmd = [
			'--no-incomplete-dir' =>FALSE,
			'--add'               => escapeshellarg ($file),
			'--download-dir'      => escapeshellarg ($download_path),
		];

		$this->_command($cmd);

		return TRUE;
	}

	public function delete ($torrent, $delete_data = FALSE)
	{
		if (is_numeric($torrent)) {
			$torrent = [$torrent];
		}
		
		if (is_array($torrent)) {
			$torrent = implode (',', $torrent);
		} elseif ( ! in_array($torrent, ['active', 'all'])) {
			return FALSE;
		}

		$action = $delete_data ? '--remove-and-delete' : '--remove';

		$cmd = [			
			$action       => FALSE,
			'-t'.$torrent => FALSE,
		];

		$this->_command($cmd);

		return TRUE;
	}


	public function version ()
	{
		if (preg_match ('#Daemon\sversion\:(.*)#iu', $this->_buffer, $parts)) {
			return trim ($parts[1]);
		}		

		return FALSE;
	}

	private function _command (array $cmd = [])
	{
		$cmdline = TransmissionRemote::$bin . " {$this->_host}:{$this->_port} ";

		if ($this->_ssl) {
			$cmd['--ssl'] = FALSE;
		}

		if ($this->_username AND $this->_password) {
			$cmd['--auth'] = escapeshellarg ("{$this->_username}:{$this->_password}");
		}	

		$cmd = array_reverse($cmd);	

		foreach ($cmd as $key=>$value) {
			$ex = "{$key}";

			if ($value) {
				$ex .= " {$value} "; 
			} else {
				$ex .= " ";
			}

			$cmdline .= $ex;
		}	

		$cmdline = trim ($cmdline);	
		$cmdline .= " 2>{$this->_temp_err} > {$this->_temp}";

		//echo $cmdline, PHP_EOL;

		system ($cmdline, $ret);
		$ret = intval ($ret);

		if ($ret !== 0) {
			$error = $this->_read_error();
			throw new \Exception ($error);
		}

		$this->_read_buffer ();

		return $this;
	}

	private function _read_buffer ()
	{
		if ($this->_temp AND file_exists($this->_temp)) {
			$this->_buffer = file_get_contents($this->_temp);
			return $this;
		}

		return FALSE;
	}

	private function _read_error ()
	{
		$error = 'Transmision Remote Error';
		
		if ($this->_temp_err AND file_exists($this->_temp_err)) {
			$e = file_get_contents($this->_temp_err);
			if ( ! empty ($e))
				$error .= ': ' . $e;
		}

		return $error;
	}	

	public function __destruct ()
	{
		if ($this->_temp AND file_exists($this->_temp)) {
			unlink ($this->_temp);
		}

		if ($this->_temp_err AND file_exists($this->_temp_err)) {
			unlink ($this->_temp_err);
		}		
	}	
}