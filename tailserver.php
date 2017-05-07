<?php
declare(strict_types = 1);
error_reporting ( E_ALL );
$async = MSG_DONTWAIT; //
$port = 9999;
$tailFiles = array (
		// '/var/log/nginx/access.log' ,
		'stdout',
		'stderr' 
);
// FIXME: 11 probably works on x86 linux, but its probably not portable
//
define ( 'EWOULDBLOCK', 11 );
define ( 'EAGAIN', 11 );
$connections = array ();
class ResourceStorage implements ArrayAccess, Countable, IteratorAggregate {
	protected $data = [ ];
	public function count() {
		return count ( $this->data );
	}
	public function getIterator() {
		return new ArrayIterator ( $this->data );
	}
	public function offsetSet($resource, $data) {
		if (! is_resource ( $resource )) {
			throw new \UnexpectedValueException ( 'expected resource, got ' . static::return_var_dump ( $resource ) );
		} else {
			$this->data [( int ) $resource] = $data;
		}
	}
	public function offsetExists($resource): bool {
		return array_key_exists ( ( int ) $resource, $this->data );
	}
	public function offsetUnset($resource) {
		unset ( $this->data [$resource] );
	}
	public function offsetGet($resource) {
		return array_key_exists ( ( int ) $resource, $this->data ) ? $this->data [( int ) $resource] : null;
	}
	function attach($resource, $data) {
		if (! is_resource ( $resource )) {
			throw new \InvalidArgumentException ();
		}
		$this->offsetSet ( $resource, $data );
		return;
	}
	function detach($resource) {
		if (! is_resource ( $resource )) {
			throw new \InvalidArgumentException ();
		}
		$this->offsetUnset ( $resource );
		return;
	}
	protected static function return_var_dump(): string {
		$args = func_get_args ();
		ob_start ();
		call_user_func_array ( 'var_dump', $args );
		return ob_get_clean ();
	}
}
if (php_sapi_name () !== 'cli') {
	die ( 'this script must run in cli!' ); // feel free to remove this line
}
?>
this is an async `tail -f` server, written in php.

... its actually not async by default, see $async and socket_getpeername. maybe i even did more mistakes, who knows?

to connect to it, write in a terminal: netcat ip port
<?php
$pipedescriptor = array (
		1 => array (
				'pipe',
				'wb' 
		) 
);
y ( $tail = proc_open ( 'tail -f ' . implode ( ' ', array_map ( 'escapeshellarg', $tailFiles ) ), $pipedescriptor, $pipes ) );
y ( $listen = socket_create ( AF_INET, SOCK_STREAM, SOL_TCP ) );
register_shutdown_function ( function () use (&$listen) {
	socket_close ( $listen );
} );
y ( socket_bind ( $listen, '0.0.0.0', $port ) );
y ( socket_listen ( $listen ) );
y ( socket_set_nonblock ( $listen ) );
$newConn = NULL;
$tmp = array ();
$newConnId = 0;
$ids = new ResourceStorage ();
echo 'starting... ', PHP_EOL;
while ( true ) {
	$newconn = NULL;
	$read = array (
			$listen 
	);
	$write = $connections;
	$except = $write;
	y ( ! is_bool ( $select = socket_select ( $read, $write, $except, NULL ) ) );
	foreach ( $except as $disconnectMe ) {
		// wild guess: a disconnect cause an "except"...
		// whatever the reason, disconnect any client with an except, feel free to add more error checking.
		$key = $ids [$disconnectMe];
		unset ( $ids [$disconnectMe] );
		socket_close ( $disconnectMe );
		unset ( $connections [$key] );
	}
	if (! empty ( $read )) {
		// new client(s)!
		while ( false !== ($newConn = socket_accept ( $listen )) ) {
			echo 'new client!: ';
			++ $newConnId;
			$peername = '';
			$peerport = - 1;
			socket_getpeername ( $newConn, $peername, $peerport ); // << not async and can cause lag on slow rdns queries...
			echo $peername . ':' . $peerport . PHP_EOL;
			$ids->attach ( $connections [$newConnId] = $newConn, $newConnId );
			echo PHP_EOL . 'connected right now: ' . count ( $connections ) . '. connections all time: ' . $newConnId, PHP_EOL;
			y ( socket_shutdown ( $newConn, 0 ) ); // we are never going to read from this socket, soo...F
			y ( socket_set_nonblock ( $newConn ) );
		}
		// accepted all clients. the new clients may want to read the latest updates too, so continue;
		continue;
	}
	if (! empty ( $write )) {
		// *someone* is ready to read. for those who are not? well, sucks to be them i guess.
		y ( ! is_bool ( $newtext = fread ( $pipes [1], 100 ) ) ); // read 100 bytes at a time...
		if (strlen ( $newtext ) > 0) {
			foreach ( $write as $client ) {
				$sent = @socket_send ( $client, $newtext, strlen ( $newtext ), $async );
				if (false === $sent) {
					$lasterr = socket_last_error ( $client );
					if ($lasterr !== EWOULDBLOCK && $lasterr !== EAGAIN) {
						// something bad happened (maybe just a disconnect?), disconnect it.
						$key = $ids [$client];
						unset ( $ids [$client] );
						socket_close ( $client );
						unset ( $connections [$key] );
					}
				}
			}
		} else {
			// crap, clients are ready to read, but there is no new data...
			sleep ( 1 ); // anyone got a better idea?
		}
	}
}
function y($in) {
	if (! $in) {
		$str = hhb_return_var_dump ( socket_last_error (), socket_strerror ( socket_last_error () ) );
		throw new \Exception ( $str );
	}
	return $in;
}
function n($in) {
	if (! ! $in) {
		throw new \Exception ();
	}
	return $in;
}
function hhb_return_var_dump(): string // works like var_dump, but returns a string instead of printing it.
{
	$args = func_get_args ();
	ob_start ();
	call_user_func_array ( 'var_dump', $args );
	return ob_get_clean ();
}

