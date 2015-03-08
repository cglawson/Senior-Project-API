<?php

/**
 * Where the API meets the road.
 * 
 * @author Caleb Lawson <caleb@lawson.rocks>
 */
require_once '../include/dbhandler.php';
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
        $response["error"] = true;
        $response["message"] = 'Required field(s) ' . substr($error_fields, 0, -2) .
                ' are missing or empty';
        echoRespnse(400, $response);
        $app->stop();
    }
}

/**
 * Echoing json response to client.
 * @param String $status_code Http response code.
 * @param Int $response Json response.
 */
function echoRespnse($status_code, $response) {
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
    if (isset($headers['Authorization'])) {
        $db = new DbHandler();

        // get the api key
        $apiKey = $headers['Authorization'];
        // validating api key
        if (!$db->isValidApiKey($apiKey)) {
            // api key is not present in users table
            $response["error"] = true;
            $response["message"] = "Invalid API key.  Access denied.";
            echoRespnse(401, $response);
            $app->stop();
        } else {
            global $user_id;
            // get user primary key id
            $user_id = $db->getUserId($apiKey);
        }
    } else {
        // api key is missing in header
        $response["error"] = true;
        $response["message"] = "API key missing.";
        echoRespnse(400, $response);
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
    verifyRequiredParams(array('username', 'uniqueID'));

    $response = array();

    // reading post params
    $username = strtolower($app->request->post('username'));
    $uniqueID = $app->request->post('uniqueID');

    $db = new DbHandler();
    $res = $db->createUser($username, $uniqueID);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "An exception has occured!";
    } else if ($res == ALREADY_EXISTS) {
        $response["message"] = "Username already registered.";
    } else {
        $response["message"] = "Successfully registered.";
        $response["apikey"] = $res;
    }
    // echo json response
    echoRespnse(201, $response);
});

/**
 * Refresh API Key
 * url - /update_key
 * method - POST
 * params - username, uniqueID
 */
$app->post('/update_key', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('username', 'uniqueID'));

    $response = array();

    // reading post params
    $username = strtolower($app->request->post('username'));
    $uniqueID = $app->request->post('uniqueID');

    $db = new DbHandler();
    $res = $db->updateApiKey($username, $uniqueID);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "An exception has occured!";
    } else {
        $response["message"] = "Successfully updated.";
        $response["apikey"] = $res;
    }
    // echo json response
    echoRespnse(201, $response);
});

/**
 * ------------------------ METHODS WITH AUTHENTICATION ------------------------
 */
/**
 * Update username.
 * method POST
 * url /update_username
 */
$app->post('/update_username', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('newUsername', 'uniqueID'));

    $response = array();

    // reading post params
    $newUsername = strtolower($app->request->post('newUsername'));
    $uniqueID = $app->request->post('uniqueID');

    global $user_id;
    $db = new DbHandler();
    $res = $db->updateUsername($user_id, $newUsername, $uniqueID);

    if ($res["status"] == OPERATION_SUCCESS) {
        $response["status"] = OPERATION_SUCCESS;
        $response["message"] = "Successfully updated username.";
        $response["newUsername"] = $res["newUsername"];
        $response["newApiKey"] = $res["newApiKey"];
    } else if ($res["status"] == TIME_CONSTRAINT) {
        $response["status"] = TIME_CONSTRAINT;
        $response["message"] = "A username can only be changed every 14 days";
        $response["days_remaining"] = $res["days_remaining"];
    } else if ($res["status"] == ALREADY_EXISTS) {
        $response["status"] = ALREADY_EXISTS;
        $response["message"] = "Another user already has this name.";
    } else if ($res["status"] == INVALID_CREDENTIALS) {
        $response["status"] = INVALID_CREDENTIALS;
        $response["message"] = "One of your credentials is invalid.";
    } else {
        $response["message"] = "Unexpected Result.";
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * Update location.
 * method POST
 * url /update_location
 */
$app->post('/update_location', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('latitude', 'longitude'));

    $response = array();

    // reading post params
    $latitude = $app->request->post('latitude');
    $longitude = $app->request->post('longitude');

    global $user_id;
    $db = new DbHandler();
    $res = $db->updateLocation($user_id, $latitude, $longitude);

    if ($res == OPERATION_SUCCESS) {
        $response["message"] = "Successfully updated.";
    } else {
        $response["message"] = "An exception has occured!";
    }
    // echo json response
    echoRespnse(201, $response);
});

