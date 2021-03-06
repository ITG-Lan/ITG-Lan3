<?php

function sec_session_start() {
    $session_name = 'itglanSession';   // Set a custom session name
    $secure = false;
    // This stops JavaScript being able to access the session id.
    $httponly = true;
    // Forces sessions to only use cookies.
    ini_set('session.use_only_cookies',1);
    $inactive = 3600;
    ini_set('session.gc_maxlifetime', $inactive); // set the session max lifetime
    if (ini_set('session.use_only_cookies', 1) === FALSE) {
        header("Location: index.php?error=Kunde inte starta en saker session");
        exit();
    }
    // Gets current cookies params.
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params($cookieParams["lifetime"],
        $cookieParams["path"], 
        $cookieParams["domain"], 
        $secure,
        $httponly);
    // Sets the session name to the one set above.
    session_name($session_name);
    session_start();            // Start the PHP session 
    session_regenerate_id();    // regenerated the session, delete the old one. 
    $_SESSION['CREATED'] = time();
}

function login($username, $password, $mysqli, $valid = false) {
    // Using prepared statements means that SQL injection is not possible.
    if ($stmt = $mysqli->prepare("SELECT id, username, password, salt FROM admin WHERE username = ? LIMIT 1")) {
        $stmt->bind_param('s', $username);  // Bind "$username" to parameter.
        $mysqli->query("SET NAMES 'UTF8';");
        $stmt->execute();    // Execute the prepared query.
        $stmt->store_result();
 
        // get variables from result.
        $stmt->bind_result($user_id, $username, $db_password, $salt);
        $stmt->fetch();
 
        // hash the password with the unique salt.
        $password = hash('sha512', $password . $salt);
        if ($stmt->num_rows == 1) {
            // If the user exists we check if the account is locked
            // from too many login attempts 
 
            if (checkbrute($user_id, $mysqli) == true) //Kanske bruteforce
            {
                if(isset($_SESSION['brute'])) //Är den satt så kollar vi om kontot är låst eller inte
                {
                    if ($_SESSION['brute'] == "captcha") //Kanske brute, kolla captcha
                    {
                        if ($valid)
                        {
                            // Check if the password in the database matches
                            // the password the user submitted.
                            if ($db_password == $password)
                            {
                                // Password is correct!
                                // Get the user-agent string of the user.
                                $user_browser = $_SERVER['HTTP_USER_AGENT'];
                                // XSS protection as we might print this value
                                $user_id = preg_replace("/[^0-9]+/", "", $user_id);
                                $_SESSION['user_id'] = $user_id;
                                // XSS protection as we might print this value
                                $username = preg_replace("/[^a-zA-ZÅåÄäÖö0-9_\-]+/", "", $username);
                                $_SESSION['username'] = $username;
                                $_SESSION['login_string'] = hash('sha512', $password . $user_browser);
                                $_SESSION['afk'] = time();
                                $mysqli->query("DELETE FROM login_attempts WHERE user_id='$user_id';");
                                unset($_SESSION['brute']);
                                // Login successful.
                                return true;
                            }
                            else
                            {
                                // Password is not correct
                                // We record this attempt in the database
                                $now = time();
                                $ip = $_SERVER['REMOTE_ADDR'];
                                $mysqli->query("INSERT INTO login_attempts(user_id, time, ip) VALUES ('$user_id', '$now', '$ip')");
                                return false;
                            }
                        }
                        else
                        {
                            header('Location: index.php?error=brute');
                        }
                    }
                    elseif ($_SESSION['brute'] == "locked") //brute, kontot låst
                    {
                        return false;
                    }
                }
                else
                {
                    // Account is locked 
                    header('Location: index.php?error=brute');
                }
            }
            else
            {
                // Check if the password in the database matches
                // the password the user submitted.
                if ($db_password == $password)
                {
                    // Password is correct!
                    // Get the user-agent string of the user.
                    $user_browser = $_SERVER['HTTP_USER_AGENT'];
                    // XSS protection as we might print this value
                    $user_id = preg_replace("/[^0-9]+/", "", $user_id);
                    $_SESSION['user_id'] = $user_id;
                    // XSS protection as we might print this value
                    $username = preg_replace("/[^a-zA-ZÅåÄäÖö0-9_\-]+/", "", $username);
                    $_SESSION['username'] = $username;
                    $_SESSION['login_string'] = hash('sha512', $password . $user_browser);
                    $_SESSION['afk'] = time();
                    unset($_SESSION['brute']);
                    // Login successful.
                    return true;
                }
                else
                {
                    // Password is not correct
                    // We record this attempt in the database
                    $now = time();
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $mysqli->query("INSERT INTO login_attempts(user_id, time, ip) VALUES ('$user_id', '$now', '$ip')");
                    return false;
                }
            }
        }
        else
        {
            // No user exists.
            return false;
        }
    }
}

