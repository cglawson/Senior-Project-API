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
        date_default_timezone_set('America/Chicago');
    }

    /* --- sp_users TABLE METHODS --- */

    /**
     * Creating new user.
     * @param String $username Display name of user.
     * @param String $uniqueID ID of android user.
     * @param String $phoneNumber Phone number of user if applicable.
     * @return String 
     */
    public function createUser($username, $uniqueID) {

        //Is the username taken?
        if (!$this->userExists($username)) {

            //Generate an API key for server-client interactions.
            $apiKey = $this->generateApiKey($username, $uniqueID);

            //Insert upon success.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_users(user_name, user_name_timestamp, user_apikey, user_sentscore, user_receivedscore)VALUES(?,NOW(),?,0,0)");
            $stmt->bind_param("ss", $username, $apiKey);

            $result = $stmt->execute();
            $stmt->close();

            if ($result) {
                return $apiKey;
            } else {
                return OPERATION_FAILED;
            }
        }
        return ALREADY_EXISTS;
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
            return $user_id["user_id"];
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
     * @param String $uniqueID ANDROID_ID is usually unique.
     */
    private function generateApiKey($username, $uniqueID) {
        return password_hash($username . $uniqueID, PASSWORD_DEFAULT);
    }

    /**
     * If the username + uniqueID hash matches up with what's in the DB,
     * update the key and return the API key to the user.
     * @param String $username 
     * @param String $uniqueID
     * @return String
     */
    public function updateApiKey($username, $uniqueID) {
        $stmt = $this->conn->prepare("SELECT user_apikey FROM SeniorProject.sp_users WHERE user_name = ?");
        $stmt->bind_param("s", $username);
        if ($stmt->execute()) {
            $apiKey = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (password_verify($username . $uniqueID, $apiKey["user_apikey"])) {
                $apiKey = $this->generateApiKey($username, $uniqueID); //Generate a new API Key
                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_apikey = ? WHERE user_name = ?");
                $stmt->bind_param("ss", $apiKey, $username);
                if ($stmt->execute()) {
                    $stmt->close();
                    return $apiKey; //Give the user their new API Key
                } else {
                    $stmt->close();
                    return OPERATION_FAILED;
                }
            } else {
                return OPERATION_FAILED;
            }
        } else {
            return OPERATION_FAILED;
        }
    }

    /**
     * Updates the user's name.  This can occur only every fourteen days.
     * @param int $userID
     * @param int $newUsername
     * @param String $uniqueID
     * @return int Days until username can be updated again.
     */
    public function updateUsername($userID, $newUsername, $uniqueID) {
        $res = array();

        $userID_copy = $userID;
        $uniqueID_copy = $uniqueID;

        $stmt = $this->conn->prepare("SELECT user_name, user_name_timestamp, user_apikey FROM SeniorProject.sp_users WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();

        if (password_verify($result["user_name"] . $uniqueID, $result["user_apikey"])) {
            $lastUpdated = date_create($result["user_name_timestamp"]);
            $now = date_create("now");
            $daysBetween = date_diff($now, $lastUpdated);

            if ($daysBetween->days >= 14) {
                if (!$this->userExists($newUsername)) {
                    $newApiKey = $this->generateApiKey($newUsername, $uniqueID);

                    $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_name = ?, user_name_timestamp = NOW(), user_apikey = ? WHERE user_id = ?");
                    $stmt->bind_param("ssi", $newUsername, $newApiKey, $userID_copy);
                    $stmt->execute();
                    $stmt->close();

                    $res["status"] = OPERATION_SUCCESS;
                    $res["newUsername"] = $newUsername;
                    $res["newApiKey"] = $newApiKey;

                    return $res;
                } else {
                    $stmt->close();
                    $res["status"] = ALREADY_EXISTS;

                    return $res;
                }
            } else {
                $stmt->close();
                $res["status"] = TIME_CONSTRAINT;
                $res["days_remaining"] = 14 - $daysBetween->days;
                
                return $res;
            }
        } else {
            $stmt->close();
            $res["status"] = INVALID_CREDENTIALS;
            
            return $res;
        }
    }

    /* --- sp_user_locations TABLE METHODS --- */

    /**
     * Updates the user's latitude and longitude.  Users may have this turned
     * off in their client settings.
     * @param int $userID
     * @param double $latitude
     * @param double $longitude
     */
    public function updateLocation($userID, $latitude, $longitude) {
        $this->deleteLocation($userID);

        // Scaled to MEDIUMINT for SQL.
        $latitude = $latitude * 10000;
        $longitude = $longitude * 10000;

        $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_user_locations(user_location_id,user_location_lat,user_location_lon)VALUES(?,?,?)");
        $stmt->bind_param("idd", $userID, $latitude, $longitude);
        if ($stmt->execute()) {
            $stmt->close();
            return OPERATION_SUCCESS;
        } else {
            $stmt->close();
            return OPERATION_FAILED;
        }
    }

    /**
     * Removes the user's latitude and longitude.
     * @param int $userID
     */
    public function deleteLocation($userID) {
        $stmt = $this->conn->prepare("DELETE IGNORE FROM SeniorProject.sp_user_locations WHERE user_location_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $stmt->close();
        return OPERATION_SUCCESS;
    }

    /**
     * Return 50 users closest to a location within 1000 miles.
     * @param double $latitude
     * @param double $longitude
     */
    public function nearbyUsers($latitude, $longitude, $user_id) {
        $condition = "NOT user_location_id = " . $user_id;

        $stmt = $this->conn->prepare("CALL FindNearest(?,?,5,1000,50,?)");
        $stmt->bind_param("dds", $latitude, $longitude, $condition);
        if ($stmt->execute()) {
            $nearby = $stmt->get_result();
            $stmt->close();
            return $nearby;
        } else {
            $stmt->close();
            return OPERATION_FAILED;
        }
    }

    /* --- sp_friends TABLE METHODS --- */

    /**
     * Add friends entry to database.
     * @param int $initiator User_ID from the sp_users table.
     * @param int $target User_ID from the sp_users table.
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
        } else {
            return ALREADY_EXISTS;
        }
    }

    /**
     * Remove friends entry from database.
     * @param int $initiator User_ID from the sp_users table.
     * @param int $target User_ID from the sp_users table.
     */
    public function removeFriend($initiator, $target) {
        $stmt = $this->conn->prepare("DELETE FROM SeniorProject.sp_friends WHERE (friend_initiatorid = ? AND friend_targetid = ?) OR (friend_initiatorid = ? AND friend_targetid = ?)");
        $stmt->bind_param("iiii", $initiator, $target, $target, $initiator); // Symmetrical to prevent the friendship from degrading into a friend request.
        if ($stmt->execute()) {
            return OPERATION_SUCCESS;
        } else {
            return OPERATION_FAILED;
        }
    }

    /**
     * Get friends of a particular user.
     * @param int $initiator from the sp_users table.
     * @return array Array of user friends.
     */
    public function getFriends($initiator) {
        $friends = array();
        $stmt = $this->conn->prepare("SELECT friend_targetid from SeniorProject.sp_friends WHERE friend_initiatorid = ?");
        $stmt->bind_param("i", $initiator);
        if ($stmt->execute()) {
            $potential = $stmt->get_result();
            while ($target = $potential->fetch_assoc()) {
                $stmt = $this->conn->prepare("SELECT * from SeniorProject.sp_friends WHERE friend_initiatorid = ? AND friend_targetid = ?");
                $stmt->bind_param("ii", $target["friend_targetid"], $initiator);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $friends[] = $target["friend_targetid"];
                }
            }
            $stmt->close();
            return $friends;
        } else {
            $stmt->close();
            return OPERATION_FAILED;
        }
    }

    /**
     * Get friends you've added, but not added you back.
     * @param int $initiator User_ID from the sp_users table.
     * @return array Array of pending friend requests.
     */
    public function getPendingRequests($initiator) {
        $pending = array();
        $stmt = $this->conn->prepare("SELECT friend_targetid from SeniorProject.sp_friends WHERE friend_initiatorid = ?");
        $stmt->bind_param("i", $initiator);
        if ($stmt->execute()) {
            $potential = $stmt->get_result();
            while ($target = $potential->fetch_assoc()) {
                $stmt = $this->conn->prepare("SELECT * from SeniorProject.sp_friends WHERE friend_initiatorid = ? AND friend_targetid = ?");
                $stmt->bind_param("ii", $target["friend_targetid"], $initiator);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows <= 0) {
                    $pending[] = $target["friend_targetid"];
                }
            }
            $stmt->close();
            return $pending;
        } else {
            $stmt->close();
            return OPERATION_FAILED;
        }
    }

    /**
     * Get users that wish to friend you.
     * @param int $target User_ID from the sp_users table.
     * @return array Array of pending friend requests.
     */
    public function getFriendRequests($target) {
        $pending = array();
        $stmt = $this->conn->prepare("SELECT friend_initiatorid from SeniorProject.sp_friends WHERE friend_targetid = ?");
        $stmt->bind_param("i", $target);
        if ($stmt->execute()) {
            $potential = $stmt->get_result();
            while ($initiator = $potential->fetch_assoc()) {
                $stmt = $this->conn->prepare("SELECT * from SeniorProject.sp_friends WHERE friend_initiatorid = ? AND friend_targetid = ?");
                $stmt->bind_param("ii", $target, $initiator["friend_initiatorid"]);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows <= 0) {
                    $pending[] = $initiator["friend_initiatorid"];
                }
            }
            $stmt->close();
            return $pending;
        } else {
            $stmt->close();
            return OPERATION_FAILED;
        }
    }

    /* --- sp_activities TABLE METHODS --- */

    /**
     * Check if user is violating cooldown period.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     * @return boolean
     */
    public function cooldownActive($initiator, $target) {
        $stmt = $this->conn->prepare("SELECT activity_timestamp FROM SeniorProject.sp_activities WHERE activity_initiator = ? AND activity_target = ? ORDER BY activity_id DESC LIMIT 1");
        $stmt->bind_param("ii", $initiator, $target);

        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_assoc();

            if (!is_null($result["activity_timestamp"])) {
                $now = date_create("now");
                $lastBoop = date_create($result["activity_timestamp"]);

                $minutesBetween_DI = date_diff($now, $lastBoop);
                $cooldown_DI = date_diff(date_create("now"), date_create("-10 minutes"));

                $minutesBetween = $minutesBetween_DI->i + $minutesBetween_DI->h * 60 + $minutesBetween_DI->d * 1440 + $minutesBetween_DI->m * 43800 + $minutesBetween_DI->y * 525600;
                $cooldown = $cooldown_DI->i + $cooldown_DI->h * 60 + $cooldown_DI->d * 1440 + $cooldown_DI->m * 43800 + $cooldown_DI->y * 525600;

                if ($minutesBetween > $cooldown) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }
        }
        return OPERATION_FAILED;
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