/**
 * Delete location.
 * method POST
 * url /delete_location
 */
$app->post('/delete_location', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array());

    $response = array();

    global $user_id;
    $db = new DbHandler();
    $res = $db->deleteLocation($user_id);

    if ($res == OPERATION_SUCCESS) {
        $response["message"] = "Successfully deleted.";
    } else {
        $response["message"] = "An exception has occured!";
    }
    // echo json response
    echoRespnse(201, $response);
});

/**
 * List all nearby users.
 * method POST
 * url /get_nearby
 */
$app->post('/get_nearby', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('latitude', 'longitude'));

    global $user_id;
    $response = array();
    $response["nearby users"] = [];

    // reading post params
    $latitude = $app->request->post('latitude');
    $longitude = $app->request->post('longitude');

    $db = new DbHandler();
    $res = $db->nearbyUsers($latitude, $longitude, $user_id);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["message"] = "Success!";

        while ($user = $res->fetch_assoc()) {
            $tmp = array();
            $tmp["user_location_id"] = $user["user_location_id"];
            array_push($response["nearby users"], $tmp);
        }
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * Request a user's friendship.
 * method POST
 * url /add_friend
 */
$app->post('/add_friend', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('friendID'));

    $response = array();

    // reading post params
    $friendID = $app->request->post('friendID');

    global $user_id;
    $db = new DbHandler();
    $res = $db->addFriend($user_id, $friendID);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["message"] = "Success!";
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * Remove a user's friendship.
 * method POST
 * url /remove_friend
 */
$app->post('/remove_friend', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('friendID'));

    $response = array();

    // reading post params
    $friendID = $app->request->post('friendID');

    global $user_id;
    $db = new DbHandler();
    $res = $db->removeFriend($user_id, $friendID);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["message"] = "Success!";
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * List the friends of a particular user.
 * method GET
 * url /get_friends
 */
$app->get('/get_friends', 'authenticate', function() {

    $response = array();
    $response["friends"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getFriends($user_id);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["friends"] = $res;
    }


    // echo json response
    echoRespnse(201, $response);
});

/**
 * List the users you have pending friendships with.
 * method GET
 * url /get_pending
 */
$app->get('/get_pending', 'authenticate', function() {
    $response = array();
    $response["friends"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getPendingRequests($user_id);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["friends"] = $res;
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * List the users you have pending friendships with.
 * method GET
 * url /get_requests
 */
$app->get('/get_requests', 'authenticate', function() {
    $response = array();
    $response["friend requests"] = [];

    global $user_id;
    $db = new DbHandler();
    $res = $db->getFriendRequests($user_id);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["friend requests"] = $res;
    }

    // echo json response
    echoRespnse(201, $response);
});

/**
 * Boop a user.
 * method POST
 * url /boop_user
 */
$app->post('/boop_user', 'authenticate', function() use ($app) {
    // check for required params
    verifyRequiredParams(array('targetid'));

    $response = array();

    // reading post params
    $targetID = $app->request->post('targetid');

    global $user_id;
    $db = new DbHandler();
    $res = $db->boopUser($user_id, $targetID);

    if ($res == OPERATION_FAILED) {
        $response["message"] = "Fail!";
    } else {
        $response["message"] = "Success!";
    }

    // echo json response
    echoRespnse(201, $response);
});


$app->run();
?>