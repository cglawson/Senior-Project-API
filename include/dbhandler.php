<?php

/**
 * Class handles CRUD operations with the database.
 *
 * @author Caleb Lawson <caleb@lawson.rocks>
 */
class DbHandler {

    private $conn;

    function __construct() {
        require_once dirname(__FILE__) . '/dbconnect.php';
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
    }

    /**
     * Creating new user.
     * @param String $username Display name of user.
     * @param String $androidID ID of android user.
     * @param String $phoneNumber Phone number of user if applicable.
     * @return int 
     */
    public function createUser($username, $androidID) {

        //Is the username taken?
        if (!$this->UserExists($username)) {

            //Generate an API key for server-client interactions.
            $apiKey = $this->generateApiKey($username, $androidID);

            //Insert upon success.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_users(user_name, user_apikey, user_sentscore, user_receivedscore)VALUES(?,?,0,0)");
            $stmt->bind_param("ss", $username, $apiKey);

            $result = $stmt->execute();
            $stmt->close();

            if ($result) {
                return USER_CREATED_SUCCESSFULLY;
            } else {
                return USER_CREATE_FAILED;
            }
        }
        return USER_ALREADY_EXISTED;
    }

    /**
     * Check for unique username.
     * @param String $username Name to check for in database.
     * @return boolean
     */
    private function userExists($username) {
        $stmt = $this->conn->prepare("SELECT user_id FROM SeniorProject.sp_users WHERE user_name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Fetching user ID by API key.
     * @param String $api_key User API key.
     */
    public function getUserId($api_key) {
        $stmt = $this->conn->prepare("SELECT user_id FROM SeniorProject.sp_users WHERE user_apikey = ?");
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
     * Validating user API key.
     * If the api key is there in db, it is a valid key.
     * @param String $api_key user api key
     * @return boolean
     */
    public function isValidApiKey($api_key) {
        $stmt = $this->conn->prepare("SELECT user_id FROM SeniorProject.sp_users WHERE user_apikey = ?");
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Generating unique key for server-client interactions.
     * @param String $username Usernames are guaranteed to be unique.
     * @param String $androidID ANDROID_ID is usually unique.
     */
    private function generateApiKey($username, $androidID) {
        return password_hash($username . $androidID, PASSWORD_DEFAULT);
    }

    /**
     * If the username + androidID hash matches up with what's in the DB,
     * update the key and return the API key to the user.
     * accounts.
     * @param String $username 
     * @param String $androidID
     * @return String
     */
    public function updateApiKey($username, $androidID) {
        $stmt = $this->conn->prepare("SELECT user_apikey FROM SeniorProject.sp_users WHERE sp_username = ?");
        $stmt->bind_param("s", $username);
        if ($stmt->execute()) {
            $apiKey = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (password_verify($username . $androidID, $apiKey)) {
                $apiKey = generateApiKey($username, $androidID); //Generate a new API Key
                $stmt = $this->conn->prepare("UPDATE SET SeniorProject.sp_users user_apikey = ? WHERE user_name = ?");
                $stmt->bind_param("ss", $apikey, $username);
                if ($stmt->execute()) {
                    $stmt->close();
                    return $apiKey; //Give the user their new API Key
                } else {
                    $stmt->close();
                    return OPERATION_FAILED;
                }
            }
        } else {
            return OPERATION_FAILED;
        }
    }

    /**
     * Users are friends if their relationship is symmetrical in the SeniorProject.sp_friends table. 
     * @param int $initiator User_ID from the sp_users table
     * @param int $target User_ID from the sp_users table
     * @return boolean
     */
    public function friends($initiator, $target) {
        $stmt = $this->conn->prepare("SELECT friend_shipid FROM SeniorProject.sp_friends WHERE (friend_initiatorid = ? AND friend_initiatorid = ?) OR (friend_initiatorid = ? AND friend_initiatorid = ?)");
        $stmt->bind_param("iiii", $initiator, $target, $target, $initiator);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 1; //If there are two rows, the relationships is symmetrical.
    }

    /**
     * Add friends entry to database.
     * @param int $initiator User_ID from the sp_users table
     * @param int $target User_ID from the sp_users table
     */
    public function addFriend($initiator, $target) {
        $stmt = $this->conn->prepare("SELECT friend_shipid FROM SeniorProject.sp_friends WHERE friend_initiatorid = ? AND friend_targetid = ?");
        $stmt->bind_param("ii", $initiator, $target);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;

        if ($num_rows == 0) {
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_friends(friend_initiatorid, friend_targetid) VALUES(?,?)");
            $stmt->bind_param("ii", $initiator, $target);
            if ($stmt->execute()) {
                $stmt->close();
                return OPERATION_SUCCESS;
            } else {
                $stmt->close();
                return OPERATION_FAILED;
            }
        }

        return ALREADY_EXISTS;
    }

    /**
     * Determine the value of a particular Boop.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     * @return int
     */
    public function boopValue($initiator, $target) {
        $initiatorPowerUps = array();
        $targetPowerUps = array();
        $value = 1;

        $stmt = $this->conn->prepare("SELECT inventory_elixir_id FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_active = 'true'");
        $stmt->bind_param("s", $initiator);
        $stmt->exexute();
        $initiatorPowerUps = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($initiatorPowerUps as $powerUpID) {
            switch ($powerUpID) {
                case 0:
                    break;
                case 1:
                    break;
                case 2:
                    break;
            }
        }

        $stmt = $this->conn->prepare("SELECT inventory_elixir_id FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_active = 'true'");
        $stmt->bind_param("s", $target);
        $stmt->exexute();
        $targetPowerUps = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($initiatorPowerUps as $powerUpID) {
            switch ($powerUpID) {
                case 0:
                    break;
                case 1:
                    break;
                case 2:
                    break;
            }
        }

        return $value;
    }

    /**
     * Send a Boop to a user.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     */
    public function boopUser($initiator, $target) {
        $timestamp = date('Y-m-d G:i:s');

        $value = boopValue($initiator, $target);

        $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_activities(activity_initiator, activity_target, activity_timestamp) VALUES(?,?,?) WHERE EXISTS( SELECT * FROM SeniorProject.sp_users WHERE user_id = ?)");
        $stmt->bind_param("iisi", $initiator, $target, $timestamp, $target);
        if ($stmt->execute()) {
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users user_likessent = user_likessent + ? WHERE user_id = ?");
            $stmt->bind_param("ii", $initiator, $value);
            $stmt->execute();

            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users user_likesreceived = user_likesreceived + ? WHERE user_id = ?");
            $stmt->bind_param("ii", $target, $value);
            $stmt->execute();

            $stmt->close();
            return OPERATION_SUCCESS;
        } else {
            return OPERATION_FAILED;
        }
    }

}

?>