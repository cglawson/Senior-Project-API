<?php

/**
 * Where the API meets the road.
 * 
 * @author Caleb Lawson <caleb@lawson.rocks>
 */
require_once '../include/dbhandler.php';
require_once '../include/config.php';
require '.././libs/Slim/Slim.php';

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();

// User ID from database, used in conjunction with the authenticate function - Global
$user_id = NULL;

/**
 * Verifying whether required parameters are present.
 * @param Array $required_fields Array of required parameters.
 */
function verifyRequiredParams($required_fields) {
    $error = false;
    $error_fields = "";
    $request_params = array();
    $request_params = $_REQUEST;
    // Handling PUT request parameters.
    if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
        $app = \Slim\Slim::getInstance();
        parse_str($app->request()->getBody(), $request_params);
    }
    foreach ($required_fields as $field) {
        if (!isset($request_params[$field]) || strlen(trim($request_params[$field])) <= 0) {
            $error = true;
            $error_fields .= $field . ', ';
        }
    }

    if ($error) {
        // Required field(s) are missing or empty.
        // Echo error json and stop the app.
        $response = array();
        $app = \Slim\Slim::getInstance();
        $response["status"] = OPERATION_FAILED;
        $response["required fields"] = substr($error_fields, 0, -2);
        echoResponse(400, $response);
        $app->stop();
    }
}

/**
 * Echoing json response to client.
 * @param String $status_code Http response code.
 * @param Int $response Json response.
 */
function echoResponse($status_code, $response) {
    $app = \Slim\Slim::getInstance();
    // Http response code
    $app->status($status_code);

    // setting response content type to json
    $app->contentType('application/json');

    echo json_encode($response);
}

/**
 * Adding Middle Layer to authenticate every request.
 * Checking if the request has valid API key in the 'Authorization' header.
 */
function authenticate(\Slim\Route $route) {
    // Getting request headers
    $headers = apache_request_headers();
    $response = array();
    $app = \Slim\Slim::getInstance();

    // Verifying Authorization Header
    if (isset($headers['authorization'])) {
        $db = new DbHandler();

        // get the api key
        $apiKey = $headers['authorization'];
        // validating api key
        if (!$db->isValidApiKey($apiKey)) {
            // api key is not present in users table
            $response["status"] = INVALID_CREDENTIALS;
            echoResponse(401, $response);
            $app->stop();
        } else {
            global $user_id;
            // get user primary key id
            $user_id = $db->getUserId($apiKey);
        }
    } else {
        // api key is missing in header
        $response["status"] = OPERATION_FAILED;
        echoResponse(400, $response);
        $app->stop();
    }
}

/**
 * ----------- METHODS WITHOUT AUTHENTICATION ---------------------------------
 */
/**
 * User Registration
 * url - /register
 * method - POST
 * params - username, uniqueID
 */
$app->post('/register', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('username', 'uid'));

    $response = array();

    // reading post params
    $username = strtolower($app->request->post('username'));
    $uniqueID = $app->request->post('uid');

    $db = new DbHandler();
    $res = $db->createUser($username, $uniqueID);

    if ($res["status"] == ALREADY_EXISTS) {
        $response["status"] = ALREADY_EXISTS;
    } else {
        $response["status"] = OPERATION_SUCCESS;
        $response["apikey"] = $res["apikey"];
    }

    // echo json response
    echoResponse(201, $response);
});

/**
 * Refresh API Key
 * url - /refresh_apikey
 * method - PUT
 * params - username, uniqueID
 */
$app->put('/refresh_apikey', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('username', 'uid'));

    $response = array();

    // reading post params
    $username = strtolower($app->request->put('username'));
    $uniqueID = $app->request->put('uid');

    $db = new DbHandler();
    $res = $db->updateApiKey($username, $uniqueID);

    if ($res["status"] == DOES_NOT_EXIST) {
        $response["status"] = DOES_NOT_EXIST;
    } else if ($res["status"] == INVALID_CREDENTIALS) {
        $response["status"] = INVALID_CREDENTIALS;
    } else {
        $response["status"] = OPERATION_SUCCESS;
        $response["apikey"] = $res["apikey"];
    }

    // echo json response
    echoResponse(201, $response);
});

/**
 * ------------------------ METHODS WITH AUTHENTICATION ------------------------
 */
/**
 * Update username.
 * method PUT
 * url /username
 */
