<?php

/**
 * Class to handle all db operations
 * This class will have CRUD methods for database tables
 *
 * @author Ravi Tamada
 */
class DbHandler {

    private $conn;

    function __construct() {
        require_once dirname(__FILE__) . '/dbconnect.php';
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
    }

    /* ------------- `sp_users` table method ------------------ */

    /**
     * Creating new user
     * @param String $username Display name of user
     * @param String $androidID ID of android user
     * @param String $phoneNumber Phone number of user if applicable
     */
    public function createUser($username, $androidID, $phoneNumber) {
        $response = array();

        //Is the username taken?
        if (!$this->UserExists($username)) {

            //Generate an API key for server-client interactions.
            $apiKey = $this->generateApiKey($username, $androidID);

            //Insert upon success.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_users(user_name, user_apikey, user_phone, user_likessent, user_likesreceived)VALUES(?,?,?,0,0)");
            $stmt->bind_param("sss", $username, $apiKey, $phoneNumber);

            $result = $stmt->execute();
            $stmt->close();

            if ($result) {
                return USER_CREATED_SUCCESSFULLY;
            } else {
                return USER_CREATE_FAILED;
            }
        } else {
            return USER_ALREADY_EXISTED;
        }
        return $response;
    }

    /**
     * Check for unique username
     * @param String $username Name to check for in database
     * @return boolean
     */
    private function userExists($username) {
        $stmt = $this->conn->prepare("SELECT user_id from SeniorProject.sp_users WHERE user_name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Fetching user id by api key
     * @param String $api_key user api key
     */
    public function getUserId($api_key) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        if ($stmt->execute()) {
            $user_id = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $user_id;
        } else {
            return NULL;
        }
    }

    /**
     * Validating user api key
     * If the api key is there in db, it is a valid key
     * @param String $api_key user api key
     * @return boolean
     */
    public function isValidApiKey($api_key) {
        $stmt = $this->conn->prepare("SELECT id from users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Generating unique key for server-client interactions
     * @param String $username Usernames are guaranteed to be unique
     * @param String $androidID ANDROID_ID is usually unique
     */
    private function generateApiKey($username, $androidID) {
        return password_hash($username . $androidID, PASSWORD_DEFAULT);
    }

    /**
     * If the username + androidID hash matches up with what's in the DB,
     * Update the key and return the API key to the user.
     * accounts.
     * @param String $username
     * @param String $androidID
     */
    public function updateApiKey($username, $androidID) {
        $stmt = $this->conn->prepare("SELECT user_apikey FROM SeniorProject.sp_users WHERE sp_username = ?");
        $stmt->bind_param("s", $username);
        if ($stmt->execute()) {
            $apiKey = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (password_verify($username . $androidID, $apiKey)) {
                $apiKey = generateApiKey($username,$androidID); //Generate a new API Key
                $stmt = $this->conn->prepare("UPDATE SET SeniorProject.sp_users user_apikey = ? WHERE user_name = ?");
                $stmt->bind_param("ss", $apikey, $username);
                if($stmt->execute()){
                    $stmt->close();
                    return $apiKey;
                } else {
                    $stmt->close();
                    return NULL;
                }
            }
        } else {
            return NULL;
        }
    }

}

?>