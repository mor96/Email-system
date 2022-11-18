
<?php
// This code is based on the code from class examples in CSC302,
// but has been modified to conform to this project.
header('Content-type: application/json');

// For debugging:
error_reporting(E_ALL);
ini_set('display_errors', '1');

// TODO Change this as needed. SQLite will look for a file with this name, or
// create one if it can't find it.
$dbName = 'mail.db';

session_start();

// Leave this alone. It checks if you have a directory named www-data in
// you home directory (on a *nix server). If so, the database file is
// sought/created there. Otherwise, it uses the current directory.
// The former works on digdug where I've set up the www-data folder for you;
// the latter should work on your computer.
$matches = [];
preg_match('#^/~([^/]*)#', $_SERVER['REQUEST_URI'], $matches);
$homeDir = count($matches) > 1 ? $matches[1] : '';
$dataDir = "/home/$homeDir/www-data";
if(!file_exists($dataDir)){
    $dataDir = __DIR__;
}
$dbh = new PDO("sqlite:$dataDir/$dbName")   ;
// Set our PDO instance to raise exceptions when errors are encountered.
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Put your other code here.

createTables();

$supportedActions = [
    'signup', 'signin', 'getTable', 'sendEmail','getEmails', 'starEmail','dumpEmail'
];

//This code taken from class example and been modifeid 
// Handle incoming requests.
if(array_key_exists('action', $_POST)){
    $action = $_POST['action'];
    if(array_search($_POST['action'], $supportedActions) !== false){
        $_POST['action']($_POST);
    } else {
        die(json_encode([
            'success' => false, 
            'error' => 'Invalid action: '. $action
        ]));
    }
}

if(array_key_exists('action', $_GET)){
    $action = $_GET['action'];
    if(array_search($_GET['action'], $supportedActions) !== false){
        $_GET['action']($_GET);
    } else {
        die(json_encode([
            'success' => false, 
            'error' => 'Invalid action: '. $action
        ]));
    }
}


function createTables(){
    global $dbh;

    try{
        // Create the Users table.
        $dbh->exec('create table if not exists Users('. 
            'userId integer primary key autoincrement, '. 
            'username text unique, '. 
            'fname text, '. 
            'lname text, '. 
            'password text, '. 
            'createdAt datetime default(datetime()))');

        // Create the mails table.
        $dbh->exec('create table if not exists mails('. 
            'messageId integer primary key autoincrement, '. 
            'senderId integer, '. 
            'receiverId integer, '. 
            'message text, '. 
            'starred integer, '. 
            'sentAt datetime default(datetime()), '. 
            'foreign key (senderId) references Users(id), '. 
            'foreign key (receiverId) references Users(id))');

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error creating the tables: $e"
        ]));
    }
}


function error($message, $responseCode=400){
    http_response_code($responseCode);
    die(json_encode([
        'success' => false, 
        'error' => $message
    ]));
}



function authenticate($username, $password){
    global $dbh;

    // check that username and password are not null.
    if($username == null || $password == null){
        error('Bad request -- both a username and password are required');
    }

    // grab the row from Users that corresponds to $username
    try {
        $statement = $dbh->prepare('select password from Users '.
            'where username = :username');
        $statement->execute([
            ':username' => $username,
        ]);
        $passwordHash = $statement->fetch()[0];
        
        // user password_verify to check the password.
        if(password_verify($password, $passwordHash)){
            return true;
        }
        error('Could not authenticate username and password.', 401);
        

    } catch(Exception $e){
        error('Could not authenticate username and password: '. $e);
    }
}



/**
 * Checks if the user is signed in; if not, emits a 403 error.
 */
function mustBeSignedIn(){
    if(!(key_exists('signedin', $_SESSION) && $_SESSION['signedin'])){
        error("You must be signed in to perform that action.", 403);
    }
}

/**
 * Log a user in. Requires the parameters:
 *  - username
 *  - password
 * 
 * @param data An JSON object with these fields:
 *               - success -- whether everything was successful or not
 *               - error -- the error encountered, if any (only if success is false)
 */
function signin($data){
    if(authenticate($data['username'], $data['password'])){
        $_SESSION['signedin'] = true;
        $_SESSION['user-id'] = getUserByUsername($data['username'])['userId'];
        $_SESSION['username'] = $data['username']; 

        die(json_encode([
            'username' => $data['username'],
            'success' => true
        ]));
    } else {
        error('Username or password not found.', 401);
    }
}



/**
 * Logs the user out if they are logged in.
 * 
 * @param data An JSON object with these fields:
 *               - success -- whether everything was successful or not
 *               - error -- the error encountered, if any (only if success is false)
 */
function signout($data){
    session_destroy();
    die(json_encode([
        'success' => true
    ]));
}


/**
 * Adds a user to the database. Requires the parameters:
 *  - username
 * 
 * @param data An JSON object with these fields:
 *               - success -- whether everything was successful or not
 *               - id -- the id of the user just added (only if success is true)
 *               - error -- the error encountered, if any (only if success is false)
 */