$app->put('/username', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('newUsername', 'uid'));

    $response = array();

    // reading post params
    $newUsername = strtolower($app->request->put('newUsername'));
    $uniqueID = $app->request->put('uid');

    global $user_id;
    $db = new DbHandler();
    $res = $db->updateUsername($user_id, $newUsername, $uniqueID);

    if ($res["status"] == OPERATION_SUCCESS) {
        $response["status"] = OPERATION_SUCCESS;
        $response["new username"] = $res["newUsername"];
        $response["new apikey"] = $res["newApiKey"];
    } else if ($res["status"] == TIME_CONSTRAINT) {
        $response["status"] = TIME_CONSTRAINT;
        $response["days remaining"] = $res["days_remaining"];
    } else if ($res["status"] == ALREADY_EXISTS) {
        $response["status"] = ALREADY_EXISTS;
    } else if ($res["status"] == INVALID_CREDENTIALS) {
        $response["status"] = INVALID_CREDENTIALS;
    } else {
        $response["status"] = OPERATION_FAILED;
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * Update location.
 * method PUT
 * url /location
 */
$app->put('/location', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('latitude', 'longitude'));

    $response = array();

    // reading post params
    $latitude = $app->request->put('latitude');
    $longitude = $app->request->put('longitude');

    global $user_id;
    $db = new DbHandler();
    $res = $db->updateLocation($user_id, $latitude, $longitude);

    $response["status"] = OPERATION_SUCCESS;

    // echo json response
    echoResponse(200, $response);
});

/**
 * Delete location.
 * method DELETE
 * url /location
 */
$app->delete('/location', 'authenticate', function() use ($app) {
    $response = array();

    global $user_id;
    $db = new DbHandler();
    $res = $db->deleteLocation($user_id);

    $response["status"] = OPERATION_SUCCESS;

    // echo json response
    echoResponse(200, $response);
});

/**
 * List all nearby users.
 * method GET
 * url /nearby
 */
$app->post('/nearby', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('latitude', 'longitude'));

    global $user_id;
    $response = array();
    $response["nearby users"] = [];

    // reading post params
    $latitude = $app->request->get('latitude');
    $longitude = $app->request->get('longitude');

    $db = new DbHandler();
    $res = $db->nearbyUsers($latitude, $longitude, $user_id);

    $response["status"] = OPERATION_SUCCESS;

    while ($user = $res["nearby users"]->fetch_assoc()) {
        $tmp = array();
        $tmp["user id"] = $user["user_id"];
        $tmp["username"] = $user["user_name"];
        $tmp["user received score"] = $user["user_receivedscore"];
        $tmp["user sent score"] = $user["user_sentscore"];
        array_push($response["nearby users"], $tmp);
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * Request a user's friendship.
 * method POST
 * url /friend
 */
$app->post('/friend', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('target'));

    $response = array();

    // reading post params
    $target = $app->request->post('target');

    global $user_id;
    $db = new DbHandler();
    $res = $db->addFriend($user_id, $target);

    if ($res["status"] == OPERATION_FAILED) {
        $response["status"] = OPERATION_FAILED;
    } else if ($res["status"] == ALREADY_EXISTS) {
        $response["status"] = ALREADY_EXISTS;
    } else {
        $response["status"] = OPERATION_SUCCESS;
    }

    // echo json response
    echoResponse(201, $response);
});

/**
 * Request a user's friendship by username.
 * method POST
 * url /friend_username
 */
$app->post('/friend_username', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('target'));

    $response = array();

    // reading post params
    $target = $app->request->post('target');

    global $user_id;
    $db = new DbHandler();
    $res = $db->addFriendByUsername($user_id, $target);

    if ($res["status"] == OPERATION_FAILED) {
        $response["status"] = OPERATION_FAILED;
    } else if ($res["status"] == DOES_NOT_EXIST) {
        $response["status"] = DOES_NOT_EXIST;
    } else if ($res["status"] == ALREADY_EXISTS) {
        $response["status"] = ALREADY_EXISTS;
    } else {
        $response["status"] = OPERATION_SUCCESS;
    }

    // echo json response
    echoResponse(201, $response);
});

/**
 * Remove a user's friendship.
 * method DELETE
 * url /friend
 */
$app->delete('/friend/:id', 'authenticate', function($target) use ($app) {
    $response = array();

    global $user_id;
    $db = new DbHandler();
    $res = $db->removeFriend($user_id, $target);

    $response["status"] = OPERATION_SUCCESS;

    // echo json response
    echoResponse(200, $response);
});

/**
 * List the friends of a particular user.
 * method GET
 * url /friends
 */
