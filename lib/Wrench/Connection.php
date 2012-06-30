<?php
namespace Wrench;

use Wrench\Exception\CloseException;

use Wrench\Exception\ConnectionException;

use Wrench\Exception\HandshakeException;

use Wrench\Exception\BadRequestException;

use Wrench\Util\Configurable;

use Wrench\Socket;
use Wrench\Server;
use \RuntimeException;
use Wrench\Exception as WrenchException;
use \Exception;

/**
 * Represents a client connection on the server side
 *
 * i.e. the `Server` manages a bunch of `Connection`s
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Simon Samtleben <web@lemmingzshadow.net>
 */
class Connection extends Configurable
{
    protected $manager;

    /**
     * Socket object
     *
     * Wraps the client connection resource
     *
     * @var Socket
     */
    protected $socket;

    /**
     * Whether the connection has successfully handshaken
     *
     * @var boolean
     */
    protected $handshaked = false;

    /**
     * The application this connection belongs to
     *
     * @var Application
     */
    protected $application = null;

    /**
     * The IP address of the client
     *
     * @var string
     */
	protected $ip;

	/**
	 * The port of the client
	 *
	 * @var int
	 */
	protected $port;

	/**
	 * Connection ID
	 *
	 * @var string|null
	 */
	protected $id = null;

	public $waitingForData = false;
	private $_dataBuffer = '';

    /**
     * Constructor
     *
     * @param Server $server
     * @param resource $socket
     * @param array $options
     * @throws InvalidArgumentException
     */
	public function __construct(
	    ConnectionManager $manager,
	    $socket,
	    array $options = array()
    ) {
        $this->manager = $manager;

        parent::__construct($options);

        $this->configureSocket($socket);
        $this->configureClientInformation();

		$this->log('Connected');
    }

    /**
     * @see Wrench\Util.Configurable::configure()
     */
    protected function configure(array $options)
    {
        $options = array_merge(array(
            'socket_class'         => 'Wrench\Socket\ServerClientSocket',
            'socket_options'       => array(),
            'connection_id_secret' => 'asu5gj656h64Da(0crt8pud%^WAYWW$u76dwb',
            'connection_id_algo'   => 'sha512',
        ), $options);

        parent::configure($options);
    }

    /**
     * @param resource $socket
     */
    protected function configureSocket($socket)
    {
        $class   = $this->options['socket_class'];
        $options = $this->options['socket_options'];
        $this->socket = new $class($socket, $options);
    }

    /**
     * @throws RuntimeException
     */
    protected function configureClientInformation()
    {
		$name = stream_socket_get_name($this->socket->getResource(), true);

		$tmp = explode(':', $name);
		if (count($tmp) == 2) {
    		$this->ip = $tmp[0];
    		$this->port = $tmp[1];
    		$this->configureClientId();
		} else {
		    throw new RuntimeException('Could not get client information');
		}
    }

    /**
     * Configures the client ID
     *
     * We hash the client ID to prevent leakage of information if another client
     * happens to get a hold of an ID. The secret *must* be lengthy, and must
     * be kept secret for this to work: otherwise it's trivial to search the space
     * of possible IP addresses/ports (well, if not trivial, at least very fast).
     */
    protected function configureClientId()
    {
        $message = sprintf(
		    '%s:uri=%s&ip=%s&port=%s',
            $this->options['connection_id_secret'],
		    rawurlencode($this->manager->getUri()),
		    rawurlencode($this->ip),
	        rawurlencode($this->port)
        );

        $algo = $this->options['connection_id_algo'];

        if (extension_loaded('gmp')) {
            $hash = hash($algo, $message, true);
            $hash = gmp_strval(gmp_init($hash, 16), 62);
        } else {
            $hash = hash($algo, $message);
        }

        $this->id = $hash;
    }

	/**
	 * Data receiver
	 *
	 * Called by the connection manager when the connection has received data
	 *
	 * @param string $data
	 */
	public function onData($data)
    {
        if (!$this->handshaked) {
            return $this->handshake($data);
        }
        return $this->handle($data);
    }

    public function handshake($data)
    {
        try {
            list($path, $origin, $key, $extensions)
                = $this->protocol->validateRequestHandshake($data);

            $this->application = $this->manager->getApplicationForPath($path);
            if (!$this->application) {
                throw new BadRequestException('Invalid application');
            }

            $this->manager->getServer()->notify(
                Server::EVENT_HANDSHAKE_REQUEST,
                array($this, $path, $origin, $key, $extensions)
            );

            $response = $this->protocol->getResponseHandshake($key);

            if ($this->socket->send($response) === false) {
                throw new HandshakeException('Could not send handshake response');
            }

            $this->handshaked = true;

            $this->log(sprintf(
                'Handshake successful: %s:%d (%s) connected to %s',
                $this->getIp(),
                $this->getPort(),
                $this->getId(),
                $path
            ), 'info');

            $this->manager->getServer()->notify(
                Server::EVENT_HANDSHAKE_SUCCESSFUL,
                array($this)
            );

            $this->application->onConnect($this);
        } catch (WrenchException $e) {
            $this->log('Handshake failed: ' . $e, 'err');
            throw $e;
        }
    }

