<?php
/**
 * ORM Auth driver.
 *
 * @copyright  (c) 2007-2016  Kohana Team
 * @copyright  (c) 2016-2019  Koseven Team
 * @copyright  (c) since 2019 Modseven Team
 * @license        https://koseven.ga/LICENSE
 */

namespace Modseven\ORM\Auth;

use Modseven\Cookie;
use Modseven\Request;

use Modseven\Auth\Auth;
use Modseven\ORM\Exception;
use Modseven\ORM\Model\Auth\User;
use Modseven\ORM\Model\Auth\Role;
use Modseven\ORM\Model\Auth\User\Token;

class ORM extends Auth
{
    
    /**
     * Checks if a session is active.
     *
     * @param mixed $role Role name string, role ORM object, or array with role names
     *
     * @return  boolean
     *
     * @throws Exception
     */
    public function loggedIn($role = null) : bool
    {
        // Get the user from the session
        $user = $this->getUser();

        if ( ! $user)
        {
            return false;
        }

        if ($user instanceof User && $user->loaded())
        {
            // If we don't have a roll no further checking is needed
            if ( ! $role)
            {
                return true;
            }

            if (is_array($role))
            {
                // Get all the roles
                $roles = \Modseven\ORM\ORM::factory($this->classRole())
                            ->where('name', 'IN', $role)
                            ->findAll()
                            ->asArray(null, 'id');

                // Make sure all the roles are valid ones
                if (count($roles) !== count($role))
                {
                    return false;
                }
            }
            elseif ( ! is_object($role))
            {
                // Load the role
                $roles = \Modseven\ORM\ORM::factory($this->classRole(), ['name' => $role]);

                if ( ! $roles->loaded())
                {
                    return false;
                }
            }
            else
            {
                $roles = $role;
            }

            return $user->has('roles', $roles);
        }

        return false;
    }

    /**
     * Logs a user in.
     *
     * @param mixed   $user     User to login
     * @param string  $password Password
     * @param boolean $remember enable auto login
     *
     * @return  boolean
     *
     * @throws \Modseven\Exception
     * @throws \Modseven\Auth\Exception
     */
    protected function _login($user, string $password, bool $remember)
    {
        if ( ! is_object($user)) {
            $username = $user;

            // Load the user
            $user = \Modseven\ORM\ORM::factory($this->classUser());
            $user->where($user->uniqueKey($username), '=', $username)->find();
        }

        // Create a hashed password
        $password = $this->hash($password);

        // If the passwords match, perform a login
        if ($user->password === $password && $user->has('roles', \Modseven\ORM\ORM::factory($this->classRole(), ['name' => 'login'])))
        {
            if ($remember === true)
            {
                // Token data
                $data = [
                    'user_id'    => $user->pk(),
                    'expires'    => time() + $this->_config['lifetime'],
                    'user_agent' => sha1(Request::$user_agent),
                ];

                // Create a new autologin token
                $token = \Modseven\ORM\ORM::factory($this->classToken())
                            ->values($data)
                            ->create();

                // Set the autologin cookie
                Cookie::set('authautologin', $token->token, $this->_config['lifetime']);
            }

            // Finish the login
            $this->completeLogin($user);

            return true;
        }

        // Login failed
        return false;
    }

    /**
     * Forces a user to be logged in, without specifying a password.
     *
     * @param mixed   $user                   username string, or user ORM object
     * @param boolean $mark_session_as_forced mark the session as forced
     */
    public function forceLogin($user, bool $mark_session_as_forced = false) : void
    {
        if ( ! is_object($user)) {
            $username = $user;

            // Load the user
            $user = \Modseven\ORM\ORM::factory($this->classUser());
            $user->where($user->uniqueKey($username), '=', $username)->find();
        }

        if ($mark_session_as_forced === true) {
            // Mark the session as forced, to prevent users from changing account information
            $this->_session->set('auth_forced', true);
        }

        // Run the standard completion
        $this->completeLogin($user);
    }