//        $stmt = $this->conn->prepare("SELECT inventory_elixir_id FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_active = 'true'");
//        $stmt->bind_param("s", $initiator);
//        $stmt->exexute();
//        $initiatorPowerUps = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
//
//        foreach ($initiatorPowerUps as $powerUpID) {
//            switch ($powerUpID) {
//                case 0:
//                    break;
//                case 1:
//                    break;
//                case 2:
//                    break;
//            }
//        }
//
//        $stmt = $this->conn->prepare("SELECT inventory_elixir_id FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_active = 'true'");
//        $stmt->bind_param("s", $target);
//        $stmt->exexute();
//        $targetPowerUps = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
//
//        foreach ($initiatorPowerUps as $powerUpID) {
//            switch ($powerUpID) {
//                case 0:
//                    break;
//                case 1:
//                    break;
//                case 2:
//                    break;
//            }
//        }

        return $value;
    }

    /**
     * Send a Boop to a user.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     */
    public function boopUser($initiator, $target) {
        if (!$this->cooldownActive($initiator, $target)) {
            $value = $this->boopValue($initiator, $target);

            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_activities(activity_initiator, activity_target, activity_value, activity_timestamp) VALUES(?,?,?,NOW())");
            $stmt->bind_param("iii", $initiator, $target, $value);
            if ($stmt->execute()) {
                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_sentscore = user_sentscore + ? WHERE user_id = ?");
                $stmt->bind_param("ii", $value, $initiator);
                $stmt->execute();

                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_receivedscore = user_receivedscore + ? WHERE user_id = ?");
                $stmt->bind_param("ii", $value, $target);
                $stmt->execute();

                $stmt->close();
                return OPERATION_SUCCESS;
            } else {
                return OPERATION_FAILED;
            }
        } else {
            return OPERATION_FAILED;
        }
    }

}

?>