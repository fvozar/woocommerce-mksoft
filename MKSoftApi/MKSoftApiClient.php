<?php


class MKSoftApiClient
{
	/** @var string */
	private $ftp_host;

	/** @var string */
	private $ftp_user;

	/** @var string */
	private $ftp_pass;


	/**
	 * MKSoftApiClient constructor.
	 *
	 * @param string $ftp_host
	 * @param string $ftp_user
	 * @param string $ftp_pass
	 */
	public function __construct($ftp_host, $ftp_user, $ftp_pass)
	{
		$this->ftp_host = $ftp_host;
		$this->ftp_user = $ftp_user;
		$this->ftp_pass = $ftp_pass;
	}


	/**
	 * @param string $orderFile
	 * @param string $payload
	 *
	 * @throws \RuntimeException
	 */
	public function sync_order($orderFile, $payload)
	{
		$connection = $this->obtain_connection();

		if (null === $connection) {
			throw new RuntimeException("Can't connect to FTP server. Check if credentials are valid.");
		}

		$tempFile = tmpfile();
		fwrite($tempFile, $payload);
		fseek($tempFile, 0);

		$uploaded = ftp_fput($connection, $orderFile, $tempFile, FTP_BINARY);

		if ( ! $uploaded) {
			throw new RuntimeException("File wasn't uploaded successfully");
		}

		ftp_close($connection);
		fclose($tempFile);
	}


	/**
	 * @return resource
	 */
	private function obtain_connection()
	{
		preg_match("/ftp:\/\/(.*?)(\/.*)/i", $this->ftp_host, $match);

		$connection = ftp_connect($match[1]);

		if (ftp_login($connection, $this->ftp_user, $this->ftp_pass) && ftp_chdir($connection, $match[2])) {
			return $connection;
		}

		return null;
	}
}