function checkbrute($user_id, $mysqli) {
    // Get timestamp of current time 
    $now = time();
 
    // All login attempts are counted from the past 2 hours. 
    $valid_attempts = $now - (1 * 60 * 60);
 
    if ($stmt = $mysqli->prepare("SELECT time FROM login_attempts WHERE user_id = ? AND time > '$valid_attempts'")) {
        $stmt->bind_param('i', $user_id);
 
        // Execute the prepared query. 
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows >= 5 && $stmt->num_rows <= 15) //Visa captcha
        {
            $_SESSION['brute'] = "captcha";
            return true;
        }
        elseif ($stmt->num_rows >= 16) //Mer än 15 försök, då låser den sig i en timme
        {
            $_SESSION['brute'] = "locked";
            return true;
        }
        else //Inge bruteforce än...
        {
            if(isset($_SESSION['brute']))
            {
                unset($_SESSION['brute']);
            }
            return false;
        }
    }
}

function login_check($mysqli) {
    // Check if all session variables are set 
    if (isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'])) {
 
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $username = $_SESSION['username'];
 
        // Get the user-agent string of the user.
        $user_browser = $_SERVER['HTTP_USER_AGENT'];
 
        if ($stmt = $mysqli->prepare("SELECT password FROM admin WHERE id = ? LIMIT 1")) {
            // Bind "$user_id" to parameter. 
            $stmt->bind_param('i', $user_id);
            $mysqli->query("SET NAMES 'UTF8';");
            $stmt->execute();   // Execute the prepared query.
            $stmt->store_result();
 
            if ($stmt->num_rows == 1) {
                // If the user exists get variables from result.
                $stmt->bind_result($password);
                $stmt->fetch();
                $login_check = hash('sha512', $password . $user_browser);
 
                if ($login_check == $login_string)
                {
                    $inactive = 900;
                    if (time() - $_SESSION['afk'] > $inactive)
                    {
                        // Unset all session values 
                        $_SESSION = array();
                         
                        // get session parameters 
                        $params = session_get_cookie_params();
                         
                        // Delete the actual cookie. 
                        setcookie(session_name(),
                                '', time() - 42000, 
                                $params["path"], 
                                $params["domain"], 
                                $params["secure"], 
                                $params["httponly"]);
                         
                        // Destroy session 
                        session_destroy();
                        header('Location: index.php?error=afk');
                    }
                    elseif (time() - $_SESSION['CREATED'] > 3600)
                    {
                        // Unset all session values 
                        $_SESSION = array();
                         
                        // get session parameters 
                        $params = session_get_cookie_params();
                         
                        // Delete the actual cookie. 
                        setcookie(session_name(),
                                '', time() - 42000, 
                                $params["path"], 
                                $params["domain"], 
                                $params["secure"], 
                                $params["httponly"]);
                         
                        // Destroy session 
                        session_destroy();
                        header('Location: index.php?error=timeout');
                    }
                    else
                    {
                        $_SESSION['afk'] = time();
                        // Logged In!!!! 
                        return true;
                    }

                }
                else {
                    // Not logged in 
                    return false;
                }
            } else {
                // Not logged in 
                return false;
            }
        } else {
            // Not logged in 
            return false;
        }
    } else {
        // Not logged in 
        return false;
    }
}
?>