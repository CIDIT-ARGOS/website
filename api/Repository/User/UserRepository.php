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
        error_log("Entrou aqui!!");
        try {
            $connection = $db->getConnection();
            $escapeUser = mysqli_real_escape_string($connection, $user);
            $stmt = mysqli_prepare($connection, UserConstants::FIND_BY_USER);
            mysqli_stmt_bind_param($stmt, "s", $escapeUser);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $userIsFind = $result ? mysqli_fetch_assoc($result) : null;
            return $userIsFind;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao buscar usuário: " . $e->getMessage());
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
}