    /**
     * Logs a user in, based on the authautologin cookie.
     *
     * @return  mixed
     *
     * @throws \Modseven\Exception
     */
    public function autoLogin()
    {
        if ($token = Cookie::get('authautologin'))
        {
            // Load the token and user
            $token = \Modseven\ORM\ORM::factory($this->classToken(), ['token' => $token]);

            if ($token->loaded() && $token->user->loaded())
            {
                if (hash_equals($token->user_agent, sha1(Request::$user_agent)))
                {
                    // Save the token to create a new unique token
                    $token->save();

                    // Set the new token
                    Cookie::set('authautologin', $token->token, $token->expires - time());

                    // Complete the login with the found data
                    $this->completeLogin($token->user);

                    // Automatic login was successful
                    return $token->user;
                }

                // Token is invalid
                $token->delete();
            }
        }

        return false;
    }

    /**
     * Gets the currently logged in user from the session (with auto_login check).
     * Returns $default if no user is currently logged in.
     *
     * @param mixed $default to return in case user isn't logged in
     *
     * @return  mixed
     *
     * @throws \Modseven\Exception
     */
    public function getUser($default = null)
    {
        $user = parent::getUser($default);

        // check for "remembered" login
        if (($user === $default) && ($user = $this->autoLogin()) === false)
        {
            return $default;
        }

        return $user;
    }

    /**
     * Log a user out and remove any autologin cookies.
     *
     * @param boolean $destroy    completely destroy the session
     * @param boolean $logout_all remove all tokens for user
     *
     * @return  boolean
     *
     * @throws \Modseven\Exception
     */
    public function logout($destroy = false, $logout_all = false) : bool
    {
        // Set by force_login()
        $this->_session->delete('auth_forced');

        if ($token = Cookie::get('authautologin'))
        {
            // Delete the autologin cookie to prevent re-login
            Cookie::delete('authautologin');

            // Clear the autologin token from the database
            $token = \Modseven\ORM\ORM::factory($this->classToken(), ['token' => $token]);

            if ($logout_all && $token->loaded())
            {
                // Delete all user tokens. This isn't the most elegant solution but does the job
                $tokens =  \Modseven\ORM\ORM::factory($this->classToken())->where('user_id', '=', $token->user_id)->findAll();

                foreach ($tokens as $_token)
                {
                    $_token->delete();
                }
            }
            elseif ($token->loaded())
            {
                $token->delete();
            }
        }

        return parent::logout($destroy);
    }

    /**
     * Get the stored password for a username.
     *
     * @param mixed $user username string, or user ORM object
     *
     * @return  string
     */
    public function password($user) : string
    {
        if ( ! is_object($user))
        {
            $username = $user;

            // Load the user
            $user = \Modseven\ORM\ORM::factory($this->classUser());
            $user->where($user->uniqueKey($username), '=', $username)->find();
        }

        return $user->password;
    }

    /**
     * Complete the login for a user by incrementing the logins and setting
     * session data: user_id, username, roles.
     *
     * @param object $user user ORM object
     *
     * @return  bool
     */
    protected function completeLogin($user) : bool
    {
        $user->complete_login();

        return parent::completeLogin($user);
    }

    /**
     * Compare password with original (hashed). Works for current (logged in) user
     *
     * @param string $password
     *
     * @return  boolean
     *
     * @throws \Modseven\Exception
     * @throws \Modseven\Auth\Exception
     */
    public function checkPassword(string $password) : bool
    {
        $user = $this->getUser();

        if ( ! $user) {
            return false;
        }

        return hash_equals($this->hash($password), $user->password);
    }
    
    protected function classRole() : string
    {
        return $this->_config['classRole'];
    }
    
    protected function classUser() : string
    {
        return $this->_config['classUser'];
    }
    
    protected function classToken() : string
    {
        return $this->_config['classToken'];
    }
    
    
}
