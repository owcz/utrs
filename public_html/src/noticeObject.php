<?php

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once('exceptions.php');
require_once('unblocklib.php');
require_once('userObject.php');

class Notice{
	private $messageId;
	private $message;
	private $author;
	private $lastEditTime;
	private static $colorCodes = "(red|green|blue|yellow|orange|purple|gray|grey|#[0-9a-f]{3,3}|#[0-9a-f]{6,6})";
	
	public function __construct(array $vars, $fromDB){
		if($fromDB){
			$this->messageId = $vars['messageID'];
			$this->message = $vars['message'];
			$this->author = User::getUserById($vars['author']);
			$this->lastEditTime = $vars['time'];
		}
		else{
			$mess = sanitizeText($vars['message']);

			$this->validate($mess);
			
			$this->message = sanitizeText($mess);
			$this->author = getCurrentUser();
			
			$this->insert();
		}
	}
	
	private function validate($message){
		if(strlen($message) > 2048){
			throw new UTRSIllegalModificationException("Your message is too long to store in the database. " .
				"Please shorten your message to less than 2048 characters. (Current length: " . strlen($mess) . ")");
		}
		
		$syntaxCodes = array();
		$syntaxIndex = 0;
		
		// scan through the string and be sure no formatting overlaps
		// *this /is not* ok/ - it'll break the page
		// *this /is/ ok* - that'll display correctly
		for($i = 0; $i < strlen($message); $i++){
			$char = substr($message, $i, 1); // get each character
			
			if($char == '*' || $char == '/' || $char == '_'){
				if($syntaxCodes[$syntaxIndex - 1] == $char){
					// if the last syntax token encountered matches
					// remove it from the stack
					unset($syntaxCodes[$syntaxIndex - 1]);
					$syntaxIndex--;
				}
				else{
					// else, see if it exists in the stack
					$this->checkForExistingToken($syntaxCodes, $char);
					// if not, add it to the stack
					$syntaxCodes[$syntaxIndex] = $char;
					$syntaxIndex++;
				}
			}
			else if($char == '}'){
				if($syntaxCodes[$syntaxIndex - 1] == '{'){
					// if the last syntax token encountered starts
					// a link, remove it
					unset($syntaxCodes[$syntaxIndex - 1]);
					$syntaxIndex--;
				}
				else{
					// else, see if it exists in the stack
					$this->checkForExistingToken($syntaxCodes, '{');
					// if not, add it to the stack
					$syntaxCodes[$syntaxIndex] = $char;
					$syntaxIndex++;
				}
			}
			else if($char == '{'){
				if(substr($message, $i + 1, 4) == "http"){
					//make sure we aren't already in a link
					checkForExistingToken($syntaxCodes, '{');
					// advance loop to next space to avoid issues with
					// italics and / signs in the url
					$i = strpos($message, ' ', $i);
					// add link to the stack
					$syntaxCodes[$syntaxIndex] = $char;
					$syntaxIndex++;
				}
				// if next four characters aren't http, ignore
			}
			else if($char == '['){
				$end = strpos($message, ']', $i);
				if($end !== false){
					if(substr($message, $i + 1, 1) != '/'){
						// if opening a color tag
						$color = substr($message, $i + 1, ($end - 1) - $i);
						// make sure it's a valid color
						if($color !== false && preg_match('~^' . $this->colorCodes . '$~i', $color)){
							// add to stack
							$syntaxCodes[$syntaxIndex] = '[/' . $color . ']';
							$syntaxIndex++;
							// advance loop to save time
							$i = $end; 
						}
					}
					else{
						// if closing a color tag
						$color = substr($message, $i + 2, ($end - 2) - $i);
						// make sure it's a valid color
						if($color !== false && preg_match('~^' . $this->colorCodes . '$~i', $color)){
							// if on top of stack, remove
							if($syntaxCodes[$syntaxIndex] == '[/' . $color . ']'){
								unset($syntaxCodes[$syntaxIndex]);
								$syntaxIndex--;
								// advance loop to save time
								$i = $end; 
							}
							else{
								checkForExistingToken($syntaxCodes, '[/' . $color . ']');
							}
						}
					}
				}
			}
		}
		// if we get down here with no exceptions, it's good to go.
	}
	
