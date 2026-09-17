<?php

namespace api\Service\Student;

require_once __DIR__ . '/Database/DbConnection.php';
require_once __DIR__ . './Repository/User/UserRepository.php';

use api\Repository\User\UserRepository;
use api\Database\DbConnection\DbConnection;

class StudentService
{
    private $userRepository;
    private $db;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->db = new DbConnection(
            "localhost",
            "argos",
            "argos",
            "argos"
        );
    }

    public function getById(int $id)
    {
        if ($id <= 0 || !is_int($id)) {
            throw new \InvalidArgumentException("ID inválido. Deve ser um número inteiro positivo.");
        }
        return $this->userRepository->getById($this->db, $id);
    }
}