$app->get('/friend', 'authenticate', function() {

    $response = array();
    $response["friends"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getFriends($user_id);

    $response["status"] = OPERATION_SUCCESS;

    while ($friend = $res->fetch_assoc()) {
        $tmp = array();
        $tmp["user id"] = $friend["user_id"];
        $tmp["username"] = $friend["user_name"];
        $tmp["user received score"] = $friend["user_receivedscore"];
        $tmp["user sent score"] = $friend["user_sentscore"];

        array_push($response["friends"], $tmp);
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * List the users you have pending friendships with.
 * method GET
 * url /outgoing_requests
 */
$app->get('/outgoing_requests', 'authenticate', function() {
    $response = array();
    $response["pending"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getPendingRequests($user_id);

    $response["status"] = OPERATION_SUCCESS;

    while ($pend = $res->fetch_assoc()) {
        $tmp = array();
        $tmp["user id"] = $pend["user_id"];
        $tmp["username"] = $pend["user_name"];
        $tmp["user received score"] = $pend["user_receivedscore"];
        $tmp["user sent score"] = $pend["user_sentscore"];

        array_push($response["pending"], $tmp);
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * List the users you have pending friendships with.
 * method GET
 * url /incoming_requests
 */
$app->get('/incoming_requests', 'authenticate', function() {
    $response = array();
    $response["requests"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getFriendRequests($user_id);

    $response["status"] = OPERATION_SUCCESS;

    while ($requ = $res->fetch_assoc()) {
        $tmp = array();
        $tmp["user id"] = $requ["user_id"];
        $tmp["username"] = $requ["user_name"];
        $tmp["user received score"] = $requ["user_receivedscore"];
        $tmp["user sent score"] = $requ["user_sentscore"];

        array_push($response["requests"], $tmp);
    }
    // echo json response
    echoResponse(200, $response);
});

/**
 * Boop a user.
 * method POST
 * url /boop
 */
$app->post('/boop', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('target'));

    $response = array();

    // reading post params
    $target = $app->request->post('target');

    global $user_id;
    $db = new DbHandler();
    $res = $db->boopUser($user_id, $target);

    if ($res["status"] == TIME_CONSTRAINT) {
        $response["status"] = TIME_CONSTRAINT;
    } else if ($res["status"] == OPERATION_FAILED) {
        $response["status"] = OPERATION_FAILED;
    } else {
        $response["status"] = OPERATION_SUCCESS;
        $response["timestamp"] = $res["timestamp"];
        $response["initiator boop value"] = $res["initiator boop value"];
        $response["target boop value"] = $res["target boop value"];
        $response["initiator elixirs used"] = $res["initiator elixirs used"];
        $response["target elixirs used"] = $res["target elixirs used"];
        $response["rewards"] = $res["reward"];
    }
    // echo json response
    echoResponse(201, $response);
});

/**
 * Get boops sent to you since last check.
 * method GET
 * url /boop
 */
$app->get('/boop', 'authenticate', function() {

    $response = array();

    global $user_id;
    $db = new DbHandler();
    $res = $db->getBoopsSinceChecked($user_id);

    $response = $res;

    // echo json response
    echoResponse(200, $response);
});
$app->run();

/**
 * List the inventory of a particular user.
 * method GET
 * url /inventory
 */
$app->get('/inventory', 'authenticate', function() {

    $response = array();
    $response["inventory"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getInventory($user_id);

    $response["status"] = OPERATION_SUCCESS;
    while ($inv = $res["inventory"]->fetch_assoc()) {
        $tmp = array();
        $tmp["id"] = $inv["elixir_id"];
        $tmp["type"] = $inv["elixir_type"];
        $tmp["name"] = $inv["elixir_name"];
        $tmp["description"] = $inv["elixir_desc"];
        $tmp["quantity"] = $inv["inventory_quantity"];
        $tmp["active"] = $inv["inventory_active"];

        array_push($response["inventory"], $tmp);
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * Set an inventory item active.
 * method PUT
 * url /inventory
 */
$app->put('/inventory', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('elixir', 'active'));

    $response = array();

    // reading post params
    $elixir = $app->request->put('elixir');
    $active = $app->request->put('active');


    global $user_id;
    $db = new DbHandler();
    $res = $db->setInventoryActive($user_id, $elixir, $active);

    if ($res["status"] == OPERATION_FAILED) {
        $response["status"] = OPERATION_FAILED;
    } else {
        $response["status"] = OPERATION_SUCCESS;
    }
    // echo json response
    echoResponse(200, $response);
});

/**
 * List the top 20 users based on sentscore.
 * method GET
 * url /top_senders
 */
$app->get('/top_senders', 'authenticate', function() {

    $response = array();
    $response["top senders"] = array();

    $db = new DbHandler();
    $res = $db->globalTopSenders(20);

    if ($res["status"] == OPERATION_SUCCESS) {
        $response["status"] = OPERATION_SUCCESS;

        while ($player = $res["top senders"]->fetch_assoc()) {
            $tmp = array();
            $tmp["username"] = $player["user_name"];
            $tmp["user sent score"] = $player["user_sentscore"];
            $tmp["user received score"] = $player["user_receivedscore"];

            array_push($response["top senders"], $tmp);
        }
    } else {
        $response["status"] = OPERATION_FAILED;
    }

    // echo json response
    echoResponse(200, $response);
});

/**
 * List the top 20 users based on receivedscore.
 * method GET
 * url /top_receivers
 */
$app->get('/top_receivers', 'authenticate', function() {

    $response = array();
    $response["top receivers"] = array();

    $db = new DbHandler();
    $res = $db->globalTopReceivers(20);

    if ($res["status"] == OPERATION_SUCCESS) {
        $response["status"] = OPERATION_SUCCESS;

        while ($player = $res["top receivers"]->fetch_assoc()) {
            $tmp = array();
            $tmp["user_name"] = $player["user_name"];
            $tmp["user_sentscore"] = $player["user_sentscore"];
            $tmp["user_receivedscore"] = $player["user_receivedscore"];

            array_push($response["top receivers"], $tmp);
        }
    } else {
        $response["status"] = OPERATION_FAILED;
    }

    // echo json response
    echoResponse(200, $response);
});
?>