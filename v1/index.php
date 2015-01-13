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
        $api_key = $headers['Authorization'];
        // validating api key
        if (!$db->isValidApiKey($api_key)) {
            // api key is not present in users table
            $response["error"] = true;
            $response["message"] = "Invalid API key.  Access denied.";
            echoRespnse(401, $response);
            $app->stop();
        } else {
            global $user_id;
            // get user primary key id
            $user_id = $db->getUserId($api_key);
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
 * params - username, androidID, phoneNumber
 */
$app->post('/register', function() use ($app) {
            // check for required params
            verifyRequiredParams(array('username', 'androidID', 'phoneNumber'));

            $response = array();

            // reading post params
            $username = $app->request->post('username');
            $androidID = $app->request->post('androidID');
            $phoneNumber = $app->request->post('phoneNumber');

            $db = new DbHandler();
            $res = $db->createUser($username, $androidID, $phoneNumber);

            if ($res == USER_CREATED_SUCCESSFULLY) {
                $response["error"] = false;
                $response["message"] = "You have sucessfully registered!";
            } else if ($res == USER_CREATE_FAILED) {
                $response["error"] = true;
                $response["message"] = "Oops, an exception has occured!";
            } else if ($res == USER_ALREADY_EXISTED) {
                $response["error"] = true;
                $response["message"] = "Sorry, either that username is taken, "
                        . "or you have already registered.";
            }
            // echo json response
            echoRespnse(201, $response);
        });

/**
 * ------------------------ METHODS WITH AUTHENTICATION ------------------------
 */

$app->run();
?>