	public function sendHttpResponse($httpStatusCode = 400)
	{
		$httpHeader = 'HTTP/1.1 ';
		switch($httpStatusCode)
		{
			case 400:
				$httpHeader .= '400 Bad Request';
			break;

			case 401:
				$httpHeader .= '401 Unauthorized';
			break;

			case 403:
				$httpHeader .= '403 Forbidden';
			break;

			case 404:
				$httpHeader .= '404 Not Found';
			break;

			case 501:
				$httpHeader .= '501 Not Implemented';
			break;
		}
		$httpHeader .= "\r\n";
		$this->server->writeBuffer($this->socket, $httpHeader);
	}


    private function handle($data)
    {
		if ($this->waitingForData === true) {
			$data = $this->_dataBuffer . $data;
			$this->_dataBuffer = '';
			$this->waitingForData = false;
		}

		$decoded = $this->protocol->decode($data);

		if ($decoded === false) {
			$this->waitingForData = true;
			$this->_dataBuffer .= $data;
			return false;
		} else {
			$this->_dataBuffer = '';
			$this->waitingForData = false;
		}

		switch ($decoded['type']) {
			case 'text':
				$this->application->onData($decodedData['payload'], $this);
			break;

			case 'binary':
				if(method_exists($this->application, 'onBinaryData'))
				{
					$this->application->onBinaryData($decodedData['payload'], $this);
				}
				else
				{
					$this->close(1003);
				}
			break;

			case 'ping':
				$this->send($decodedData['payload'], 'pong', false);
				$this->log('Ping? Pong!');
			break;

			case 'pong':
				// server currently not sending pings, so no pong should be received.
			break;

			case 'close':
				$this->close();
				$this->log('Disconnected');
			break;
		}

		return true;
    }

    /**
     * Sends the payload to the connection
     *
     * @param string $payload
     * @param string $type
     * @param boolean $masked
     * @throws HandshakeException
     * @throws ConnectionException
     * @return boolean
     */
    public function send($payload, $type = 'text', $masked = false)
    {
        if (!$payload) {
            return false;
        }

        if (!$this->handshaked) {
            throw new HandshakeException('Connection is not handshaked');
        }

        $encoded = $this->protocol->encode($payload, $type, $masked);

        if (!$encoded) {
            $this->log('Could not send message: encoded message is empty', 'warn');
            return false;
        }

        if (!$this->socket->send($encoded)) {
            $this->log('Could not send payload to client', 'warn');
            throw new ConnectionException('Could not send data to connection');
        }

		return true;
    }

    public function processException(Exception $e)
    {
        try {
            if (!$this->handshaked) {
                $response = $this->protocol->getErrorHandshake($e);
                $this->socket->send($response);
            } else {
                $response = $this->protocol->getCloseFrame($e);
                $this->socket->send($response);
            }
        } catch (Exception $e) {
            $this->log('Unable to send error response', 'warning');
        }

        $connection->close();
    }

    public function process()
    {
        $data = $this->socket->receive();
        $bytes = strlen($data);

        if ($bytes === 0 || $data === false) {
            throw new CloseException('Error reading data from socket');
        }

        $this->onData($data);
    }

	public function close($statusCode = 1000)
	{
		$payload = str_split(sprintf('%016b', $statusCode), 8);
		$payload[0] = chr(bindec($payload[0]));
		$payload[1] = chr(bindec($payload[1]));
		$payload = implode('', $payload);

		switch($statusCode)
		{
			case 1000:
				$payload .= 'normal closure';
			break;

			case 1001:
				$payload .= 'going away';
			break;

			case 1002:
				$payload .= 'protocol error';
			break;

			case 1003:
				$payload .= 'unknown data (opcode)';
			break;

			case 1004:
				$payload .= 'frame too large';
			break;

			case 1007:
				$payload .= 'utf8 expected';
			break;

			case 1008:
				$payload .= 'message violates server policy';
			break;
		}

		if($this->send($payload, 'close', false) === false)
		{
			return false;
		}

		if($this->application)
		{
            $this->application->onDisconnect($this);
        }
		stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
		$this->server->removeClientOnClose($this);
	}

	public function onDisconnect()
    {
        $this->log('Disconnected', 'info');
        $this->close(1000);
    }

    public function log($message, $priority = 'info')
    {
        $this->manager->log(sprintf(
            '%s: %s:%d (%s): %s',
            __CLASS__,
            $this->getIp(),
            $this->getPort(),
            $this->getId(),
            $message
        ), $priority);
    }

	public function getIp()
	{
		return $this->ip;
	}

	public function getPort()
	{
		return $this->port;
	}

	public function getId()
	{
		return $this->id;
	}

	public function getSocket()
	{
		return $this->socket;
	}

	public function getClientApplication()
	{
		return (isset($this->application)) ? $this->application : false;
	}
}