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
        require_once dirname(__FILE__) . '/elixirhandler.php';
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
     * Fetching user ID by username.
     * @param String $username
     */
    public function getUserId_username($username) {
        $stmt = $this->conn->prepare("SELECT user_id FROM SeniorProject.sp_users WHERE user_name = ?");
        $stmt->bind_param("s", $username);
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
     * Add friends entry to the database by name.
     * @param type $initiator
     * @param type $username
     */
    public function addFriendByUsername($initiator, $username) {
        $target = $this->getUserId_username($username);

        if (!is_null($target)) {
            $res = $this->addFriend($initiator, $target);
        } else {
            $res = DOES_NOT_EXIST;
        }

        return $res;
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
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore
                                      FROM SeniorProject.sp_friends f, SeniorProject.sp_users u
                                      WHERE f.friend_targetid = u.user_id AND f.friend_initiatorid = ? AND EXISTS
                                        (SELECT * 
                                        FROM SeniorProject.sp_friends
                                        WHERE friend_targetid = ?)
                                        ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $initiator, $initiator);
        $stmt->execute();

        $friends = $stmt->get_result();
        $stmt->close();
        return $friends;
    }

    /**
     * Get friends you've added, but not added you back.
     * @param int $initiator User_ID from the sp_users table.
     * @return array Array of pending friend requests.
     */
    public function getPendingRequests($initiator) {
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore
                                      FROM SeniorProject.sp_friends f, SeniorProject.sp_users u
                                      WHERE f.friend_targetid = u.user_id AND f.friend_initiatorid = ? AND f.friend_targetid NOT IN
                                        (SELECT friend_initiatorid 
                                        FROM SeniorProject.sp_friends
                                        WHERE friend_targetid = ?)
                                      ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $initiator, $initiator);
        $stmt->execute();

        $pending = $stmt->get_result();
        $stmt->close();
        return $pending;
    }

    /**
     * Get users that wish to friend you.
     * @param int $target User_ID from the sp_users table.
     * @return array Array of pending friend requests.
     */
    public function getFriendRequests($target) {
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore
                                      FROM SeniorProject.sp_friends f, SeniorProject.sp_users u
                                      WHERE f.friend_initiatorid = u.user_id AND f.friend_targetid = ? AND f.friend_initiatorid NOT IN
                                        (SELECT friend_targetid 
                                        FROM SeniorProject.sp_friends
                                        WHERE friend_initiatorid = ?)
                                      ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $target, $target);
        $stmt->execute();

        $requests = $stmt->get_result();
        $stmt->close();
        return $requests;
    }

    /* --- sp_activities TABLE METHODS --- */

    /**
     * Check if user is violating cooldown period.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     * @return boolean
     */
    public function cooldownActive($initiator, $target) {
        $stmt = $this->conn->prepare("SELECT activity_timestamp FROM SeniorProject.sp_activities WHERE activity_initiator = ? AND activity_target = ? ORDER BY activity_timestamp DESC LIMIT 1");
        $stmt->bind_param("ii", $initiator, $target);

        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_assoc();

            if (!is_null($result["activity_timestamp"])) {
                $now = date_create("now");
                $lastBoop = date_create($result["activity_timestamp"]);

                $minutesBetween_DI = date_diff($now, $lastBoop);
                $cooldown_DI = date_diff(date_create("now"), date_create("-10 minutes"));

                $minutesBetween = $minutesBetween_DI->i + $minutesBetween_DI->h * 60 + $minutesBetween_DI->d * 1440 + $minutesBetween_DI->m * 43800 + $minutesBetween_DI->y * 525600;
                $cooldown = $cooldown_DI->i;

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
     * @return array
     */
    public function boopValue($initiator, $target) {
        $targetCopy = $target;
        $initiatorCopy = $initiator;

        $tmp = array();

        $res = array();
        $res["initiatorBoopValue"] = 1;
        $res["targetBoopValue"] = 1;
        $res["initiatorElixirsUsed"] = array();
        $res["ieuHumanReadable"] = array();
        $res["targetElixirsUsed"] = array();
        $res["teuHumanReadable"] = array();

        //Get the scores for score calculation.
        $stmt = $this->conn->prepare("SELECT user_sentscore FROM SeniorProject.sp_users WHERE user_id = ?");
        $stmt->bind_param("i", $initiator);
        $stmt->execute();
        $initiatorSS = $stmt->get_result()->fetch_assoc();

        $stmt = $this->conn->prepare("SELECT user_receivedscore FROM SeniorProject.sp_users WHERE user_id = ?");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $targetRS = $stmt->get_result()->fetch_assoc();

        //Active Elixirs.
        $stmt = $this->conn->prepare("SELECT i.inventory_elixir_id, e.elixir_name FROM SeniorProject.sp_user_inventories i, SeniorProject.sp_elixirs e WHERE i.inventory_user_id = ? AND i.inventory_active = 1 AND i.inventory_elixir_id = e.elixir_id ORDER BY i.inventory_elixir_id ASC");
        $stmt->bind_param("i", $initiator);
        $stmt->execute();
        $initiatorElixirs = $stmt->get_result();

        while ($elixir = $initiatorElixirs->fetch_assoc()) {
            switch ($elixir["inventory_elixir_id"]) {

                //Poisons               
                case 2011: //Birch Blood.
                    $tmp = birchBlood(1);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2012: //Deluxe Birch Blood.
                    $tmp = birchBlood(2);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2021: //Glove Cleaner.
                    $tmp = gloveCleaner(2);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2022: //Deluxe Glove Cleaner.
                    $tmp = gloveCleaner(3);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2023: //Premium Glove Cleaner.
                    $tmp = gloveCleaner(4);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2031: //Altotoxin.
                    $tmp = altotoxin(3, $targetRS["user_receivedscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2032: //Deluxe Altotoxin.
                    $tmp = altotoxin(4, $targetRS["user_receivedscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2033: //Premium Altotoxin.
                    $tmp = altotoxin(5, $targetRS["user_receivedscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2041: //Rumpelstiltskin's Decotion.
                    $tmp = rumpDeco(4, $targetRS["user_receivedscore"], $initiatorSS["user_sentscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2042: //Deluxe Rumplestiltskin's Decotion.
                    $tmp = rumpDeco(5, $targetRS["user_receivedscore"], $initiatorSS["user_sentscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2051: //Vampire Venom.
                    $tmp = vampVenom(4, $targetRS["user_receivedscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 2052: //Deluxe Vampire Venom.
                    $tmp = vampVenom(5, $targetRS["user_receivedscore"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;

                //Boosters
                case 4011: //Eagle Eye.
                    $tmp = eagleEye($res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4021: //Lite Corn Syrup.
                    $tmp = cornSyrup(1);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4022: //Corn Syrup.
                    $tmp = cornSyrup(2);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4023: //High Fructose Corn Syrup.
                    $tmp = cornSyrup(3);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4031: //Super Electrolyte Punch.
                    $tmp = electrolytePunch(3, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4032: //Deluxe Mega Electrolyte Punch.
                    $tmp = electrolytePunch(4, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4033: //Premium Mondo Electrolyte Punch.
                    $tmp = electrolytePunch(5, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4041: //Discontinued Cereal Sludge.
                    $tmp = cerealSludge($res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;

                default:
                    $res["initiatorBoopValue"] = 1;
                    $res["targetBoopValue"] = 1;
                    break;
            }
            $this->decrementInventory($initiator, $elixir["inventory_elixir_id"]);
            $res["initiatorElixirsUsed"][] = $elixir["inventory_elixir_id"];
            $res["ieuHumanReadable"][] = $elixir["elixir_name"];
        }

        //Passive Elixirs.
        $stmt = $this->conn->prepare("SELECT i.inventory_elixir_id, e.elixir_name FROM SeniorProject.sp_user_inventories i, SeniorProject.sp_elixirs e WHERE i.inventory_user_id = ? AND i.inventory_active = 1 AND i.inventory_elixir_id = e.elixir_id ORDER BY i.inventory_elixir_id ASC");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $targetElixirs = $stmt->get_result();

        while ($elixir = $targetElixirs->fetch_assoc()) {
            switch ($elixir["inventory_elixir_id"]) {

                //Shields
                case 1011: //Wood Mitigation Shield.
                    $tmp = mitigationShield(1, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1012: //Bronze Mitigation Shield.
                    $tmp = mitigationShield(2, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1013: //Iron Mitigation Shield.
                    $tmp = mitigationShield(3, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1014: //Rearden Steel Mitigation Shield.
                    $tmp = mitigationShield(4, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1015: //Diamond Mitigation Shield.
                    $tmp = mitigationShield(5, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1021: //Wood Negation Shield.
                    $tmp = negationShield(1, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1022: //Bronze Negation Shield.
                    $tmp = negationShield(2, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1023: //Iron Negation Shield.
                    $tmp = negationShield(3, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1024: //Rearden Steel Negation Shield.
                    $tmp = negationShield(4, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1025: //Diamond Negation Shield.
                    $tmp = negationShield(5, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1031: //Wood Reflection Shield.
                    $tmp = reflectionShield(1, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1032: //Bronze Reflection Shield.
                    $tmp = reflectionShield(2, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1033: //Iron Reflection Shield.
                    $tmp = reflectionShield(3, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1034: //Rearden Steel Reflection Shield.
                    $tmp = reflectionShield(4, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1035: //Diamond Reflection Shield.
                    $tmp = reflectionShield(5, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1041: //Rearden Steel Inversion Shield.
                    $tmp = inversionShield(4, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 1042: //Diamond Inversion Shield.
                    $tmp = inversionShield(5, $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;

                //Antivenom
                case 3011:
                    $tmp = antivenom($res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;

                //Boosters
                case 4031: //Super Electrolyte Punch.
                    $tmp = electrolytePunch(3, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4032: //Deluxe Mega Electrolyte Punch.
                    $tmp = electrolytePunch(4, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;
                case 4033: //Premium Mondo Electrolyte Punch.
                    $tmp = electrolytePunch(5, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $res["initiatorBoopValue"] += $tmp["iniBVal"];
                    $res["targetBoopValue"] += $tmp["tarBVal"];
                    break;

                default:
                    $res["initiatorBoopValue"] = 0;
                    $res["targetBoopValue"] = 0;
                    break;
            }
            $this->decrementInventory($target, $elixir["inventory_elixir_id"]);
            $res["targetElixirsUsed"][] = $elixir["inventory_elixir_id"];
            $res["teuHumanReadable"][] = $elixir["elixir_name"];
        }

        return $res;
    }

    /**
     * Send a Boop to a user.
     * @param int $initiator User_ID of the sender.
     * @param int $target User_ID of the receiver.
     */
    public function boopUser($initiator, $target) {
        $response = array();
        $response["reward"] = array();
        if (!($initiator == $target) and ! $this->cooldownActive($initiator, $target)) {
            $res = $this->boopValue($initiator, $target);
            $timestamp = date('Y-m-d G:i:s');

            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_activities(activity_initiator, activity_target, activity_value_initiator, activity_value_target, activity_timestamp) VALUES(?,?,?,?,?)");
            $stmt->bind_param("iiiis", $initiator, $target, $res["initiatorBoopValue"], $res["targetBoopValue"], $timestamp);
            if ($stmt->execute()) {
                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_sentscore = user_sentscore + ? WHERE user_id = ?");
                $stmt->bind_param("ii", $res["initiatorBoopValue"], $initiator);
                $stmt->execute();

                foreach ($res["initiatorElixirsUsed"] as $elixir) {
                    $this->addHistory($timestamp, $initiator, $target, $elixir, 1);
                }

                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_receivedscore = user_receivedscore + ? WHERE user_id = ?");
                $stmt->bind_param("ii", $res["targetBoopValue"], $target);
                $stmt->execute();

                foreach ($res["targetElixirsUsed"] as $elixir) {
                    $this->addHistory($timestamp, $initiator, $target, $elixir, 0);
                }

                $randElixir = $this->randomElixir(); //Reward initiator with qty 1-2 of a random elixir.
                if ($randElixir > 0) {
                    $tmp = array();

                    $this->addInventoryItem($initiator, $randElixir, mt_rand(1, 2));

                    $stmt = $this->conn->prepare("SELECT elixir_name, elixir_desc FROM SeniorProject.sp_elixirs WHERE elixir_id = ?");
                    $stmt->bind_param("i", $randElixir);
                    $stmt->execute();

                    $tmp = $stmt->get_result()->fetch_assoc();
                    array_push($response["reward"], $tmp);
                }



                $stmt->close();

                $response["timestamp"] = $timestamp;
                $response["initiator boop value"] = $res["initiatorBoopValue"];
                $response["target boop value"] = $res["targetBoopValue"];
                $response["initiator elixirs used"] = $res["ieuHumanReadable"];
                $response["target elixirs used"] = $res["teuHumanReadable"];
                $response["status"] = OPERATION_SUCCESS;
                return $response;
            } else {
                $stmt->close();

                $response["status"] = OPERATION_FAILED;
                return $response;
            }
        } else {
            $response["status"] = TIME_CONSTRAINT;
            return $response;
        }
    }

    public function getBoopsSinceChecked($userID) {
        $response = array();
        $response["boops since last checked"] = array();

        $stmt = $this->conn->prepare("SELECT user_lastchecked FROM SeniorProject.sp_users WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $lastChecked = $stmt->get_result()->fetch_assoc();

        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_sentscore, u.user_receivedscore, a.activity_value_initiator, a.activity_value_target, a.activity_timestamp "
                . "FROM SeniorProject.sp_activities a, SeniorProject.sp_users u "
                . "WHERE a.activity_timestamp > TIMESTAMP(?) AND a.activity_target = ? AND a.activity_initiator = u.user_id");
        $stmt->bind_param("si", $lastChecked["user_lastchecked"], $userID);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($boop = $res->fetch_assoc()) {
            $tmp = array();
            $tmp["initiator elixirs used"] = array();
            $tmp["target elixirs used"] = array();

            $stmt = $this->conn->prepare("SELECT e.elixir_name, a.elixir_activity_iORt "
                    . "FROM SeniorProject.sp_elixirs e, SeniorProject.sp_elixir_activities a "
                    . "WHERE a.elixir_activity_timestamp = ? AND a.elixir_activity_initiator = ? AND a.elixir_activity_elixirid = e.elixir_id");
            $stmt->bind_param("si", $boop["activity_timestamp"], $boop["user_id"]);
            $stmt->execute();
            $elixirsUsed = $stmt->get_result();

            while ($elixir = $elixirsUsed->fetch_assoc()) {
                $tmp1 = array();
                $tmp2 = array();
                if ($elixir["elixir_activity_iORt"] == 1) {
                    $tmp1["elixir_name"] = $elixir["elixir_name"];
                } else {
                    $tmp2["elixir_name"] = $elixir["elixir_name"];
                }

                array_push($tmp["initiator elixirs used"], $tmp1);
                array_push($tmp["target elixirs used"], $tmp2);
            }

            $tmp["user id"] = $boop["user_id"];
            $tmp["user name"] = $boop["user_name"];
            $tmp["user sent score"] = $boop["user_sentscore"];
            $tmp["user received score"] = $boop["user_receivedscore"];
            $tmp["activity value initiator"] = $boop["activity_value_initiator"];
            $tmp["activity value target"] = $boop["activity_value_target"];
            $tmp["activity timestamp"] = $boop["activity_timestamp"];

            array_push($response["boops since last checked"], $tmp);
        }

        $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users SET user_lastchecked = NOW() WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();

        $stmt->close();
        $response["status"] = OPERATION_SUCCESS;
        return $response;
    }

    /* --- sp_user_inventories TABLE METHODS --- */

    /**
     * Add x number of inventory item to a user's inventory.
     * @param int $userID
     * @param int $elixirID
     * @param int $quantity
     */
    public function addInventoryItem($userID, $elixirID, $quantity) {
        $stmt = $this->conn->prepare("SELECT * FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
        $stmt->bind_param("ii", $userID, $elixirID);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows <= 0) { //Insert new row.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_user_inventories(inventory_user_id, inventory_elixir_id, inventory_quantity) VALUES(?,?,?)");
            $stmt->bind_param("iii", $userID, $elixirID, $quantity);
            if ($stmt->execute()) {
                $stmt->close();
                return OPERATION_SUCCESS;
            } else {
                $stmt->close();
                return OPERATION_FAILED;
            }
        } else { //Update row.
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_user_inventories SET inventory_quantity = inventory_quantity + ? WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("iii", $quantity, $userID, $elixirID);
            if ($stmt->execute()) {
                $stmt->close();
                return OPERATION_SUCCESS;
            } else {
                $stmt->close();
                return OPERATION_FAILED;
            }
        }
    }

    /**
     * Decrement inventory item.
     * @param int $userID
     * @param int $elixirID
     */
    public function decrementInventory($userID, $elixirID) {
        $stmt = $this->conn->prepare("SELECT inventory_quantity FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
        $stmt->bind_param("ii", $userID, $elixirID);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if ($res["inventory_quantity"] <= 1) { //I would rather have no entry than an empty one.  Personal preference.
            $stmt = $this->conn->prepare("DELETE IGNORE FROM SeniorProject.sp_user_inventories WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("ii", $userID, $elixirID);
            $stmt->execute();
            $stmt->close();

            return OPERATION_SUCCESS;
        } else {
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_user_inventories SET inventory_quantity = inventory_quantity - 1 WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("ii", $userID, $elixirID);
            if ($stmt->execute()) {
                $stmt->close();
                return OPERATION_SUCCESS;
            } else {
                $stmt->close();
                return OPERATION_FAILED;
            }
        }
    }

    public function setInventoryActive($userID, $elixirID, $active) {
        $response = array();

        if ($active > -1 and $active < 2) {
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_user_inventories SET inventory_active = ? WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("iii", $active, $userID, $elixirID);
            if ($stmt->execute()) {
                $response["status"] = OPERATION_SUCCESS;
            } else {
                $response["status"] = OPERATION_FAILED;
            }

            $stmt->close();
        } else {
            $response["status"] = OPERATION_FAILED;
        }
        return $response;
    }

    /**
     * Get the inventory of a particular user.
     * @param $userID
     */
    public function getInventory($userID) {
        $stmt = $this->conn->prepare("SELECT eli.elixir_id, eli.elixir_type, eli.elixir_name, eli.elixir_desc, inv.inventory_quantity, inv.inventory_active
                                      FROM SeniorProject.sp_elixirs eli, SeniorProject.sp_user_inventories inv  
                                      WHERE inv.inventory_elixir_id = eli.elixir_id AND inv.inventory_user_id = ?
                                      ORDER BY eli.elixir_id ASC");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $inventory = $stmt->get_result();
        $stmt->close();
        return $inventory;
    }

    public function randomElixir() {
        $randStrength = mt_rand(0, 100000);

        if ($randStrength <= 25000) { //Get nothing.
            return -1;
        } else if ($randStrength > 25000 and $randStrength <= 50000) { //Get level 1 item.
            switch (mt_rand(1, 5)) {
                case 1:
                    return 1011; //Wood Mitigation Shield
                case 2:
                    return 1021; //Wood Negation Shield
                case 3:
                    return 1031; //Wood Reflection Shield
                case 4:
                    return 2011; //Birch Blood
                default:
                    return 4021; //Lite Corn Syrup
            }
        } else if ($randStrength > 50000 and $randStrength <= 68750) { //Get level 2 item.
            switch (mt_rand(1, 6)) {
                case 1:
                    return 1012; //Bronze Mitigation Shield
                case 2:
                    return 1022; //Bronze Negation Shield
                case 3:
                    return 1032; //Bronze Reflection Shield
                case 4:
                    return 2012; //Deluxe Birch Blood
                case 5:
                    return 2021; //Glove Cleaner
                default:
                    return 4022; //Corn Syrup
            }
        } else if ($randStrength > 68750 and $randStrength <= 84375) { //Get level 3 item.
            switch (mt_rand(1, 8)) {
                case 1:
                    return 1013; //Iron Mitigation Shield
                case 2:
                    return 1023; //Iron Negation Shield
                case 3:
                    return 1033; //Iron Reflection Shield
                case 4:
                    return 2022; //Deluxe Glove Cleaner
                case 5:
                    return 2031; //Altotoxin
                case 6:
                    return 3011; //Peptotumsinol
                case 7:
                    return 4023; //High Fructose Corn Syrup
                default:
                    return 4031; //Super Electrolyte Punch
            }
        } else if ($randStrength > 84375 and $randStrength <= 93750) { //Get level 4 item.
            switch (mt_rand(1, 10)) {
                case 1:
                    return 1014; //Rearden Steel Mitigation Shield
                case 2:
                    return 1024; //Rearden Steel Negation Shield
                case 3:
                    return 1034; //Rearden Steel Reflection Shield
                case 4:
                    return 1041; //Rearden Steel Inversion Shield
                case 5:
                    return 2023; //Premium Glove Cleaner
                case 6:
                    return 2032; //Deluxe Altotoxin
                case 7:
                    return 2041; //Rumpelstiltskin's Decoction
                case 8:
                    return 2051; //Vampire Venom
                case 9:
                    return 4032; //Deluxe Mega Electrolyte Punch
                default:
                    return 4041; //Discontinued Cereal Sludge
            }
        } else if ($randStrength > 93750) { //Get level 5 item.
            switch (mt_rand(1, 9)) {
                case 1:
                    return 1015; //Diamond Mitigation Shield
                case 2:
                    return 1025; //Diamond Negation Shield
                case 3:
                    return 1035; //Diamond Reflection Shield
                case 4:
                    return 1042; //Diamond Inversion Shield
                case 5:
                    return 2033; //Premium Altotoxin
                case 6:
                    return 2042; //Deluxe Rumpelstiltskin's Decoction
                case 7:
                    return 2052; //Deluxe Vampire Venom
                case 8:
                    return 4011; //Eagle Eye
                default:
                    return 4033; //Premium Mondo Electrolyte Punch
            }
        }
    }

    /* --- sp_elixir_activities TABLE METHODS --- */

    /**
     * Add entry describing elixirs used.
     * @param String $timestamp
     * @param int $initiator
     * @param int $target
     * @param int $elixirID
     * @param int $iORt
     */
    public function addHistory($timestamp, $initiator, $target, $elixirID, $iORt) {
        $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_elixir_activities(elixir_activity_timestamp, elixir_activity_initiator, elixir_activity_target, elixir_activity_elixirid, elixir_activity_iORt) VALUES(?,?,?,?,?)");
        $stmt->bind_param("siiii", $timestamp, $initiator, $target, $elixirID, $iORt);
        $stmt->execute();
    }

    /* --- STATS METHODS --- */

    public function globalTopSenders($number) {
        $res = array();
        $res["top senders"] = array();

        $stmt = $this->conn->prepare("SELECT user_name, user_sentscore, user_receivedscore FROM SeniorProject.sp_users ORDER BY user_sentscore DESC LIMIT ?");
        $stmt->bind_param("i", $number);
        $stmt->execute();

        $res["status"] = OPERATION_SUCCESS;
        $res["top senders"] = $stmt->get_result();

        $stmt->close();
        return $res;
    }

    public function globalTopReceivers($number) {
        $res = array();
        $res["top receivers"] = array();

        $stmt = $this->conn->prepare("SELECT user_name, user_sentscore, user_receivedscore FROM SeniorProject.sp_users ORDER BY user_receivedscore DESC LIMIT ?");
        $stmt->bind_param("i", $number);
        $stmt->execute();

        $res["status"] = OPERATION_SUCCESS;
        $res["top receivers"] = $stmt->get_result();

        $stmt->close();
        return $res;
    }

}

?>