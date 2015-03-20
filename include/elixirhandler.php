<?php

/* --- POISONS --- */

/**
 * Birch blood, do random small damage.
 * @param int $strength
 * @return array
 */
function birchBlood($strength) {
    $values = array();

    switch ($strength) {
        case 2: //Deluxe Birch Blood
            $val = mt_rand(4, 6);
            break;
        default: //Birch Blood.
            $val = mt_rand(2, 3);
            break;
    }

    $values["iniBVal"] = 0 - $val;
    $values["tarBVal"] = 0 - $val;

    return $values;
}

/**
 * Glove cleaner, do constant medium damage.
 * @param int $strength
 * @return array
 */
function gloveCleaner($strength) {
    $values = array();

    switch ($strength) {
        case 4: //Premium Glove Cleaner
            $val = -15;
            break;
        case 3: //Deluxe Glove Cleaner
            $val = -10;
            break;
        default: //Glove Cleaner
            $val = -5;
            break;
    }

    $values["iniBVal"] = $val;
    $values["tarBVal"] = $val;

    return $values;
}

/**
 * Altotoxin, do percentage damage.
 * @param int $strength
 * @param int $targetRS
 * @return type
 */
function altotoxin($strength, $targetRS) {
    $values = array();

    switch ($strength) {
        case 5: //Premium Altotoxin
            $val = $targetRS * .1;
            break;
        case 4: //Deluxe Altotoxin
            $val = $targetRS * .02;
            break;
        default: //Altotoxin
            $val = $targetRS * .01;
            break;
    }

    $values["iniBVal"] = 0 - $val;
    $values["tarBVal"] = 0 - $val;

    return $values;
}

/**
 * Rumpelstilskin's Decoction, do higher percentile damage in exchange for a smaller percentile cost.
 * @param int $strength
 * @param int $targetRS
 * @param int $initiatorSS
 * @return array
 */
function rumpDeco($strength, $targetRS, $initiatorSS) {
    $values = array();

    switch ($strength) {
        case 5: //Deluxe Rumplestiltskin's Decoction
            $values["iniBVal"] = 0 - ($initiatorSS * .03);
            $values["tarBVal"] = 0 - ($targetRS * .07);
            break;
        default: //Rumplestiltskin's Decoction
            $values["iniBVal"] = 0 - ($initiatorSS * .07);
            $values["tarBVal"] = 0 - ($targetRS * .13);
            break;
    }

    return $values;
}

/**
 * Vampire Venom, do percentile damage, and deliver positive points to the initiator.
 * @param int $strength
 * @param int $targetRS
 * @return array
 */
function vampVenom($strength, $targetRS) {
    $values = array();

    switch ($strength) {
        case 5: //Deluxe Vampire Venom
            $values["iniBVal"] = $targetRS * .1;
            $values["tarBVal"] = 0 - ($targetRS * .1);
            break;
        default: //Vampire Venom
            $values["iniBVal"] = $targetRS * .05;
            $values["tarBVal"] = 0 - ($targetRS * .05);
            break;
    }

    return $values;
}

/* --- BOOSTERS --- */

/**
 * Eagle eye, doubles the magnitude of a boop.
 * @param int $initiatorBoopValue
 * @param int $targetBoopValue
 * @return array
 */
function eagleEye($initiatorBoopValue, $targetBoopValue) {
    $values = array();

    //Eagle Eye doubles magnitude, but does not change the sign.

    if ($initiatorBoopValue < 0) {
        $values["iniBVal"] = 0 - ($initiatorBoopValue * $initiatorBoopValue);
    } else {
        $values["iniBVal"] = $initiatorBoopValue * $initiatorBoopValue;
    }

    if ($targetBoopValue < 0) {
        $values["tarBVal"] = 0 - ($targetBoopValue * $targetBoopValue);
    } else {
        $values["tarBVal"] = $targetBoopValue * $targetBoopValue;
    }

    return $values;
}

/**
 * Corn Syrup, adds a small constant to a Boop
 * @param int $strength
 * @return array
 */
function cornSyrup($strength) {
    $values = array();

    switch ($strength) {
        case 3: //High Fructose Corn Syrup
            $val = mt_rand(4, 8);
            break;
        case 2: //Corn Syrup
            $val = mt_rand(2, 4);
            break;
        default: //Lite Corn Syrup
            $val = mt_rand(1, 2);
            break;
    }

    $values["iniBVal"] = $val;
    $values["tarBVal"] = $val;

    return $values;
}

/**
 * Electrolyte Punch, increases a boop by a percentage.
 * @param int $strength
 * @param int $initiatorBoopValue
 * @param int $targetBoopValue
 * @return array
 */
function electrolytePunch($strength, $initiatorBoopValue, $targetBoopValue) {
    $values = array();

    switch ($strength) {
        case 5: //Premium Mondo Electrolyte Punch
            $values["iniBVal"] = $initiatorBoopValue * .5;
            $values["tarBVal"] = $targetBoopValue * .5;
            break;
        case 4: //Deluxe Mega Electrolyte Punch
            $values["iniBVal"] = $initiatorBoopValue * .25;
            $values["tarBVal"] = $targetBoopValue * .25;
            break;
        default: //Super Electrolyte Punch
            $values["iniBVal"] = $initiatorBoopValue * .05;
            $values["tarBVal"] = $targetBoopValue * .05;
            break;
    }

    return $values;
}

