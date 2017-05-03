<?php

declare(strict_types = 1);
?>
this is an async `tail -f` server, written in php.

... its actually not async by default, see $async and socket_getpeername. maybe i even did more mistakes, who knows?

to connect to it, write in a terminal: netcat ip port
<?php

error_reporting ( E_ALL );
$async = 0; // IF you want async, replace 0 with MSG_DONTWAIT
$port = 9999;
$tailFile = '/var/log/nginx/access.log';
$connections = array ();

$pipedescriptor = array (
		1 => array (
				'pipe',
				'wb' 
		) 
);
y ( $tail = proc_open ( 'tail -f ' . escapeshellarg ( $tailFile ), $pipedescriptor, $pipes ) );
y ( $listen = socket_create ( AF_INET, SOCK_STREAM, SOL_TCP ) );
y ( socket_bind ( $listen, '0.0.0.0', $port ) );
y ( socket_listen ( $listen ) );
$newConn = NULL;
$tmp = array ();
$newConnId = 0;
// $ids = new SplObjectStorage ();
//
class R {
	public $r;
	function __construct($resource) {
		if (! is_resource ( $resource )) {
			// ...
			throw new \UnexpectedValueException ();
		}
		$this->r = $resource;
	}
}
echo 'starting... ';
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
		// $key = $ids [$disconnectMe];
		// $ids->detach ( $disconnectMe );
		socket_close ( $disconnectMe );
		// unset ( $connections [$key] );
	
	}
	if (! empty ( $read )) {
		// new client(s)!
		do {
			echo 'new client!: ';
			++ $newConnId;
			y ( $newConn = socket_accept ( $listen ) );
			$pn = '';
			$pp = - 1;
			socket_getpeername ( $newConn, $pn, $pp ); // << not async and can cause lag on slow rdns queries...
			echo $pn . ':' . $pp . PHP_EOL;
			// $ids->attach (
			$connections [$newConnId] = $newConn; // , $newConnId
			                                      // );
			$read = array (
					$listen 
			);
		} while ( socket_select ( $read, $tmp, $tmp, 0 ) );
		// accepted all clients. the new clients may want to read the latest updates too, so continue;
		continue;
	}
	if (! empty ( $write )) {
		// *someone* is ready to read. for those who are not? well, sucks to be them i guess.
		y ( ! is_bool ( $newtext = fread ( $pipes [1], 100 ) ) ); // read 100 bytes at a time...
		foreach ( $write as $client ) {
			socket_send ( $client, $newtext, strlen ( $newtext ), $async );
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
