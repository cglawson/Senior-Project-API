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
        $res = array();

        //Is the username taken?
        if (!$this->userExists($username)) {

            //Generate an API key for server-client interactions.
            $apiKey = $this->generateApiKey($username, $uniqueID);

            //Insert upon success.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_users(user_name, user_name_timestamp, user_apikey, user_sentscore, user_receivedscore) "
                    . "VALUES(?,NOW(),?,0,0)");
            $stmt->bind_param("ss", $username, $apiKey);
            $stmt->execute();

            $stmt->close();
            $res["status"] = OPERATION_SUCCESS;
            $res["apikey"] = $apiKey;
        } else {
            $res["status"] = ALREADY_EXISTS;
        }

        return $res;
    }

    /**
     * Check for unique username.
     * @param String $username Name to check for in database.
     * @return boolean
     */
    function userExists($username) {
        $stmt = $this->conn->prepare("SELECT user_id "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_name = ?");
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
        $stmt = $this->conn->prepare("SELECT user_id "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_apikey = ?");
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
        $stmt = $this->conn->prepare("SELECT user_id "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_name = ?");
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
     * Validates user API key.
     * If the api key is in the db, it is a valid key.
     * @param String $api_key user api key
     * @return boolean
     */
    public function isValidApiKey($api_key) {
        $stmt = $this->conn->prepare("SELECT user_id "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_apikey = ?");
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
    function generateApiKey($username, $uniqueID) {
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
        $res = array();

        $stmt = $this->conn->prepare("SELECT user_apikey "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $apiKey = $stmt->get_result()->fetch_assoc();

        if (password_verify($username . $uniqueID, $apiKey["user_apikey"])) { //If hash matches stored hash, then allow the user to refresh their API key.
            $apiKey = $this->generateApiKey($username, $uniqueID); //Generate a new API Key
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users "
                    . "SET user_apikey = ? "
                    . "WHERE user_name = ?");
            $stmt->bind_param("ss", $apiKey, $username);
            $stmt->execute();

            $res["status"] = OPERATION_SUCCESS;
            $res["apikey"] = $apiKey;
        } else {
            $res["status"] = INVALID_CREDENTIALS;
        }

        $stmt->close();
        return $res;
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

        $stmt = $this->conn->prepare("SELECT user_name, user_name_timestamp, user_apikey "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();

        if (password_verify($result["user_name"] . $uniqueID, $result["user_apikey"])) { //Verify user identity.
            $lastUpdated = date_create($result["user_name_timestamp"]);
            $now = date_create("now");
            $daysBetween = date_diff($now, $lastUpdated);

            if ($daysBetween->days >= 14) { //User may update username only every 14 days.
                if (!$this->userExists($newUsername)) { //Can't take someone else's username.
                    $newApiKey = $this->generateApiKey($newUsername, $uniqueID); //Generate a new hash to match the new username.

                    $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users "
                            . "SET user_name = ?, user_name_timestamp = NOW(), user_apikey = ? "
                            . "WHERE user_id = ?");
                    $stmt->bind_param("ssi", $newUsername, $newApiKey, $userID_copy);
                    $stmt->execute(); //Update DB entry.

                    $res["status"] = OPERATION_SUCCESS;
                    $res["newUsername"] = $newUsername;
                    $res["newApiKey"] = $newApiKey;
                } else {
                    $res["status"] = ALREADY_EXISTS;
                }
            } else {
                $res["status"] = TIME_CONSTRAINT;
                $res["days_remaining"] = 14 - $daysBetween->days;
            }
        } else {
            $res["status"] = INVALID_CREDENTIALS;
        }

        $stmt->close();
        return $res;
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

        $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_user_locations(user_location_id,user_location_lat,user_location_lon) "
                . "VALUES(?,?,?)");
        $stmt->bind_param("idd", $userID, $latitude, $longitude);
        $stmt->execute();

        $stmt->close();
        $res["status"] = OPERATION_SUCCESS;

        return $res;
    }

    /**
     * Removes the user's latitude and longitude.
     * @param int $userID
     */
    public function deleteLocation($userID) {
        $stmt = $this->conn->prepare("DELETE IGNORE FROM SeniorProject.sp_user_locations "
                . "WHERE user_location_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $stmt->close();

        $res["status"] = OPERATION_SUCCESS;
        return $res;
    }

    /**
     * Return 50 users closest to a location within 1000 miles.
     * @param double $latitude
     * @param double $longitude
     */
    public function nearbyUsers($latitude, $longitude, $user_id) {
        $res = array();

        $condition = "NOT user_location_id = " . $user_id; //Don't include the user who is searching.

        $stmt = $this->conn->prepare("CALL FindNearest(?,?,5,1000,50,?)"); //Stored Procedure obtained from mysql.rjweb.org/doc.php/latlng, Rick James.
        $stmt->bind_param("dds", $latitude, $longitude, $condition);
        $stmt->execute();

        $res["status"] = OPERATION_SUCCESS;
        $res["nearby users"] = $stmt->get_result();
        $stmt->close();

        return $res;
    }

    /* --- sp_friends TABLE METHODS --- */

    /**
     * Add friends entry to database.
     * @param int $initiator User_ID from the sp_users table.
     * @param int $target User_ID from the sp_users table.
     */
    public function addFriend($initiator, $target) {
        $res = array();

        $stmt = $this->conn->prepare("SELECT * "
                . "FROM SeniorProject.sp_friends "
                . "WHERE friend_initiatorid = ? AND friend_targetid = ?");
        $stmt->bind_param("ii", $initiator, $target);
        $stmt->execute(); //Make sure an entry does not already exist.
        $stmt->store_result();
        $num_rows = $stmt->num_rows;

        if ($num_rows <= 0) { //If no entry exists, insert a new one.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_friends(friend_initiatorid, friend_targetid)"
                    . " VALUES(?,?)");
            $stmt->bind_param("ii", $initiator, $target);
            if ($stmt->execute()) {
                $stmt->close();
                $res["status"] = OPERATION_SUCCESS;
            } else {
                $stmt->close();
                $res["status"] = OPERATION_FAILED;
            }
        } else {
            $res["status"] = ALREADY_EXISTS;
        }

        return $res;
    }

    /**
     * Add friends entry to the database by name.
     * @param type $initiator
     * @param type $username
     */
    public function addFriendByUsername($initiator, $username) {
        $target = $this->getUserId_username($username);

        if (!is_null($target)) {
            $tmp = $this->addFriend($initiator, $target);
            $res["status"] = $tmp["status"];
        } else {
            $res["status"] = DOES_NOT_EXIST;
        }

        return $res;
    }

    /**
     * Remove friends entry from database.
     * @param int $initiator User_ID from the sp_users table.
     * @param int $target User_ID from the sp_users table.
     */
    public function removeFriend($initiator, $target) {
        $stmt = $this->conn->prepare("DELETE FROM SeniorProject.sp_friends "
                . "WHERE (friend_initiatorid = ? AND friend_targetid = ?) OR (friend_initiatorid = ? AND friend_targetid = ?)");
        $stmt->bind_param("iiii", $initiator, $target, $target, $initiator); // Symmetrical to prevent the friendship from degrading into a friend request.
        $stmt->execute();
        $stmt->close();

        $res["status"] = OPERATION_SUCCESS;
        return $res;
    }

    /**
     * Get friends of a particular user.
     * @param int $initiator from the sp_users table.
     * @return array Array of user friends.
     */
    public function getFriends($initiator) {
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore "
                . "FROM SeniorProject.sp_friends f, SeniorProject.sp_users u "
                . "WHERE f.friend_targetid = u.user_id AND f.friend_initiatorid = ? AND "
                . "EXISTS (SELECT * FROM SeniorProject.sp_friends WHERE friend_targetid = ?) "
                . "ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $initiator, $initiator);
        $stmt->execute(); //A relationship must be symmetrical for users to be considered friends.

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
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore "
                . "FROM SeniorProject.sp_friends f, SeniorProject.sp_users u "
                . "WHERE f.friend_targetid = u.user_id AND f.friend_initiatorid = ? AND f.friend_targetid "
                . "NOT IN (SELECT friend_initiatorid FROM SeniorProject.sp_friends WHERE friend_targetid = ?) "
                . "ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $initiator, $initiator);
        $stmt->execute(); //Show only asymmetrical relationships where you are the initiator.

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
        $stmt = $this->conn->prepare("SELECT u.user_id, u.user_name, u.user_receivedscore, u.user_sentscore "
                . "FROM SeniorProject.sp_friends f, SeniorProject.sp_users u "
                . "WHERE f.friend_initiatorid = u.user_id AND f.friend_targetid = ? AND f.friend_initiatorid "
                . "NOT IN (SELECT friend_targetid FROM SeniorProject.sp_friends WHERE friend_initiatorid = ?) "
                . "ORDER BY u.user_name ASC");
        $stmt->bind_param("ii", $target, $target);
        $stmt->execute(); //Show only asymmetrical relationships where you are the target.

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
    function cooldownActive($initiator, $target) {
        $stmt = $this->conn->prepare("SELECT activity_timestamp "
                . "FROM SeniorProject.sp_activities "
                . "WHERE activity_initiator = ? AND activity_target = ? "
                . "ORDER BY activity_timestamp DESC "
                . "LIMIT 1"); //Find the last Boop involving the two users going in a particular direction.
        $stmt->bind_param("ii", $initiator, $target);

        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_assoc();

            if (!is_null($result["activity_timestamp"])) {
                $now = date_create("now");
                $lastBoop = date_create($result["activity_timestamp"]);

                $minutesBetween_DI = date_diff($now, $lastBoop);
                $cooldown_DI = date_diff(date_create("now"), date_create("-10 minutes"));

                $minutesBetween = $minutesBetween_DI->i + $minutesBetween_DI->h * 60 + $minutesBetween_DI->d * 1440 + $minutesBetween_DI->m * 43800 + $minutesBetween_DI->y * 525600; //Convert time interval units to minutes.
                $cooldown = $cooldown_DI->i;

                if ($minutesBetween > $cooldown) { //A user can Boop a particular user only every 10 minutes.
                    return FALSE;
                } else {
                    return TRUE;
                }
            } else {
                return FALSE;
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
    function boopValue($initiator, $target) {
        $res = array();
        $res["initiatorBoopValue"] = 1;
        $res["targetBoopValue"] = 1;
        $res["initiatorElixirsUsed"] = array(); //Elixir ids of the elixirs used.
        $res["ieuHumanReadable"] = array(); //Human readable names of the elixirs used.
        $res["targetElixirsUsed"] = array();
        $res["teuHumanReadable"] = array();

        //Get the total scores for score calculation.
        $stmt = $this->conn->prepare("SELECT user_sentscore "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_id = ?");
        $stmt->bind_param("i", $initiator);
        $stmt->execute();
        $initiatorSS = $stmt->get_result()->fetch_assoc();

        $stmt = $this->conn->prepare("SELECT user_receivedscore "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_id = ?");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $targetRS = $stmt->get_result()->fetch_assoc();

        //Initiator Elixirs.
        $stmt = $this->conn->prepare("SELECT i.inventory_elixir_id, e.elixir_name "
                . "FROM SeniorProject.sp_user_inventories i, SeniorProject.sp_elixirs e "
                . "WHERE i.inventory_user_id = ? AND i.inventory_active = 1 AND i.inventory_elixir_id = e.elixir_id "
                . "ORDER BY i.inventory_elixir_id ASC");
        $stmt->bind_param("i", $initiator);
        $stmt->execute();
        $initiatorElixirs = $stmt->get_result();

        while ($elixir = $initiatorElixirs->fetch_assoc()) {
            $used = FALSE;

            switch ($elixir["inventory_elixir_id"]) { //Each elixir acts in a differing way.
                //Poisons               
                case 2011: //Birch Blood.
                    $tmp = birchBlood(1); //There are 5 strength levels.  This is the argument.
                    $used = TRUE;
                    break;
                case 2012: //Deluxe Birch Blood.
                    $tmp = birchBlood(2);
                    $used = TRUE;
                    break;
                case 2021: //Glove Cleaner.
                    $tmp = gloveCleaner(2);
                    $used = TRUE;
                    break;
                case 2022: //Deluxe Glove Cleaner.
                    $tmp = gloveCleaner(3);
                    $used = TRUE;
                    break;
                case 2023: //Premium Glove Cleaner.
                    $tmp = gloveCleaner(4);
                    $used = TRUE;
                    break;
                case 2031: //Altotoxin.
                    $tmp = altotoxin(3, $targetRS["user_receivedscore"]); //Some elixirs need to know the score of either player.
                    $used = TRUE;
                    break;
                case 2032: //Deluxe Altotoxin.
                    $tmp = altotoxin(4, $targetRS["user_receivedscore"]);
                    $used = TRUE;
                    break;
                case 2033: //Premium Altotoxin.
                    $tmp = altotoxin(5, $targetRS["user_receivedscore"]);
                    $used = TRUE;
                    break;
                case 2041: //Rumpelstiltskin's Decotion.
                    $tmp = rumpDeco(4, $targetRS["user_receivedscore"], $initiatorSS["user_sentscore"]);
                    $used = TRUE;
                    break;
                case 2042: //Deluxe Rumplestiltskin's Decotion.
                    $tmp = rumpDeco(5, $targetRS["user_receivedscore"], $initiatorSS["user_sentscore"]);
                    $used = TRUE;
                    break;
                case 2051: //Vampire Venom.
                    $tmp = vampVenom(4, $targetRS["user_receivedscore"]);
                    $used = TRUE;
                    break;
                case 2052: //Deluxe Vampire Venom.
                    $tmp = vampVenom(5, $targetRS["user_receivedscore"]);
                    $used = TRUE;
                    break;

                //Boosters
                case 4011: //Eagle Eye.
                    $tmp = eagleEye($res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4021: //Lite Corn Syrup.
                    $tmp = cornSyrup(1, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) { //Only mark as used if the Boop value is positive.
                        $used = TRUE;
                    }
                    break;
                case 4022: //Corn Syrup.
                    $tmp = cornSyrup(2, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 4023: //High Fructose Corn Syrup.
                    $tmp = cornSyrup(3, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 4031: //Super Electrolyte Punch.
                    $tmp = electrolytePunch(3, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4032: //Deluxe Mega Electrolyte Punch.
                    $tmp = electrolytePunch(4, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4033: //Premium Mondo Electrolyte Punch.
                    $tmp = electrolytePunch(5, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4041: //Discontinued Cereal Sludge.
                    $tmp = cerealSludge($res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;

                default: //Catches cheaters.  Unknown items reset the Boop value.
                    $res["initiatorBoopValue"] = 1;
                    $res["targetBoopValue"] = 1;
                    break;
            }

            if ($used) { //If an elixir is used
                $this->decrementInventory($initiator, $elixir["inventory_elixir_id"]);
                $res["initiatorElixirsUsed"][] = $elixir["inventory_elixir_id"];
                $res["ieuHumanReadable"][] = $elixir["elixir_name"];
                $res["initiatorBoopValue"] += $tmp["iniBVal"];
                $res["targetBoopValue"] += $tmp["tarBVal"];
            }
        }

        //Target Elixirs. Has a chance to affect the incoming Boop values.
        $stmt = $this->conn->prepare("SELECT i.inventory_elixir_id, e.elixir_name FROM SeniorProject.sp_user_inventories i, SeniorProject.sp_elixirs e WHERE i.inventory_user_id = ? AND i.inventory_active = 1 AND i.inventory_elixir_id = e.elixir_id ORDER BY i.inventory_elixir_id ASC");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $targetElixirs = $stmt->get_result();

        while ($elixir = $targetElixirs->fetch_assoc()) {
            $used = FALSE;

            switch ($elixir["inventory_elixir_id"]) {

                //Shields
                case 1011: //Wood Mitigation Shield.
                    $tmp = mitigationShield(1, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) { //A shield is only marked used if it is useful.
                        $used = TRUE;
                    }
                    break;
                case 1012: //Bronze Mitigation Shield.
                    $tmp = mitigationShield(2, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1013: //Iron Mitigation Shield.
                    $tmp = mitigationShield(3, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {

                        $used = TRUE;
                    }
                    break;
                case 1014: //Rearden Steel Mitigation Shield.
                    $tmp = mitigationShield(4, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1015: //Diamond Mitigation Shield.
                    $tmp = mitigationShield(5, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1021: //Wood Negation Shield.
                    $tmp = negationShield(1, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1022: //Bronze Negation Shield.
                    $tmp = negationShield(2, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1023: //Iron Negation Shield.
                    $tmp = negationShield(3, $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 1024: //Rearden Steel Negation Shield.
                    $tmp = negationShield(4, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1025: //Diamond Negation Shield.
                    $tmp = negationShield(5, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1031: //Wood Reflection Shield.
                    $tmp = reflectionShield(1, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1032: //Bronze Reflection Shield.
                    $tmp = reflectionShield(2, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1033: //Iron Reflection Shield.
                    $tmp = reflectionShield(3, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1034: //Rearden Steel Reflection Shield.
                    $tmp = reflectionShield(4, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1035: //Diamond Reflection Shield.
                    $tmp = reflectionShield(5, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1041: //Rearden Steel Inversion Shield.
                    $tmp = inversionShield(4, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;
                case 1042: //Diamond Inversion Shield.
                    $tmp = inversionShield(5, $res["targetBoopValue"]);
                    if ($tmp["iniBVal"] > 0 and $tmp["tarBVal"] > 0) {
                        $used = TRUE;
                    }
                    break;

                //Antivenom
                case 3011: //Peptotumsinol.
                    $tmp = peptotumsinol($res["targetBoopValue"]);
                    $used = TRUE;
                    break;

                //Boosters
                case 4031: //Super Electrolyte Punch.
                    $tmp = electrolytePunch(3, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4032: //Deluxe Mega Electrolyte Punch.
                    $tmp = electrolytePunch(4, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;
                case 4033: //Premium Mondo Electrolyte Punch.
                    $tmp = electrolytePunch(5, $res["initiatorBoopValue"], $res["targetBoopValue"]);
                    $used = TRUE;
                    break;

                default:
                    $res["initiatorBoopValue"] = 1;
                    $res["targetBoopValue"] = 1;
                    break;
            }
            if ($used) {
                $this->decrementInventory($target, $elixir["inventory_elixir_id"]);
                $res["targetElixirsUsed"][] = $elixir["inventory_elixir_id"];
                $res["teuHumanReadable"][] = $elixir["elixir_name"];
                $res["initiatorBoopValue"] += $tmp["iniBVal"];
                $res["targetBoopValue"] += $tmp["tarBVal"];
            }
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
        if (!($initiator == $target) and ! $this->cooldownActive($initiator, $target)) { //Make sure that user isn't Booping self or too early.
            $res = $this->boopValue($initiator, $target);
            $timestamp = date('Y-m-d G:i:s'); //Important that it is the same, used in PK. Cheaper than inserting and then searching.

            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_activities(activity_initiator, activity_target, activity_value_initiator, activity_value_target, activity_timestamp) "
                    . "VALUES(?,?,?,?,?)");
            $stmt->bind_param("iiiis", $initiator, $target, round($res["initiatorBoopValue"]), round($res["targetBoopValue"]), $timestamp);
            if ($stmt->execute()) { //Insert activity entry.
                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users "
                        . "SET user_sentscore = user_sentscore + ? "
                        . "WHERE user_id = ?");
                $stmt->bind_param("ii", round($res["initiatorBoopValue"]), $initiator);
                $stmt->execute(); //Update the initiator sent score.

                foreach ($res["initiatorElixirsUsed"] as $elixir) { //Add elixirs to the history table.
                    $this->addHistory($timestamp, $initiator, $target, $elixir, 1);
                }

                $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users "
                        . "SET user_receivedscore = user_receivedscore + ? "
                        . "WHERE user_id = ?");
                $stmt->bind_param("ii", round($res["targetBoopValue"]), $target);
                $stmt->execute();

                foreach ($res["targetElixirsUsed"] as $elixir) {
                    $this->addHistory($timestamp, $initiator, $target, $elixir, 0);
                }

                $randElixir = $this->randomElixir(); //Reward initiator with qty 1-2 of a random elixir.
                if ($randElixir > 0) { //Is -1 if nothing is received. 25% chance.
                    $this->addInventoryItem($initiator, $randElixir, mt_rand(1, 2));

                    $stmt = $this->conn->prepare("SELECT elixir_name, elixir_desc "
                            . "FROM SeniorProject.sp_elixirs "
                            . "WHERE elixir_id = ?");
                    $stmt->bind_param("i", $randElixir);
                    $stmt->execute();

                    $tmp = $stmt->get_result()->fetch_assoc();
                    $tmp2["name"] = $tmp["elixir_name"];
                    $tmp2["desc"] = $tmp["elixir_desc"];

                    array_push($response["reward"], $tmp2);
                }
                $stmt->close();

                $response["timestamp"] = $timestamp;
                $response["initiator boop value"] = round($res["initiatorBoopValue"]);
                $response["target boop value"] = round($res["targetBoopValue"]);
                $response["initiator elixirs used"] = $res["ieuHumanReadable"];
                $response["target elixirs used"] = $res["teuHumanReadable"];
                $response["status"] = OPERATION_SUCCESS;
            } else {
                $stmt->close();
                $response["status"] = OPERATION_FAILED;
            }
        } else {
            $response["status"] = TIME_CONSTRAINT;
        }
        return $response;
    }

    /**
     * Get the boops that other users have sent to you since the last time you checked.
     * @param type $userID
     * @return array
     */
    public function getBoopsSinceChecked($userID) {
        $response = array();
        $response["boops since last checked"] = array();

        $stmt = $this->conn->prepare("SELECT user_lastchecked "
                . "FROM SeniorProject.sp_users "
                . "WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute(); //Get when the user has last checked.
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
                if ($elixir["elixir_activity_iORt"] == 1) { //Put the retrieved history in the correct array.
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

        $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_users "
                . "SET user_lastchecked = NOW() "
                . "WHERE user_id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute(); //Update the time the user has last checked.

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
    function addInventoryItem($userID, $elixirID, $quantity) {
        $res = array();

        $stmt = $this->conn->prepare("SELECT * "
                . "FROM SeniorProject.sp_user_inventories "
                . "WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
        $stmt->bind_param("ii", $userID, $elixirID);
        $stmt->execute(); //Find out if an entry already exists.
        $stmt->store_result();

        if ($stmt->num_rows <= 0) { //Insert new row.
            $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_user_inventories(inventory_user_id, inventory_elixir_id, inventory_quantity)"
                    . " VALUES(?,?,?)");
            $stmt->bind_param("iii", $userID, $elixirID, $quantity);
            $stmt->execute();
        } else { //Update row.
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_user_inventories "
                    . "SET inventory_quantity = inventory_quantity + ? "
                    . "WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("iii", $quantity, $userID, $elixirID);
            $stmt->execute();
        }

        $res["status"] = OPERATION_SUCCESS;
        $stmt->close();
        return $res;
    }

    /**
     * Decrement inventory item.
     * @param int $userID
     * @param int $elixirID
     */
    function decrementInventory($userID, $elixirID) {
        $result = array();

        $stmt = $this->conn->prepare("SELECT inventory_quantity "
                . "FROM SeniorProject.sp_user_inventories "
                . "WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
        $stmt->bind_param("ii", $userID, $elixirID);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if ($res["inventory_quantity"] <= 1) { //I would rather have no entry than an empty one.  Personal preference.
            $stmt = $this->conn->prepare("DELETE IGNORE "
                    . "FROM SeniorProject.sp_user_inventories "
                    . "WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("ii", $userID, $elixirID);
            $stmt->execute();
        } else {
            $stmt = $this->conn->prepare("UPDATE SeniorProject.sp_user_inventories "
                    . "SET inventory_quantity = inventory_quantity - 1 "
                    . "WHERE inventory_user_id = ? AND inventory_elixir_id = ?");
            $stmt->bind_param("ii", $userID, $elixirID);
            $stmt->execute();
        }

        $result["status"] = OPERATION_SUCCESS;
        $stmt->close();
        return $result;
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
        $res = array();
        $res["inventory"] = array();

        $stmt = $this->conn->prepare("SELECT eli.elixir_id, eli.elixir_type, eli.elixir_name, eli.elixir_desc, inv.inventory_quantity, inv.inventory_active
                                      FROM SeniorProject.sp_elixirs eli, SeniorProject.sp_user_inventories inv  
                                      WHERE inv.inventory_elixir_id = eli.elixir_id AND inv.inventory_user_id = ?
                                      ORDER BY eli.elixir_id ASC");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $res["inventory"] = $stmt->get_result();

        $stmt->close();
        $res["status"] = OPERATION_SUCCESS;
        return $res;
    }

    /**
     * Returns a random elixir or nothing.
     * @return int
     */
    function randomElixir() {
        $randStrength = mt_rand(0, 100000);

        if ($randStrength <= 25000) { //Get nothing. 25% chance.
            return -1;
        } else if ($randStrength > 25000 and $randStrength <= 50000) { //Get level 1 item. 25% chance.
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
        } else if ($randStrength > 50000 and $randStrength <= 68750) { //Get level 2 item. 19% chance.
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
        } else if ($randStrength > 68750 and $randStrength <= 84375) { //Get level 3 item. 16% chance.
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
        } else if ($randStrength > 84375 and $randStrength <= 93750) { //Get level 4 item. 9% chance.
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
        } else if ($randStrength > 93750) { //Get level 5 item. 6% chance.
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
    function addHistory($timestamp, $initiator, $target, $elixirID, $iORt) {
        $stmt = $this->conn->prepare("INSERT INTO SeniorProject.sp_elixir_activities(elixir_activity_timestamp, elixir_activity_initiator, elixir_activity_target, elixir_activity_elixirid, elixir_activity_iORt) "
                . "VALUES(?,?,?,?,?)");
        $stmt->bind_param("siiii", $timestamp, $initiator, $target, $elixirID, $iORt);
        $stmt->execute();
    }

    /* --- STATS METHODS --- */

    /**
     * Return X top senders.
     * @param int $number
     * @return array
     */
    public function globalTopSenders($number) {
        $res = array();
        $res["top senders"] = array();

        $stmt = $this->conn->prepare("SELECT user_name, user_sentscore, user_receivedscore "
                . "FROM SeniorProject.sp_users "
                . "ORDER BY user_sentscore DESC "
                . "LIMIT ?");
        $stmt->bind_param("i", $number);
        $stmt->execute();

        $res["status"] = OPERATION_SUCCESS;
        $res["top senders"] = $stmt->get_result();

        $stmt->close();
        return $res;
    }

    /**
     * Return X top receivers.
     * @param int $number
     * @return array
     */
    public function globalTopReceivers($number) {
        $res = array();
        $res["top receivers"] = array();

        $stmt = $this->conn->prepare("SELECT user_name, user_sentscore, user_receivedscore "
                . "FROM SeniorProject.sp_users "
                . "ORDER BY user_receivedscore DESC "
                . "LIMIT ?");
        $stmt->bind_param("i", $number);
        $stmt->execute();

        $res["status"] = OPERATION_SUCCESS;
        $res["top receivers"] = $stmt->get_result();

        $stmt->close();
        return $res;
    }

}

?>