	private function checkForExistingToken($syntaxCodes, $match){
		$syntaxError = "Your message contains overlapping formatting which will not render properly. Formatting" .
			" sections opened within another formatting section must be closed before the preceding one is closed." .
			" For example, '<tt>this /string *is* formatted/ correctly</tt>', however " .
			"'<tt>this *string /is* not/ correct</tt>' because the bold section ends before the italic section does." .
			" Furthermore, links may not contain other links.";

		for($j = 0; $j < sizeOf($syntaxCodes); $j++){
			if($syntaxCodes[$j] == $match){
				throw new UTRSIllegalModificationException($syntaxError);
			}
		}
	}
	
	private function insert(){
				
		$db = connectToDB();
		
		$query = "INSERT INTO sitenotice (message, author) VALUES ('" . 
				mysql_escape_string($this->message) . "', '" . $this->author->getUserId() . "')";
		
		debug($query);
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
		
		$this->messageId = mysql_insert_id($db);
		
		$query = "SELECT time FROM sitenotice WHERE messageID='" . $this->messageId . "'";
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
		
		$data = mysql_fetch_assoc($result);
		
		$this->lastEditTime = $data['time'];
	}
	
	public function update($message){
		$message = sanitizeText($message);
		
		$db = connectToDB();
		
		$query = "UPDATE sitenotice SET message = '" . mysql_escape_string($message) . 
			"', author = '" . getCurrentUser()->getUserId() . "' WHERE messageID = '" . 
			$this->messageId . "'";
		
		debug($query);
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
		
		$this->message = $message;
		$this->author = getCurrentUser();
		
		$query = "SELECT time FROM sitenotice WHERE messageID='" . $this->messageId . "'";
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
		
		$data = mysql_fetch_assoc($result);
		
		$this->lastEditTime = $data['time'];
	}
	
	public static function delete($messageId){
		$query = "DELETE FROM sitenotice WHERE messageID='" . $messageId . "'";
		
		debug($query);
		
		$db = connectToDB();
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
	}
	
	public function getMessageId(){
		return $this->messageId;
	}
	
	public function getMessage(){
		return $this->message;
	}
	
	public function getAuthor(){
		return $this->author;
	}
	
	public function getLastEditTime(){
		return $this->lastEditTime;
	}
	
	public function getFormattedMessage(){
		return $this->format($this->message);
	}
	
	public static function format($string){
		$string = sanitizeText($string);
		$this->validate($string);
		
		// while we have matching color tokens...
		while(preg_match('~^.*?\[' . $this->colorCodes . '\].+?\[/\1\].*?$~i', $string)){
			// handle [red]color[/red]
			// supported tags: red, orange, yellow, green, blue, purple, grey, gray, three- or six-digit hex code
			$string = preg_replace(
			'~\[' . $this->colorCodes . '\](.+?)\[/\1\]~i',
			'<span style="color:$1">$2</span>', 
			$string);
		}
		// handle {http://enwp.org links}
		$string = preg_replace('/\{http(\S+?) (.+?)\}/', '<a href="http$1">$2</a>', $string);
		// handle /italics/
		$string = preg_replace('#([^<:/])/(.+?)([^<:/])/#', '$1<i>$2$3</i>', $string);
		// handle *bolds*
		$string = preg_replace('/\*(.+?)\*/', '<b>$1</b>', $string);
		// handle _underlines_
		$string = preg_replace('/_(.+?)_/', '<u>$1</u>', $string);
			
		return $string;
	}
	
	public static function getNoticeById($messageId){
		$query = "SELECT * FROM sitenotice WHERE messageId = '" . $messageId . "'";
		
		debug($query);
		
		$db = connectToDB();
		
		$result = mysql_query($query, $db);
		
		if(!$result){
			$error = mysql_error($db);
			debug('ERROR: ' . $error . '<br/>');
			throw new UTRSDatabaseException($error);
		}
		
		return new Notice(mysql_fetch_assoc($result), true);
	}
}

?>