function signup($data){
    global $dbh;

    $saltedHash = password_hash($data['password'], PASSWORD_BCRYPT);

    try {
        $statement = $dbh->prepare('insert into Users(fname, lname, username, password) '.
            'values (:fname, :lname, :username, :password)');
        $statement->execute([
            ':fname' => $data['fname'],
            ':lname' => $data['lname'],
            ':username' => $data['username'],
            ':password' => $saltedHash
        ]);

        $userId = $dbh->lastInsertId();
        die(json_encode([
            'success' => true,
            'id' => $userId
        ]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error adding the user: $e"
        ]));
    }
}

function sendEmail($data){
    global $dbh;

    // authenticate($data['username'], $data['password']);
    mustBeSignedIn();
    //authorize($data);

    try {

        $statement = $dbh->prepare('select userId from Users '. 
        'where username = :username');
         $statement->execute([
        ':username' => $data['username']
         ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);


        $statement = $dbh->prepare('insert into mails'. 
            '(senderId, receiverId, message) values (:senderId, :receiverId, :message)');
        $statement->execute([
            ':senderId' => $_SESSION['user-id'], 
            ':receiverId' => $user['userId'],
            ':message' => $data['message']
        ]);

        die(json_encode([
            'success' => true,
        ]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error sending this message: $e"
        ]));
    }
}



/**
 * Outputs the row of the given table that matches the given id.
 */
function getTableRow($table, $data){
    global $dbh;
    try {
        $statement = $dbh->prepare("select * from $table where id = :id");
        $statement->execute([':id' => $data['id']]);
        // Use fetch here, not fetchAll -- we're only grabbing a single row, at 
        // most.
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        die(json_encode(['success' => true, 'data' => $row]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error fetching rows from table $table: $e"
        ]));
    }
}



/**
 * Looks up a user by their username. 
 * 
 * @param $username The username of the user to look up.
 * @return The user's row in the Users table or null if no user is found.
 */
function getUserByUsername($username){
    global $dbh;
    try {
        $statement = $dbh->prepare("select * from Users where username = :username");
        $statement->execute([':username' => $username]);
        // Use fetch here, not fetchAll -- we're only grabbing a single row, at 
        // most.
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row;

    } catch(PDOException $e){
        return null;
    }
}




/**
 * Outputs all the values of a database table. 
 * 
 * @param table The name of the table to display.
 */
function getTable($table){
    global $dbh;
    try {
        $statement = $dbh->prepare("select * from Users");
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        die(json_encode(['success' => true, 'data' => $rows]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error fetching rows from table $table: $e"
        ]));
    }
}


function getEmails($data){
    global $dbh;
    $method = $data['method']; 

    try {

        if($method == 'starred'){
            $method = 'receiverId';
            $statement = $dbh->prepare("select * from mails where $method = :user and starred = 1");
            $statement->execute([':user' =>  $_SESSION['user-id']]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        else {
            $statement = $dbh->prepare("select * from mails where $method = :user");
            $statement->execute([':user' =>  $_SESSION['user-id']]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        die(json_encode(['success' => true, 'data' => $rows]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error fetching rows from table $method: $e"
        ]));
    }
}



function getEmailsTest($data){
    global $dbh;
    $method = $data['method']; 

    try {

        if($method == 'starred'){
            $method = 'receiverId';
            $statement = $dbh->prepare("select * from mails where $method = :user and starred = 1");
            $statement->execute([':user' =>  $_SESSION['user-id']]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        else {
            $statement = $dbh->prepare("select * from mails inner join Users on Users.userId = mails.senderId where mails.receiverId = :user");
            $statement->execute([':user' =>  $_SESSION['user-id']]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        die(json_encode(['success' => true, 'data' => $rows]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error fetching rows from table $method: $e"
        ]));
    }
}



function starEmail($data){
    global $dbh;
    $id = $data['emailId']; 
    try {
        $statement = $dbh->prepare("select * from mails where messageId = :messageId");
        $statement->execute([':messageId' =>  $data['emailId']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if($row['starred'] == 0){
            $statement = $dbh->prepare("update mails set starred = :value where messageId = :messageId");
            $statement->execute([':messageId' =>  $data['emailId'],
                                ':value' => 1]);
        } else{
            $statement = $dbh->prepare("update mails set starred = 0 where messageId = :messageId");
            $statement->execute([':messageId' =>  $data['emailId']]);
        }
        die(json_encode(['success' => true]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error starring this email: $e"
        ]));
    }
}



function dumpEmail($data){
    global $dbh;
    $id = $data['emailId']; 
    try {
        $statement = $dbh->prepare("delete from mails where messageId = :messageId");
        $statement->execute([':messageId' =>  $data['emailId']]);
        die(json_encode(['success' => true]));

    } catch(PDOException $e){
        http_response_code(400);
        die(json_encode([
            'success' => false, 
            'error' => "There was an error deleting this email: $e"
        ]));
    }
}
?>