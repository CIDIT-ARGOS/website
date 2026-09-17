<?php

namespace api\Repository\User;

require_once __DIR__ . '/../../Database/DbConnection.php';
require_once __DIR__ . '/../../../Common/Constants/queries/User.Constants.php';


use api\Database\DbConnection\DbConnection;
use Common\Constants\queries\UserConstants;

class UserRepository
{
    public function findByUser(string $user, DbConnection $db)
    {
        try {
            $connection = $db->getConnection();
            $escapeUser = mysqli_real_escape_string($connection, $user);
            $stmt = mysqli_prepare($connection, UserConstants::FIND_BY_USER);
            mysqli_stmt_bind_param($stmt, "s", $escapeUser);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            return $result ? mysqli_fetch_assoc($result) : null;;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao buscar usuário: " . $e->getMessage());
        }
    }


    public function getById(DbConnection $db, int $userId)
    {
        try {
            $connection = $db->getConnection();
            $stmt = mysqli_prepare($connection, UserConstants::GET_BY_STUDENT_ID);
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            return $result ? mysqli_fetch_assoc($result) : null;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao buscar usuário por ID: " . $e->getMessage());
        }
    }

    public function updateLastLogin(int $userId, DbConnection $db)
    {
        try {
            $connection = $db->getConnection();
            $stmt = mysqli_prepare($connection, UserConstants::UPDATE_LAST_LOGIN);
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
        } catch (\Exception $e) {
            throw new \Exception("Erro ao atualizar último login: " . $e->getMessage());
        }
    }

    public function getAll(
        string $sql,
        string $types,
        array $params,
        DbConnection $db
    ) {
        try {
            $connection = $db->getConnection();
            $stmt = mysqli_prepare($connection, $sql);
            if ($types && $params) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            return mysqli_stmt_get_result($stmt);
        } catch (\Exception $e) {
            throw new \Exception("Erro ao buscar usuários: " . $e->getMessage());
        }
    }
}
