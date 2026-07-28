<?php

namespace App;

class Auth
{
    private const SESSION_KEY = 'user_authenticated';

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(string $password): bool
    {
        $hash = Config::get('ADMIN_PASSWORD_HASH');
        if (password_verify($password, $hash)) {
            $_SESSION[self::SESSION_KEY] = true;
            session_regenerate_id(true);
            return true;
        }
        return false;
    }

    public static function check(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function require(): void
    {
        if (!self::check()) {
            $page = $_GET['page'] ?? 'dashboard';
            $redirect = urlencode($page);
            header('Location: index.php?page=login&redirect=' . $redirect);
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
