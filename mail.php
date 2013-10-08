<?php

/*

David Guest 2013
IT Services
University of Sussex

A simple IMAP client to login to a designated mail server and
return basic details in JSON format about a user's inbox.

*/


//enter mail server details here
$mailserver = "ssl://imap.exchange.sussex.ac.uk";
$mailserverport = 993;

if(isset($_REQUEST["username"])) {
	$user = $_REQUEST["username"];
} 

if(isset($_REQUEST["password"])) {
	$pass = $_REQUEST["password"];
} 

$showloginform = true;

//function to send an IMAP command and present the response
function get_response($connection, $command) {
	if ($connection != false) {
	
		stream_set_blocking($connection, false);
		
		fwrite($connection, $command);
		$lines = array();
		$i = 0;
		$output = "";
		
		while (true) {
			$line = fgets($connection, 4096);
			$i += strlen($line);
			if ($i == 0) continue;
			$output .= $line . "<br />";
			if(isset($command)) { 
				if(substr($command,0,4) == substr($line,0,4)) {
					break;
				}
			} elseif (strlen(trim($line)) < 1) {
				break;
			}
		}
		return $output;
		
	} else {
		return "BAD CONNECTION<br/>";
	}
}
	
if(isset($user) && isset($pass)) {

	//set up an array to hold the data
	$userdata = array("name"=>$user,"total"=>0,"unseen"=>0);
	$unseen_items = array();
	
	
	//connect and get raw message
	$conn = fsockopen($mailserver, $mailserverport);
	$outcome = get_response($conn);
	
	//try logging in to Exchange
	$command = "A001 LOGIN " . $user . " " . $pass . "\r\n";
	$outcome = strtolower(get_response($conn, $command));
	
	//IMAP command to go to the INBOX
	$command = "A002 SELECT INBOX\r\n";
	$outcome = get_response($conn, $command);
	$response_lines = explode("<br />", $outcome);

	//parse the response to get the total number of messages
	//and the number of unseen messages
	foreach($response_lines as $response_line) {
		if(stristr($response_line, "RECENT")) {
			$bits = explode(" ", $response_line);
			$quantity = $bits[1];
			$userdata["unseen"] = intval($quantity);
		} elseif(stristr($response_line, "EXISTS")) {
			$bits = explode(" ", $response_line);
			$quantity = $bits[1];
			$userdata["total"] = intval($quantity);
		}
	}
	
	//now get the message IDs of the unseen messages
	$command = "A003 SEARCH UNSEEN\r\n";
	$outcome = get_response($conn, $command);
	$response_lines = explode("<br />", $outcome);
	foreach($response_lines as $response_line) {
		if(stristr($response_line, "* SEARCH")) {
			$bits = explode(" ", $response_line);
			foreach($bits as $bit) {
				if($bit != "*" && !stristr($bit, "SEARCH")) {
					$unseen_items[] = intval($bit);
				}
			}
		} 
	}
	
	
	//for the most recent unseen messages (up to an arbitrary limit
	//of 100), get the date, subject and sender
	rsort($unseen_items);
	$msg_headers = array();
	
	//we have sent three messages already so next will be A004
	$command_number = 4;
	if(count($unseen_items)>0) {
		if(count($unseen_items)<101) {
			$max_msg = count($unseen_items);
		} else {
			$max_msg = 100;
		}
		for($u=0;$u<$max_msg;$u++) {
			$item_no = $unseen_items[$u];
			
			//construct the IMAP message reference
			$cmd_prefix = "A" . str_pad($command_number, 3, "0", STR_PAD_LEFT);
			$command = $cmd_prefix . " FETCH " . $item_no . " body.peek[header]\r\n";
			$rheader = get_response($conn, $command);
			$header_bits = explode("\r\n",$rheader);
			$params = array("From","Date","Subject");
			$message_details = array();
			foreach($header_bits as $header_bit) {
				if(preg_match("/=\?/", $header_bit)) {
					//decode headers if they are encoded
					//usually applies to subject lines from certain clients
					$header_bit = mb_decode_mimeheader($header_bit);
				}
				$header_bit = str_replace("<br />","",$header_bit);
				$header_bit = str_replace("<","(",$header_bit);
				$header_bit = str_replace(">",")", $header_bit);
				$pieces = explode(": ", $header_bit);
				if(in_array($pieces[0], $params)) {
					$param_id = $pieces[0];
					$param_offset = strlen($param_id . ": ");
					$param_body = substr($header_bit, $param_offset);
					if($param_id == "Subject") {
						$param_body_add = iconv_mime_decode($header_bit,
				   2, "ISO-8859-1");
					} else {
						$param_body_add = trim($param_body);
					}
					$message_details[$param_id] = trim($param_body);
				} 
			}
			$msg_headers[] = $message_details;
			$command_number++;
		}
	}
	
	//log out of the IMAP server and close the connection
	$cmd_prefix = "A" . str_pad($command_number, 3, "0", STR_PAD_LEFT);
	$command = $cmd_prefix . " LOGOUT\r\n";
	$outcome = get_response($conn, $command);
	fclose($conn);
	
	//build an array of the data returned
	$userdata["unseen"] = count($unseen_items);
	$userdata["unseen_messages"] = $unseen_items;
	if(count($msg_headers)>0) {
		$userdata["unseen_message_details"] = $msg_headers;
	}
	
	//return the data in JSON format
	echo json_encode($userdata);
	
	
	
} else {
	//show the login form if we don't have the details we need
	echo "<!DOCTYPE html>";
	echo "<head><title>mail</title>";
	echo "<style>
	body {font-family:sans-serif;}
	.panel {background: #efefef; padding: 10px; color: #333;}
	li {padding-bottom: 10px;}
	</style>";
	echo "</head>";
	echo "<body>";
	echo "<h1>mail</h1>";
	echo "<p>Please <strong>post</strong> or <strong>get</strong> the required parameters to call this service.</p>";
	echo "<div class=\"panel\">";
	echo "<h2>Required parameters</h2>";
	echo "<ul>";
	echo "<li><strong>username</strong><br />Email username</li>";
	echo "<li><strong>password</strong><br />Email password</li>";
	echo "</ul>";
	echo "<h2>Response</h2>";
	echo "<p>JSON which contains a summary of the user's email inbox.</p>";
	echo "<p><strong>Example</strong><br/>{ \"name\" : \"ano23\" , \"total\" : 240 , \"unseen\" : 2 , \"unseen_messages\" : [ 239 , 240 ] , \"unseen_message_details\" : [{ \"From\" : \"Jo Brooks\" , \"Subject\" : \"RE: hello\" , \"Date\" : \"Thu, 18 Jul 2013 15:58:03 +0100\" } , { \"From\" : \"Harry Field\" , \"Subject\" : \"Lose weight fast!\" , \"Date\" : \"Wed, 17 Jul 2013 12:35:26 +0100\" } ] }</p>";
	if($showloginform) {
		echo "<h2>Test</h2>";
		echo "<p>Fill in this form to call the service manually.</p>";
		echo "<form method=\"post\">";
		echo "username<br/>";
		echo "<input name=\"username\" type=\"text\" /><br/><br/>";
		echo "password<br/>";
		echo "<input name=\"password\" type=\"password\" /><br/><br/>";
		echo "<input type=\"submit\" value=\"go\" />";
	}
	echo "</div>";
	echo "</body></html>";
}
	
	

	
?>