/**
 * Discontinued Cereal Sludge, multiplies by .25, then adds 10.
 * @param type $initiatorBoopValue
 * @param type $targetBoopValue
 * @return type
 */
function cerealSludge($initiatorBoopValue, $targetBoopValue) {
    $values = array();

    $values["iniBVal"] = ($initiatorBoopValue * .25) + 10;
    $values["tarBVal"] = ($targetBoopValue * .25) + 10;

    return $values;
}

/* --- SHIELDS --- */

/**
 * Mitigation Shield, blocks a percentage of incoming damage.
 * @param int $strength
 * @param int $targetBoopValue
 * @return array
 */
function mitigationShield($strength, $targetBoopValue) {
    $values = array();

    if ($targetBoopValue < 0) { //If damage is aimed at the target.
        switch ($strength) {
            case 5: //Diamond Mitigation Shield
                $values["iniBVal"] = 0; //Poison still affects initiator Sent Score.
                $values["tarBVal"] = abs($targetBoopValue * .95);
                break;
            case 4: //Rearden Steel Mitigation Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * .75);
                break;
            case 3: //Iron Mitigation Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * .50);
                break;
            case 2: //Bronze Mitigation Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * .25);
                break;
            default: //Wood Mitigation Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * .10);
                break;
        }
    } else {
        $values["iniBVal"] = 0;
        $values["tarBVal"] = 0;
    }

    return $values;
}

/**
 * Negation Shield, absorbs up to X incoming damage.
 * @param int $strength
 * @param int $targetBoopValue
 * @return array
 */
function negationShield($strength, $targetBoopValue) {
    $values = array();

    if ($targetBoopValue < 0) { //If damage is aimed at the target.
        switch ($strength) {
            case 5: //Diamond Negation Shield
                $shieldPts = 30;
                break;
            case 4: //Rearden Steel Negation Shield
                $shieldPts = 20;
                break;
            case 3: //Iron Negation Shield
                $shieldPts = 10;
                break;
            case 2: //Bronze Negation Shield
                $shieldPts = 5;
                break;
            default: //Wood Negation Shield
                $shieldPts = 2;
                break;
        }

        $count = 0;
        while ($targetBoopValue + $count < 0 and $count < $shieldPts) {
            $count++;
        }

        $values["iniBVal"] = 0; //Poison still affects initiator Sent Score.
        $values["tarBVal"] = $count;
    } else {
        $values["iniBVal"] = 0;
        $values["tarBVal"] = 0;
    }

    return $values;
}

/**
 * Reflection Shield, blocks and reflects a percentage of damage.
 * @param type $strength
 * @param type $targetBoopValue
 * @return array
 */
function reflectionShield($strength, $targetBoopValue) {
    $values = array();

    if ($targetBoopValue < 0) { //If damage is aimed at the target.
        switch ($strength) {
            case 5: //Diamond Reflection Shield
                $percent = .95;
                break;
            case 4: //Rearden Steel Reflection Shield
                $percent = .50;
                break;
            case 3: //Iron Reflection Shield
                $percent = .25;
                break;
            case 2: //Bronze Reflection Shield
                $percent = .10;
                break;
            default: //Wood Reflection Shield
                $percent = .05;
                break;
        }

        $values["iniBVal"] = $targetBoopValue * $percent;
        $values["tarBVal"] = abs($targetBoopValue * $percent);
    } else {
        $values["iniBVal"] = 0;
        $values["tarBVal"] = 0;
    }

    return $values;
}

/**
 * Inversion Shield, blocks a percentage of damage and converts a percentage of damage to positive points.
 * @param int $strength
 * @param int $targetBoopValue
 * @return array
 */
function inversionShield($strength, $targetBoopValue) {
    $values = array();

    if ($targetBoopValue < 0) { //If damage is aimed at the target.
        switch ($strength) {
            case 5: //Diamond Inversion Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * 2);
                break;
            default: //Rearden Steel Inversion Shield
                $values["iniBVal"] = 0;
                $values["tarBVal"] = abs($targetBoopValue * 1.50);
                break;
        }
    } else {
        $values["iniBVal"] = 0;
        $values["tarBVal"] = 0;
    }

    return $values;
}

/* --- ANTIVENOM --- */

/**
 * Antivenom, completely counteracts damage.
 * @param int $targetBoopValue
 * @return array
 */
function antivenom($targetBoopValue) {
    $values = array();

    if ($targetBoopValue < 0) { //If damage is aimed at the target.
        $values["iniBVal"] = 0;
        $values["tarBVal"] = abs($targetBoopValue);
    } else {
        $values["iniBVal"] = 0;
        $values["tarBVal"] = 0;
    }

    return $values;
}

?>